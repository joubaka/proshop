<?php
namespace App\Lights;

use App\Lights\Shelly\RelayDriver;
use App\Lights\Shelly\RehearsalRelay;
use App\Lights\Shelly\ControlProbe;
use App\Lights\Shelly\LiveCloudRelay;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/** Durable relay-control and billing state machine for supervised and customer sessions. */
class SafetySessions
{
    public function __construct(private Portal $portal) {}
    private function db() { return $this->portal->db(); }
    private function now(): int { return $this->portal->now(); }
    public function available(): bool { return $this->db()->getSchemaBuilder()->hasTable('lights_control_sessions'); }
    private function lock(callable $fn)
    {
        return $this->db()->transaction(function () use ($fn) {
            $this->db()->table('lights_locks')->where('id', 1)->lockForUpdate()->firstOrFail();
            return $fn();
        }, 3);
    }
    private function admin(int $actor): void
    {
        abort_unless($this->db()->table('lights_users')->where('id', $actor)->where('is_admin', true)->where('active', true)->exists(), 403);
    }
    private function fail(string $message): never { throw ValidationException::withMessages(['lights' => $message]); }
    private function event(string $kind, object $session, ?int $actor = null): void
    {
        $this->db()->table('lights_events')->insert(['actor_id' => $actor, 'kind' => 'control_'.$kind,
            'details' => json_encode(['session' => $session->id, 'channel' => (int) $session->channel, 'driver' => $session->driver]), 'created_at' => $this->now()]);
    }
    public function start(int $actor, int $channel, string $key, int $quotedRate, string $driver): string
    {
        $this->admin($actor);
        abort_unless(in_array($channel, [0, 1], true) && Str::isUuid($key) && in_array($driver, ['rehearsal', 'cloud'], true), 422);
        $rate = (int) config('lights.control.rate_cents', 6000);
        if ($rate < 100 || $rate > 100000 || $quotedRate !== $rate) { $this->fail('The pilot rate changed. Refresh before starting.'); }
        return $this->lock(function () use ($actor, $channel, $key, $driver, $rate) {
            $this->admin($actor);
            $old = $this->db()->table('lights_control_sessions')->where('user_id', $actor)->where('request_key', $key)->first();
            if ($old) {
                if ((int) $old->channel !== $channel || $old->driver !== $driver) { $this->fail('That request belongs to another court or mode.'); }
                return $old->id;
            }
            if ($driver === 'cloud' && !\App\Lights\Shelly\PilotApproval::allows($channel)) {
                $this->fail('Physical controls are locked pending supervised on-site acceptance for this court.');
            }
            $blockingControl = $this->db()->table('lights_control_sessions')->where('active_user_id', $actor)->first();
            if ($blockingControl) {
                $this->fail('Court '.((int) $blockingControl->channel === 0 ? 3 : 4).' has an unresolved '.str_replace('_', ' ', $blockingControl->state).' session. Confirm it OFF and release it below before starting another court.');
            }
            if ($this->db()->table('lights_sessions')->where('active_user_id', $actor)->exists()) { $this->fail('Finish your existing session first.'); }
            if ($this->db()->table('lights_control_sessions')->where('active_channel', $channel)->exists()) { $this->fail('This channel is reserved, including any unresolved command.'); }
            if ($driver === 'cloud') {
                $heartbeat = (int) $this->db()->table('lights_worker')->where('id', 1)->value('seen_at');
                if ($heartbeat < $this->now() - (int) config('lights.worker_healthy_seconds', 15) || $heartbeat > $this->now() + 5) { $this->fail('A healthy accounting worker is required before physical ON.'); }
            }
            $balance = (int) $this->db()->table('lights_users')->where('id', $actor)->value('balance_cents');
            $fundedSeconds = min(300, max(1, (int) config('lights.control.max_seconds', 60)), intdiv($balance * 3600, $rate));
            if ($fundedSeconds < 1) { $this->fail('Add simulated credit to your admin wallet before the rehearsal or pilot.'); }
            $seconds = app(OperatingHours::class)->limitSeconds($fundedSeconds);
            if ($seconds < 1) { $this->fail('The midnight shutdown boundary is too close to start another session.'); }
            $id = (string) Str::uuid();
            $this->db()->table('lights_control_sessions')->insert(['id' => $id, 'user_id' => $actor, 'active_user_id' => $actor,
                'channel' => $channel, 'active_channel' => $channel, 'request_key' => $key, 'driver' => $driver, 'state' => 'reserved',
                'rate_cents' => $rate, 'budget_cents' => $balance, 'duration_seconds' => $seconds, 'created_at' => $this->now()]);
            $this->event('reserved', $this->db()->table('lights_control_sessions')->find($id), $actor);
            return $id;
        });
    }
    public function startCustomer(int $user, int $courtId, string $key, int $quotedRate): string
    {
        $acceptance = app()->environment('acceptance');
        abort_unless(config('lights.control.live_enabled') && config('lights.control.customer_enabled')
            && ($acceptance || (config('lights.mode') === 'live' && app()->environment(['production', 'staging']))),
            503, 'Customer light control is not enabled.');
        abort_unless(Str::isUuid($key) && $this->db()->getSchemaBuilder()->hasColumn('lights_control_sessions', 'court_id'), 503);
        return $this->lock(function () use ($user, $courtId, $key, $quotedRate, $acceptance) {
            $member = $this->db()->table('lights_users')->where('active', true)->find($user);
            abort_unless($member, 403);
            $court = $this->db()->table('lights_courts')->where('active', true)->find($courtId);
            abort_unless($court, 404);
            if ((int) $court->rate_cents !== $quotedRate) { $this->fail('The court rate changed. Refresh before switching on.'); }
            $driver = $acceptance ? 'cloud_customer' : 'customer_cloud';
            $old = $this->db()->table('lights_control_sessions')->where('user_id', $user)->where('request_key', $key)->first();
            if ($old) {
                if ((int) $old->court_id !== $courtId || $old->driver !== $driver) { $this->fail('That request belongs to another court.'); }
                return $old->id;
            }
            if ($acceptance && config('lights.control.local_approval_required')
                && !\App\Lights\Shelly\PilotApproval::allows((int) $court->channel)) {
                $this->fail('Timed ON is locked. An administrator must complete the on-site arming checklist for this court.');
            }
            if ($this->db()->table('lights_sessions')->where('active_user_id', $user)->exists()) {
                $this->fail('Finish the older simulator session before using physical court control.');
            }
            if ($this->db()->table('lights_control_sessions')->where('active_channel', $court->channel)->exists()) {
                $this->fail('This court is already in use or awaiting a safety check.');
            }
            $heartbeat = (int) $this->db()->table('lights_worker')->where('id', 1)->value('seen_at');
            if ($heartbeat < $this->now() - (int) config('lights.worker_healthy_seconds', 15) || $heartbeat > $this->now() + 5) {
                $this->fail('Light control is temporarily unavailable. Please contact the venue.');
            }
            $activeForUser = $this->db()->table('lights_control_sessions')->where('active_user_id', $user)->get();
            $combinedRate = (int) $court->rate_cents + $activeForUser->sum('rate_cents');
            $fundedSeconds = min((int) config('lights.max_session_seconds', 14400),
                intdiv((int) $member->balance_cents * 3600, $combinedRate));
            if ($fundedSeconds < 1) { $this->fail('Top up your wallet before switching on.'); }
            $seconds = app(OperatingHours::class)->limitSeconds($fundedSeconds);
            if ($seconds < 1) { $this->fail('The venue is closing at midnight; this court cannot be switched on now.'); }
            if ($activeForUser->isNotEmpty()) {
                $sharedDeadline = $this->now() + $seconds;
                foreach ($activeForUser as $activeSession) {
                    $deadline = $activeSession->deadline_at === null ? $sharedDeadline : min((int) $activeSession->deadline_at, $sharedDeadline);
                    $this->db()->table('lights_control_sessions')->where('id', $activeSession->id)->update(['deadline_at' => $deadline]);
                }
            }
            $id = (string) Str::uuid();
            $this->db()->table('lights_control_sessions')->insert(['id' => $id, 'user_id' => $user, 'court_id' => $courtId,
                'active_user_id' => $user, 'channel' => $court->channel, 'active_channel' => $court->channel,
                'request_key' => $key, 'driver' => $driver, 'state' => 'reserved', 'rate_cents' => $court->rate_cents,
                'budget_cents' => $member->balance_cents, 'duration_seconds' => $seconds, 'created_at' => $this->now()]);
            $session = $this->db()->table('lights_control_sessions')->find($id);
            $this->event('customer_reserved', $session, $user);
            return $id;
        });
    }
    public function stop(int $actor, string $id): void
    {
        $this->lock(function () use ($actor, $id) {
            $this->admin($actor);
            $s = $this->db()->table('lights_control_sessions')->where('id', $id)->firstOrFail();
            if (!$s->active_user_id || $s->stop_requested_at !== null) { return; }
            $this->db()->table('lights_control_sessions')->where('id', $id)->update(['stop_requested_at' => $this->now()]);
            $this->event('stop_requested', $s, $actor);
        });
    }
    public function stopCustomer(int $user, string $id): void
    {
        $this->lock(function () use ($user, $id) {
            $s = $this->db()->table('lights_control_sessions')->where('id', $id)->where('user_id', $user)
                ->whereIn('driver', ['customer_cloud', 'cloud_customer'])->firstOrFail();
            if (!$s->active_user_id || $s->stop_requested_at !== null) { return; }
            $this->db()->table('lights_control_sessions')->where('id', $id)->update(['stop_requested_at' => $this->now()]);
            $this->event('customer_stop_requested', $s, $user);
        });
    }
    private function charge(object $s): void
    {
        if ($s->started_at === null) { return; }
        // Freeze at the member's stop request, original budget deadline, or uncertainty.
        $until = min($this->now(), (int) $s->deadline_at, $s->stop_requested_at ?? PHP_INT_MAX);
        $elapsed = max(0, $until - (int) $s->started_at);
        $memberBalance = (int) $this->db()->table('lights_users')->where('id', $s->user_id)->value('balance_cents');
        $total = max((int) $s->charged_cents, min((int) $s->budget_cents,
            (int) $s->charged_cents + $memberBalance, intdiv($elapsed * $s->rate_cents + 3599, 3600)));
        $delta = $total - (int) $s->charged_cents;
        if (!$delta) { return; }
        $balance = (int) $this->db()->table('lights_users')->where('id', $s->user_id)->value('balance_cents') - $delta;
        if ($balance < 0) { throw new \LogicException('Pilot wallet invariant failed.'); }
        $this->db()->table('lights_users')->where('id', $s->user_id)->update(['balance_cents' => $balance]);
        $customer = in_array($s->driver, ['customer_cloud', 'cloud_customer'], true);
        $this->db()->table('lights_ledger')->insert(['user_id' => $s->user_id, 'amount_cents' => -$delta, 'balance_after' => $balance,
            'kind' => $customer ? 'usage' : 'pilot_usage', 'reference' => ($customer ? 'usage:' : 'pilot:').$s->id.':'.$total, 'created_at' => $this->now()]);
        $this->db()->table('lights_control_sessions')->where('id', $s->id)->update(['charged_cents' => $total]);
    }
    public function tick(?RelayDriver $testDriver = null): void
    {
        if (!$this->available()) { return; }
        foreach ($this->db()->table('lights_control_sessions')->whereNotNull('active_user_id')->pluck('id') as $id) {
            $s = $this->lock(function () use ($id) {
                $s = $this->db()->table('lights_control_sessions')->find($id);
                if (!$s || !$s->active_user_id) { return null; }
                if (in_array($s->state, ['starting', 'stopping'], true)) {
                    if ($s->command_at < $this->now() - 30) {
                        $this->db()->table('lights_control_sessions')->where('id', $id)->update(['state' => $s->state === 'starting' ? 'running' : 'review', 'uncertain' => true,
                            'stop_requested_at' => $s->stop_requested_at ?? $s->command_at, 'note' => 'Interrupted command. ON will never be replayed. Request OFF, then confirm physical state.']);
                        $this->event('interrupted', $s);
                    }
                    return null;
                }
                $this->charge($s);
                if ($s->state === 'review') { return null; }
                if ($s->state === 'reserved' && $s->stop_requested_at !== null) {
                    $this->complete($s); return null;
                }
                $expired = $s->deadline_at !== null && $this->now() >= $s->deadline_at;
                if ($s->state === 'running' && !$expired && $s->stop_requested_at === null) { return null; }
                $action = $s->state === 'reserved' ? 'starting' : 'stopping';
                $changes = ['state' => $action, 'command_at' => $this->now()];
                if ($action === 'starting') {
                    $duration = app(OperatingHours::class)->limitSeconds((int) $s->duration_seconds);
                    if ($duration < 1) {
                        $this->db()->table('lights_control_sessions')->where('id', $id)
                            ->update(['note' => 'ON was not sent because the midnight shutdown boundary is too close.']);
                        $this->complete($s);
                        return null;
                    }
                    $changes += ['duration_seconds' => $duration, 'sent_at' => $this->now(),
                        'deadline_at' => $this->now() + $duration];
                }
                $this->db()->table('lights_control_sessions')->where('id', $id)->update($changes);
                $this->event($action, $s);
                return $this->db()->table('lights_control_sessions')->find($id);
            });
            if (!$s) { continue; }
            // No network calls inside a database transaction or its automatic retry scope.
            $driver = $testDriver ?? match ($s->driver) {
                'cloud' => app(ControlProbe::class),
                'cloud_customer' => app(ControlProbe::class),
                'customer_cloud' => app(LiveCloudRelay::class),
                default => new RehearsalRelay,
            };
            try {
                $receipt = $s->state === 'starting' ? $driver->on($s) : null;
                if ($s->state === 'stopping') { $driver->off($s); }
                $status = app(\App\Lights\Shelly\HardwareStatus::class);
                if ($s->state === 'starting' && is_array($receipt)) {
                    $status->recordChannel((int) $s->channel, $receipt, true);
                } elseif ($s->state === 'stopping') {
                    $status->recordChannel((int) $s->channel, ['output' => false, 'has_errors' => false], true);
                }
                $this->lock(function () use ($s, $receipt) {
                    $current = $this->db()->table('lights_control_sessions')->find($s->id);
                    if ($current->state !== $s->state || $current->command_at !== $s->command_at) { return; }
                    if ($s->state === 'starting') {
                        if (($receipt['output'] ?? null) !== true || !is_numeric($receipt['timer_started_at'] ?? null)
                            || $receipt['timer_started_at'] < $s->sent_at - 10 || $receipt['timer_started_at'] > $this->now() + 2
                            || ($receipt['timer_duration'] ?? null) != $s->duration_seconds || $this->now() >= $s->deadline_at) {
                            throw new \RuntimeException('Missing fresh ON/timer evidence.');
                        }
                        $this->db()->table('lights_control_sessions')->where('id', $s->id)->update(['state' => 'running', 'started_at' => $this->now()]);
                        $this->event('on_timer_confirmed', $s);
                    } else {
                        // Supervised pilots require a separate physical confirmation. Trusted
                        // localhost customer mode and production complete after a definite OFF ack.
                        $requiresReview = $s->uncertain || $s->driver === 'cloud'
                            || ($s->driver === 'cloud_customer' && config('lights.control.local_approval_required'));
                        if ($requiresReview) {
                            $this->db()->table('lights_control_sessions')->where('id', $s->id)->update(['state' => 'review', 'stopped_at' => $this->now(),
                                'note' => 'OFF acknowledged. Confirm the physical court is off before releasing its reservation.']);
                        } else { $this->complete($s); }
                        $this->event('off_acknowledged', $s);
                    }
                });
            } catch (\App\Lights\Shelly\CommandNotSent) {
                $this->lock(function () use ($s) {
                    // A definite preflight rejection must not trigger OFF on an already-used court.
                    $current = $this->db()->table('lights_control_sessions')->find($s->id);
                    if ($s->state === 'starting' && $current->state === 'starting') {
                        $this->db()->table('lights_control_sessions')->where('id', $s->id)->update(['note' => 'Preflight rejected. No ON or OFF command sent; no charge.']);
                        $this->complete($s); $this->event('not_sent', $s);
                    }
                });
            } catch (\Throwable) {
                $this->lock(function () use ($s) {
                    $current = $this->db()->table('lights_control_sessions')->find($s->id);
                    if ($current->state !== $s->state) { return; }
                    $this->db()->table('lights_control_sessions')->where('id', $s->id)->update(['state' => $s->state === 'starting' ? 'running' : 'review', 'uncertain' => true,
                        'stop_requested_at' => $current->stop_requested_at ?? $this->now(),
                        'note' => 'Command outcome uncertain. No ON retry. Request safety OFF and check the court on-site.']);
                    $this->event('uncertain', $s);
                });
            }
        }
    }
    private function complete(object $s): void
    {
        $this->db()->table('lights_control_sessions')->where('id', $s->id)->update(['state' => 'completed',
            'active_user_id' => null, 'active_channel' => null, 'stopped_at' => $this->now()]);
        $this->event('completed', $s);
    }
    public function review(int $actor, string $id, string $action): void
    {
        $this->lock(function () use ($actor, $id, $action) {
            $this->admin($actor);
            $s = $this->db()->table('lights_control_sessions')->where('id', $id)->firstOrFail();
            if ($s->state !== 'review') { $this->fail('This session is not awaiting review.'); }
            if ($action === 'off') {
                $this->db()->table('lights_control_sessions')->where('id', $id)->update(['state' => 'running', 'stop_requested_at' => $s->stop_requested_at ?? $this->now()]);
                $this->event('safety_off_requested', $s, $actor);
            } elseif ($action === 'confirmed_off') {
                // A definite OFF acknowledgement supersedes the original device
                // timer. Without one, retain the conservative timer deadline.
                $releaseAfter = $s->stopped_at !== null
                    ? max((int) $s->stopped_at + 30, (int) $s->command_at + 30)
                    : max((int) $s->deadline_at + 30, (int) $s->command_at + 30);
                if ($this->now() < $releaseAfter) { $this->fail('Wait until the timer and in-flight-command safety window have passed before release.'); }
                $this->charge($s); $this->complete($s); $this->event('operator_confirmed_off', $s, $actor);
            } else { abort(422); }
        });
    }
    public function rows() { return $this->db()->table('lights_control_sessions')->orderByDesc('created_at')->limit(30)->get(); }
    public function customerState(int $user): ?object
    {
        if (!$this->available() || !$this->db()->getSchemaBuilder()->hasColumn('lights_control_sessions', 'court_id')) { return null; }
        return $this->db()->table('lights_control_sessions')->where('user_id', $user)->whereIn('driver', ['customer_cloud', 'cloud_customer'])
            ->whereNotNull('active_user_id')->first();
    }
}
