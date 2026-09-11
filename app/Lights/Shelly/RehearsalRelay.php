<?php
namespace App\Lights\Shelly;

/** No network or physical device operations. Exercises the same durable session engine. */
class RehearsalRelay implements RelayDriver
{
    public function on(object $session): array
    {
        return ['output' => true, 'timer_started_at' => now()->getTimestamp(), 'timer_duration' => (int) $session->duration_seconds];
    }
    public function off(object $session): void {}
}
