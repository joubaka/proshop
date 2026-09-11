<?php
namespace App\Lights\Shelly;

use Symfony\Component\Process\Process;

class ControlProbe extends Probe implements RelayDriver
{
    private string $action = '';
    private string $id = '';
    public function on(object $session): array
    {
        $this->id = $session->id; $this->action = 'on';
        return $this->run()['receipt'];
    }
    public function off(object $session): void
    {
        $this->id = $session->id; $this->action = 'off';
        $this->run();
    }
    protected function makeProcess(): Process
    {
        return new Process([PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'display_errors=0', '-d', 'log_errors=0',
            '-d', 'zend.exception_ignore_args=1', '-d', 'disable_functions=', '-d', 'allow_url_fopen=0',
            base_path('scripts/local-acceptance/shelly-control.php'), $this->action, $this->id],
            base_path(), parent::makeProcess()->getEnv(), null, 35);
    }
}
