<?php

namespace App\Lights\Shelly;

use Illuminate\Validation\ValidationException;

/** Read-only gate. This class never sends a relay command. */
class StartConnection
{
    public function check(int $channel, bool $adopt): void
    {
        try {
            if (app()->environment('acceptance')) {
                $report = app(Probe::class)->run();
            } else {
                $secret = config('lights.shelly.auth_key');
                if (!is_string($secret) || !preg_match('/\A[A-Za-z0-9+\/_=.-]{16,4096}\z/D', $secret)) {
                    throw new \RuntimeException('Status authorization unavailable.');
                }
                $report = app(CloudStatus::class)->check($secret);
            }
        } catch (\Throwable $error) {
            \App\Lights\Diagnostics::write('Lights connection check failed before session reservation.', [], $error);
            $this->fail();
        }
        $state = collect($report['channels'] ?? [])->firstWhere('channel', $channel);
        $now = time();
        if (($report['online'] ?? null) !== true
            || !is_numeric($report['checked_at'] ?? null)
            || $report['checked_at'] < $now - 15 || $report['checked_at'] > $now + 5) {
            $this->fail();
        }
        if (!is_array($state) || ($state['has_errors'] ?? null) !== false
            || ($state['output'] ?? null) !== $adopt) {
            throw ValidationException::withMessages(['lights' => 'The court controller is online, but this court is not ready. Refresh and try again, or contact the facility. No session was started.']);
        }
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['lights' => 'We cannot reach the lights controller. Please ask the facility to check its power, Wi-Fi and internet connection, then try again. No session was started.']);
    }
}
