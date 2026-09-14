<?php

namespace Tests\Feature;

use App\Lights\Member;
use App\Lights\Portal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Support\RegressionTestCase;

class LightsPortalTest extends RegressionTestCase
{
    private Portal $portal;
    private Member $player;
    private Member $other;
    private Member $admin;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lights.enabled' => true, 'lights.mode' => 'simulation', 'lights.require_verified_email' => false,
            'database.connections.lights' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('lights');
        foreach (glob(database_path('migrations/lights/*.php')) as $file) { (require $file)->up(); }
        $this->portal = app(Portal::class);
        $this->player = Member::create(['name' => 'Player', 'email' => 'player@test.test', 'password' => bcrypt('TestPassword!2026')]);
        $this->other = Member::create(['name' => 'Other', 'email' => 'other@test.test', 'password' => bcrypt('TestPassword!2026')]);
        $this->admin = Member::create(['name' => 'Admin', 'email' => 'admin@test.test', 'password' => bcrypt('TestPassword!2026')]);
        $this->admin->is_admin = true; $this->admin->save();
        foreach ([0, 1] as $channel) { $this->portal->saveCourt($this->admin->id, null, ['name' => 'Court '.($channel + 1), 'rate_cents' => 6000, 'device_label' => 'demo', 'channel' => $channel, 'active' => true]); }
        $this->travelTo(now()->startOfSecond());
    }

    private function credit(Member $user, int $amount = 1000): string
    {
        $id = $this->portal->topup($user->id, $amount, (string) Str::uuid());
        $this->portal->confirmTopup($user->id, $id, 'paid');
        return $id;
    }

    public function test_separate_auth_and_default_disabled_live_modes(): void
    {
        $this->get('/lights')->assertRedirect(route('lights.login'));
        $this->post('/lights/login', ['email' => $this->player->email, 'password' => 'TestPassword!2026'])->assertRedirect(route('lights.home'));
        $this->assertAuthenticatedAs($this->player, 'lights'); $this->assertGuest('web');
        $this->get('/lights')->assertOk()->assertSee('Top up your wallet')->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/lights/admin')->assertForbidden();
        config(['lights.mode' => 'live']); $this->get('/lights')->assertNotFound();
        config(['lights.mode' => 'simulation', 'lights.enabled' => false]); $this->get('/lights')->assertNotFound();
        $this->assertSame(0, $this->player->fresh()->balance_cents);
    }

    public function test_shared_front_door_prioritizes_lights_and_keeps_pos_login_separate(): void
    {
        $this->get('/')->assertRedirect(route('lights.login'))->assertHeader('Cache-Control', 'no-store, private');
        $this->get('/login')->assertRedirect(route('lights.login'));
        $this->get('/lights/login')->assertOk()
            ->assertSee('POS staff access')->assertSee(route('pos.login'))
            ->assertSee(route('lights.login').'#login', false)
            ->assertSee('id="login" tabindex="-1"', false);
        $this->assertSame(url('/pos/login'), route('pos.login'));
        $this->actingAs($this->player, 'lights');
        $this->get('/')->assertRedirect(route('lights.home'));
        $this->assertGuest('web');
        $this->post('/lights/logout')->assertRedirect(route('lights.login'));
        $this->get('/')->assertRedirect(route('lights.login'));
    }

    public function test_lights_assets_do_not_collide_with_the_application_route(): void
    {
        $this->assertDirectoryDoesNotExist(public_path('lights'));
        $this->assertFileExists(public_path('lights-assets/portal.css'));

        $this->get('/lights/login')->assertOk()
            ->assertSee('/lights-assets/portal.css?v=7', false)
            ->assertSee('/lights-assets/portal.js?v=7', false);
        $this->actingAs($this->player, 'lights')->get('/lights')->assertOk()
            ->assertSee('Install Court Lights')
            ->assertSee('data-home-tab="courts"', false)
            ->assertSee('data-home-panel="activity"', false);
        $this->get('/lights/service-worker.js')->assertOk()
            ->assertHeader('Service-Worker-Allowed', '/lights/')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_legacy_local_login_opens_isolated_lights_portal_when_wamp_lights_are_disabled(): void
    {
        config(['lights.enabled' => false, 'lights.local_acceptance_url' => 'http://127.0.0.1:8097/lights/login']);
        $this->get('http://localhost/login')
            ->assertRedirect('http://127.0.0.1:8097/lights/login')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_topup_is_pending_until_confirmation_and_exactly_once(): void
    {
        $key = (string) Str::uuid(); $id = $this->portal->topup($this->player->id, 1000, $key);
        $this->assertSame($id, $this->portal->topup($this->player->id, 1000, $key));
        $this->assertSame(0, $this->player->fresh()->balance_cents);
        $this->portal->confirmTopup($this->player->id, $id, 'paid');
        $this->portal->confirmTopup($this->player->id, $id, 'paid');
        $this->assertSame(1000, $this->player->fresh()->balance_cents);
        $this->assertSame(1, $this->portal->db()->table('lights_ledger')->count());
        $this->assertSame(1001, Portal::cents('10.01'));
    }

    public function test_cancelled_and_failed_payments_never_credit_wallet(): void
    {
        foreach (['cancelled', 'failed'] as $outcome) {
            $id = $this->portal->topup($this->player->id, 1000, (string) Str::uuid());
            $this->portal->confirmTopup($this->player->id, $id, $outcome);
            $this->portal->confirmTopup($this->player->id, $id, 'paid');
        }
        $this->assertSame(0, $this->player->fresh()->balance_cents);
        $this->assertSame(0, $this->portal->db()->table('lights_ledger')->count());
    }

    public function test_manual_off_and_repeat_off_do_not_overcharge(): void
    {
        $this->credit($this->player); $id = $this->portal->start($this->player->id, 1);
        $this->assertSame($id, $this->portal->start($this->player->id, 1));
        $this->travel(1)->seconds(); $this->portal->tick();
        $this->assertSame(998, $this->player->fresh()->balance_cents);
        $this->travel(59)->seconds(); $this->portal->stop($this->player->id, $id);
        $this->travel(20)->seconds(); $this->portal->stop($this->player->id, $id); $this->portal->tick();
        $this->assertSame(900, $this->player->fresh()->balance_cents);
        $this->assertSame(100, $this->portal->db()->table('lights_sessions')->find($id)->charged_cents);
        $this->assertFalse((bool) $this->portal->db()->table('lights_courts')->find(1)->relay_on);
    }

    public function test_completed_session_history_shows_duration_and_cost(): void
    {
        $this->credit($this->player);
        $id = $this->portal->start($this->player->id, 1);
        $this->travel(72)->seconds();
        $this->portal->stop($this->player->id, $id);

        $this->actingAs($this->player, 'lights')->get('/lights')
            ->assertOk()
            ->assertSee('1m 12s used')
            ->assertSee('−R 1.20');
    }

    public function test_existing_simulator_session_can_stop_after_customer_hardware_is_enabled(): void
    {
        $this->credit($this->player);
        $session = $this->portal->start($this->player->id, 1);
        config(['lights.control.customer_enabled' => true]);
        $this->actingAs($this->player, 'lights')->postJson('/lights/sessions/'.$session.'/stop')->assertOk();
        $this->assertNull($this->portal->db()->table('lights_sessions')->find($session)->active_user_id);
    }

    public function test_worker_recovery_caps_billing_at_funded_cutoff_without_browser(): void
    {
        $this->credit($this->player); $id = $this->portal->start($this->player->id, 1);
        $deadline = $this->portal->db()->table('lights_sessions')->find($id)->deadline_at;
        $this->travel(1200)->seconds(); $this->portal->tick(true);
        $session = $this->portal->db()->table('lights_sessions')->find($id);
        $this->assertSame(0, $this->player->fresh()->balance_cents);
        $this->assertSame($deadline, $session->stopped_at); $this->assertNull($session->active_court_id);
        $this->assertSame('credit_exhausted', $session->stop_reason);
        $this->assertFalse((bool) $this->portal->db()->table('lights_courts')->find(1)->relay_on);
    }

    public function test_foreign_topups_sessions_and_admin_actions_are_forbidden(): void
    {
        $topup = $this->credit($this->player); $session = $this->portal->start($this->player->id, 1);
        $this->actingAs($this->other, 'lights');
        $this->get('/lights/topups/'.$topup)->assertNotFound();
        $this->postJson('/lights/topups/'.$topup.'/simulate', ['outcome' => 'paid'])->assertNotFound();
        $this->postJson('/lights/sessions/'.$session.'/stop')->assertNotFound();
        $this->postJson('/lights/admin/sessions/'.$session.'/stop')->assertForbidden();
        $this->postJson('/lights/admin/courts', [])->assertForbidden();
        $this->getJson('/lights/state')->assertOk()->assertJsonPath('session', null)->assertJsonMissing(['email' => $this->player->email]);
        $this->assertNotNull($this->portal->db()->table('lights_sessions')->find($session)->active_user_id);
    }

    public function test_member_can_run_both_courts_but_each_court_has_one_owner(): void
    {
        $this->credit($this->player); $this->credit($this->other); $first = $this->portal->start($this->player->id, 1);
        $this->actingAs($this->player, 'lights')->postJson('/lights/courts/2/start', ['request_key' => (string) Str::uuid(), 'quoted_rate_cents' => 6000])->assertOk();
        $this->actingAs($this->other, 'lights')->postJson('/lights/courts/1/start', ['request_key' => (string) Str::uuid(), 'quoted_rate_cents' => 6000])->assertUnprocessable();
        $sessions = $this->portal->db()->table('lights_sessions')->where('active_user_id', $this->player->id)->get();
        $this->assertCount(2, $sessions);
        $this->assertSame(300, $sessions->min('deadline_at') - $this->portal->now());
        $this->assertSame($first, $this->portal->snapshot($this->player->id)['sessions']->first()->id);
    }

    public function test_admin_rate_change_guard_emergency_stop_and_channel_uniqueness(): void
    {
        $this->credit($this->player); $id = $this->portal->start($this->player->id, 1);
        $data = ['court_id' => 1, 'name' => 'Court one', 'rate' => '90.00', 'device_label' => 'demo', 'channel' => 0, 'active' => 1];
        $this->actingAs($this->admin, 'lights')->postJson('/lights/admin/courts', $data)->assertUnprocessable();
        $this->travel(60)->seconds(); $this->post('/lights/admin/sessions/'.$id.'/stop')->assertRedirect();
        $this->post('/lights/admin/courts', $data)->assertRedirect();
        $this->assertSame(9000, $this->portal->db()->table('lights_courts')->find(1)->rate_cents);
        $this->assertSame(6000, $this->portal->db()->table('lights_sessions')->find($id)->rate_cents);
        $data['court_id'] = 2; $this->postJson('/lights/admin/courts', $data)->assertUnprocessable();
        $this->get('/lights/admin')->assertOk()->assertSee('Wallet ledger');
    }

    public function test_empty_wallet_validation_and_revoked_member(): void
    {
        $this->actingAs($this->player, 'lights');
        $this->postJson('/lights/courts/1/start', ['request_key' => (string) Str::uuid(), 'quoted_rate_cents' => 6000])->assertUnprocessable();
        foreach (['-10', '10.999', '1e3', '0', '5001', 'NaN'] as $amount) { $this->postJson('/lights/topups', ['amount' => $amount, 'request_key' => (string) Str::uuid()])->assertUnprocessable(); }
        $this->player->active = false; $this->player->save();
        $this->getJson('/lights/state')->assertUnauthorized();
        $this->assertSame(0, $this->portal->db()->table('lights_sessions')->count());
    }

    public function test_topup_during_play_does_not_extend_existing_cutoff(): void
    {
        $this->credit($this->player); $id = $this->portal->start($this->player->id, 1);
        $deadline = $this->portal->db()->table('lights_sessions')->find($id)->deadline_at;
        $this->credit($this->player); $this->travel(601)->seconds(); $this->portal->tick();
        $this->assertSame(1000, $this->player->fresh()->balance_cents);
        $this->assertSame($deadline, $this->portal->db()->table('lights_sessions')->find($id)->stopped_at);
    }

    public function test_write_failure_rolls_back_wallet_session_and_simulated_relay(): void
    {
        $this->credit($this->player); $id = $this->portal->start($this->player->id, 1);
        $this->travel(60)->seconds();
        $this->portal->db()->statement("CREATE TRIGGER fail_ledger BEFORE INSERT ON lights_ledger BEGIN SELECT RAISE(ABORT, 'test ledger failure'); END");
        try { $this->portal->stop($this->player->id, $id); $this->fail('Expected failure'); } catch (\Illuminate\Database\QueryException $expected) { }
        $this->assertSame(1000, $this->player->fresh()->balance_cents);
        $this->assertNotNull($this->portal->db()->table('lights_sessions')->find($id)->active_user_id);
        $this->assertTrue((bool) $this->portal->db()->table('lights_courts')->find(1)->relay_on);
        $this->portal->db()->statement('DROP TRIGGER fail_ledger'); $this->portal->stop($this->player->id, $id);
        $this->assertSame(900, $this->player->fresh()->balance_cents);
    }

    public function test_delayed_start_replay_cannot_switch_lights_back_on_after_off(): void
    {
        $this->credit($this->player); $key = (string) Str::uuid();
        $id = $this->portal->start($this->player->id, 1, $key);
        $this->portal->stop($this->player->id, $id);
        $this->travel(60)->seconds();
        $this->assertSame($id, $this->portal->start($this->player->id, 1, $key));
        $this->assertNull($this->portal->snapshot($this->player->id)['session']);
        $this->assertSame(1, $this->portal->db()->table('lights_sessions')->count());
    }

    public function test_clock_rollback_cannot_reduce_recorded_cost_and_four_hour_limit_preserves_credit(): void
    {
        $this->credit($this->player, 50000); $id = $this->portal->start($this->player->id, 1);
        $this->travel(60)->seconds(); $this->portal->tick();
        $this->travel(-30)->seconds(); $this->portal->tick();
        $this->assertSame(49900, $this->player->fresh()->balance_cents);
        $this->travel(6)->hours(); $this->portal->tick();
        $this->assertSame(26000, $this->player->fresh()->balance_cents);
        $this->assertSame('session_limit', $this->portal->db()->table('lights_sessions')->find($id)->stop_reason);
    }

    public function test_csrf_is_required_and_registration_cannot_grant_admin_or_balance(): void
    {
        // A supported local environment enables real CSRF checks without enabling production.
        $this->app->instance('env', 'local');
        $this->postJson('/lights/topups', ['amount' => '10.00', 'request_key' => (string) Str::uuid()])->assertStatus(419);
        $this->withSession(['_token' => 'lights-test-csrf'])->post('/lights/register', ['_token' => 'lights-test-csrf',
            'name' => 'New member', 'email' => 'new@test.test', 'password' => 'LongPassword!2026', 'password_confirmation' => 'LongPassword!2026',
            'terms' => '1', 'is_admin' => true, 'balance_cents' => 100000])->assertRedirect(route('lights.home'));
        $member = Member::where('email', 'new@test.test')->firstOrFail();
        $this->assertFalse($member->is_admin); $this->assertSame(0, $member->balance_cents);
        $this->app->instance('env', 'production'); $this->get('/lights')->assertNotFound();
    }

    public function test_stale_rate_and_reused_topup_with_a_different_amount_are_rejected(): void
    {
        $this->credit($this->player); $this->actingAs($this->player, 'lights');
        $this->postJson('/lights/courts/1/start', ['request_key' => (string) Str::uuid(), 'quoted_rate_cents' => 5000])
            ->assertUnprocessable()->assertJsonValidationErrors('lights');
        $this->assertSame(0, $this->portal->db()->table('lights_sessions')->count());
        $key = (string) Str::uuid(); $this->portal->topup($this->player->id, 1000, $key);
        $this->postJson('/lights/topups', ['request_key' => $key, 'amount' => '20.00'])->assertUnprocessable()->assertJsonValidationErrors('lights');
        $this->assertSame(1000, $this->player->fresh()->balance_cents);
    }
}
