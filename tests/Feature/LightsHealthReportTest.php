<?php

namespace Tests\Feature;

use App\Lights\HealthReport;
use App\Lights\Portal;
use Illuminate\Support\Facades\DB;
use Tests\Support\RegressionTestCase;

class LightsHealthReportTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'production';
        config([
            'database.connections.lights' => [
                'driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '',
                'foreign_key_constraints' => true,
            ],
        ]);
        DB::purge('lights');
        foreach (glob(database_path('migrations/lights/*.php')) as $file) {
            (require $file)->up();
        }
    }

    public function test_runtime_health_cannot_hide_disabled_live_launch_gates(): void
    {
        config([
            'app.url' => 'http://club.test',
            'lights.enabled' => true,
            'lights.mode' => 'live',
            'lights.payfast.enabled' => false,
            'lights.payfast.sandbox' => true,
            'lights.control.live_enabled' => false,
            'lights.control.customer_enabled' => false,
            'lights.require_verified_email' => true,
            'mail.from.address' => 'not-an-email',
        ]);
        $this->makeRuntimeHealthy();

        $report = app(HealthReport::class)->get();

        $this->assertTrue($report['healthy']);
        $this->assertFalse($report['ready_for_live']);
        $this->assertFalse($report['launch_gates']['https_app_url']);
        $this->assertFalse($report['launch_gates']['payfast_enabled']);
        $this->assertFalse($report['launch_gates']['payfast_live']);
        $this->assertFalse($report['launch_gates']['payfast_credentials_configured']);
        $this->assertFalse($report['launch_gates']['shelly_control_enabled']);
        $this->assertFalse($report['launch_gates']['shelly_credentials_configured']);
        $this->assertFalse($report['launch_gates']['customer_control_enabled']);
        $this->assertFalse($report['launch_gates']['mail_sender_configured']);
        config(['mail.from.address' => 'lights@club.test']);
        $this->artisan('lights:health --json')->assertExitCode(1);
    }

    public function test_all_launch_gates_and_runtime_evidence_are_required_for_success(): void
    {
        config([
            'app.url' => 'https://lights.club.test',
            'lights.enabled' => true,
            'lights.mode' => 'live',
            'lights.payfast.enabled' => true,
            'lights.payfast.sandbox' => false,
            'lights.payfast.merchant_id' => 'live-merchant',
            'lights.payfast.merchant_key' => 'live-key',
            'lights.payfast.passphrase' => 'live-passphrase',
            'lights.control.live_enabled' => true,
            'lights.shelly.auth_key' => 'live-shelly-key',
            'lights.control.customer_enabled' => true,
            'lights.require_verified_email' => true,
            'mail.from.address' => 'lights@club.test',
            'database.default' => 'mysql',
            'database.connections.mysql.database' => 'proshop',
            'database.connections.lights.database' => 'proshop',
        ]);
        $this->makeRuntimeHealthy();

        $report = app(HealthReport::class)->get();

        $this->assertTrue($report['healthy']);
        $this->assertTrue($report['ready_for_live']);
        $this->assertTrue($report['launch_gates']['lights_database_configured']);
        $this->assertNotContains(false, $report['launch_gates'], true);
        $this->artisan('lights:health --json')->assertExitCode(0);
    }

    private function makeRuntimeHealthy(): void
    {
        $now = app(Portal::class)->now();
        DB::connection('lights')->table('lights_worker')->insert(['id' => 1, 'seen_at' => $now]);
        DB::connection('lights')->table('lights_hardware_status')->insert([
            ['channel' => 0, 'online' => true, 'output' => false, 'has_errors' => false, 'checked_at' => $now],
            ['channel' => 1, 'online' => true, 'output' => false, 'has_errors' => false, 'checked_at' => $now],
        ]);
    }
}
