<?php

namespace App\Lights\Shelly;

class LiveCloudRelay implements RelayDriver
{
    private function control(): CloudControl
    {
        if (config('lights.mode') !== 'live' || !config('lights.control.live_enabled')
            || !config('lights.control.customer_enabled') || !app()->environment(['production', 'staging'])) {
            throw new CommandNotSent('Customer hardware control is not enabled.');
        }
        $secret = config('lights.shelly.auth_key');
        if (!is_string($secret) || !preg_match('/\A[A-Za-z0-9+\/_=.-]{16,4096}\z/D', $secret)) {
            throw new CommandNotSent('Shelly authorization is not configured.');
        }
        return new CloudControl($secret);
    }

    public function on(object $session): array
    {
        return $this->control()->on((int) $session->channel, (int) $session->duration_seconds);
    }

    public function off(object $session): void
    {
        $this->control()->off((int) $session->channel);
    }
}
