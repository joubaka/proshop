<?php

namespace Tests\Feature;

use App\Console\ScheduleRegistrar;
use Illuminate\Console\Scheduling\Schedule;
use Tests\TestCase;

class ScheduleRegistrationTest extends TestCase
{
    public function test_live_and_production_register_all_four_jobs(): void
    {
        foreach (['live', 'production'] as $env) {
            config(['app.env' => $env]);
            $schedule = new Schedule;
            ScheduleRegistrar::register($schedule);
            $events = $schedule->events();
            $this->assertCount(4, $events);
            $this->assertSame(['50 23 * * *', '30 23 * * *', '45 23 * * *', '0 8 * * *'], array_column($events, 'expression'));
            foreach ($events as $event) {
                $this->assertTrue($event->withoutOverlapping);
                $this->assertStringNotContainsString('dummyBusiness', $event->command);
            }
        }
    }

    public function test_laravel_bootstrap_registers_the_production_schedule(): void
    {
        config(['app.env' => 'production']);
        $this->artisan('schedule:list')->expectsOutputToContain('pos:generateSubscriptionInvoices')->assertSuccessful();
        $this->assertCount(4, app(Schedule::class)->events());
    }

    public function test_local_and_testing_do_not_register_business_jobs(): void
    {
        foreach (['local', 'testing'] as $env) {
            config(['app.env' => $env]);
            $schedule = new Schedule;
            ScheduleRegistrar::register($schedule);
            $this->assertCount(0, $schedule->events());
        }
    }

    public function test_live_lights_worker_is_registered_as_a_background_sub_minute_task(): void
    {
        config(['app.env' => 'production', 'lights.enabled' => true, 'lights.mode' => 'live']);
        $schedule = new Schedule;
        ScheduleRegistrar::register($schedule);
        $event = collect($schedule->events())->first(fn ($event) => str_contains($event->command, 'lights:tick'));
        $this->assertNotNull($event);
        $this->assertSame('* * * * *', $event->expression);
        $this->assertSame(1, $event->repeatSeconds);
        $this->assertTrue($event->withoutOverlapping);
        $this->assertTrue($event->runInBackground);
    }
}
