<?php

namespace App\Lights\Shelly;

use RuntimeException;
use Symfony\Component\Process\Process;

class Probe
{
    public function run(): array
    {
        $process = $this->makeProcess();
        try {
            $process->run();
            $result = json_decode($process->getOutput(), true);
            if (!$process->isSuccessful() || !is_array($result)) { throw new RuntimeException(); }
        } catch (\Throwable) { throw new RuntimeException('The read-only checker could not run. No switching command was sent.'); }
        if (isset($result['error'])) {
            if (($result['not_sent'] ?? false) === true) { throw new CommandNotSent('Preflight rejected. No switching command sent.'); }
            throw new RuntimeException($result['error']);
        }
        return $result;
    }

    protected function makeProcess(): Process
    {
        // Only this dedicated CLI gets cURL; the demo HTTP process stays network-blocked.
        // No credentials are passed on the command line or through the process environment.
        // Under cli-server, Symfony's getenv/$_SERVER intersection drops SystemRoot.
        // Windows' resolver needs it. Preserve only these OS values explicitly, from the
        // real process environment (not request/server input), never a guessed directory.
        $environment = [];
        if (PHP_OS_FAMILY === 'Windows') {
            foreach (['SystemRoot', 'WINDIR', 'TEMP', 'TMP'] as $name) {
                $value = getenv($name, true);
                if (is_string($value) && $value !== '') { $environment[$name] = $value; }
            }
        }
        return new Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'display_errors=0',
            '-d', 'log_errors=0', '-d', 'zend.exception_ignore_args=1',
            '-d', 'disable_functions=', '-d', 'allow_url_fopen=0',
            base_path('scripts/local-acceptance/shelly-status.php')], base_path(), $environment, null, 12);
    }
}
