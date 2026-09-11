<?php

namespace App\Lights\Shelly;

use App\Lights\Portal;
use Illuminate\Validation\ValidationException;

class PilotArmer
{
    public function __construct(private Probe $probe, private Portal $portal) {}

    public function arm(int $channel): void
    {
        if (!config('lights.control.live_enabled') || !app()->environment('acceptance')) {
            throw ValidationException::withMessages(['lights' => 'The physical-control kill switch is disabled.']);
        }
        $report = $this->probe->run();
        $state = $report['channels'][$channel] ?? null;
        if (!$report['online'] || !is_array($state) || $state['output'] !== false || $state['has_errors']) {
            throw ValidationException::withMessages(['lights' => 'Cannot arm ON: the selected court must report online, OFF and fault-free.']);
        }
        $heartbeat = (int) $this->portal->db()->table('lights_worker')->where('id', 1)->value('seen_at');
        if ($heartbeat < $this->portal->now() - 5 || $heartbeat > $this->portal->now() + 5) {
            throw ValidationException::withMessages(['lights' => 'Cannot arm ON while the accounting worker is unhealthy.']);
        }
        $directory = base_path('.local-acceptance/private/shelly');
        $approval = ['device' => PrivateSettings::DEVICE, 'channel' => $channel, 'expires_at' => $this->portal->now() + 600,
            'onsite_empty_court_confirmed' => true, 'operator_onsite_confirmed' => true,
            'reboot_off_and_overrides_checked' => true];
        $path = PilotApproval::path($channel);
        if (file_put_contents($path, json_encode($approval, JSON_THROW_ON_ERROR), LOCK_EX) === false) {
            throw ValidationException::withMessages(['lights' => 'The one-use ON approval could not be saved.']);
        }
        chmod($path, 0600);
    }
}
