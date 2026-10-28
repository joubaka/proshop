<?php

namespace App\Lights\Shelly;

use RuntimeException;

/** Serializes requests from web and worker processes on this installation. No credentials stored. */
class RequestCoordinator
{
    public function __construct(private ?string $path = null) {}

    public function run(callable $request, float $waitSeconds = 10): mixed
    {
        // Also used by the standalone acceptance status helper, without booting Laravel.
        $path = $this->path ?? dirname(__DIR__, 3).'/storage/framework/cache/lights-shelly-requests.lock';
        $handle = fopen($path, 'c+');
        if (!$handle) { throw new RuntimeException('Shelly request coordination is unavailable.'); }
        $deadline = hrtime(true) / 1e9 + min(10, max(0, $waitSeconds));
        $locked = false;
        try {
            do {
                $locked = flock($handle, LOCK_EX | LOCK_NB);
                if ($locked) { break; }
                if (hrtime(true) / 1e9 >= $deadline) { throw new RuntimeException('Shelly is busy. Please try again shortly.'); }
                usleep(20000);
            } while (true);
            rewind($handle);
            $last = (float) stream_get_contents($handle);
            // Pace across processes and across status/control clients, including failures.
            $delay = max(0, 1.1 - (microtime(true) - $last));
            if (hrtime(true) / 1e9 + $delay >= $deadline) {
                throw new RuntimeException('Shelly is busy. Please try again shortly.');
            }
            if ($delay > 0) { usleep((int) ceil($delay * 1e6)); }
            try { return $request(); }
            finally {
                rewind($handle);
                if (!ftruncate($handle, 0) || fwrite($handle, sprintf('%.6F', microtime(true))) === false || !fflush($handle)) {
                    throw new RuntimeException('Shelly request coordination could not be saved.');
                }
            }
        } finally {
            if ($locked) { flock($handle, LOCK_UN); }
            fclose($handle);
        }
    }
}
