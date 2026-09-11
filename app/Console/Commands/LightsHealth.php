<?php

namespace App\Console\Commands;

use App\Lights\HealthReport;
use Illuminate\Console\Command;

class LightsHealth extends Command
{
    protected $signature = 'lights:health {--json : Print machine-readable JSON}';
    protected $description = 'Check the lights worker, Shelly status, uncertain commands, and pending payments';

    public function handle(HealthReport $health, \App\Lights\PayFast\Settings $payFastSettings): int
    {
        $payFastSettings->apply();
        $report = $health->get();
        if ($this->option('json')) {
            $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        } else {
            $this->components->twoColumnDetail('Worker', $report['worker']['healthy'] ? '<fg=green>healthy</>' : '<fg=red>not reporting</>');
            $this->components->twoColumnDetail('Shelly', ($report['hardware']['online'] ?? false) ? '<fg=green>online</>' : '<fg=red>offline</>');
            $this->components->twoColumnDetail('Uncertain actions', (string) ($report['attention']['uncertain_controls'] + $report['attention']['uncertain_manual_commands']));
            $this->components->twoColumnDetail('PayFast pending over one hour', (string) $report['attention']['payfast_pending_over_one_hour']);
            foreach ($report['launch_gates'] as $gate => $passed) {
                $this->components->twoColumnDetail('Launch: '.str_replace('_', ' ', $gate), $passed ? '<fg=green>ready</>' : '<fg=red>blocked</>');
            }
            $this->components->twoColumnDetail('Ready for live members', $report['ready_for_live'] ? '<fg=green>yes</>' : '<fg=red>no</>');
        }
        return $report['ready_for_live'] ? self::SUCCESS : self::FAILURE;
    }
}
