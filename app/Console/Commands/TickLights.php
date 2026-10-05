<?php

namespace App\Console\Commands;

use App\Lights\ManualSwitches;
use App\Lights\Portal;
use App\Lights\SafetySessions;
use App\Lights\Shelly\StatusSynchronizer;
use Illuminate\Console\Command;

class TickLights extends Command
{
    protected $signature = 'lights:tick';
    protected $description = 'Reconcile court-light timers, usage and safety state';

    public function handle(Portal $portal, SafetySessions $safety, ManualSwitches $manual, StatusSynchronizer $status): int
    {
        if (!$portal->enabled()) { return self::SUCCESS; }
        $stage = 'accounting';
        try {
            $portal->tick(true);
            $stage = 'session_control';
            $safety->tick();
            $stage = 'midnight_cutoff';
            $manual->enforceMidnightCutoff();
            $stage = 'manual_control';
            $manual->tick();
            $stage = 'status_refresh';
            $status->refreshIfDue();
        } catch (\Throwable $error) {
            \App\Lights\Diagnostics::write('Lights worker failed.', ['stage' => $stage], $error);
            throw $error;
        }
        return self::SUCCESS;
    }
}
