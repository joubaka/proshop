<?php

namespace Tests\Feature;

use App\Product;
use App\Variation;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RegressionTestCase;

class ProductBarcodeTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
        Schema::create('products', function (Blueprint $t) {
            $t->increments('id'); $t->integer('business_id'); $t->string('name');
            $t->string('type')->default('single'); $t->string('sku'); $t->string('barcode_type')->default('EAN13'); $t->timestamps();
        });
        Schema::create('variations', function (Blueprint $t) {
            $t->increments('id'); $t->integer('product_id'); $t->string('name')->default('DUMMY');
            $t->string('sub_sku'); $t->decimal('sell_price_inc_tax')->default(100); $t->decimal('default_purchase_price')->default(50); $t->timestamps(); $t->softDeletes();
        });
        Schema::create('business_locations', function (Blueprint $t) { $t->increments('id'); $t->integer('business_id'); });
        Schema::create('product_locations', function (Blueprint $t) { $t->integer('product_id'); $t->integer('location_id'); });
        require_once database_path('migrations/2019_03_12_120336_create_activity_log_table.php');
        (new \CreateActivityLogTable)->up();
        Schema::table('activity_log', function (Blueprint $t) { $t->integer('business_id')->nullable(); $t->string('event')->nullable(); $t->uuid('batch_uuid')->nullable(); });
        $this->withoutMiddleware([
            \App\Http\Middleware\IsInstalled::class, \App\Http\Middleware\SetSessionData::class,
            \App\Http\Middleware\Language::class, \App\Http\Middleware\Timezone::class, \App\Http\Middleware\CheckUserLogin::class,
        ]);
    }

    private function product(int $businessId = 1, string $sku = 'OLD-1', string $type = 'single'): array
    {
        $product = Product::create(['business_id' => $businessId, 'name' => 'Barcode test product', 'sku' => $sku, 'type' => $type, 'barcode_type' => 'EAN13']);
        $variation = Variation::create(['product_id' => $product->id, 'sub_sku' => $sku.'-V', 'name' => 'Blue']);
        return [$product, $variation];
    }

    private function payload(Product $product, Variation $variation, string $barcode = '0001234567890'): array
    {
        return ['variation_id' => $variation->id, 'previous_barcode' => $variation->sub_sku, 'previous_sku' => $product->sku, 'barcode' => $barcode];
    }

    public function test_cashier_with_existing_product_permission_can_update_only_barcode_fields_and_audit(): void
    {
        $user = $this->signInWithPermissions(['product.update', 'access_all_locations']);
        $user->update(['username' => 'cashier']);
        [$product, $variation] = $this->product();
        $data = $this->payload($product, $variation) + ['sell_price_inc_tax' => 1, 'default_purchase_price' => 1, 'name' => 'Changed'];
        $this->putJson(route('products.barcode.update', $product->id), $data)->assertOk()->assertJsonPath('barcode', '0001234567890');
        $this->assertSame('0001234567890', $product->fresh()->sku);
        $this->assertSame('0001234567890', $variation->fresh()->sub_sku);
        $this->assertSame('C128', $product->fresh()->barcode_type);
        $this->assertSame('Barcode test product', $product->fresh()->name);
        $this->assertEquals(100, $variation->fresh()->sell_price_inc_tax);
        $this->assertEquals(50, $variation->fresh()->default_purchase_price);
        $audit = DB::table('activity_log')->where('description', 'barcode_updated')->first();
        $this->assertSame($user->id, (int) $audit->causer_id);
        $this->assertSame(1, (int) $audit->business_id);
        $this->assertSame('OLD-1-V', json_decode($audit->properties, true)['old']['barcode']);
    }

    public function test_sale_permission_alone_does_not_grant_barcode_editing(): void
    {
        $this->signInWithPermissions(['sell.create', 'access_all_locations']);
        [$product, $variation] = $this->product();
        $this->get(route('products.barcode.edit', $product->id))->assertForbidden();
        $this->putJson(route('products.barcode.update', $product->id), $this->payload($product, $variation))->assertForbidden();
        $this->assertSame('OLD-1-V', $variation->fresh()->sub_sku);
    }

    public function test_foreign_business_and_variation_are_rejected(): void
    {
        $this->signInWithPermissions(['product.update', 'access_all_locations']);
        [$product, $variation] = $this->product(2);
        $this->get(route('products.barcode.edit', $product->id))->assertNotFound();
        $this->putJson(route('products.barcode.update', $product->id), $this->payload($product, $variation))->assertNotFound();
        [$own, $ownVariation] = $this->product();
        $this->putJson(route('products.barcode.update', $own->id), $this->payload($own, $variation))->assertNotFound();
    }

    public function test_location_restrictions_apply_to_reads_and_writes(): void
    {
        $this->signInWithPermissions(['product.update', 'location.10']);
        DB::table('business_locations')->insert([['id' => 10, 'business_id' => 1], ['id' => 20, 'business_id' => 1]]);
        [$product, $variation] = $this->product();
        DB::table('product_locations')->insert(['product_id' => $product->id, 'location_id' => 20]);
        $this->get(route('products.barcode.edit', $product->id))->assertNotFound();
        $this->putJson(route('products.barcode.update', $product->id), $this->payload($product, $variation))->assertNotFound();
        DB::table('product_locations')->where('product_id', $product->id)->update(['location_id' => 10]);
        $this->putJson(route('products.barcode.update', $product->id), $this->payload($product, $variation))->assertOk();
    }

    public function test_duplicates_against_product_and_variation_codes_are_rejected(): void
    {
        $this->signInWithPermissions(['product.update', 'access_all_locations']);
        [$product, $variation] = $this->product();
        [$other, $otherVariation] = $this->product(1, 'TAKEN');
        foreach ([$other->sku, $otherVariation->sub_sku] as $barcode) {
            $this->putJson(route('products.barcode.update', $product->id), $this->payload($product, $variation, $barcode))->assertUnprocessable()->assertJsonValidationErrors('barcode');
        }
        $this->assertSame('OLD-1-V', $variation->fresh()->sub_sku);
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_stale_edit_is_rejected_and_identical_retry_is_a_no_op(): void
    {
        $this->signInWithPermissions(['product.update', 'access_all_locations']);
        [$product, $variation] = $this->product();
        $old = $this->payload($product, $variation);
        $this->putJson(route('products.barcode.update', $product->id), $old)->assertOk();
        $this->putJson(route('products.barcode.update', $product->id), $old)->assertUnprocessable()->assertJsonValidationErrors('barcode');
        $this->putJson(route('products.barcode.update', $product->id), $this->payload($product->fresh(), $variation->fresh()))->assertOk();
        $this->assertDatabaseCount('activity_log', 1);
    }

    public function test_variable_product_updates_selected_variation_without_changing_parent_or_sibling(): void
    {
        $this->signInWithPermissions(['product.update', 'access_all_locations']);
        [$product, $variation] = $this->product(1, 'PARENT', 'variable');
        $sibling = Variation::create(['product_id' => $product->id, 'sub_sku' => 'SIBLING', 'name' => 'Red']);
        $this->putJson(route('products.barcode.update', $product->id), $this->payload($product, $variation))->assertOk();
        $this->assertSame('PARENT', $product->fresh()->sku);
        $this->assertSame('SIBLING', $sibling->fresh()->sub_sku);
    }

    public function test_invalid_barcode_does_not_write(): void
    {
        $this->signInWithPermissions(['product.update', 'access_all_locations']);
        [$product, $variation] = $this->product();
        foreach (['bad code', "bad\ncode", str_repeat('1', 65), ''] as $barcode) {
            $this->putJson(route('products.barcode.update', $product->id), $this->payload($product, $variation, $barcode))->assertUnprocessable()->assertJsonValidationErrors('barcode');
        }
        $this->assertDatabaseCount('activity_log', 0);
    }

    public function test_audit_failure_rolls_back_both_barcode_identifiers(): void
    {
        $this->signInWithPermissions(['product.update', 'access_all_locations']);
        [$product, $variation] = $this->product();
        $this->mock(\App\Utils\ProductUtil::class, function ($mock) {
            $mock->shouldReceive('activityLog')->once()->andThrow(new \RuntimeException('Audit unavailable'));
        });
        $this->putJson(route('products.barcode.update', $product->id), $this->payload($product, $variation))->assertStatus(500);
        $this->assertSame('OLD-1', $product->fresh()->sku);
        $this->assertSame('OLD-1-V', $variation->fresh()->sub_sku);
        $this->assertSame('EAN13', $product->fresh()->barcode_type);
    }
}
