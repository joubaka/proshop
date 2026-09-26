<?php

namespace Tests\Feature;

use App\Shop\Channel;
use App\Shop\CatalogAdminService;
use App\Shop\ShopProduct;
use App\Shop\ShopVariation;
use App\Product;
use App\Http\Controllers\Shop\AdminCatalogController;
use Illuminate\Http\Request;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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

    public function test_repeated_online_product_saves_update_one_publication_instead_of_creating_a_duplicate(): void
    {
        $channel = $this->channel();
        $this->publish($channel, 100, 'Published racket', 'published-racket');
        $product = Product::findOrFail(100);
        $settings = [
            'slug' => 'published-racket', 'published' => true,
            'variations' => [2100 => ['published' => true, 'safety_stock' => 0]],
        ];

        app(CatalogAdminService::class)->saveProduct($channel, $product, $settings);
        app(CatalogAdminService::class)->saveProduct($channel, $product, $settings);

        $this->assertSame(1, ShopProduct::where('shop_channel_id', $channel->id)->where('product_id', $product->id)->count());
    }

    public function test_catalogue_admin_can_replace_the_online_display_picture(): void
    {
        Storage::fake('public');
        $channel = $this->channel();
        $this->publish($channel, 100, 'Published racket', 'published-racket');
        $shopProduct = ShopProduct::where('product_id', 100)->firstOrFail();

        $first = app(CatalogAdminService::class)->replacePrimaryImage(
            $shopProduct,
            UploadedFile::fake()->image('first-racket.jpg', 800, 600)->size(300),
        );
        Storage::disk('public')->assertExists($first->path);

        $replacement = app(CatalogAdminService::class)->replacePrimaryImage(
            $shopProduct->fresh(),
            UploadedFile::fake()->image('replacement-racket.png', 800, 600)->size(300),
        );

        Storage::disk('public')->assertMissing($first->path);
        Storage::disk('public')->assertExists($replacement->path);
        $this->assertSame(1, $shopProduct->images()->count());
        $this->assertTrue($replacement->is_primary);
        $this->get('/shop')->assertOk()->assertSee($replacement->url, false);
        $this->get('/shop/products/published-racket')->assertOk()->assertSee($replacement->url, false);
    }

    public function test_catalogue_admin_can_search_eligible_products_by_name_or_sku(): void
    {
        $channel = $this->channel();
        $this->publish($channel, 100, 'Competition racket', 'competition-racket');
        $this->publish($channel, 101, 'Training balls', 'training-balls');
        $this->signInWithPermissions(['shop.catalog.view', 'access_all_locations']);

        $byName = Request::create('/shop-admin/catalog/1/products', 'GET', ['search' => 'racket']);
        $byName->setLaravelSession($this->app['session']->driver());
        $byName->setUserResolver(fn () => auth()->user());
        $nameResults = app(AdminCatalogController::class)->products($byName, $channel)->getData()['products'];

        $this->assertSame(['Competition racket'], $nameResults->pluck('name')->all());

        $bySku = Request::create('/shop-admin/catalog/1/products', 'GET', ['search' => 'SKU-101']);
        $bySku->setLaravelSession($this->app['session']->driver());
        $bySku->setUserResolver(fn () => auth()->user());
        $skuResults = app(AdminCatalogController::class)->products($bySku, $channel)->getData()['products'];

        $this->assertSame(['Training balls'], $skuResults->pluck('name')->all());
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

    public function test_authorized_staff_can_edit_channel_name_and_enabled_status_without_changing_ownership(): void
    {
        $channel = $this->channel();
        $this->signInWithPermissions(['shop.catalog.view', 'shop.catalog.manage', 'access_all_locations']);

        $this->patch(route('shop.admin.catalog.channels.update', $channel), [
            'name' => 'HSC Pro Shop',
        ])->assertRedirect(route('shop.admin.catalog.index'));

        $channel->refresh();
        $this->assertSame('HSC Pro Shop', $channel->name);
        $this->assertFalse($channel->enabled);
        $this->assertSame('main', $channel->slug);
        $this->assertSame(1, (int) $channel->business_id);
        $this->assertSame(10, (int) $channel->location_id);
    }

    public function test_general_pos_sale_permissions_do_not_grant_catalogue_publication_access(): void
    {
        $channel = $this->channel();
        $this->signInWithPermissions(['sell.view', 'sell.create', 'sell.update', 'access_all_locations']);

        $this->patch(route('shop.admin.catalog.channels.update', $channel), [
            'name' => 'Unauthorized change', 'enabled' => '1',
        ])->assertForbidden();

        $this->assertSame('Cape Sports ProShop', $channel->fresh()->name);
        $this->assertTrue($channel->fresh()->enabled);
    }

    public function test_channel_edit_rejects_cross_business_tampering(): void
    {
        DB::table('business_locations')->insert([
            'id' => 20, 'business_id' => 2, 'name' => 'Other business location',
        ]);
        $channel = Channel::create([
            'business_id' => 2, 'location_id' => 20, 'slug' => 'other',
            'name' => 'Other shop', 'currency' => 'ZAR', 'enabled' => true,
        ]);
        $this->signInWithPermissions(['shop.catalog.view', 'shop.catalog.manage', 'access_all_locations']);

        $this->get(route('shop.admin.catalog.channels.edit', $channel))->assertNotFound();
        $this->patch(route('shop.admin.catalog.channels.update', $channel), [
            'name' => 'Tampered name', 'enabled' => '0',
        ])->assertNotFound();

        $this->assertDatabaseHas('shop_channels', [
            'id' => $channel->id, 'name' => 'Other shop', 'enabled' => true,
        ]);
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
