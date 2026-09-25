<?php

namespace Tests\Feature;

use App\Shop\AccountPaymentAttempt;
use App\Shop\AccountPaymentService;
use App\Shop\Customer;
use App\Shop\CustomerContactLink;
use App\Shop\PayFast\Gateway;
use App\Transaction;
use App\Utils\TransactionUtil;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Mockery;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Tests\Support\RegressionTestCase;

class ShopCustomerAccountTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
        Schema::create('contacts', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('business_id'); $table->string('type');
            $table->string('name'); $table->string('email')->nullable(); $table->string('mobile')->nullable();
            $table->string('contact_status')->default('active'); $table->unsignedInteger('created_by')->default(1);
            $table->softDeletes(); $table->timestamps();
        });
        Schema::create('business_locations', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('business_id'); $table->string('name');
        });
        Schema::create('shop_channels', function (Blueprint $table) {
            $table->id(); $table->unsignedInteger('business_id'); $table->unsignedInteger('location_id');
            $table->string('slug')->unique(); $table->string('name'); $table->string('currency')->default('ZAR');
            $table->boolean('enabled')->default(true); $table->json('settings')->nullable(); $table->timestamps();
        });
        Schema::create('shop_orders', function (Blueprint $table) {
            $table->id(); $table->timestamps();
        });
        Schema::create('transactions', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('business_id'); $table->unsignedInteger('contact_id');
            $table->string('type'); $table->string('status'); $table->string('payment_status')->default('due');
            $table->decimal('final_total', 22, 4); $table->string('invoice_no')->nullable();
            $table->dateTime('transaction_date')->nullable(); $table->timestamps();
        });
        Schema::create('transaction_payments', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('transaction_id')->nullable();
            $table->unsignedInteger('business_id'); $table->unsignedInteger('payment_for');
            $table->decimal('amount', 22, 4); $table->string('method'); $table->string('note')->nullable();
            $table->string('gateway')->nullable(); $table->string('payment_ref_no')->nullable();
            $table->boolean('paid_through_link')->default(false); $table->boolean('is_return')->default(false);
            $table->dateTime('paid_on'); $table->timestamps();
        });
        Schema::create('shop_payments', function (Blueprint $table) {
            $table->id(); $table->string('provider_reference')->nullable();
        });
        (require database_path('migrations/2026_09_25_000100_create_shop_customer_accounts.php'))->up();
        DB::table('business_locations')->insert(['id' => 1, 'business_id' => 1, 'name' => 'Main']);
        DB::table('shop_channels')->insert(['id' => 1, 'business_id' => 1, 'location_id' => 1, 'slug' => 'main', 'name' => 'ProShop', 'enabled' => 1, 'created_at' => now(), 'updated_at' => now()]);
        config(['shop.enabled' => true, 'shop.channel' => 'main', 'shop.customer_accounts_enabled' => true,
            'shop.account_payments_enabled' => true, 'shop.payfast.enabled' => true]);
    }

    public function test_registration_only_requests_a_pending_contact_link_until_email_and_staff_verification(): void
    {
        DB::table('contacts')->insert(['id' => 10, 'business_id' => 1, 'type' => 'customer', 'name' => 'Jamie POS', 'email' => 'jamie@example.test', 'created_at' => now(), 'updated_at' => now()]);
        $this->post('/shop/account/register', [
            'name' => 'Jamie Portal', 'email' => 'JAMIE@example.test', 'mobile' => '0821234567',
            'password' => 'LongPassword1', 'password_confirmation' => 'LongPassword1', 'terms' => '1',
        ])->assertRedirect(route('shop.account.verification.notice'));
        $customer = Customer::firstOrFail();
        $this->assertFalse($customer->hasVerifiedEmail());
        $this->assertDatabaseHas('shop_customer_contact_links', [
            'shop_customer_id' => $customer->id, 'business_id' => 1, 'contact_id' => 10, 'status' => 'pending',
        ]);
        Notification::assertSentTo($customer, \App\Notifications\ShopCustomerVerifyEmail::class);
        $url = URL::temporarySignedRoute('shop.account.verify', now()->addMinute(), [
            'id' => $customer->id, 'hash' => sha1($customer->email),
        ]);
        $this->actingAs($customer, 'shop_customer')->get($url)->assertRedirect(route('shop.account.dashboard'));
        $this->assertTrue($customer->fresh()->hasVerifiedEmail());
        $this->assertSame('pending', CustomerContactLink::firstOrFail()->status);
        $this->post('/shop/account/logout')->assertRedirect(route('shop.home'));
        $this->from('/shop/account/register')->post('/shop/account/register', [
            'name' => 'Duplicate', 'email' => 'JAMIE@EXAMPLE.TEST',
            'password' => 'LongPassword1', 'password_confirmation' => 'LongPassword1', 'terms' => '1',
        ])->assertRedirect('/shop/account/register')->assertSessionHasErrors('email');
        $this->assertDatabaseCount('shop_customers', 1);
    }

    public function test_only_a_verified_business_scoped_link_can_start_an_invoice_payment(): void
    {
        [$customer, $invoice] = $this->linkedInvoice('pending');
        $gateway = Mockery::mock(Gateway::class);
        $gateway->shouldNotReceive('accountCheckout');
        $util = Mockery::mock(TransactionUtil::class);
        $service = new AccountPaymentService($gateway, $util);
        try {
            $service->checkout($customer, $invoice);
            $this->fail('Pending links must not expose or pay invoices.');
        } catch (NotFoundHttpException) {
            $this->assertDatabaseCount('shop_account_payment_attempts', 0);
        }

        CustomerContactLink::query()->update(['status' => 'verified', 'verified_at' => now()]);
        $util->shouldReceive('getTotalPaid')->once()->with($invoice->id)->andReturn(25);
        $gateway->shouldReceive('accountCheckout')->once()->andReturn(['url' => 'https://example.test', 'fields' => []]);
        $service->checkout($customer, $invoice);
        $attempt = AccountPaymentAttempt::firstOrFail();
        $this->assertDatabaseHas('shop_account_payment_attempts', [
            'shop_customer_id' => $customer->id, 'business_id' => 1, 'contact_id' => 10,
            'transaction_id' => $invoice->id, 'expected_amount_cents' => 7500, 'status' => 'provider_pending',
        ]);
        $other = Customer::create([
            'uuid' => 'b79cdbf1-72af-4fe9-aed7-f52878a3aaf0', 'name' => 'Other',
            'email' => 'other@example.test', 'password' => Hash::make('LongPassword1'), 'email_verified_at' => now(),
        ]);
        auth('shop_customer')->login($other);
        $this->expectException(ModelNotFoundException::class);
        app(\App\Http\Controllers\Shop\CustomerAccountPaymentController::class)->returned($attempt->id);
    }

    public function test_verified_notification_records_one_canonical_invoice_payment_and_replay_is_safe(): void
    {
        [$customer, $invoice] = $this->linkedInvoice('verified');
        $gateway = Mockery::mock(Gateway::class);
        $util = Mockery::mock(TransactionUtil::class);
        $util->shouldReceive('getTotalPaid')->twice()->with($invoice->id)->andReturn(0);
        $util->shouldReceive('setAndGetReferenceCount')->once()->andReturn(1);
        $util->shouldReceive('generateReferenceNumber')->once()->andReturn('PAY-1');
        $util->shouldReceive('updatePaymentStatus')->once()->andReturn('paid');
        $util->shouldReceive('activityLog')->once();
        $service = new AccountPaymentService($gateway, $util);
        $gateway->shouldReceive('accountCheckout')->once()->andReturn(['url' => 'https://example.test', 'fields' => []]);
        $service->checkout($customer, $invoice);
        $attempt = AccountPaymentAttempt::firstOrFail();
        $verified = [
            'merchant_payment_id' => $attempt->merchant_payment_id, 'provider_reference' => 'PF-ACCOUNT-1',
            'amount_cents' => 10000, 'notification_hash' => hash('sha256', 'one'),
        ];
        $gateway->shouldReceive('verify')->twice()->andReturn($verified);
        $request = Request::create('/shop/account/payfast/notify', 'POST');
        $this->assertSame('paid', $service->receive($request));
        $this->assertSame('already_paid', $service->receive($request));
        $this->assertDatabaseCount('transaction_payments', 1);
        $this->assertDatabaseHas('transaction_payments', [
            'transaction_id' => $invoice->id, 'business_id' => 1, 'payment_for' => 10,
            'amount' => 100, 'gateway' => 'payfast', 'paid_through_link' => 1,
        ]);
        $this->assertDatabaseHas('shop_account_payment_attempts', [
            'status' => 'completed', 'active_transaction_id' => null, 'provider_reference' => 'PF-ACCOUNT-1',
        ]);
    }

    public function test_amount_mismatch_is_quarantined_without_recording_a_pos_payment(): void
    {
        [$customer, $invoice] = $this->linkedInvoice('verified');
        $gateway = Mockery::mock(Gateway::class);
        $util = Mockery::mock(TransactionUtil::class);
        $util->shouldReceive('getTotalPaid')->once()->andReturn(0);
        $gateway->shouldReceive('accountCheckout')->once()->andReturn(['url' => 'https://example.test', 'fields' => []]);
        $service = new AccountPaymentService($gateway, $util);
        $service->checkout($customer, $invoice);
        $attempt = AccountPaymentAttempt::firstOrFail();
        $gateway->shouldReceive('verify')->once()->andReturn([
            'merchant_payment_id' => $attempt->merchant_payment_id, 'provider_reference' => 'PF-WRONG',
            'amount_cents' => 9900, 'notification_hash' => hash('sha256', 'wrong'),
        ]);
        $this->assertSame('amount_mismatch', $service->receive(Request::create('/', 'POST')));
        $this->assertDatabaseCount('transaction_payments', 0);
        $this->assertDatabaseHas('shop_account_payment_attempts', ['status' => 'review_required', 'failure_reason' => 'amount_mismatch']);
    }

    private function linkedInvoice(string $status): array
    {
        DB::table('contacts')->insert(['id' => 10, 'business_id' => 1, 'type' => 'customer', 'name' => 'Jamie', 'email' => 'jamie@example.test', 'created_at' => now(), 'updated_at' => now()]);
        $customer = Customer::create(['uuid' => 'a3afcb41-12cc-42ad-8db6-83f6200bec76', 'name' => 'Jamie', 'email' => 'jamie@example.test', 'password' => Hash::make('LongPassword1')]);
        CustomerContactLink::create(['shop_customer_id' => $customer->id, 'business_id' => 1, 'contact_id' => 10, 'status' => $status, 'verification_method' => 'staff_review', 'verified_at' => $status === 'verified' ? now() : null]);
        $invoice = Transaction::create(['business_id' => 1, 'contact_id' => 10, 'type' => 'sell', 'status' => 'final', 'payment_status' => 'due', 'final_total' => 100, 'invoice_no' => 'INV-1', 'transaction_date' => now()]);
        return [$customer, $invoice];
    }
}
