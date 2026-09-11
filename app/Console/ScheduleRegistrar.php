<?php

namespace App\Console;

use Illuminate\Console\Scheduling\Schedule;

class ScheduleRegistrar
{
    public static function register(Schedule $schedule): void
    {
        if (config('lights.enabled') && config('lights.mode') === 'live') {
            $schedule->command('lights:tick')->everySecond()->withoutOverlapping(1)->runInBackground();
        }
        if (in_array(config('app.env'), ['live', 'production'], true)) {
            $schedule->command('backup:run')->dailyAt('23:50')->withoutOverlapping();
            $schedule->command('pos:generateSubscriptionInvoices')->dailyAt('23:30')->withoutOverlapping();
            $schedule->command('pos:updateRewardPoints')->dailyAt('23:45')->withoutOverlapping();
            $schedule->command('pos:autoSendPaymentReminder')->dailyAt('08:00')->withoutOverlapping();
        }

        // Demo resets delete data: never register them in a live environment.
        if (config('app.env') === 'demo' && config('mail.username')) {
            $schedule->command('pos:dummyBusiness')->cron('0 */3 * * *')
                ->emailOutputTo(config('mail.username'));
        }
    }
}
