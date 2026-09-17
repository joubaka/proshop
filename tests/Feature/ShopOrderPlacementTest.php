<?php

namespace Tests\Feature;

use App\Shop\CartService;
use App\Shop\Channel;
use App\Shop\DeliveryQuote;
use App\Shop\Exceptions\InsufficientStock;
use App\Shop\OrderPlacementService;
use App\Shop\OrderOperationsService;
use App\Shop\Order;
use App\Shop\PaidOrderFinalizer;
use App\Shop\Payment;
use App\Shop\PaymentService;
use App\Shop\PosSaleFinalizer;
use App\Shop\PayFast\Gateway;
use App\Shop\ShopProduct;
use App\Shop\ShopVariation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\URL;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Tests\Support\RegressionTestCase;
use Mockery;

class ShopOrderPlacementTest extends RegressionTestCase
{
    private Channel $channel;
    private object $paymentFinalizer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
        $this->createPosSchema();
        (require database_path('migrations/2026_09_17_000100_create_shop_catalog_tables.php'))->up();
        (require database_path('migrations/2026_09_17_000200_create_shop_commerce_tables.php'))->up();
        (require database_path('migrations/2026_09_17_000300_create_shop_payments_table.php'))->up();
        config([
            'app.url' => 'https://shop.example.test', 'shop.enabled' => true, 'shop.checkout_enabled' => true,
            'shop.channel' => 'main', 'shop.reservation_minutes' => 20,
            'shop.payfast' => [
                'enabled' => true, 'merchant_id' => '10000100', 'merchant_key' => 'test-key',
                'passphrase' => 'test passphrase', 'process_url' => 'https://sandbox.payfast.co.za/eng/process',
                'validate_url' => 'https://sandbox.payfast.co.za/eng/query/validate', 'source_cidrs' => ['127.0.0.1/32'],
            ],
        ]);
        URL::forceRootUrl('https://shop.example.test');
        URL::forceScheme('https');
        $this->app->bind(Gateway::class, fn () => new class extends Gateway {
            protected function serverConfirmation(string $parameters): bool { return true; }
        });
        $this->paymentFinalizer = new class implements PaidOrderFinalizer {
            public int $calls = 0;
            public function finalize(Order $order, Payment $payment): void { $this->calls++; }
        };
        $this->app->instance(PaidOrderFinalizer::class, $this->paymentFinalizer);
        DB::table('business_locations')->insert(['id' => 10, 'business_id' => 1, 'name' => 'ProShop']);
        $this->channel = Channel::create([
            'business_id' => 1, 'location_id' => 10, 'slug' => 'main',
            'name' => 'Cape Sports ProShop', 'currency' => 'ZAR', 'enabled' => true,
        ]);
    }

    public function test_cart_token_is_hashed_and_cannot_accept_another_channels_variation(): void
    {
        $variation = $this->publish($this->channel, 100, stock: 5);
        [$cart, $token] = app(CartService::class)->create($this->channel);

        $this->assertNotSame($token, $cart->token_hash);
        $this->assertSame($cart->id, app(CartService::class)->find($this->channel, $token)?->id);
        app(CartService::class)->put($cart, $variation->id, 2);
        $this->assertDatabaseHas('shop_cart_items', ['shop_cart_id' => $cart->id, 'quantity' => 2]);

        DB::table('business_locations')->insert(['id' => 20, 'business_id' => 2, 'name' => 'Other']);
        $other = Channel::create([
            'business_id' => 2, 'location_id' => 20, 'slug' => 'other', 'name' => 'Other',
            'currency' => 'ZAR', 'enabled' => true,
        ]);
        [$otherCart] = app(CartService::class)->create($other);
        $this->expectException(ModelNotFoundException::class);
        app(CartService::class)->put($otherCart, $variation->id, 1);
    }

    public function test_checkout_snapshots_server_prices_and_reserves_without_deducting_physical_stock(): void
    {
        $variation = $this->publish($this->channel, 100, price: 115, stock: 5, taxRate: 15);
        [$cart] = app(CartService::class)->create($this->channel);
        app(CartService::class)->put($cart, $variation->id, 2);

        $order = app(OrderPlacementService::class)->place($cart, $this->customer(), DeliveryQuote::collection());

        $this->assertSame(23000, $order->subtotal_cents);
        $this->assertSame(3000, $order->tax_cents);
        $this->assertSame(23000, $order->total_cents);
        $this->assertSame('pending', $order->payment_status);
        $this->assertSame('awaiting_payment', $order->order_status);
        $this->assertSame(11500, $order->items->first()->unit_price_inc_tax_cents);
        $this->assertSame(1500, $order->items->first()->unit_tax_cents);
        $this->assertSame('active', $order->reservations->first()->status);
        $this->assertSame(2, $order->reservations->first()->quantity);
        $this->assertSame('order_reserved', $order->events->first()->event_type);
        $this->assertSame('converted', $cart->fresh()->status);
        $this->assertSame(5.0, (float) DB::table('variation_location_details')->value('qty_available'));
        $this->assertNull($order->transaction_id);
    }

    public function test_active_reservations_prevent_overselling_and_expired_reservations_release_availability(): void
    {
        $variation = $this->publish($this->channel, 100, stock: 3);
        $first = $this->cartWith($variation, 2);
        $firstOrder = app(OrderPlacementService::class)->place($first, $this->customer(), DeliveryQuote::collection());

        $second = $this->cartWith($variation, 2);
        try {
            app(OrderPlacementService::class)->place($second, $this->customer(), DeliveryQuote::collection());
            $this->fail('A second order should not reserve more stock than is available.');
        } catch (InsufficientStock $exception) {
            $this->assertSame(1, $exception->available);
        }
        $this->assertDatabaseCount('shop_orders', 1);
        $this->assertSame('active', $second->fresh()->status);
        $this->assertSame(3.0, (float) DB::table('variation_location_details')->value('qty_available'));

        $firstOrder->reservations()->update(['expires_at' => now()->subMinute()]);
        $third = $this->cartWith($variation, 2);
        $thirdOrder = app(OrderPlacementService::class)->place($third, $this->customer(), DeliveryQuote::collection());
        $this->assertSame(2, $thirdOrder->reservations->first()->quantity);
        $this->assertDatabaseCount('shop_orders', 2);
    }

    public function test_insufficient_line_rolls_back_the_entire_order_and_all_reservations(): void
    {
        $available = $this->publish($this->channel, 100, stock: 5);
        $unavailable = $this->publish($this->channel, 101, stock: 0);
        [$cart] = app(CartService::class)->create($this->channel);
        app(CartService::class)->put($cart, $available->id, 1);
        app(CartService::class)->put($cart, $unavailable->id, 1);

        try {
            app(OrderPlacementService::class)->place($cart, $this->customer(), DeliveryQuote::collection());
            $this->fail('The order should fail atomically when any line is unavailable.');
        } catch (InsufficientStock $exception) {
            $this->assertSame(0, $exception->available);
        }

        $this->assertDatabaseCount('shop_orders', 0);
        $this->assertDatabaseCount('shop_order_items', 0);
        $this->assertDatabaseCount('shop_stock_reservations', 0);
        $this->assertSame('active', $cart->fresh()->status);
    }

    public function test_customer_can_review_cart_submit_collection_checkout_and_open_only_a_signed_order_link(): void
    {
        config(['shop.checkout_enabled' => true]);
        $variation = $this->publish($this->channel, 100, price: 249.95, stock: 4, taxRate: 15);
        [$cart, $token] = app(CartService::class)->create($this->channel);
        app(CartService::class)->put($cart, $variation->id, 2);

        $this->withCookie(CartService::COOKIE, $token)->get('/shop/cart')
            ->assertOk()->assertSee('Product 100')->assertSee('R 499.90')->assertHeader('Cache-Control', 'no-store, private');
        $this->withCookie(CartService::COOKIE, $token)->get('/shop/checkout')
            ->assertOk()->assertSee('SECURE CHECKOUT')->assertSee('Collection order');

        $response = $this->withCookie(CartService::COOKIE, $token)->post('/shop/checkout', [
            'name' => 'Jamie Player', 'email' => 'jamie@example.test', 'mobile' => '0821234567',
            'address_line_1' => '1 Centre Court', 'city' => 'Cape Town',
            'province' => 'Western Cape', 'postal_code' => '8001', 'terms' => '1',
        ])->assertRedirect()->assertCookieExpired(CartService::COOKIE);

        $signedUrl = $response->headers->get('Location');
        $this->get($signedUrl)->assertOk()->assertSee('ORDER RESERVED')->assertSee('R 499.90');
        $order = \App\Shop\Order::firstOrFail();
        $this->get(route('shop.orders.show', $order->uuid))->assertForbidden();
        $this->assertSame('converted', $cart->fresh()->status);
        $this->assertSame(4.0, (float) DB::table('variation_location_details')->value('qty_available'));
    }

    public function test_checkout_mutations_remain_unavailable_while_checkout_feature_is_disabled(): void
    {
        config(['shop.checkout_enabled' => false]);
        $variation = $this->publish($this->channel, 100);

        $this->post('/shop/cart/items', ['shop_variation_id' => $variation->id, 'quantity' => 1])->assertNotFound();
        $this->get('/shop/checkout')->assertNotFound();
        $this->assertDatabaseCount('shop_carts', 0);
        $this->assertDatabaseCount('shop_orders', 0);
    }

    public function test_payfast_checkout_uses_server_owned_amount_and_signed_customer_urls(): void
    {
        $order = $this->placedOrder(249.95, 2);
        $checkout = app(PaymentService::class)->checkout($order);

        $payment = Payment::firstOrFail();
        $this->assertSame(49990, $payment->expected_amount_cents);
        $this->assertSame($payment->uuid, $checkout['fields']['m_payment_id']);
        $this->assertSame('499.90', $checkout['fields']['amount']);
        $this->assertStringStartsWith('https://shop.example.test/shop/payfast/return/', $checkout['fields']['return_url']);
        $this->get($checkout['fields']['return_url'])->assertRedirect();
        $this->assertSame('pending', $order->fresh()->payment_status, 'A browser return must never mark an order paid.');
    }

    public function test_verified_payfast_notification_finalizes_order_exactly_once(): void
    {
        $order = $this->placedOrder(100, 1);
        app(PaymentService::class)->checkout($order);
        $payment = Payment::firstOrFail();
        $payload = $this->paymentPayload($payment, 'PF-SHOP-1', '100.00');

        $this->post('/shop/payfast/notify', $payload)->assertOk()->assertSeeText('OK');
        $this->post('/shop/payfast/notify', $payload)->assertOk()->assertSeeText('OK');

        $this->assertSame(1, $this->paymentFinalizer->calls);
        $this->assertSame('paid', $order->fresh()->payment_status);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('confirmed', $order->reservations()->first()->status);
        $this->assertSame(1, $order->events()->where('event_type', 'payment_confirmed')->count());
    }

    public function test_invalid_signature_and_wrong_amount_never_finalize_an_order(): void
    {
        $order = $this->placedOrder(100, 1);
        app(PaymentService::class)->checkout($order);
        $payment = Payment::firstOrFail();
        $invalid = $this->paymentPayload($payment, 'PF-SHOP-2', '100.00');
        $invalid['signature'] = str_repeat('0', 32);
        $this->post('/shop/payfast/notify', $invalid)->assertBadRequest();

        $wrongAmount = $this->paymentPayload($payment, 'PF-SHOP-3', '101.00');
        $this->post('/shop/payfast/notify', $wrongAmount)->assertBadRequest();

        $this->assertSame(0, $this->paymentFinalizer->calls);
        $this->assertSame('payment_exception', $order->fresh()->payment_status);
        $this->assertSame('requires_attention', $payment->fresh()->status);
        $this->assertSame('amount_mismatch', $payment->fresh()->failure_reason);
    }

    public function test_late_verified_payment_is_retained_for_attention_without_finalizing_stock(): void
    {
        $order = $this->placedOrder(100, 1);
        app(PaymentService::class)->checkout($order);
        $payment = Payment::firstOrFail();
        $order->update(['reservation_expires_at' => now()->subMinute()]);
        $order->reservations()->update(['expires_at' => now()->subMinute()]);

        $this->post('/shop/payfast/notify', $this->paymentPayload($payment, 'PF-LATE', '100.00'))
            ->assertOk()->assertSeeText('OK');

        $this->assertSame(0, $this->paymentFinalizer->calls);
        $this->assertSame('payment_exception', $order->fresh()->payment_status);
        $this->assertSame('reservation_expired', $payment->fresh()->failure_reason);
        $this->assertSame('active', $order->reservations()->first()->status);
    }

    public function test_notification_signature_preserves_payfast_raw_encoding(): void
    {
        $order = $this->placedOrder(100, 1);
        app(PaymentService::class)->checkout($order);
        $payment = Payment::firstOrFail();
        $raw = 'm_payment_id='.$payment->uuid.'&pf_payment_id=PF-RAW&payment_status=COMPLETE&amount_gross=100.00'
            .'&merchant_id=10000100&item_name=ProShop%20order%20'.$order->order_number;
        $signature = md5($raw.'&passphrase='.urlencode('test passphrase'));
        $request = Request::create('/shop/payfast/notify', 'POST', [
            'm_payment_id' => $payment->uuid, 'pf_payment_id' => 'PF-RAW', 'payment_status' => 'COMPLETE',
            'amount_gross' => '100.00', 'merchant_id' => '10000100', 'item_name' => 'ProShop order '.$order->order_number,
            'signature' => $signature,
        ], [], [], ['REMOTE_ADDR' => '127.0.0.1', 'CONTENT_TYPE' => 'application/x-www-form-urlencoded'], $raw.'&signature='.$signature);

        $verified = app(Gateway::class)->verify($request);
        $this->assertSame($payment->uuid, $verified['merchant_payment_id']);
        $this->assertSame(10000, $verified['amount_cents']);
    }

    public function test_pos_finalizer_creates_customer_sale_payment_mapping_and_stock_movement(): void
    {
        Schema::table('business', function (Blueprint $table) {
            $table->unsignedInteger('owner_id')->nullable();
            $table->string('accounting_method')->default('fifo');
            $table->text('pos_settings')->nullable();
        });
        DB::table('users')->insert([
            'id' => 9, 'business_id' => 1, 'username' => 'shop-owner', 'password' => 'test',
            'first_name' => 'Owner', 'user_type' => 'admin', 'status' => 'active',
            'allow_login' => true, 'language' => 'en', 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('business')->where('id', 1)->update(['owner_id' => 9, 'accounting_method' => 'fifo']);
        Schema::create('contacts', function (Blueprint $table) {
            $table->increments('id'); $table->unsignedInteger('business_id'); $table->string('type');
            $table->string('name'); $table->string('email')->nullable(); $table->string('mobile');
            $table->unsignedInteger('created_by'); $table->string('address_line_1')->nullable();
            $table->string('address_line_2')->nullable(); $table->string('city')->nullable();
            $table->string('state')->nullable(); $table->string('zip_code')->nullable();
            $table->softDeletes(); $table->timestamps();
        });

        $order = $this->placedOrder(115, 2, 15);
        app(PaymentService::class)->checkout($order);
        $payment = Payment::firstOrFail();
        $payment->provider_reference = 'PF-POS-1';

        $transaction = (object) ['id' => 555, 'final_total' => 230.0, 'sell_lines' => collect([(object) ['id' => 1]])];
        $transactions = Mockery::mock(\App\Utils\TransactionUtil::class);
        $transactions->shouldReceive('createSellTransaction')->once()->withArgs(fn ($businessId, $input, $invoiceTotal, $ownerId) =>
            $businessId === 1 && $ownerId === 9 && (float) $input['final_total'] === 230.0
                && (float) $invoiceTotal['total_before_tax'] === 200.0 && (float) $invoiceTotal['tax'] === 30.0
        )->andReturn($transaction);
        $transactions->shouldReceive('createOrUpdateSellLines')->once()->withArgs(fn ($sale, $lines, $location) =>
            $sale === $transaction && count($lines) === 1 && $location === 10 && $lines[0]['quantity'] === 2
        );
        $transactions->shouldReceive('createOrUpdatePaymentLines')->once()->withArgs(fn ($sale, $payments, $businessId, $ownerId) =>
            $sale === $transaction && (float) $payments[0]['amount'] === 230.0 && $payments[0]['method'] === 'other'
                && $businessId === 1 && $ownerId === 9
        );
        $transactions->shouldReceive('updatePaymentStatus')->once()->with(555, 230.0);
        $transactions->shouldReceive('mapPurchaseSell')->once();
        $products = Mockery::mock(\App\Utils\ProductUtil::class);
        $products->shouldReceive('decreaseProductQuantity')->once()->with(400, 2400, 10, 2);

        (new PosSaleFinalizer($transactions, $products))->finalize($order->load(['items', 'channel']), $payment);

        $this->assertDatabaseHas('contacts', ['business_id' => 1, 'email' => 'jamie@example.test', 'created_by' => 9]);
        $this->assertSame(555, $order->fresh()->transaction_id);
        $this->assertNotNull($order->fresh()->contact_id);
    }

    public function test_expiry_job_releases_unpaid_reservations_and_payment_attempts_once(): void
    {
        $order = $this->placedOrder(100, 2);
        app(PaymentService::class)->checkout($order);
        $order->update(['reservation_expires_at' => now()->subMinute()]);
        $order->reservations()->update(['expires_at' => now()->subMinute()]);

        $this->assertSame(1, app(OrderOperationsService::class)->expireReservations());
        $this->assertSame(0, app(OrderOperationsService::class)->expireReservations());

        $this->assertSame('expired', $order->fresh()->payment_status);
        $this->assertSame('released', $order->reservations()->first()->status);
        $this->assertSame('expired', $order->payments()->first()->status);
        $this->assertSame(1, $order->events()->where('event_type', 'reservation_expired')->count());
    }

    public function test_paid_collection_fulfilment_is_ordered_idempotent_and_audited(): void
    {
        $order = $this->placedOrder(100, 1);
        $order->update(['payment_status' => 'paid', 'order_status' => 'confirmed', 'paid_at' => now()]);
        $operations = app(OrderOperationsService::class);

        $operations->markReady($order, 71);
        $operations->markReady($order->fresh(), 71);
        $operations->markCollected($order->fresh(), 72);
        $operations->markCollected($order->fresh(), 72);

        $this->assertSame('completed', $order->fresh()->order_status);
        $this->assertSame('collected', $order->fresh()->fulfilment_status);
        $this->assertSame(1, $order->events()->where('event_type', 'order_ready')->count());
        $this->assertSame(1, $order->events()->where('event_type', 'order_collected')->count());
        $this->assertSame(71, $order->events()->where('event_type', 'order_ready')->value('actor_id'));
    }

    public function test_unpaid_cancellation_releases_stock_but_paid_order_requires_refund_workflow(): void
    {
        $order = $this->placedOrder(100, 1);
        app(PaymentService::class)->checkout($order);
        $operations = app(OrderOperationsService::class);
        $operations->cancelUnpaid($order, 73, 'Customer requested cancellation');

        $this->assertSame('cancelled', $order->fresh()->order_status);
        $this->assertSame('released', $order->reservations()->first()->status);
        $this->assertSame('cancelled', $order->payments()->first()->status);
        $this->assertSame('Customer requested cancellation',
            $order->events()->where('event_type', 'order_cancelled')->firstOrFail()->metadata['reason']);

        $paid = $this->placedOrder(100, 1);
        $paid->update(['payment_status' => 'paid', 'order_status' => 'confirmed']);
        $this->expectException(ValidationException::class);
        $operations->cancelUnpaid($paid, 73, 'Cannot silently cancel paid order');
    }

    private function cartWith(ShopVariation $variation, int $quantity)
    {
        [$cart] = app(CartService::class)->create($this->channel);
        app(CartService::class)->put($cart, $variation->id, $quantity);
        return $cart;
    }

    private function placedOrder(float $price, int $quantity, float $taxRate = 0): Order
    {
        $variation = $this->publish(
            $this->channel,
            400 + Payment::count(),
            price: $price,
            stock: 10,
            taxRate: $taxRate,
        );
        $cart = $this->cartWith($variation, $quantity);
        return app(OrderPlacementService::class)->place($cart, $this->customer(), DeliveryQuote::collection());
    }

    private function paymentPayload(Payment $payment, string $reference, string $amount): array
    {
        $data = [
            'm_payment_id' => $payment->merchant_payment_id, 'pf_payment_id' => $reference,
            'payment_status' => 'COMPLETE', 'amount_gross' => $amount, 'merchant_id' => '10000100',
            'item_name' => 'ProShop order '.$payment->order->order_number,
        ];
        $parameters = collect($data)->map(fn ($value, $key) => $key.'='.urlencode(trim((string) $value)))->implode('&');
        $data['signature'] = md5($parameters.'&passphrase='.urlencode('test passphrase'));
        return $data;
    }

    private function customer(): array
    {
        return [
            'name' => 'Jamie Player', 'email' => 'JAMIE@example.test', 'mobile' => '0821234567',
            'billing_address' => ['line_1' => '1 Centre Court', 'city' => 'Cape Town', 'postal_code' => '8001'],
        ];
    }

    private function publish(Channel $channel, int $productId, float $price = 100, int $stock = 5, float $taxRate = 0): ShopVariation
    {
        $productVariationId = $productId + 1000; $variationId = $productId + 2000;
        DB::table('products')->insert([
            'id' => $productId, 'business_id' => $channel->business_id, 'name' => 'Product '.$productId,
            'type' => 'single', 'sku' => 'SKU-'.$productId, 'tax' => $taxRate > 0 ? 1 : null,
            'tax_type' => 'inclusive', 'is_inactive' => false, 'not_for_selling' => false,
        ]);
        DB::table('product_locations')->insert(['product_id' => $productId, 'location_id' => $channel->location_id]);
        DB::table('product_variations')->insert(['id' => $productVariationId, 'product_id' => $productId, 'name' => 'Default']);
        DB::table('variations')->insert([
            'id' => $variationId, 'product_id' => $productId, 'product_variation_id' => $productVariationId,
            'name' => 'Standard', 'sub_sku' => 'VAR-'.$productId, 'sell_price_inc_tax' => $price,
        ]);
        DB::table('variation_location_details')->insert([
            'product_id' => $productId, 'product_variation_id' => $productVariationId,
            'variation_id' => $variationId, 'location_id' => $channel->location_id, 'qty_available' => $stock,
        ]);
        $shopProduct = ShopProduct::create([
            'shop_channel_id' => $channel->id, 'product_id' => $productId,
            'slug' => 'product-'.$productId, 'published_at' => now(),
        ]);
        return ShopVariation::create([
            'shop_product_id' => $shopProduct->id, 'variation_id' => $variationId,
            'published' => true, 'safety_stock' => 0,
        ]);
    }

    private function createPosSchema(): void
    {
        Schema::create('business_locations', fn (Blueprint $t) => [$t->increments('id'), $t->unsignedInteger('business_id'), $t->string('name')]);
        Schema::create('tax_rates', fn (Blueprint $t) => [$t->increments('id'), $t->unsignedInteger('business_id')->default(1), $t->string('name'), $t->decimal('amount', 22, 4), $t->softDeletes()]);
        DB::table('tax_rates')->insert(['id' => 1, 'business_id' => 1, 'name' => 'VAT', 'amount' => 15]);
        Schema::create('products', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedInteger('business_id'); $t->string('name'); $t->string('type'); $t->string('sku');
            $t->unsignedInteger('tax')->nullable(); $t->string('tax_type')->default('inclusive');
            $t->boolean('is_inactive')->default(false); $t->boolean('not_for_selling')->default(false);
            $t->boolean('enable_stock')->default(true); $t->unsignedInteger('unit_id')->nullable();
            $t->string('image')->nullable(); $t->unsignedInteger('brand_id')->nullable(); $t->unsignedInteger('category_id')->nullable(); $t->timestamps();
        });
        Schema::create('product_locations', fn (Blueprint $t) => [$t->unsignedInteger('product_id'), $t->unsignedInteger('location_id')]);
        Schema::create('product_variations', function (Blueprint $t) { $t->increments('id'); $t->unsignedInteger('product_id'); $t->string('name'); $t->timestamps(); });
        Schema::create('variations', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedInteger('product_id'); $t->unsignedInteger('product_variation_id');
            $t->string('name'); $t->string('sub_sku')->nullable(); $t->decimal('sell_price_inc_tax', 22, 4)->nullable(); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('variation_location_details', function (Blueprint $t) {
            $t->increments('id'); $t->unsignedInteger('product_id'); $t->unsignedInteger('product_variation_id');
            $t->unsignedInteger('variation_id'); $t->unsignedInteger('location_id'); $t->decimal('qty_available', 22, 4); $t->timestamps();
        });
    }
}
