<?php

namespace App\Lights;

use App\Lights\Shelly\HardwareStatus;

class HealthReport
{
    public function __construct(private Portal $portal, private HardwareStatus $hardware) {}

    public function get(): array
    {
        $db = $this->portal->db();
        $now = $this->portal->now();
        $worker = $db->table('lights_worker')->where('id', 1)->first();
        $hardware = $this->hardware->latest();
        $uncertainControls = $db->getSchemaBuilder()->hasTable('lights_control_sessions')
            ? $db->table('lights_control_sessions')->where('uncertain', true)->whereNotNull('active_user_id')->count() : 0;
        $uncertainManual = $db->getSchemaBuilder()->hasTable('lights_manual_commands')
            ? $db->table('lights_manual_commands')->where('state', 'uncertain')->count() : 0;
        $oldPendingPayments = $db->table('lights_topups')->where('gateway', 'payfast')->where('status', 'pending')
            ->where('created_at', '<', $now - 3600)->count();

        $defaultConnection = (string) config('database.default');
        $defaultDatabase = (string) config("database.connections.{$defaultConnection}.database");
        $lightsDatabase = (string) config('database.connections.lights.database');
        $payfastCredentials = ['merchant_id', 'merchant_key', 'passphrase'];

        $launchGates = [
            'lights_enabled' => (bool) config('lights.enabled'),
            'mode_live' => config('lights.mode') === 'live',
            'https_app_url' => str_starts_with(strtolower((string) config('app.url')), 'https://'),
            'separate_lights_database' => $lightsDatabase !== '' && $lightsDatabase !== 'lights_not_configured'
                && $lightsDatabase !== $defaultDatabase,
            'mail_sender_configured' => filter_var(config('mail.from.address'), FILTER_VALIDATE_EMAIL) !== false,
            'payfast_enabled' => (bool) config('lights.payfast.enabled'),
            'payfast_live' => ! (bool) config('lights.payfast.sandbox'),
            'payfast_credentials_configured' => collect($payfastCredentials)
                ->every(fn (string $key) => trim((string) config("lights.payfast.{$key}")) !== ''),
            'shelly_control_enabled' => (bool) config('lights.control.live_enabled'),
            'shelly_credentials_configured' => trim((string) config('lights.shelly.auth_key')) !== '',
            'customer_control_enabled' => (bool) config('lights.control.customer_enabled'),
            'verified_email_required' => (bool) config('lights.require_verified_email'),
        ];
        $runtimeHealthy = (bool) ($worker && (int) $worker->seen_at >= $now - 15 && $hardware && $hardware['online']
            && $uncertainControls === 0 && $uncertainManual === 0);
        $readyForLive = $runtimeHealthy && $oldPendingPayments === 0
            && ! in_array(false, $launchGates, true);

        return [
            'healthy' => $runtimeHealthy,
            'ready_for_live' => $readyForLive,
            'checked_at' => $now,
            'worker' => ['healthy' => (bool) ($worker && (int) $worker->seen_at >= $now - 15),
                'seen_at' => $worker ? (int) $worker->seen_at : null],
            'hardware' => $hardware,
            'midnight_cutoff' => ['timezone' => config('lights.cutoff_timezone'),
                'last_date' => $worker->midnight_cutoff_date ?? null, 'last_at' => $worker->midnight_cutoff_at ?? null],
            'attention' => ['uncertain_controls' => $uncertainControls, 'uncertain_manual_commands' => $uncertainManual,
                'payfast_pending_over_one_hour' => $oldPendingPayments],
            'launch_gates' => $launchGates,
        ];
    }
}
