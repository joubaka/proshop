<?php

namespace App\Lights;

use Carbon\CarbonImmutable;
use DateTimeZone;

class OperatingHours
{
    public function __construct(private Portal $portal) {}

    public function localDate(): string
    {
        return $this->localNow()->format('Y-m-d');
    }

    public function secondsUntilMidnight(): int
    {
        $now = $this->localNow();

        return max(1, (int) $now->diffInSeconds($now->addDay()->startOfDay(), false));
    }

    public function limitSeconds(int $seconds): int
    {
        $safeWindow = $this->secondsUntilMidnight()
            - max(1, (int) config('lights.cutoff_dispatch_buffer_seconds', 10));

        return max(0, min($seconds, $safeWindow));
    }

    private function localNow(): CarbonImmutable
    {
        $zone = new DateTimeZone((string) config('lights.cutoff_timezone', 'Africa/Johannesburg'));

        return CarbonImmutable::createFromTimestamp($this->portal->now(), $zone);
    }
}
