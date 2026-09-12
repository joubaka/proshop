<?php

namespace Tests\Feature;

use App\Lights\Member;
use App\Lights\PayFast\Gateway;
use App\Lights\Portal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Tests\Support\RegressionTestCase;

class LightsPayFastTest extends RegressionTestCase
{
    private Portal $portal;
    private Member $member;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app['env'] = 'production';
        config([
            'app.env' => 'production', 'app.url' => 'https://lights.example.test', 'lights.enabled' => true, 'lights.mode' => 'live',
            'lights.payfast' => [
                'enabled' => true, 'merchant_id' => '10000100', 'merchant_key' => 'test-key',
                'passphrase' => 'test passphrase', 'process_url' => 'https://sandbox.payfast.co.za/eng/process',
                'validate_url' => 'https://sandbox.payfast.co.za/eng/query/validate',
                'source_cidrs' => ['127.0.0.1/32'],
            ],
            'database.connections.lights' => ['driver' => 'sqlite', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => true],
        ]);
        DB::purge('lights');
        foreach (glob(database_path('migrations/lights/*.php')) as $file) { (require $file)->up(); }
        $this->portal = app(Portal::class);
        $this->member = Member::create(['name' => 'PayFast Member', 'email' => 'payfast@test.test', 'password' => bcrypt('TestPassword!2026')]);
        $this->app->bind(Gateway::class, fn () => new class extends Gateway {
            protected function serverConfirmation(string $parameters): bool { return true; }
        });
    }

    public function test_verified_notification_credits_the_wallet_exactly_once(): void
    {
        $topup = $this->portal->topup($this->member->id, 1000, (string) Str::uuid(), 'payfast');
        $payload = $this->payload($topup, 'PF-123', '10.00');

        $this->post('/lights/payfast/notify', $payload)->assertOk()->assertSeeText('OK');
        $this->post('/lights/payfast/notify', $payload)->assertOk()->assertSeeText('OK');

        $this->assertSame(1000, $this->member->fresh()->balance_cents);
        $this->assertSame(1, $this->portal->db()->table('lights_ledger')->where('reference', 'payfast:PF-123')->count());
    }

    public function test_invalid_signature_or_amount_never_credits_the_wallet(): void
    {
        Log::spy();
        $topup = $this->portal->topup($this->member->id, 1000, (string) Str::uuid(), 'payfast');
        $badSignature = $this->payload($topup, 'PF-124', '10.00');
        $badSignature['signature'] = str_repeat('0', 32);
        $this->post('/lights/payfast/notify', $badSignature)->assertBadRequest();

        $wrongAmount = $this->payload($topup, 'PF-125', '11.00');
        $this->post('/lights/payfast/notify', $wrongAmount)->assertBadRequest();
        $this->assertSame(0, $this->member->fresh()->balance_cents);
        $this->assertSame(0, $this->portal->db()->table('lights_ledger')->count());
        Log::shouldHaveReceived('warning')->twice()->withArgs(function (string $message, array $context) use ($topup): bool {
            return $message === 'PayFast ITN rejected.'
                && $context['topup_id'] === $topup
                && $context['source_ip'] === '127.0.0.1'
                && !array_key_exists('signature', $context)
                && !array_key_exists('payload', $context);
        });
    }

    public function test_notification_signature_preserves_payfast_raw_encoding(): void
    {
        $topup = $this->portal->topup($this->member->id, 1000, (string) Str::uuid(), 'payfast');
        $data = [
            'm_payment_id' => $topup, 'pf_payment_id' => 'PF-RAW', 'payment_status' => 'COMPLETE',
            'amount_gross' => '10.00', 'merchant_id' => '10000100', 'item_name' => 'Court Lights wallet top-up',
        ];
        $raw = 'm_payment_id='.$topup.'&pf_payment_id=PF-RAW&payment_status=COMPLETE&amount_gross=10.00'
            .'&merchant_id=10000100&item_name=Court%20Lights%20wallet%20top-up';
        $data['signature'] = md5($raw.'&passphrase='.urlencode('test passphrase'));
        $request = Request::create('/lights/payfast/notify', 'POST', $data, [], [], [
            'REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded',
        ], $raw.'&signature='.$data['signature']);

        $payment = app(Gateway::class)->verify($request);

        $this->assertSame($topup, $payment['topup']);
        $this->assertSame(1000, $payment['amount_cents']);
    }

    public function test_admin_can_store_encrypted_payfast_settings_without_exposing_secrets(): void
    {
        $this->member->is_admin = true;
        $this->member->save();
        $token = Str::random(40);
        $this->actingAs($this->member, 'lights')->withSession(['_token' => $token])->post('/lights/admin/payfast', [
            '_token' => $token,
            'enabled' => '1', 'sandbox' => '1', 'merchant_id' => 'sandbox-merchant',
            'merchant_key' => 'sandbox-key', 'passphrase' => 'sandbox-passphrase',
        ])->assertRedirect(route('lights.admin').'#settings');

        $row = $this->portal->db()->table('lights_payfast_settings')->find(1);
        $this->assertNotSame('sandbox-key', $row->merchant_key);
        $this->assertSame('sandbox-key', Crypt::decryptString($row->merchant_key));
        $this->assertStringNotContainsString('sandbox-key', (string) $this->portal->db()->table('lights_events')
            ->where('kind', 'payfast_settings_updated')->value('details'));

        $this->get('/lights/admin')->assertOk()->assertSee('PayFast')
            ->assertSee('role="tablist"', false)->assertSee('data-admin-tab="members"', false)
            ->assertSee('id="member-search"', false)
            ->assertDontSee('sandbox-key')->assertDontSee('sandbox-passphrase');
        $this->post('/lights/admin/payfast', [
            '_token' => $token,
            'enabled' => '0', 'sandbox' => '0', 'merchant_id' => '', 'merchant_key' => '', 'passphrase' => '',
        ])->assertRedirect(route('lights.admin').'#settings');
        $this->assertSame($row->merchant_key, $this->portal->db()->table('lights_payfast_settings')->find(1)->merchant_key);
    }

    public function test_payfast_cannot_be_enabled_without_all_credentials(): void
    {
        $this->member->is_admin = true;
        $this->member->save();
        config(['lights.payfast.merchant_id' => null, 'lights.payfast.merchant_key' => null,
            'lights.payfast.passphrase' => null]);
        $token = Str::random(40);

        $this->actingAs($this->member, 'lights')->withSession(['_token' => $token])->from('/lights/admin')->post('/lights/admin/payfast', [
            '_token' => $token,
            'enabled' => '1', 'sandbox' => '1', 'merchant_id' => 'only-one',
            'merchant_key' => '', 'passphrase' => '',
        ])->assertRedirect('/lights/admin')->assertSessionHasErrors('enabled');
        $this->assertSame(0, $this->portal->db()->table('lights_payfast_settings')->count());
    }

    private function payload(string $topup, string $reference, string $amount): array
    {
        $data = [
            'm_payment_id' => $topup, 'pf_payment_id' => $reference, 'payment_status' => 'COMPLETE',
            'amount_gross' => $amount, 'merchant_id' => '10000100', 'item_name' => 'Court Lights wallet top-up',
        ];
        $parameters = collect($data)->map(fn ($value, $key) => $key.'='.urlencode(trim((string) $value)))->implode('&');
        $data['signature'] = md5($parameters.'&passphrase='.urlencode('test passphrase'));
        return $data;
    }
}
