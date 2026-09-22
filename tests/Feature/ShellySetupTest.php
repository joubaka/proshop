<?php

namespace Tests\Feature;

use App\Lights\Member;
use App\Lights\Shelly\CloudStatus;
use App\Lights\Shelly\PrivateSettings;
use App\Lights\Shelly\Probe;
use Illuminate\Support\Facades\DB;
use Tests\Support\RegressionTestCase;

class ShellySetupTest extends RegressionTestCase
{
    private string $directory;
    private PrivateSettings $settings;
    private Member $admin;
    private const KEY = 'test-only-cloud-key-123456789';

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = base_path('tests/.cache/shelly-'.bin2hex(random_bytes(8)));
        $this->settings = new PrivateSettings($this->directory);
        // Bind, rather than touching the actual local credential directory.
        $this->app->bind(PrivateSettings::class, fn () => $this->settings);
        config(['lights.enabled' => true, 'lights.mode' => 'simulation', 'lights.shelly_setup' => true,
            'database.connections.lights' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('lights');
        (require database_path('migrations/lights/2026_09_03_180000_create_lights_portal.php'))->up();
        $this->admin = Member::create(['name' => 'Admin', 'email' => 'shelly@test.test', 'password' => bcrypt('LocalTest!2026')]);
        $this->admin->is_admin = true; $this->admin->save();
    }

    protected function tearDown(): void
    {
        if (isset($this->directory) && is_dir($this->directory)) {
            foreach (['credentials.enc', 'encryption.key', 'settings.lock'] as $name) {
                if (is_file($this->directory.'/'.$name)) { unlink($this->directory.'/'.$name); }
            }
            rmdir($this->directory);
        }
        parent::tearDown();
    }

    public function test_only_local_lights_admin_can_reach_setup_and_post_actions(): void
    {
        foreach (['/lights/admin/shelly', '/lights/admin/shelly/check'] as $uri) {
            $this->postJson($uri, ['shelly_key' => self::KEY])->assertUnauthorized()->assertDontSee(self::KEY);
        }
        $this->actingAs($this->admin, 'web')->get('/lights/admin/shelly')->assertRedirect(route('lights.login'));
        $this->actingAs($this->admin, 'lights');
        $this->get('/lights/admin/shelly')->assertOk()->assertSee('Read-only commissioning')
            ->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Frame-Options', 'DENY');
        $this->withServerVariables(['REMOTE_ADDR' => '192.168.10.2'])->post('/lights/admin/shelly', ['shelly_key' => self::KEY])->assertNotFound();
        $this->assertFalse($this->settings->configured());
        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->admin->is_admin = false; $this->admin->save();
        $this->post('/lights/admin/shelly', ['shelly_key' => self::KEY])->assertForbidden();
        $this->assertFalse($this->settings->configured());
    }

    public function test_saving_encrypts_key_and_does_not_probe_or_flash_it(): void
    {
        $this->mock(Probe::class)->shouldNotReceive('run');
        $this->actingAs($this->admin, 'lights')->post('/lights/admin/shelly', ['shelly_key' => self::KEY])->assertRedirect(route('lights.admin.shelly'));
        $this->assertSame(self::KEY, $this->settings->secret());
        $this->assertStringNotContainsString(self::KEY, file_get_contents($this->directory.'/credentials.enc'));
        $this->assertSame(32, strlen(file_get_contents($this->directory.'/encryption.key')));
        $this->get('/lights/admin/shelly')->assertDontSee(self::KEY)->assertSee('A key is saved');
        $this->assertStringNotContainsString(self::KEY, serialize(session()->all()));
        $this->post('/lights/admin/shelly', ['shelly_key' => 'bad key'])->assertRedirect();
        $this->assertSame(self::KEY, $this->settings->secret());
        $this->assertStringNotContainsString('bad key', serialize(session()->all()));
        $this->assertSame(0, DB::connection('lights')->table('lights_sessions')->count());
        $this->assertSame(0, DB::connection('lights')->table('lights_ledger')->count());
    }

    public function test_post_check_is_explicit_and_setup_can_be_disabled(): void
    {
        $this->actingAs($this->admin, 'lights');
        $this->post('/lights/admin/shelly/check')->assertSessionHas('status', 'Save the cloud key first.');
        $this->settings->save(self::KEY);
        $report = $this->client($this->device())->check(self::KEY);
        $this->mock(Probe::class)->shouldReceive('run')->once()->andReturn($report);
        $this->post('/lights/admin/shelly/check')->assertSessionHas('shelly_report', $report);
        $this->get('/lights/admin/shelly')->assertSee('Cloud reports device online')->assertSee('may be cached')
            ->assertSee('Court 3: channel 0')->assertSee('Court 4: channel 1')
            ->assertSee('Channel 0 · Court 3')->assertSee('Channel 1 · Court 4')->assertDontSee('court unconfirmed');
        config(['lights.shelly_setup' => false]);
        $this->get('/lights/admin/shelly')->assertNotFound();
        $this->post('/lights/admin/shelly/check')->assertNotFound();
    }

    public function test_real_csrf_failure_never_exposes_or_saves_the_key(): void
    {
        $this->app['env'] = 'acceptance';
        config(['app.debug' => true]);
        $this->actingAs($this->admin, 'lights')->post('/lights/admin/shelly', ['shelly_key' => self::KEY])
            ->assertStatus(419)->assertDontSee(self::KEY);
        $this->assertFalse($this->settings->configured());
        $this->assertFalse(config('app.debug'));
    }

    public function test_admin_and_control_show_live_hardware_status_separately_from_simulated_sessions(): void
    {
        (require database_path('migrations/lights/2026_09_03_240000_create_lights_hardware_status.php'))->up();
        $this->settings->save(self::KEY);
        $report = $this->client($this->device())->check(self::KEY);
        $this->mock(Probe::class)->shouldReceive('run')->twice()->andReturn($report);
        $this->actingAs($this->admin, 'lights');

        $this->post('/lights/admin/shelly/check', ['return_to' => 'admin'])
            ->assertRedirect(route('lights.admin'))->assertSessionHas('shelly_report', $report);
        $this->get('/lights/admin')->assertOk()
            ->assertSee('Current light status')->assertSee('Court 3')->assertSee('Court 4')
            ->assertSee('ON')->assertSee('No automatic cutoff timer reported')
            ->assertSee('No active sessions')
            ->assertDontSee('All courts are off.');
        $this->getJson('/lights/admin/hardware-state')->assertOk()
            ->assertJsonPath('online', true)->assertJsonPath('channels.1.output', true);

        (require database_path('migrations/lights/2026_09_03_200000_create_lights_control_sessions.php'))->up();
        $this->post('/lights/admin/shelly/check', ['return_to' => 'control'])
            ->assertRedirect(route('lights.admin.control'))->assertSessionHas('shelly_report', $report);
        $this->get('/lights/admin/control')->assertOk()->assertSee('Current light status')->assertSee('Refresh live status');
    }

    private function device(): array
    {
        return ['id' => PrivateSettings::DEVICE, 'code' => 'SPSW-202PE12UL', 'gen' => 'G2', 'online' => 1,
            'status' => ['sys' => ['unixtime' => 1788450000], 'switch:1' => ['output' => true, 'apower' => 1920, 'voltage' => 240.5]]];
    }

    private function client(array $device): CloudStatus
    {
        return new CloudStatus(function ($body, $key) use ($device) {
            $this->assertSame(self::KEY, $key);
            $request = json_decode($body, true);
            $this->assertSame([PrivateSettings::DEVICE], $request['ids']);
            $this->assertSame(['status'], $request['select']);
            $this->assertArrayNotHasKey('on', $request);
            return [200, json_encode([$device])];
        });
    }

    public function test_cloud_parser_distinguishes_unknown_offline_and_actual_zero(): void
    {
        $report = $this->client($this->device())->check(self::KEY);
        $this->assertTrue($report['online']);
        $this->assertNull($report['channels'][0]['output']);
        $this->assertNull($report['channels'][0]['watts']);
        $this->assertTrue($report['channels'][1]['output']);
        $this->assertSame(1920, $report['channels'][1]['watts']);
        $device = $this->device(); $device['online'] = 0;
        $device['status']['switch:1'] = ['output' => false, 'apower' => 0, 'errors' => ['overtemp']];
        $report = $this->client($device)->check(self::KEY);
        $this->assertFalse($report['online']);
        $this->assertFalse($report['channels'][1]['output']);
        $this->assertSame(0, $report['channels'][1]['watts']);
        $this->assertTrue($report['channels'][1]['has_errors']);
    }

    public function test_cloud_failures_are_sanitized_and_never_retried(): void
    {
        foreach ([301, 401, 403, 429, 500] as $code) {
            $calls = 0;
            $client = new CloudStatus(function () use ($code, &$calls) { $calls++; return [$code, self::KEY]; });
            try { $client->check(self::KEY); $this->fail('Failure accepted'); }
            catch (\RuntimeException $error) { $this->assertStringNotContainsString(self::KEY, $error->getMessage()); }
            $this->assertSame(1, $calls);
        }
        $client = new CloudStatus(fn () => throw new \RuntimeException('https://example.test/?auth_key='.self::KEY));
        try { $client->check(self::KEY); $this->fail('Transport failure accepted'); }
        catch (\RuntimeException $error) { $this->assertStringNotContainsString(self::KEY, $error->getMessage()); $this->assertNull($error->getPrevious()); }
        foreach (['id' => 'wrong', 'code' => 'wrong', 'online' => '1'] as $field => $value) {
            $device = $this->device(); $device[$field] = $value;
            try { $this->client($device)->check(self::KEY); $this->fail('Wrong identity accepted'); }
            catch (\RuntimeException $error) { $this->assertStringContainsString('did not match', $error->getMessage()); }
        }
    }

    public function test_transport_diagnostics_are_safe_and_actionable(): void
    {
        foreach ([6 => 'DNS', 7 => 'sandbox', 28 => 'timed out', 60 => 'certificate', 77 => 'certificate', 23 => 'response limit', 0 => 'unavailable'] as $number => $message) {
            $client = new CloudStatus(fn () => throw new \App\Lights\Shelly\ConnectionFailure($number));
            try { $client->check(self::KEY); $this->fail('Transport failure accepted'); }
            catch (\RuntimeException $error) {
                $this->assertStringContainsString($message, $error->getMessage());
                $this->assertStringNotContainsString(self::KEY, $error->getMessage());
                $this->assertNull($error->getPrevious());
            }
        }
    }

    public function test_probe_failure_is_displayed_as_an_error_not_success(): void
    {
        $this->settings->save(self::KEY);
        $this->mock(Probe::class)->shouldReceive('run')->once()->andThrow(new \RuntimeException('Network unavailable.'));
        $this->actingAs($this->admin, 'lights')->post('/lights/admin/shelly/check')
            ->assertSessionHas('shelly_error', 'Network unavailable.')->assertSessionMissing('status');
        $this->get('/lights/admin/shelly')->assertSee('role="alert"', false)->assertSee('Network unavailable.');
    }

    public function test_windows_probe_preserves_resolver_environment_without_trusting_request_values(): void
    {
        if (PHP_OS_FAMILY !== 'Windows') { $this->markTestSkipped('Windows subprocess environment regression.'); }
        $originalServer = $_SERVER;
        try {
            $_SERVER = ['APP_ENV' => 'acceptance', 'SystemRoot' => 'untrusted-request-value'];
            $probe = new class extends Probe {
                public function process(): \Symfony\Component\Process\Process { return $this->makeProcess(); }
            };
            $process = $probe->process();
            $this->assertNotEmpty(getenv('SystemRoot', true));
            $this->assertSame(getenv('SystemRoot', true), $process->getEnv()['SystemRoot']);
            $this->assertNotContains(self::KEY, $process->getEnv());
            $this->assertStringNotContainsString(self::KEY, $process->getCommandLine());
        } finally { $_SERVER = $originalServer; }
    }
}
