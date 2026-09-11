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
        $portal->tick(true);
        $safety->tick();
        $manual->enforceMidnightCutoff();
        $manual->tick();
        $status->refreshIfDue();
        return self::SUCCESS;
    }
}
