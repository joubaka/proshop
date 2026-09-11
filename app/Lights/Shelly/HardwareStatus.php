<?php

namespace App\Lights\Shelly;

use App\Lights\Portal;

class HardwareStatus
{
    public function __construct(private Portal $portal) {}

    public function record(array $report): void
    {
        if (!$this->portal->db()->getSchemaBuilder()->hasTable('lights_hardware_status')) { return; }
        $checkedAt = (int) ($report['checked_at'] ?? time());
        foreach (($report['channels'] ?? []) as $channel) {
            $number = $channel['channel'] ?? null;
            if (!in_array($number, [0, 1], true)) { continue; }
            $output = is_bool($channel['output'] ?? null) ? $channel['output'] : null;
            $this->portal->db()->table('lights_hardware_status')->updateOrInsert(
                ['channel' => $number],
                ['online' => (bool) ($report['online'] ?? false), 'output' => $output,
                    'watts' => is_numeric($channel['watts'] ?? null) ? $channel['watts'] : null,
                    'volts' => is_numeric($channel['volts'] ?? null) ? $channel['volts'] : null,
                    'has_errors' => (bool) ($channel['has_errors'] ?? false), 'checked_at' => $checkedAt]
            );
        }
    }

    public function recordChannel(int $channel, array $state, bool $online): void
    {
        if (!in_array($channel, [0, 1], true)
            || !$this->portal->db()->getSchemaBuilder()->hasTable('lights_hardware_status')) {
            return;
        }
        $output = is_bool($state['output'] ?? null) ? $state['output'] : null;
        $this->portal->db()->table('lights_hardware_status')->updateOrInsert(
            ['channel' => $channel],
            ['online' => $online, 'output' => $output,
                'watts' => is_numeric($state['watts'] ?? null) ? $state['watts'] : null,
                'volts' => is_numeric($state['volts'] ?? null) ? $state['volts'] : null,
                'has_errors' => (bool) ($state['has_errors'] ?? false), 'checked_at' => $this->portal->now()]
        );
    }

    public function latest(): ?array
    {
        if (!$this->portal->db()->getSchemaBuilder()->hasTable('lights_hardware_status')) { return null; }
        $rows = $this->portal->db()->table('lights_hardware_status')->whereIn('channel', [0, 1])->orderBy('channel')->get();
        if ($rows->count() !== 2) { return null; }
        return ['online' => $rows->every(fn ($row) => (bool) $row->online),
            'checked_at' => (int) $rows->min('checked_at'),
            'channels' => $rows->map(fn ($row) => ['channel' => (int) $row->channel,
                'output' => $row->output === null ? null : (bool) $row->output,
                'watts' => $row->watts === null ? null : (float) $row->watts,
                'volts' => $row->volts === null ? null : (float) $row->volts,
                'has_errors' => (bool) $row->has_errors, 'timer_started_at' => null,
                'timer_duration' => null])->all()];
    }
}
