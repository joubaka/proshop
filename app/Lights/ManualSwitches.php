<?php

namespace App\Lights;

use App\Lights\Shelly\CommandNotSent;
use App\Lights\Shelly\HardwareStatus;
use App\Lights\Shelly\ManualControlProbe;
use App\Lights\Shelly\LiveCloudRelay;
use Illuminate\Support\Str;

class ManualSwitches
{
    public function __construct(
        private Portal $portal,
        private ManualControlProbe $probe,
        private HardwareStatus $hardwareStatus,
        private LiveCloudRelay $liveRelay,
    ) {}

    private function db() { return $this->portal->db(); }

    public function on(int $actor, int $channel, string $requestKey, int $seconds = 60): object
    {
        return $this->queue($actor, $channel, $requestKey, 'on', app(OperatingHours::class)->limitSeconds($seconds));
    }

    public function off(int $actor, int $channel, string $requestKey): object
    {
        return $this->queue($actor, $channel, $requestKey, 'off', null);
    }

    private function queue(int $actor, int $channel, string $requestKey, string $action, ?int $seconds): object
    {
        abort_unless(in_array($channel, [0, 1], true) && Str::isUuid($requestKey), 422);
        if ($action === 'on') {
            abort_unless($seconds !== null && $seconds >= 1 && $seconds <= 300, 422);
        }

        return $this->db()->transaction(function () use ($actor, $channel, $requestKey, $action, $seconds) {
            $this->db()->table('lights_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            abort_unless($this->db()->table('lights_users')->where('id', $actor)
                ->where('is_admin', true)->where('active', true)->exists(), 403);
            $existing = $this->db()->table('lights_manual_commands')
                ->where('actor_id', $actor)->where('request_key', $requestKey)->first();
            if ($existing) { return $existing; }

            $id = (string) Str::uuid();
            $now = $this->portal->now();
            // The newest button press is the operator's intent. A command already handed to
            // Shelly is never interrupted or replayed, but older unsent commands are cancelled.
            $this->db()->table('lights_manual_commands')->where('channel', $channel)->where('state', 'queued')
                ->update(['state' => 'cancelled', 'completed_at' => $now,
                    'note' => 'Superseded before dispatch by a newer admin command.']);
            $this->db()->table('lights_manual_commands')->insert([
                'id' => $id, 'actor_id' => $actor, 'request_key' => $requestKey,
                'channel' => $channel, 'action' => $action, 'duration_seconds' => $seconds,
                'state' => 'queued', 'created_at' => $now,
            ]);
            $this->db()->table('lights_events')->insert([
                'actor_id' => $actor, 'kind' => 'manual_shelly_'.$action.'_requested',
                'details' => json_encode(['command' => $id, 'channel' => $channel, 'seconds' => $seconds], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);

            return $this->db()->table('lights_manual_commands')->find($id);
        }, 3);
    }

    public function rows()
    {
        if (!$this->db()->getSchemaBuilder()->hasTable('lights_manual_commands')) { return collect(); }

        return $this->db()->table('lights_manual_commands')->orderByDesc('created_at')->limit(30)->get();
    }

    public function reconcileConfirmedOff(array $report): int
    {
        if (!$this->db()->getSchemaBuilder()->hasTable('lights_manual_commands')
            || !($report['online'] ?? false)) {
            return 0;
        }

        $checkedAt = (int) ($report['checked_at'] ?? 0);
        $resolved = 0;
        foreach (($report['channels'] ?? []) as $channel) {
            $number = $channel['channel'] ?? null;
            if (!in_array($number, [0, 1], true)
                || ($channel['output'] ?? null) !== false
                || ($channel['has_errors'] ?? false)) {
                continue;
            }

            $commands = $this->db()->table('lights_manual_commands')
                ->where('channel', $number)->where('action', 'off')->where('state', 'uncertain')
                ->where('command_at', '<=', $checkedAt)->get();
            foreach ($commands as $command) {
                $updated = $this->db()->table('lights_manual_commands')->where('id', $command->id)
                    ->where('state', 'uncertain')->update([
                        'state' => 'resolved_off',
                        'completed_at' => $checkedAt,
                        'note' => 'Resolved by a later healthy Shelly status confirming the channel is off.',
                    ]);
                if (!$updated) { continue; }
                $resolved++;
                $this->db()->table('lights_events')->insert([
                    'actor_id' => $command->actor_id,
                    'kind' => 'manual_shelly_off_resolved',
                    'details' => json_encode(['command' => $command->id, 'channel' => $number,
                        'confirmed_at' => $checkedAt], JSON_THROW_ON_ERROR),
                    'created_at' => $checkedAt,
                ]);
            }
        }

        return $resolved;
    }

    public function enforceMidnightCutoff(): void
    {
        if (!$this->db()->getSchemaBuilder()->hasColumn('lights_worker', 'midnight_cutoff_date')) { return; }
        $date = app(OperatingHours::class)->localDate();
        $now = $this->portal->now();
        $this->db()->transaction(function () use ($date, $now) {
            $this->db()->table('lights_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            $worker = $this->db()->table('lights_worker')->where('id', 1)->lockForUpdate()->first();
            if (!$worker) {
                $this->db()->table('lights_worker')->insert(['id' => 1, 'seen_at' => $now,
                    'midnight_cutoff_date' => $date, 'midnight_cutoff_at' => null]);
                return;
            }
            if ($worker->midnight_cutoff_date === null) {
                $this->db()->table('lights_worker')->where('id', 1)->update(['midnight_cutoff_date' => $date]);
                return;
            }
            if ($worker->midnight_cutoff_date === $date) { return; }

            $actor = $this->db()->table('lights_users')->where('is_admin', true)->where('active', true)
                ->orderBy('id')->value('id');
            if (!$actor) { return; }
            foreach ([0, 1] as $channel) {
                $this->db()->table('lights_manual_commands')->where('channel', $channel)->where('state', 'queued')
                    ->update(['state' => 'cancelled', 'completed_at' => $now,
                        'note' => 'Superseded by the venue midnight cutoff.']);
                $id = (string) Str::uuid();
                $this->db()->table('lights_manual_commands')->insert([
                    'id' => $id, 'actor_id' => $actor, 'request_key' => (string) Str::uuid(),
                    'channel' => $channel, 'action' => 'off', 'duration_seconds' => null,
                    'state' => 'queued', 'created_at' => $now,
                ]);
            }
            $this->db()->table('lights_worker')->where('id', 1)->update([
                'midnight_cutoff_date' => $date, 'midnight_cutoff_at' => $now,
            ]);
            $this->db()->table('lights_events')->insert([
                'actor_id' => $actor, 'kind' => 'midnight_cutoff_queued',
                'details' => json_encode(['date' => $date, 'channels' => [0, 1]], JSON_THROW_ON_ERROR),
                'created_at' => $now,
            ]);
        }, 3);
    }

    public function tick(): void
    {
        if (!$this->db()->getSchemaBuilder()->hasTable('lights_manual_commands')) { return; }
        $now = $this->portal->now();
        $this->db()->table('lights_manual_commands')->where('state', 'sending')
            ->where('command_at', '<', $now - 40)->update([
                'state' => 'uncertain',
                'note' => 'The command worker was interrupted. Check the court; ON is never replayed automatically.',
                'completed_at' => $now,
            ]);

        $command = $this->db()->transaction(function () use ($now) {
            $this->db()->table('lights_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            $queued = $this->db()->table('lights_manual_commands')->where('state', 'queued')
                ->orderBy('created_at')->lockForUpdate()->first();
            if (!$queued) { return null; }
            if ($queued->action === 'on') {
                $safeDuration = app(OperatingHours::class)->limitSeconds((int) $queued->duration_seconds);
                if ($safeDuration < 1) {
                    $this->db()->table('lights_manual_commands')->where('id', $queued->id)->update([
                        'state' => 'rejected', 'completed_at' => $now,
                        'note' => 'ON was not sent because the midnight shutdown boundary is too close.',
                    ]);
                    return null;
                }
                if ($safeDuration !== (int) $queued->duration_seconds) {
                    $this->db()->table('lights_manual_commands')->where('id', $queued->id)
                        ->update(['duration_seconds' => $safeDuration]);
                }
            }
            $this->db()->table('lights_manual_commands')->where('id', $queued->id)->where('state', 'queued')
                ->update(['state' => 'sending', 'command_at' => $now]);

            return $this->db()->table('lights_manual_commands')->find($queued->id);
        }, 3);
        if (!$command) { return; }

        try {
            if (app()->environment(['production', 'staging']) && config('lights.control.customer_enabled')) {
                if ($command->action === 'on') { $relayState = $this->liveRelay->on($command); }
                else { $this->liveRelay->off($command); $relayState = ['output' => false]; }
            } else {
                $result = $this->probe->send($command->id);
                $relayState = $command->action === 'on' ? ($result['receipt'] ?? []) : ['output' => false];
            }
            $this->hardwareStatus->recordChannel((int) $command->channel, $relayState, true);
            $status = 'completed';
            $note = $command->action === 'on'
                ? 'Shelly acknowledged ON with a '.(int) $command->duration_seconds.'-second automatic cutoff.'
                : 'Shelly acknowledged OFF and reported the channel off.';
        } catch (CommandNotSent) {
            $status = 'rejected';
            $note = 'Shelly preflight rejected ON. No switching command was sent; refresh live status before retrying.';
        } catch (\Throwable) {
            $status = 'uncertain';
            $note = 'Command outcome is uncertain. Check the court before sending another ON command.';
        }

        $completedAt = $this->portal->now();
        $this->db()->table('lights_manual_commands')->where('id', $command->id)->where('state', 'sending')
            ->update(['state' => $status, 'note' => $note, 'completed_at' => $completedAt]);
        $this->db()->table('lights_events')->insert([
            'actor_id' => $command->actor_id, 'kind' => 'manual_shelly_'.$command->action.'_'.$status,
            'details' => json_encode(['command' => $command->id, 'channel' => $command->channel], JSON_THROW_ON_ERROR),
            'created_at' => $completedAt,
        ]);
    }
}
