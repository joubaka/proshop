<?php

namespace App\Lights\Shelly;

use Symfony\Component\Process\Process;

class EmergencyOffProbe extends Probe
{
    private string $id = '';

    public function send(string $id): void
    {
        $this->id = $id;
        $this->run();
    }

    protected function makeProcess(): Process
    {
        return new Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'display_errors=0', '-d', 'log_errors=0',
            '-d', 'zend.exception_ignore_args=1', '-d', 'disable_functions=', '-d', 'allow_url_fopen=0',
            base_path('scripts/local-acceptance/shelly-emergency-off.php'), $this->id],
            base_path(), parent::makeProcess()->getEnv(), null, 15);
    }
}
