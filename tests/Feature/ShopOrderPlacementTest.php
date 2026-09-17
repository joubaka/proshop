<?php

namespace Tests\Feature;

use App\Shop\CartService;
use App\Shop\Channel;
use App\Shop\DeliveryQuote;
use App\Shop\Exceptions\InsufficientStock;
use App\Shop\OrderPlacementService;
use App\Shop\ShopProduct;
use App\Shop\ShopVariation;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RegressionTestCase;

class ShopOrderPlacementTest extends RegressionTestCase
{
    private Channel $channel;

    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
        $this->createPosSchema();
        (require database_path('migrations/2026_09_17_000100_create_shop_catalog_tables.php'))->up();
        (require database_path('migrations/2026_09_17_000200_create_shop_commerce_tables.php'))->up();
        config(['shop.enabled' => true, 'shop.channel' => 'main', 'shop.reservation_minutes' => 20]);
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

    private function cartWith(ShopVariation $variation, int $quantity)
    {
        [$cart] = app(CartService::class)->create($this->channel);
        app(CartService::class)->put($cart, $variation->id, $quantity);
        return $cart;
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
