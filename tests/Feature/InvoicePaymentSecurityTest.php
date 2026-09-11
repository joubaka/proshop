<?php

namespace Tests\Feature;

use App\Http\Controllers\SellPosController;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class InvoicePaymentSecurityTest extends TestCase
{
    private $controller;
    private $util;

    protected function setUp(): void
    {
        parent::setUp();
        (require database_path('migrations/2026_09_03_160000_create_invoice_payment_attempts_table.php'))->up();
        Schema::create('business', function ($table) {
            $table->id();
            $table->text('pos_settings');
        });
        Schema::create('transactions', function ($table) {
            $table->id();
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('contact_id')->default(1);
            $table->string('invoice_token')->nullable();
            $table->string('type')->default('sell');
            $table->string('status')->default('final');
            $table->string('payment_status')->default('due');
            $table->decimal('final_total')->default(100);
            $table->timestamps();
        });
        Schema::create('transaction_payments', function ($table) {
            $table->id();
            $table->unsignedInteger('transaction_id');
            $table->unsignedInteger('business_id');
            $table->unsignedInteger('payment_for');
            $table->decimal('amount');
            $table->string('method');
            $table->string('note');
            $table->string('gateway');
            $table->string('payment_ref_no');
            $table->boolean('paid_through_link');
            $table->dateTime('paid_on');
            $table->timestamps();
        });
        $this->settings();
        DB::table('transactions')->insert(['id' => 1, 'business_id' => 1, 'invoice_token' => 'private-token']);
        DB::table('transactions')->insert(['id' => 2, 'business_id' => 1, 'invoice_token' => 'other-token']);
        $this->util = Mockery::mock(TransactionUtil::class)->makePartial();
        $this->util->shouldReceive('getTotalPaid')->andReturn(0)->byDefault();
        $this->util->shouldNotReceive('getInvoicePaymentLink');
        $this->app->instance(TransactionUtil::class, $this->util);
        $this->controller = Mockery::mock(SellPosController::class, [
            app(\App\Utils\ContactUtil::class), app(\App\Utils\ProductUtil::class),
            app(\App\Utils\BusinessUtil::class), $this->util,
            app(\App\Utils\CashRegisterUtil::class), app(\App\Utils\ModuleUtil::class),
            app(\App\Utils\NotificationUtil::class),
        ])->makePartial();
        $this->app->instance(SellPosController::class, $this->controller);
    }

    private function settings(array $override = []): void
    {
        DB::table('business')->updateOrInsert(['id' => 1], ['pos_settings' => json_encode(array_merge([
            'enable_payment_link' => true, 'stripe_public_key' => 'test-public', 'stripe_secret_key' => 'test-secret',
        ], $override))]);
    }

    private function deniedGateway(): void
    {
        $this->controller->shouldNotReceive('pay_stripe');
        $this->controller->shouldNotReceive('pay_razorpay');
    }

    public function test_missing_token_is_rejected_without_disclosing_a_link(): void
    {
        $this->deniedGateway();
        $this->postJson('/confirm-payment/1', ['gateway' => 'stripe'])->assertNotFound()->assertHeaderMissing('Location');
    }

    public function test_wrong_or_other_invoice_tokens_are_rejected(): void
    {
        $this->deniedGateway();
        foreach (['wrong', 'other-token', ['private-token']] as $token) {
            $this->postJson('/confirm-payment/1', ['invoice_token' => $token, 'gateway' => 'stripe'])
                ->assertNotFound()->assertHeaderMissing('Location');
        }
    }

    public function test_disabled_links_and_non_final_invoices_cannot_be_paid(): void
    {
        $this->deniedGateway();
        $this->settings(['enable_payment_link' => false]);
        $this->postJson('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'stripe'])->assertNotFound();
        $this->settings();
        DB::table('transactions')->where('id', 1)->update(['status' => 'draft']);
        $this->postJson('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'stripe'])->assertNotFound();
    }

    public function test_unsupported_and_unconfigured_gateways_are_rejected(): void
    {
        $this->deniedGateway();
        $this->postJson('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'arbitrary'])
            ->assertUnprocessable()->assertJsonValidationErrors('gateway');
        $this->postJson('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'razorpay'])->assertUnprocessable();
    }

    public function test_paid_or_zero_balance_invoices_are_rejected(): void
    {
        $this->deniedGateway();
        DB::table('transactions')->where('id', 1)->update(['payment_status' => 'paid']);
        $this->postJson('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'stripe'])->assertUnprocessable();
        DB::table('transactions')->where('id', 1)->update(['payment_status' => 'due', 'final_total' => 0]);
        $this->postJson('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'stripe'])->assertUnprocessable();
    }

    public function test_unknown_invoices_and_non_sale_transactions_are_rejected(): void
    {
        $this->deniedGateway();
        $this->getJson('/pay/missing-token')->assertNotFound();
        $this->postJson('/confirm-payment/999', ['invoice_token' => 'private-token', 'gateway' => 'stripe'])->assertNotFound();
        DB::table('transactions')->where('id', 1)->update(['type' => 'purchase']);
        $this->postJson('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'stripe'])->assertNotFound();
    }

    public function test_razorpay_uses_the_same_authorization_and_recording_path(): void
    {
        $this->settings(['razor_pay_key_id' => 'test-id', 'razor_pay_key_secret' => 'test-secret']);
        $this->controller->shouldReceive('pay_razorpay')->once()->andReturn('test-razorpay');
        $this->controller->shouldNotReceive('pay_stripe');
        $this->util->shouldReceive('setAndGetReferenceCount')->once()->andReturn(1);
        $this->util->shouldReceive('generateReferenceNumber')->once()->andReturn('PAY-1');
        $this->util->shouldReceive('updatePaymentStatus')->once()->andReturn('paid');
        $this->util->shouldReceive('activityLog')->once();
        $this->post('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'razorpay'])
            ->assertRedirect('/pay/private-token')->assertSessionHas('status.success', 1);
        $this->assertDatabaseHas('transaction_payments', ['gateway' => 'razorpay', 'amount' => 100, 'note' => 'test-razorpay']);
    }

    public function test_gateway_failure_redirects_only_after_token_authorization(): void
    {
        $this->controller->shouldReceive('pay_stripe')->once()->andThrow(new \RuntimeException('Provider failure'));
        $this->post('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'stripe'])
            ->assertRedirect('/pay/private-token')->assertSessionHas('status.success', 0);
        $this->assertDatabaseCount('transaction_payments', 0);
    }

    public function test_authorized_payment_is_recorded_without_a_real_provider_call(): void
    {
        $this->controller->shouldReceive('pay_stripe')->once()->andReturn('test-charge');
        $this->util->shouldReceive('setAndGetReferenceCount')->once()->andReturn(1);
        $this->util->shouldReceive('generateReferenceNumber')->once()->andReturn('PAY-1');
        $this->util->shouldReceive('updatePaymentStatus')->once()->andReturn('paid');
        $this->util->shouldReceive('activityLog')->once();
        $this->post('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'stripe'])
            ->assertRedirect('/pay/private-token')->assertSessionHas('status.success', 1);
        $this->assertDatabaseHas('transaction_payments', ['transaction_id' => 1, 'amount' => 100, 'note' => 'test-charge']);
    }

    public function test_in_flight_attempt_blocks_an_overlapping_request_before_another_charge(): void
    {
        $this->controller->shouldReceive('pay_stripe')->once()->andReturnUsing(function () {
            $attempt = \App\InvoicePaymentAttempt::firstOrFail();
            $this->assertSame('processing', $attempt->status);
            $this->assertSame(0, DB::transactionLevel());
            $calls = 0;
            try {
                app(\App\Support\InvoicePaymentCoordinator::class)->pay(\App\Transaction::findOrFail(1), 'stripe', function () use (&$calls) { $calls++; });
                $this->fail('Overlapping charge must be denied.');
            } catch (\Symfony\Component\HttpKernel\Exception\HttpException $error) {
                $this->assertSame(409, $error->getStatusCode());
            }
            $this->assertSame(0, $calls);
            return 'one-charge-only';
        });
        $this->util->shouldReceive('setAndGetReferenceCount')->once()->andReturn(1);
        $this->util->shouldReceive('generateReferenceNumber')->once()->andReturn('PAY-1');
        $this->util->shouldReceive('updatePaymentStatus')->once()->andReturn('paid');
        $this->util->shouldReceive('activityLog')->once();
        $this->post('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'stripe'])
            ->assertSessionHas('status.success', 1);
        $this->assertDatabaseCount('transaction_payments', 1);
    }

    public function test_provider_success_and_database_failure_can_retry_recording_without_charging_again(): void
    {
        $this->controller->shouldReceive('pay_stripe')->once()->andReturn('confirmed-charge');
        $fail = true;
        $this->util->shouldReceive('setAndGetReferenceCount')->twice()->andReturnUsing(function () use (&$fail) {
            if ($fail) { $fail = false; throw new \RuntimeException('Simulated DB failure'); }
            return 1;
        });
        $this->util->shouldReceive('generateReferenceNumber')->once()->andReturn('PAY-1');
        $this->util->shouldReceive('updatePaymentStatus')->once()->andReturn('paid');
        $this->util->shouldReceive('activityLog')->once();
        $input = ['invoice_token' => 'private-token', 'gateway' => 'stripe'];
        $this->post('/confirm-payment/1', $input)->assertSessionHas('status.success', 0);
        $this->assertDatabaseCount('transaction_payments', 0);
        $this->assertDatabaseHas('invoice_payment_attempts', ['status' => 'provider_succeeded', 'provider_payment_id' => 'confirmed-charge']);
        $this->post('/confirm-payment/1', $input)->assertSessionHas('status.success', 1);
        $this->assertDatabaseCount('transaction_payments', 1);
        $attempt = \App\InvoicePaymentAttempt::firstOrFail();
        $this->assertSame('completed', $attempt->status);
        $this->assertNull($attempt->active_transaction_id);
        app(\App\Support\InvoicePaymentCoordinator::class)->record($attempt->id);
        $this->assertDatabaseCount('transaction_payments', 1);
    }

    public function test_uncertain_provider_outcome_blocks_retries_and_does_not_rollback_caller(): void
    {
        $this->controller->shouldReceive('pay_stripe')->once()->andThrow(new \RuntimeException('Timeout after possible charge'));
        $input = ['invoice_token' => 'private-token', 'gateway' => 'stripe'];
        $this->post('/confirm-payment/1', $input)->assertSessionHas('status.success', 0);
        $this->assertDatabaseHas('invoice_payment_attempts', ['transaction_id' => 1, 'status' => 'review_required', 'active_transaction_id' => 1]);
        DB::beginTransaction();
        try {
            $this->post('/confirm-payment/1', $input)->assertSessionHas('status.success', 0);
            $this->assertSame(1, DB::transactionLevel());
            $this->assertDatabaseCount('invoice_payment_attempts', 1);
            $this->assertDatabaseCount('transaction_payments', 0);
        } finally { DB::rollBack(); }
    }

    public function test_changed_invoice_balance_preserves_confirmed_charge_for_review(): void
    {
        $this->controller->shouldReceive('pay_stripe')->once()->andReturnUsing(function () {
            DB::table('transactions')->where('id', 1)->update(['final_total' => 50]);
            return 'charge-before-edit';
        });
        $this->post('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'stripe'])->assertSessionHas('status.success', 0);
        $this->assertDatabaseCount('transaction_payments', 0);
        $this->assertDatabaseHas('invoice_payment_attempts', ['status' => 'provider_succeeded', 'amount' => 100, 'active_transaction_id' => 1]);
    }

    public function test_failed_reservation_never_calls_a_provider(): void
    {
        $this->deniedGateway();
        \App\InvoicePaymentAttempt::creating(function () { throw new \RuntimeException('Cannot persist reservation'); });
        $this->post('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'stripe'])
            ->assertSessionHas('status.success', 0);
        $this->assertDatabaseCount('invoice_payment_attempts', 0);
        $this->assertDatabaseCount('transaction_payments', 0);
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_review_command_requires_business_and_cannot_record_unknown_or_foreign_attempts(): void
    {
        $this->controller->shouldReceive('pay_stripe')->once()->andThrow(new \RuntimeException('Unknown outcome'));
        $this->post('/confirm-payment/1', ['invoice_token' => 'private-token', 'gateway' => 'stripe']);
        $attempt = \App\InvoicePaymentAttempt::firstOrFail();
        $this->artisan('payments:review')->assertFailed();
        $this->artisan('payments:review', ['--business' => 1])->assertSuccessful();
        $this->artisan('payments:review', ['--business' => 2, '--record' => $attempt->id])->assertFailed();
        $this->artisan('payments:review', ['--business' => 1, '--record' => $attempt->id])->assertFailed();
        $this->assertDatabaseCount('transaction_payments', 0);
    }
}
