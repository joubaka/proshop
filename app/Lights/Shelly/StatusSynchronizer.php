<?php

namespace App\Lights\Shelly;

use App\Lights\ManualSwitches;
use App\Lights\Portal;

class StatusSynchronizer
{
    public function __construct(
        private Portal $portal,
        private HardwareStatus $status,
        private ManualSwitches $manual,
    ) {}

    public function refreshIfDue(): void
    {
        if (!config('lights.enabled') || !config('lights.control.live_enabled')
            || !$this->portal->db()->getSchemaBuilder()->hasTable('lights_hardware_status')
            || !$this->portal->db()->getSchemaBuilder()->hasColumn('lights_worker', 'status_attempted_at')) {
            return;
        }
        if ($this->portal->db()->getSchemaBuilder()->hasTable('lights_control_sessions')
            && $this->portal->db()->table('lights_control_sessions')->whereNotNull('active_user_id')
                ->whereIn('state', ['reserved', 'starting', 'running', 'stopping'])->exists()) {
            // Control confirmation has priority over background/read-only polling. Successful
            // commands update HardwareStatus directly; review state can resume cloud polling.
            return;
        }

        $now = $this->portal->now();
        $claimed = $this->portal->db()->table('lights_worker')->where('id', 1)
            ->where(fn ($query) => $query->whereNull('status_attempted_at')->orWhere('status_attempted_at', '<', $now - 15))
            ->update(['status_attempted_at' => $now]);
        if (!$claimed) { return; }

        try {
            if (app()->environment('acceptance')) {
                $report = app(Probe::class)->run();
            } elseif (config('lights.mode') === 'live' && app()->environment(['production', 'staging'])) {
                $secret = config('lights.shelly.auth_key');
                if (!is_string($secret) || !preg_match('/\A[A-Za-z0-9+\/_=.-]{16,4096}\z/D', $secret)) { return; }
                $report = (new CloudStatus)->check($secret);
            } else { return; }
            $this->status->record($report);
            $this->manual->reconcileConfirmedOff($report);
        } catch (\Throwable) {
            // Preserve the last known status. Staleness is visible in the portal and monitoring.
        }
    }
}
