<?php
namespace Tests\Feature;

use App\Lights\Member;
use App\Lights\ManualSwitches;
use App\Lights\OperatingHours;
use App\Lights\Portal;
use App\Lights\SafetySessions;
use App\Lights\Shelly\CloudControl;
use App\Lights\Shelly\CommandNotSent;
use App\Lights\Shelly\PrivateSettings;
use App\Lights\Shelly\RelayDriver;
use App\Lights\Shelly\Probe;
use App\Lights\Shelly\StatusSynchronizer;
use App\Lights\Shelly\ManualControlProbe;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\CarbonImmutable;
use Tests\Support\RegressionTestCase;

class LightsSafetyTest extends RegressionTestCase
{
    private Portal $portal;
    private SafetySessions $safety;
    private Member $admin;
    private Member $other;
    protected function setUp(): void
    {
        parent::setUp();
        config(['lights.enabled' => true, 'lights.mode' => 'simulation', 'lights.shelly_setup' => true,
            'lights.control' => ['live_enabled' => false, 'rate_cents' => 6000, 'max_seconds' => 60],
            'database.connections.lights' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('lights');
        foreach (glob(database_path('migrations/lights/*.php')) as $file) { (require $file)->up(); }
        $this->portal = app(Portal::class); $this->safety = app(SafetySessions::class);
        $this->admin = Member::create(['name' => 'Operator', 'email' => 'operator@test.test', 'password' => bcrypt('TestPassword!2026')]);
        $this->other = Member::create(['name' => 'Other', 'email' => 'other@test.test', 'password' => bcrypt('TestPassword!2026')]);
        foreach ([$this->admin, $this->other] as $member) {
            $member->is_admin = true; $member->save();
            $topup = $this->portal->topup($member->id, 1000, (string) Str::uuid());
            $this->portal->confirmTopup($member->id, $topup, 'paid');
        }
        $this->travelTo(now()->startOfSecond());
    }
    private function start(int $channel = 0, ?string $key = null): string
    {
        return $this->safety->start($this->admin->id, $channel, $key ?? (string) Str::uuid(), 6000, 'rehearsal');
    }
    public function test_diagnostics_capture_lifecycle_and_transport_without_private_payloads(): void
    {
        $entries = [];
        $logger = \Mockery::mock(\Psr\Log\LoggerInterface::class);
        $logger->shouldReceive('log')->andReturnUsing(function ($level, $event, $context) use (&$entries) {
            $entries[] = compact('level', 'event', 'context');
        });
        \Illuminate\Support\Facades\Log::shouldReceive('channel')->with('lights')->andReturn($logger);
        $id = $this->start();
        $driver = $this->driver();
        $this->safety->tick($driver);
        $this->safety->stop($this->admin->id, $id);
        $driver->failOff = true;
        $this->safety->tick($driver);
        $this->assertSame('review', $this->controlSession($id)->state);
        $this->assertContains('Lights session: on_timer_confirmed', array_column($entries, 'event'));
        $this->assertContains('Lights session: uncertain', array_column($entries, 'event'));
        $client = new CloudControl('private-test-key', function () {
            throw new \RuntimeException('auth_key=private-test-key private-provider-body');
        });
        try { $client->off(0); } catch (\RuntimeException) {}
        $encoded = json_encode($entries);
        $this->assertStringContainsString('switch', $encoded);
        $this->assertStringNotContainsString('private-test-key', $encoded);
        $this->assertStringNotContainsString('private-provider-body', $encoded);
        $this->assertStringNotContainsString('sensitive-provider-detail', $encoded);
    }
    public function test_logging_failure_does_not_prevent_switching_or_safety_review(): void
    {
        \Illuminate\Support\Facades\Log::shouldReceive('channel')->with('lights')->andThrow(new \RuntimeException('Log unavailable'));
        $id = $this->start();
        $driver = $this->driver();
        $this->safety->tick($driver);
        $this->assertSame('running', $this->controlSession($id)->state);
        $this->safety->stop($this->admin->id, $id);
        $driver->failOff = true;
        $this->safety->tick($driver);
        $this->assertSame('review', $this->controlSession($id)->state);
        $this->assertSame(1, $driver->offs);
    }
    private function controlSession(string $id): object { return $this->portal->db()->table('lights_control_sessions')->find($id); }
    private function driver(): RelayDriver
    {
        return new class implements RelayDriver {
            public int $ons = 0; public int $offs = 0;
            public bool $failOn = false; public bool $failOff = false; public bool $badTimer = false; public bool $reject = false;
            public ?\Closure $callback = null;
            public function on(object $s): array
            {
                if (DB::connection('lights')->transactionLevel() !== 0) { throw new \LogicException('Network inside transaction'); }
                $this->ons++;
                if ($this->callback) { ($this->callback)(); }
                if ($this->reject) { throw new CommandNotSent(); }
                if ($this->failOn) { throw new \RuntimeException('sensitive-provider-detail'); }
                return ['output' => true, 'timer_started_at' => now()->getTimestamp(), 'timer_duration' => $this->badTimer ? 999 : $s->duration_seconds];
            }
            public function adopt(object $s): array { return $this->on($s); }
            public function off(object $s): void { $this->offs++; if ($this->failOff) { throw new \RuntimeException('sensitive-provider-detail'); } }
        };
    }
    public function test_rehearsal_confirms_timer_before_billing_and_stop_is_idempotent(): void
    {
        $id = $this->start(); $driver = $this->driver();
        $this->assertNull($this->controlSession($id)->started_at);
        $this->assertSame(1000, $this->admin->fresh()->balance_cents);
        $this->safety->tick($driver);
        $this->assertSame('running', $this->controlSession($id)->state);
        $this->travel(12)->seconds(); $this->safety->tick($driver);
        $this->assertSame(20, $this->controlSession($id)->charged_cents);
        $this->safety->stop($this->admin->id, $id); $this->safety->tick($driver);
        $this->safety->stop($this->admin->id, $id); $this->safety->tick($driver);
        $this->assertSame('completed', $this->controlSession($id)->state);
        $this->assertSame(980, $this->admin->fresh()->balance_cents);
        $this->assertSame(1, $driver->ons); $this->assertSame(1, $driver->offs);
    }

    public function test_trusted_local_customer_session_completes_after_definite_off_ack(): void
    {
        $this->app['env'] = 'acceptance';
        config(['lights.control.local_approval_required' => false]);
        $id = $this->start();
        $this->portal->db()->table('lights_control_sessions')->where('id', $id)->update(['driver' => 'cloud_customer']);
        $driver = $this->driver();

        $this->safety->tick($driver);
        $this->travel(6)->seconds();
        $this->safety->stop($this->admin->id, $id);
        $this->safety->tick($driver);

        $session = $this->controlSession($id);
        $this->assertSame('completed', $session->state);
        $this->assertNull($session->active_user_id);
        $this->assertNull($session->active_channel);
        $this->assertSame(10, $session->charged_cents);
    }
    public function test_late_worker_caps_usage_at_budget_and_releases_only_after_off(): void
    {
        $this->admin->balance_cents = 10; $this->admin->save();
        $id = $this->start(); $this->assertSame(6, $this->controlSession($id)->duration_seconds);
        $driver = $this->driver(); $this->safety->tick($driver);
        $this->travel(120)->seconds(); $this->safety->tick($driver);
        $this->assertSame(10, $this->controlSession($id)->charged_cents);
        $this->assertSame(0, $this->admin->fresh()->balance_cents);
        $this->assertSame('completed', $this->controlSession($id)->state);
        $this->assertSame(1, $driver->offs);
    }
    public function test_stop_before_dispatch_never_sends_on_or_off(): void
    {
        $id = $this->start(); $driver = $this->driver();
        $this->safety->stop($this->admin->id, $id); $this->safety->tick($driver);
        $this->assertSame('completed', $this->controlSession($id)->state);
        $this->assertSame(0, $driver->ons + $driver->offs);
    }
    public function test_delayed_request_replay_cannot_start_again(): void
    {
        $key = (string) Str::uuid(); $id = $this->start(0, $key);
        $this->safety->stop($this->admin->id, $id); $this->safety->tick();
        $this->assertSame($id, $this->start(0, $key));
        $this->assertSame('completed', $this->controlSession($id)->state);
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->start(1, $key);
    }
    public function test_uncertain_on_is_not_retried_and_queues_only_safety_off(): void
    {
        $id = $this->start(); $driver = $this->driver(); $driver->failOn = true;
        $this->safety->tick($driver); $this->safety->tick($driver); $this->safety->tick($driver);
        $this->assertSame(1, $driver->ons); $this->assertSame(1, $driver->offs);
        $this->assertSame('review', $this->controlSession($id)->state);
        $this->assertNotNull($this->controlSession($id)->active_channel);
        $this->assertSame(0, $this->controlSession($id)->charged_cents);
        $this->assertStringNotContainsString('sensitive-provider-detail', json_encode($this->safety->rows()));
    }
    public function test_missing_timer_evidence_is_not_billed(): void
    {
        $id = $this->start(); $driver = $this->driver(); $driver->badTimer = true;
        $this->safety->tick($driver);
        $this->assertNull($this->controlSession($id)->started_at);
        $this->safety->tick($driver);
        $this->assertSame(1, $driver->offs);
        $this->assertSame(1000, $this->admin->fresh()->balance_cents);
    }
    public function test_preflight_rejection_never_turns_off_an_existing_court(): void
    {
        $id = $this->start(); $driver = $this->driver(); $driver->reject = true;
        $this->safety->tick($driver); $this->safety->tick($driver);
        $this->assertSame('completed', $this->controlSession($id)->state);
        $this->assertSame(0, $driver->offs);
        $this->assertSame(0, $this->controlSession($id)->charged_cents);
        $this->assertStringContainsString('No ON or OFF command was sent and no charge was made.', $this->controlSession($id)->note);
        config(['lights.control.customer_enabled' => true]);
        $this->portal->db()->table('lights_control_sessions')->where('id', $id)->update(['driver' => 'cloud_customer']);
        $failure = $this->portal->snapshot($this->admin->id)['last_start_failure'];
        $this->assertSame($id, $failure->id);
        $this->assertStringContainsString('no charge was made', $failure->note);
    }
    public function test_failed_off_freezes_billing_and_requires_review(): void
    {
        $id = $this->start(); $driver = $this->driver(); $this->safety->tick($driver);
        $this->travel(12)->seconds(); $this->safety->stop($this->admin->id, $id);
        $driver->failOff = true; $this->safety->tick($driver);
        $this->assertSame('review', $this->controlSession($id)->state);
        $this->travel(120)->seconds(); $this->safety->tick($driver);
        $this->assertSame(20, $this->controlSession($id)->charged_cents);
        $this->safety->review($this->admin->id, $id, 'confirmed_off');
        $this->assertSame('completed', $this->controlSession($id)->state);
    }

    public function test_physical_off_confirmation_releases_after_command_margin_not_original_timer(): void
    {
        $id = $this->start();
        $driver = $this->driver();
        $this->safety->tick($driver);
        $this->travel(12)->seconds();
        $this->safety->stop($this->admin->id, $id);
        $driver->failOff = true;
        $this->safety->tick($driver);
        $session = $this->controlSession($id);
        $this->assertSame('review', $session->state);
        $this->assertGreaterThan(now()->timestamp + 30, $session->deadline_at);

        $this->travel(29)->seconds();
        try {
            $this->safety->review($this->admin->id, $id, 'confirmed_off');
            $this->fail('The in-flight-command safety margin was bypassed.');
        } catch (\Illuminate\Validation\ValidationException $exception) {
            $this->assertStringContainsString('1 more seconds', $exception->errors()['lights'][0]);
        }

        $this->travel(1)->second();
        $this->safety->review($this->admin->id, $id, 'confirmed_off');
        $released = $this->controlSession($id);
        $this->assertSame('completed', $released->state);
        $this->assertNull($released->active_channel);
    }

    public function test_admin_can_confirm_physical_off_and_release_a_reviewed_session(): void
    {
        $id = $this->start();
        $driver = $this->driver();
        $driver->failOn = true;
        $this->safety->tick($driver);
        $this->safety->tick($driver);
        $this->assertSame('review', $this->controlSession($id)->state);

        // A definite OFF acknowledgement replaces the original device timer,
        // while retaining the 30-second in-flight-command safety margin.
        $this->travel(31)->seconds();
        $this->actingAs($this->admin, 'lights')->post(route('lights.admin.control.review', $id), [
            'action' => 'confirmed_off', 'physical_off' => '1',
        ])->assertRedirect(route('lights.admin'));

        $session = $this->controlSession($id);
        $this->assertSame('completed', $session->state);
        $this->assertNull($session->active_user_id);
        $this->assertNull($session->active_channel);
        $this->assertSame(1000, $this->admin->fresh()->balance_cents);
    }
    public function test_stop_during_on_handoff_is_not_lost(): void
    {
        $id = $this->start(); $driver = $this->driver();
        $driver->callback = fn () => $this->safety->stop($this->admin->id, $id);
        $this->safety->tick($driver); $this->safety->tick($driver);
        $this->assertSame('completed', $this->controlSession($id)->state);
        $this->assertSame(0, $this->controlSession($id)->charged_cents);
    }
    public function test_interrupted_on_recovers_without_replaying_it(): void
    {
        $id = $this->start();
        $this->portal->db()->table('lights_control_sessions')->where('id', $id)->update(['state' => 'starting', 'command_at' => now()->timestamp - 31]);
        $driver = $this->driver(); $this->safety->tick($driver); $this->safety->tick($driver);
        $this->assertSame(0, $driver->ons); $this->assertSame(1, $driver->offs);
        $this->assertSame('review', $this->controlSession($id)->state);
    }
    public function test_same_channel_and_wallet_are_reserved_across_modes(): void
    {
        $this->start();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->safety->start($this->other->id, 0, (string) Str::uuid(), 6000, 'rehearsal');
    }
    public function test_admin_rehearsal_wallet_cannot_also_start_the_old_simulator(): void
    {
        $this->portal->saveCourt($this->admin->id, null, ['name' => 'Demo', 'device_label' => 'demo', 'channel' => 0, 'rate_cents' => 6000, 'active' => true]);
        $this->start();
        $this->expectException(\Illuminate\Validation\ValidationException::class);
        $this->portal->start($this->admin->id, 1);
    }
    public function test_route_boundaries_and_physical_enable_gate(): void
    {
        $this->get('/lights/admin/control')->assertRedirect(route('lights.login'));
        $this->actingAs($this->admin, 'lights')->get('/lights/admin/control')->assertOk()->assertSee('Manual court controls');
        $input = ['channel' => 0, 'request_key' => (string) Str::uuid()];
        $this->postJson('/lights/admin/control/manual-on', $input)->assertUnprocessable();
        $this->assertSame(0, $this->portal->db()->table('lights_manual_commands')->count());
        config(['lights.control.live_enabled' => true]);
        $this->admin->is_admin = false; $this->admin->save();
        $this->postJson('/lights/admin/control/manual-on', $input)->assertForbidden();
    }

    public function test_web_control_requests_only_queue_work_for_the_worker(): void
    {
        config(['lights.control.live_enabled' => true]);
        $this->mock(ManualControlProbe::class)->shouldNotReceive('send');
        $this->actingAs($this->admin, 'lights')->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $input = ['channel' => 0, 'request_key' => (string) Str::uuid()];
        $this->post('/lights/admin/control/manual-on', $input)->assertRedirect(route('lights.admin.control'));
        $command = $this->portal->db()->table('lights_manual_commands')->first();
        $this->assertSame('queued', $command->state);
        $this->assertNull($command->command_at);

        $this->get('/lights/admin/control')->assertOk();
        $command = $this->portal->db()->table('lights_manual_commands')->find($command->id);
        $this->assertSame('queued', $command->state);
        $this->assertNull($command->command_at);
    }

    public function test_direct_admin_on_and_off_need_no_wallet_arming_or_review_and_only_queue_in_web_request(): void
    {
        config(['lights.control.live_enabled' => true]);
        $this->admin->balance_cents = 0;
        $this->admin->save();
        $probe = $this->mock(ManualControlProbe::class);
        $probe->shouldReceive('send')->twice()->andReturn(
            ['action' => 'on', 'receipt' => ['output' => true, 'timer_duration' => 60]],
            ['action' => 'off', 'receipt' => ['output' => false]],
        );
        $this->actingAs($this->admin, 'lights')->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);

        $this->get('/lights/admin/control')->assertOk()
            ->assertSee('Switch Court 3 ON')->assertSee('Switch Court 3 OFF')
            ->assertDontSee('Arm the timed ON button')->assertDontSee('Safety action required');
        $this->postJson('/lights/admin/control/manual-on', [
            'channel' => 0, 'request_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('command.state', 'queued');
        $this->assertSame('queued', $this->portal->db()->table('lights_manual_commands')->where('action', 'on')->value('state'));

        $switches = app(ManualSwitches::class);
        $switches->tick();
        $this->assertSame('completed', $this->portal->db()->table('lights_manual_commands')->latest('created_at')->value('state'));
        $this->assertTrue((bool) $this->portal->db()->table('lights_hardware_status')->where('channel', 0)->value('output'));

        $this->postJson('/lights/admin/control/emergency-off', [
            'channel' => 0, 'request_key' => (string) Str::uuid(),
        ])->assertOk()->assertJsonPath('command.state', 'queued');
        $this->assertSame('queued', $this->portal->db()->table('lights_manual_commands')->where('action', 'off')->value('state'));
        $switches->tick();
        $this->assertFalse((bool) $this->portal->db()->table('lights_hardware_status')->where('channel', 0)->value('output'));
    }

    public function test_uncertain_direct_on_is_never_replayed(): void
    {
        $probe = $this->mock(ManualControlProbe::class);
        $probe->shouldReceive('send')->once()->andThrow(new \RuntimeException('provider detail'));
        $switches = app(ManualSwitches::class);
        $switches->on($this->admin->id, 1, (string) Str::uuid(), 60);

        $switches->tick();
        $switches->tick();

        $command = $this->portal->db()->table('lights_manual_commands')->where('action', 'on')->first();
        $this->assertSame('uncertain', $command->state);
        $this->assertStringNotContainsString('provider detail', $command->note);
    }

    public function test_on_timer_cannot_cross_midnight_and_daily_cutoff_queues_off_once_for_both_courts(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-09-04 23:59:30', 'Africa/Johannesburg'));
        $this->portal->tick(true);
        $this->portal->db()->table('lights_worker')->where('id', 1)
            ->update(['midnight_cutoff_date' => '2026-09-04']);
        $switches = app(ManualSwitches::class);
        $command = $switches->on($this->admin->id, 0, (string) Str::uuid(), 60);
        $this->assertSame(20, (int) $command->duration_seconds);
        $this->assertSame(30, app(OperatingHours::class)->secondsUntilMidnight());

        $this->travel(30)->seconds();
        $switches->enforceMidnightCutoff();
        $switches->enforceMidnightCutoff();

        $this->assertSame('cancelled', $this->portal->db()->table('lights_manual_commands')->find($command->id)->state);
        $this->assertSame(2, $this->portal->db()->table('lights_manual_commands')
            ->where('action', 'off')->where('state', 'queued')->count());
        $this->assertSame(1, $this->portal->db()->table('lights_events')->where('kind', 'midnight_cutoff_queued')->count());
        $this->assertSame('2026-09-05', $this->portal->db()->table('lights_worker')->where('id', 1)->value('midnight_cutoff_date'));
    }

    public function test_confirmed_commands_refresh_the_persisted_hardware_state(): void
    {
        $id = $this->start(0); $driver = $this->driver();
        $this->safety->tick($driver);
        $status = $this->portal->db()->table('lights_hardware_status')->where('channel', 0)->first();
        $this->assertTrue((bool) $status->output);

        $this->safety->stop($this->admin->id, $id); $this->safety->tick($driver);
        $status = $this->portal->db()->table('lights_hardware_status')->where('channel', 0)->first();
        $this->assertFalse((bool) $status->output);
    }

    public function test_worker_refreshes_hardware_status_at_a_bounded_interval(): void
    {
        $this->app['env'] = 'acceptance';
        config(['lights.control.live_enabled' => true]);
        $this->portal->tick(true);
        $report = ['online' => true, 'checked_at' => now()->timestamp, 'channels' => [
            ['channel' => 0, 'output' => false, 'watts' => 0, 'volts' => 240.1, 'has_errors' => false],
            ['channel' => 1, 'output' => true, 'watts' => 1900, 'volts' => 240.2, 'has_errors' => false],
        ]];
        $this->mock(Probe::class)->shouldReceive('run')->once()->andReturn($report);

        $sync = app(StatusSynchronizer::class);
        $sync->refreshIfDue();
        $sync->refreshIfDue();

        $this->assertFalse((bool) $this->portal->db()->table('lights_hardware_status')->where('channel', 0)->value('output'));
        $this->assertTrue((bool) $this->portal->db()->table('lights_hardware_status')->where('channel', 1)->value('output'));
    }

    public function test_fresh_healthy_off_status_resolves_only_uncertain_off_commands(): void
    {
        $switches = app(ManualSwitches::class);
        $now = now()->timestamp;
        foreach (['off', 'on'] as $action) {
            $this->portal->db()->table('lights_manual_commands')->insert([
                'id' => (string) Str::uuid(), 'actor_id' => $this->admin->id,
                'request_key' => (string) Str::uuid(), 'channel' => 0, 'action' => $action,
                'state' => 'uncertain', 'created_at' => $now - 20, 'command_at' => $now - 10,
                'completed_at' => $now - 5, 'note' => 'Original uncertainty retained in events.',
            ]);
        }

        $resolved = $switches->reconcileConfirmedOff([
            'online' => true, 'checked_at' => $now, 'channels' => [
                ['channel' => 0, 'output' => false, 'has_errors' => false],
            ],
        ]);

        $this->assertSame(1, $resolved);
        $this->assertSame('resolved_off', $this->portal->db()->table('lights_manual_commands')
            ->where('action', 'off')->value('state'));
        $this->assertSame('uncertain', $this->portal->db()->table('lights_manual_commands')
            ->where('action', 'on')->value('state'));
        $this->assertSame(1, $this->portal->db()->table('lights_events')
            ->where('kind', 'manual_shelly_off_resolved')->count());
    }
    public function test_fresh_healthy_off_status_automatically_releases_reviewed_session_after_safety_window(): void
    {
        $id = $this->start();
        $driver = $this->driver();
        $this->safety->tick($driver);
        $this->travel(12)->seconds();
        $this->safety->stop($this->admin->id, $id);
        $driver->failOff = true;
        $this->safety->tick($driver);
        $this->assertSame('review', $this->controlSession($id)->state);

        $report = fn (bool $output) => ['online' => true, 'checked_at' => now()->timestamp, 'channels' => [
            ['channel' => 0, 'output' => $output, 'has_errors' => false],
        ]];
        $this->travel(29)->seconds();
        $this->assertSame(0, $this->safety->reconcileConfirmedOff($report(false)));
        $this->travel(1)->second();
        $this->assertSame(0, $this->safety->reconcileConfirmedOff($report(true)));
        $this->assertSame(1, $this->safety->reconcileConfirmedOff($report(false)));

        $session = $this->controlSession($id);
        $this->assertSame('completed', $session->state);
        $this->assertNull($session->active_user_id);
        $this->assertNull($session->active_channel);
        $this->assertSame(20, (int) $session->charged_cents);
        $this->assertSame(1, $this->portal->db()->table('lights_events')
            ->where('kind', 'control_status_confirmed_off_released')->count());
        $this->assertSame(0, $this->safety->reconcileConfirmedOff($report(false)));
    }
    public function test_cloud_commands_use_exact_channel_timer_and_never_toggle_or_retry(): void
    {
        $calls = []; $output = false;
        $client = new CloudControl('synthetic-test-key', function ($endpoint, $body) use (&$calls, &$output) {
            $calls[] = [$endpoint, $body];
            if ($endpoint === '/set/switch') { $output = $body['on']; return [200, '']; }
            return [200, json_encode([['id' => PrivateSettings::DEVICE, 'code' => 'SPSW-202PE12UL', 'gen' => 'G2', 'online' => 1,
                'status' => ['switch:1' => ['output' => $output, 'timer_duration' => 60, 'timer_started_at' => now()->timestamp]]]])];
        });
        $receipt = $client->on(1, 60); $this->assertTrue($receipt['output']);
        $this->assertSame(['id' => PrivateSettings::DEVICE, 'channel' => 1, 'on' => true, 'toggle_after' => 60], $calls[1][1]);
        $client->off(1);
        $this->assertSame(['id' => PrivateSettings::DEVICE, 'channel' => 1, 'on' => false], $calls[3][1]);
        $this->assertCount(5, $calls);
    }
    public function test_slow_switch_acknowledgement_has_more_time_without_replaying_on(): void
    {
        $calls = []; $output = false; $clock = 0.0;
        $client = new CloudControl('synthetic-test-key', function ($endpoint, $body, $timeoutMs) use (&$calls, &$output, &$clock) {
            $calls[] = $endpoint;
            if ($endpoint === '/set/switch') {
                // A six-second provider acknowledgement exceeded the old four-second limit.
                if ($timeoutMs < 6000) { throw new \RuntimeException('Timeout'); }
                $clock += 6; $output = $body['on'];
                return [200, ''];
            }
            return [200, json_encode([['id' => PrivateSettings::DEVICE, 'code' => 'SPSW-202PE12UL', 'gen' => 'G2', 'online' => 1,
                'status' => ['switch:0' => ['output' => $output, 'timer_duration' => 60, 'timer_started_at' => now()->timestamp]]]])];
        }, function () use (&$clock) { return $clock; });
        $this->assertTrue($client->on(0, 60)['output']);
        $this->assertSame(['/get', '/set/switch', '/get'], $calls);
    }
    public function test_lost_off_acknowledgement_is_resolved_by_healthy_status_without_resending(): void
    {
        $calls = [];
        $client = new CloudControl('synthetic-test-key', function ($endpoint) use (&$calls) {
            $calls[] = $endpoint;
            if ($endpoint === '/set/switch') { throw new \RuntimeException('Lost acknowledgement'); }
            return [200, json_encode([['id' => PrivateSettings::DEVICE, 'code' => 'SPSW-202PE12UL', 'gen' => 'G2', 'online' => 1,
                'status' => ['switch:0' => ['output' => false]]]])];
        });
        $client->off(0);
        $this->assertSame(['/set/switch', '/get'], $calls);
    }
    public function test_offline_cached_off_status_cannot_resolve_a_lost_off_acknowledgement(): void
    {
        $calls = [];
        $client = new CloudControl('synthetic-test-key', function ($endpoint) use (&$calls) {
            $calls[] = $endpoint;
            if ($endpoint === '/set/switch') { throw new \RuntimeException('Timeout'); }
            return [200, json_encode([['id' => PrivateSettings::DEVICE, 'code' => 'SPSW-202PE12UL', 'gen' => 'G2', 'online' => 0,
                'status' => ['switch:0' => ['output' => false]]]])];
        });
        try { $client->off(0); $this->fail('Offline status accepted as OFF evidence'); }
        catch (\RuntimeException $error) { $this->assertSame('OFF status could not be confirmed.', $error->getMessage()); }
        $this->assertSame(1, count(array_filter($calls, fn ($endpoint) => $endpoint === '/set/switch')));
    }
    public function test_control_operation_budget_stops_network_calls_before_interrupted_window(): void
    {
        $calls = []; $clock = 0.0;
        $client = new CloudControl('synthetic-test-key', function ($endpoint) use (&$calls, &$clock) {
            $calls[] = $endpoint; $clock += 25;
            throw new \RuntimeException('Timeout');
        }, function () use (&$clock) { return $clock; });
        try { $client->off(0); $this->fail('Exhausted budget accepted'); } catch (\RuntimeException) {}
        $this->assertSame(['/set/switch'], $calls);
    }
    public function test_lost_on_acknowledgement_never_replays_on_or_starts_billing(): void
    {
        $calls = [];
        $client = new CloudControl('synthetic-test-key', function ($endpoint) use (&$calls) {
            $calls[] = $endpoint;
            if ($endpoint === '/set/switch') { throw new \RuntimeException('Timeout'); }
            return [200, json_encode([['id' => PrivateSettings::DEVICE, 'code' => 'SPSW-202PE12UL', 'gen' => 'G2', 'online' => 1,
                'status' => ['switch:0' => ['output' => false]]]])];
        });
        $driver = new class($client) implements RelayDriver {
            public function __construct(private CloudControl $client) {}
            public function on(object $s): array { return $this->client->on((int) $s->channel, (int) $s->duration_seconds); }
            public function adopt(object $s): array { return $this->on($s); }
            public function off(object $s): void { $this->client->off((int) $s->channel); }
        };
        $id = $this->start();
        $this->safety->tick($driver);
        $session = $this->controlSession($id);
        $this->assertTrue((bool) $session->uncertain);
        $this->assertNull($session->started_at);
        $this->assertSame(0, (int) $session->charged_cents);
        $this->assertSame(['/get', '/set/switch'], $calls);
    }
    public function test_cloud_preflight_unknown_output_sends_no_switch_command(): void
    {
        $calls = [];
        $client = new CloudControl('synthetic-test-key', function ($endpoint, $body) use (&$calls) {
            $calls[] = $endpoint;
            return [200, json_encode([['id' => PrivateSettings::DEVICE, 'code' => 'SPSW-202PE12UL', 'gen' => 'G2', 'online' => 1]])];
        });
        try { $client->on(0, 60); $this->fail('Unknown output accepted'); }
        catch (CommandNotSent) { $this->assertSame(['/get'], $calls); }
    }
    public function test_cloud_adoption_requires_existing_on_and_replaces_the_timer_once(): void
    {
        $calls = []; $output = true;
        $client = new CloudControl('synthetic-test-key', function ($endpoint, $body) use (&$calls, &$output) {
            $calls[] = [$endpoint, $body];
            if ($endpoint === '/set/switch') { $output = $body['on']; return [200, '']; }
            return [200, json_encode([['id' => PrivateSettings::DEVICE, 'code' => 'SPSW-202PE12UL', 'gen' => 'G2', 'online' => 1,
                'status' => ['switch:0' => ['output' => $output, 'timer_duration' => 120, 'timer_started_at' => now()->timestamp]]]])];
        });

        $receipt = $client->adopt(0, 120);

        $this->assertTrue($receipt['output']);
        $this->assertSame(['id' => PrivateSettings::DEVICE, 'channel' => 0, 'on' => true, 'toggle_after' => 120], $calls[1][1]);
        $this->assertCount(3, $calls);
    }
}
