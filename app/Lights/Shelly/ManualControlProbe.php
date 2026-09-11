<?php

namespace App\Lights\Shelly;

use Symfony\Component\Process\Process;

class ManualControlProbe extends Probe
{
    private string $id = '';

    public function send(string $id): array
    {
        $this->id = $id;

        return $this->run();
    }

    protected function makeProcess(): Process
    {
        return new Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'display_errors=0', '-d', 'log_errors=0',
            '-d', 'zend.exception_ignore_args=1', '-d', 'disable_functions=', '-d', 'allow_url_fopen=0',
            base_path('scripts/local-acceptance/shelly-manual.php'), $this->id],
            base_path(), parent::makeProcess()->getEnv(), null, 35);
    }
}
