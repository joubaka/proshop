<?php

namespace Tests\Feature;

use App\Shop\Channel;
use App\Shop\CatalogAdminService;
use App\Shop\ShopProduct;
use App\Shop\ShopVariation;
use App\Product;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RegressionTestCase;
use Illuminate\Validation\ValidationException;

class ShopCatalogTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->createIdentitySchema();
        $this->createCatalogSchema();
        $migration = require database_path('migrations/2026_09_17_000100_create_shop_catalog_tables.php');
        $migration->up();
        (require database_path('migrations/2026_09_17_000200_create_shop_commerce_tables.php'))->up();
        config(['shop.enabled' => true, 'shop.channel' => 'main', 'shop.products_per_page' => 24]);
    }

    public function test_storefront_is_dormant_until_explicitly_enabled(): void
    {
        config(['shop.enabled' => false]);

        $this->get('/shop')->assertNotFound();
    }

    public function test_catalogue_only_shows_explicitly_published_products_for_the_channel_business_and_location(): void
    {
        $channel = $this->channel();
        $this->publish($channel, 100, 'Published racket', 'published-racket');
        $this->publish($channel, 101, 'Draft racket', 'draft-racket', published: false);
        $this->publish($channel, 102, 'Wrong location racket', 'wrong-location', locationId: 11);
        $this->publish($channel, 103, 'Internal product', 'internal-product', notForSelling: true);
        $this->publish($channel, 104, 'Hidden variation', 'hidden-variation', variationPublished: false);

        $this->get('/shop')
            ->assertOk()
            ->assertSee('Published racket')
            ->assertSee('R 999.00')
            ->assertDontSee('Draft racket')
            ->assertDontSee('Wrong location racket')
            ->assertDontSee('Internal product')
            ->assertDontSee('Hidden variation');
    }

    public function test_product_page_cannot_cross_publication_or_channel_boundaries(): void
    {
        $channel = $this->channel();
        $this->publish($channel, 100, 'Published racket', 'published-racket');
        $this->publish($channel, 101, 'Draft racket', 'draft-racket', published: false);

        $this->get('/shop/products/published-racket')
            ->assertOk()
            ->assertSee('Published racket')
            ->assertSee('3 available online');
        $this->get('/shop/products/draft-racket')->assertNotFound();
        $this->get('/shop/products/unknown')->assertNotFound();
    }

    public function test_catalogue_admin_explicitly_publishes_pos_product_and_variation_settings(): void
    {
        $channel = $this->channel();
        $this->publish($channel, 100, 'Published racket', 'old-slug', published: false, variationPublished: false);
        $product = Product::findOrFail(100);
        $variationId = 2100;

        $saved = app(CatalogAdminService::class)->saveProduct($channel, $product, [
            'slug' => 'competition-racket', 'short_description' => 'Tournament racket',
            'web_description' => 'A controlled online description.', 'featured' => true, 'published' => true,
            'sort_order' => 5, 'variations' => [$variationId => [
                'display_name' => 'Standard grip', 'published' => true, 'sort_order' => 2,
                'safety_stock' => 1, 'maximum_order_quantity' => 2,
            ]],
        ]);

        $this->assertSame('competition-racket', $saved->slug);
        $this->assertTrue($saved->featured);
        $this->assertNotNull($saved->published_at);
        $this->assertDatabaseHas('shop_variations', [
            'shop_product_id' => $saved->id, 'variation_id' => $variationId,
            'published' => true, 'safety_stock' => 1, 'maximum_order_quantity' => 2,
        ]);
        $this->get('/shop')->assertOk()->assertSee('Published racket')->assertSee('Tournament racket');
    }

    public function test_catalogue_admin_rejects_foreign_variation_atomically(): void
    {
        $channel = $this->channel();
        $this->publish($channel, 100, 'First racket', 'first', published: false, variationPublished: false);
        $this->publish($channel, 101, 'Second racket', 'second', published: false, variationPublished: false);
        $product = Product::findOrFail(100);

        try {
            app(CatalogAdminService::class)->saveProduct($channel, $product, [
                'slug' => 'tampered', 'published' => true,
                'variations' => [2101 => ['published' => true, 'safety_stock' => 0]],
            ]);
            $this->fail('A variation belonging to another product must be rejected.');
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('variations', $e->errors());
        }

        $this->assertSame('first', ShopProduct::where('product_id', 100)->value('slug'));
        $this->assertNull(ShopProduct::where('product_id', 100)->value('published_at'));
    }

    private function channel(): Channel
    {
        DB::table('business_locations')->insert([
            'id' => 10, 'business_id' => 1, 'name' => 'Online fulfilment location',
        ]);

        return Channel::create([
            'business_id' => 1, 'location_id' => 10, 'slug' => 'main',
            'name' => 'Cape Sports ProShop', 'currency' => 'ZAR', 'enabled' => true,
        ]);
    }

    private function publish(
        Channel $channel,
        int $productId,
        string $name,
        string $slug,
        bool $published = true,
        int $locationId = 10,
        bool $notForSelling = false,
        bool $variationPublished = true,
    ): void {
        $productVariationId = $productId + 1000;
        $variationId = $productId + 2000;
        DB::table('products')->insert([
            'id' => $productId, 'business_id' => 1, 'name' => $name, 'type' => 'single',
            'sku' => 'SKU-'.$productId, 'is_inactive' => false, 'not_for_selling' => $notForSelling,
        ]);
        DB::table('business_locations')->insertOrIgnore(['id' => $locationId, 'business_id' => 1, 'name' => 'Location '.$locationId]);
        DB::table('product_locations')->insert(['product_id' => $productId, 'location_id' => $locationId]);
        DB::table('product_variations')->insert(['id' => $productVariationId, 'product_id' => $productId, 'name' => 'Default']);
        DB::table('variations')->insert([
            'id' => $variationId, 'product_id' => $productId, 'product_variation_id' => $productVariationId,
            'name' => 'Standard', 'sub_sku' => 'VAR-'.$productId, 'sell_price_inc_tax' => 999,
        ]);
        DB::table('variation_location_details')->insert([
            'product_id' => $productId, 'product_variation_id' => $productVariationId,
            'variation_id' => $variationId, 'location_id' => $locationId, 'qty_available' => 3,
        ]);
        $shopProduct = ShopProduct::create([
            'shop_channel_id' => $channel->id, 'product_id' => $productId, 'slug' => $slug,
            'short_description' => 'A test sports product.', 'published_at' => $published ? now() : null,
        ]);
        ShopVariation::create([
            'shop_product_id' => $shopProduct->id, 'variation_id' => $variationId,
            'published' => $variationPublished, 'safety_stock' => 0,
        ]);
    }

    private function createCatalogSchema(): void
    {
        Schema::create('business_locations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
        });
        Schema::create('brands', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('categories', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
        });
        Schema::create('products', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('business_id');
            $table->string('name');
            $table->string('type');
            $table->string('sku');
            $table->unsignedInteger('brand_id')->nullable();
            $table->unsignedInteger('category_id')->nullable();
            $table->boolean('is_inactive')->default(false);
            $table->boolean('not_for_selling')->default(false);
            $table->string('image')->nullable();
            $table->timestamps();
        });
        Schema::create('product_locations', function (Blueprint $table) {
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('location_id');
        });
        Schema::create('product_variations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->string('name');
            $table->timestamps();
        });
        Schema::create('variations', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variation_id');
            $table->string('name');
            $table->string('sub_sku')->nullable();
            $table->decimal('sell_price_inc_tax', 22, 4)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
        Schema::create('variation_location_details', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('product_id');
            $table->unsignedInteger('product_variation_id');
            $table->unsignedInteger('variation_id');
            $table->unsignedInteger('location_id');
            $table->decimal('qty_available', 22, 4)->default(0);
            $table->timestamps();
        });
    }
}
