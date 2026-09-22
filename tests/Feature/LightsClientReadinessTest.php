<?php

namespace Tests\Feature;

use App\Lights\AccountTokens;
use App\Lights\Member;
use App\Lights\Portal;
use App\Lights\SafetySessions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Str;
use Tests\Support\RegressionTestCase;

class LightsClientReadinessTest extends RegressionTestCase
{
    private Portal $portal;
    private Member $admin;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        config(['lights.enabled' => true, 'lights.mode' => 'simulation', 'lights.require_verified_email' => true,
            'database.connections.lights' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true]]);
        DB::purge('lights');
        foreach (glob(database_path('migrations/lights/*.php')) as $file) { (require $file)->up(); }
        $this->portal = app(Portal::class);
        $this->admin = Member::create(['name' => 'Admin', 'email' => 'admin@test.test', 'password' => bcrypt('TestPassword!2026')]);
        $this->admin->forceFill(['is_admin' => true, 'email_verified_at' => time(), 'terms_accepted_at' => time()])->save();
        $this->member = Member::create(['name' => 'Member', 'email' => 'member@test.test', 'password' => bcrypt('TestPassword!2026')]);
        $this->portal->saveCourt($this->admin->id, null, ['name' => 'Court 3', 'rate_cents' => 6000, 'device_label' => 'demo', 'channel' => 0, 'active' => true]);
    }

    public function test_registration_requires_terms_and_simulation_verifies_immediately(): void
    {
        $payload = ['name' => 'New Player', 'email' => 'new@test.test', 'password' => 'A123', 'password_confirmation' => 'A123'];
        $this->post('/lights/register', array_merge($payload, ['password' => 'A12', 'password_confirmation' => 'A12', 'terms' => '1']))
            ->assertSessionHasErrors('password');
        $this->post('/lights/register', $payload)->assertSessionHasErrors('terms');
        $this->post('/lights/register', $payload + ['terms' => '1'])->assertRedirect(route('lights.home'));
        $created = Member::where('email', 'new@test.test')->firstOrFail();
        $this->assertNotNull($created->terms_accepted_at); $this->assertNotNull($created->email_verified_at);
    }

    public function test_unverified_member_cannot_pay_or_start_until_one_time_verification(): void
    {
        $this->actingAs($this->member, 'lights');
        $this->postJson('/lights/topups', ['amount' => '10.00', 'request_key' => (string) Str::uuid()])->assertUnprocessable();
        [$token] = app(AccountTokens::class)->issue($this->member, 'verify');
        $this->get(route('lights.verify', $token))->assertRedirect(route('lights.home'));
        $this->assertNotNull($this->member->fresh()->email_verified_at);
        $this->get(route('lights.verify', $token))->assertSessionHasErrors('token');
    }

    public function test_password_reset_token_is_one_time_and_admin_adjustments_are_audited(): void
    {
        [$token] = app(AccountTokens::class)->issue($this->member, 'reset');
        $this->get(route('lights.password.reset', $token))->assertOk()
            ->assertSee('data-password-toggle="reset-password"', false)
            ->assertSee('data-password-toggle="reset-password-confirmation"', false);
        $this->post('/lights/reset-password', ['token' => $token, 'password' => 'B456', 'password_confirmation' => 'B456'])
            ->assertRedirect(route('lights.home'));
        $this->post('/lights/reset-password', ['token' => $token, 'password' => 'ChangedAgain!2026', 'password_confirmation' => 'ChangedAgain!2026'])
            ->assertSessionHasErrors('token');

        $this->actingAs($this->admin, 'lights'); $key = (string) Str::uuid();
        $payload = ['direction' => 'credit', 'amount' => '25.00', 'reason' => 'Customer service correction', 'request_key' => $key];
        $this->postJson(route('lights.admin.members.adjustment', $this->member->id), $payload)->assertOk()->assertJson(['balance_cents' => 2500]);
        $this->postJson(route('lights.admin.members.adjustment', $this->member->id), $payload)->assertOk()->assertJson(['balance_cents' => 2500]);
        $this->assertSame(1, $this->portal->db()->table('lights_ledger')->where('reference', 'adjustment:'.$key)->count());
        $this->assertSame(1, $this->portal->db()->table('lights_events')->where('kind', 'admin_balance_adjusted')->count());
    }

    public function test_password_reset_request_does_not_disclose_accounts(): void
    {
        Notification::fake();
        $known = $this->post('/lights/forgot-password', ['email' => $this->member->email]);
        $unknown = $this->post('/lights/forgot-password', ['email' => 'absent@test.test']);
        $this->assertSame($known->getSession()->get('status'), $unknown->getSession()->get('status'));
    }

    public function test_admin_can_record_cash_received_as_an_audited_wallet_topup(): void
    {
        $this->actingAs($this->admin, 'lights');
        $this->get(route('lights.admin'))->assertOk()->assertSee('Find a member and add cash')->assertSee('Add cash to wallet');
        $key = (string) Str::uuid();
        $payload = ['direction' => 'credit', 'payment_type' => 'cash', 'amount' => '125.50',
            'reason' => 'Cash receipt 1042', 'request_key' => $key];

        $this->postJson(route('lights.admin.members.adjustment', $this->member->id), $payload)
            ->assertOk()->assertJson(['message' => 'Cash received and wallet credited.', 'balance_cents' => 12550]);
        $this->postJson(route('lights.admin.members.adjustment', $this->member->id), $payload)
            ->assertOk()->assertJson(['balance_cents' => 12550]);

        $this->assertDatabaseHas('lights_ledger', ['user_id' => $this->member->id, 'amount_cents' => 12550,
            'balance_after' => 12550, 'kind' => 'cash_topup', 'reference' => 'cash:'.$key], 'lights');
        $this->assertSame(1, $this->portal->db()->table('lights_events')->where('kind', 'cash_topup_recorded')->count());
    }

    public function test_admin_member_cards_show_credit_debit_and_activity_totals(): void
    {
        $this->portal->db()->table('lights_ledger')->insert([
            ['user_id' => $this->member->id, 'amount_cents' => 5000, 'balance_after' => 5000,
                'kind' => 'cash_topup', 'reference' => 'cash:summary-credit', 'created_at' => 1700000000],
            ['user_id' => $this->member->id, 'amount_cents' => -1250, 'balance_after' => 3750,
                'kind' => 'usage', 'reference' => 'usage:summary-debit', 'created_at' => 1700000600],
        ]);
        $this->member->forceFill(['balance_cents' => 3750])->save();

        $this->actingAs($this->admin, 'lights')->get(route('lights.admin'))
            ->assertOk()
            ->assertSee('Total credits')
            ->assertSee('+ R 50.00')
            ->assertSee('Total debits')
            ->assertSee('− R 12.50')
            ->assertSee('Transactions')
            ->assertSee('15 Nov 2023 00:23 SAST');
    }

    public function test_admin_can_debit_and_credit_a_member_with_a_required_audit_reason(): void
    {
        $this->member->forceFill(['balance_cents' => 5000])->save();
        $this->actingAs($this->admin, 'lights');

        $debitKey = (string) Str::uuid();
        $this->postJson(route('lights.admin.members.adjustment', $this->member->id), [
            'direction' => 'debit', 'amount' => '12.50', 'reason' => 'Court booking fee', 'request_key' => $debitKey,
        ])->assertOk()->assertJson(['balance_cents' => 3750]);

        $creditKey = (string) Str::uuid();
        $this->postJson(route('lights.admin.members.adjustment', $this->member->id), [
            'direction' => 'credit', 'amount' => '5.00', 'reason' => 'Booking fee reversal', 'request_key' => $creditKey,
        ])->assertOk()->assertJson(['balance_cents' => 4250]);

        $this->assertDatabaseHas('lights_ledger', [
            'user_id' => $this->member->id, 'amount_cents' => -1250,
            'kind' => 'admin_adjustment', 'reference' => 'adjustment:'.$debitKey,
        ], 'lights');
        $this->assertDatabaseHas('lights_ledger', [
            'user_id' => $this->member->id, 'amount_cents' => 500,
            'kind' => 'admin_adjustment', 'reference' => 'adjustment:'.$creditKey,
        ], 'lights');
        $this->assertSame(1, $this->portal->db()->table('lights_events')
            ->where('kind', 'admin_balance_adjusted')->where('details', 'like', '%Court booking fee%')->count());

        $this->postJson(route('lights.admin.members.adjustment', $this->member->id), [
            'direction' => 'debit', 'amount' => '1.00', 'reason' => '', 'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('reason');
    }

    public function test_admin_members_are_alphabetical_searchable_and_paginated_server_side(): void
    {
        foreach (range(1, 23) as $number) {
            Member::create([
                'name' => sprintf('Player %02d', $number),
                'email' => sprintf('player%02d@test.test', $number),
                'password' => bcrypt('TestPassword!2026'),
            ]);
        }

        $this->actingAs($this->admin, 'lights')->get(route('lights.admin').'#members')
            ->assertOk()
            ->assertSee('Showing 1–20 of 25 members')
            ->assertSeeInOrder(['Admin', 'Member', 'Player 01', 'Player 18'])
            ->assertDontSee('Player 19');

        $this->get(route('lights.admin', ['members_page' => 2]).'#members')
            ->assertOk()
            ->assertSee('Showing 21–25 of 25 members')
            ->assertSee('Player 19')
            ->assertSee('Player 23')
            ->assertDontSee('Player 18');

        $this->get(route('lights.admin', ['member_search' => 'player22']).'#members')
            ->assertOk()
            ->assertSee('1 matching account')
            ->assertSee('Player 22')
            ->assertDontSee('Player 21');
    }

    public function test_cash_topup_is_admin_only_and_rejects_invalid_amounts(): void
    {
        $payload = ['direction' => 'credit', 'payment_type' => 'cash', 'amount' => '10.00',
            'reason' => 'Cash received', 'request_key' => (string) Str::uuid()];
        $this->actingAs($this->member, 'lights');
        $this->postJson(route('lights.admin.members.adjustment', $this->member->id), $payload)->assertForbidden();

        $this->actingAs($this->admin, 'lights');
        foreach (['0.00', '-10.00', '5000.01'] as $amount) {
            $this->postJson(route('lights.admin.members.adjustment', $this->member->id), array_merge($payload,
                ['amount' => $amount, 'request_key' => (string) Str::uuid()]))->assertUnprocessable();
        }
        $this->assertSame(0, $this->member->fresh()->balance_cents);
    }

    public function test_admin_command_accepts_four_character_passwords(): void
    {
        $this->artisan('lights:create-admin', ['email' => 'owner@test.test', '--name' => 'Owner'])
            ->expectsQuestion('New password (minimum 4 characters)', 'C789')
            ->expectsQuestion('Confirm password', 'C789')
            ->expectsOutput('Lights administrator saved. The password was not printed or logged.')
            ->assertSuccessful();

        $owner = Member::where('email', 'owner@test.test')->firstOrFail();
        $this->assertTrue($owner->is_admin);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('C789', $owner->password));
    }

    public function test_live_commissioning_never_falls_back_to_simulated_money_or_sessions(): void
    {
        $this->app['env'] = 'production';
        config([
            'lights.mode' => 'live',
            'lights.payfast.enabled' => false,
            'lights.control.customer_enabled' => false,
        ]);
        $this->member->forceFill(['email_verified_at' => time(), 'balance_cents' => 1000])->save();
        $this->actingAs($this->member, 'lights');

        $this->get('/lights')->assertOk()
            ->assertSee('Online payments are being commissioned')
            ->assertSee('Continue to PayFast', false);
        $this->withSession(['_token' => 'lights-csrf'])->postJson('/lights/topups', [
            '_token' => 'lights-csrf', 'amount' => '100.00', 'request_key' => (string) Str::uuid(),
        ])->assertUnprocessable()->assertJsonValidationErrors('payment');
        $this->withSession(['_token' => 'lights-csrf'])->postJson('/lights/courts/1/start', [
            '_token' => 'lights-csrf', 'request_key' => (string) Str::uuid(), 'quoted_rate_cents' => 6000,
        ])->assertUnprocessable()->assertJsonValidationErrors('lights');

        $this->assertSame(0, $this->portal->db()->table('lights_topups')->count());
        $this->assertSame(0, $this->portal->db()->table('lights_sessions')->count());
    }

    public function test_explicit_local_customer_hardware_mode_does_not_require_repeated_pilot_arming(): void
    {
        $this->app['env'] = 'acceptance';
        config([
            'lights.control.live_enabled' => true,
            'lights.control.customer_enabled' => true,
            'lights.control.local_approval_required' => false,
        ]);
        $this->member->forceFill(['balance_cents' => 1000])->save();
        $this->portal->db()->table('lights_worker')->insert(['id' => 1, 'seen_at' => $this->portal->now()]);

        $session = app(SafetySessions::class)->startCustomer(
            $this->member->id,
            1,
            (string) Str::uuid(),
            6000
        );

        $this->assertSame('cloud_customer', $this->portal->db()->table('lights_control_sessions')->find($session)->driver);
    }

    public function test_customer_can_reserve_both_physical_courts_with_one_wallet(): void
    {
        $this->app['env'] = 'acceptance';
        config([
            'lights.control.live_enabled' => true,
            'lights.control.customer_enabled' => true,
            'lights.control.local_approval_required' => false,
        ]);
        $this->portal->saveCourt($this->admin->id, null, [
            'name' => 'Court 4', 'rate_cents' => 6000, 'device_label' => 'demo', 'channel' => 1, 'active' => true,
        ]);
        $this->member->forceFill(['balance_cents' => 1000])->save();
        $this->portal->db()->table('lights_worker')->insert(['id' => 1, 'seen_at' => $this->portal->now()]);
        $safety = app(SafetySessions::class);

        $first = $safety->startCustomer($this->member->id, 1, (string) Str::uuid(), 6000);
        $second = $safety->startCustomer($this->member->id, 2, (string) Str::uuid(), 6000);

        $sessions = $this->portal->db()->table('lights_control_sessions')->where('active_user_id', $this->member->id)->get();
        $this->assertCount(2, $sessions);
        $this->assertEqualsCanonicalizing([$first, $second], $sessions->pluck('id')->all());
        $this->assertSame(300, $sessions->min('duration_seconds'));
        $snapshot = $this->portal->snapshot($this->member->id);
        $this->assertCount(2, $snapshot['sessions']);
        $this->assertSame(['on', 'on'], $snapshot['courts']->pluck('pending_action')->all());

        $safety->stopCustomer($this->member->id, $first);
        $snapshot = $this->portal->snapshot($this->member->id);
        $this->assertSame('off', $snapshot['courts']->firstWhere('id', 1)->pending_action);
        $this->assertSame('on', $snapshot['courts']->firstWhere('id', 2)->pending_action);
    }
}
