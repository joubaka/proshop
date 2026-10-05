<?php

namespace App\Lights;

use Illuminate\Support\Facades\Log;

/** Never pass credentials, raw provider payloads or exception messages here. */
class Diagnostics
{
    public static function write(string $event, array $context = [], ?\Throwable $error = null): void
    {
        if ($error) { $context['exception_class'] = $error::class; }
        try {
            Log::channel('lights')->log($error || ($context['state'] ?? null) === 'review' ? 'warning' : 'info', $event, $context);
        } catch (\Throwable) {
            // Logging must never prevent safety OFF or change billing.
        }
    }
}
