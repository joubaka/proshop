<?php

namespace Tests\Feature;

use App\Utils\ProductUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RegressionTestCase;

class StockQuantityRegressionTest extends RegressionTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('products', function ($table) {
            $table->id(); $table->boolean('enable_stock'); $table->softDeletes();
        });
        Schema::create('variations', function ($table) {
            $table->id(); $table->integer('product_id'); $table->integer('product_variation_id'); $table->softDeletes();
        });
        Schema::create('variation_location_details', function ($table) {
            $table->id(); $table->integer('product_id'); $table->integer('variation_id');
            $table->integer('product_variation_id'); $table->integer('location_id');
            $table->decimal('qty_available', 22, 4); $table->timestamps();
        });
        DB::table('products')->insert(['id' => 1, 'enable_stock' => true]);
        DB::table('variations')->insert(['id' => 1, 'product_id' => 1, 'product_variation_id' => 1]);
    }

    public function test_receipt_edit_sale_edit_and_return_preserve_quantity_deltas(): void
    {
        $util = new ProductUtil;
        $util->updateProductQuantity(1, 1, 1, 10, 0, null, false);
        $util->updateProductQuantity(1, 1, 1, 12, 10, null, false);
        $util->decreaseProductQuantity(1, 1, 1, 3);
        $this->assertQuantity(1, 9);
        $util->decreaseProductQuantity(1, 1, 1, 2, 3);
        $this->assertQuantity(1, 10);
        $util->decreaseProductQuantity(1, 1, 1, 0, 2);
        $this->assertQuantity(1, 12);
    }

    public function test_locations_are_isolated_and_transfer_conserves_stock(): void
    {
        $util = new ProductUtil;
        $util->updateProductQuantity(1, 1, 1, 10, 0, null, false);
        $util->updateProductQuantity(2, 1, 1, 5, 0, null, false);
        DB::transaction(function () use ($util) {
            $util->decreaseProductQuantity(1, 1, 1, 2.5);
            $util->updateProductQuantity(2, 1, 1, 2.5, 0, null, false);
        });
        $this->assertQuantity(1, 7.5);
        $this->assertQuantity(2, 7.5);
        $this->assertEqualsWithDelta(15, DB::table('variation_location_details')->sum('qty_available'), 0.00001);
    }

    public function test_failed_transaction_rolls_back_stock_changes(): void
    {
        $util = new ProductUtil;
        $util->updateProductQuantity(1, 1, 1, 10, 0, null, false);
        try {
            DB::transaction(function () use ($util) {
                $util->decreaseProductQuantity(1, 1, 1, 3);
                throw new \RuntimeException('Simulated failed sale');
            });
            $this->fail('Expected simulated failure');
        } catch (\RuntimeException $e) {
            $this->assertSame('Simulated failed sale', $e->getMessage());
        }
        $this->assertQuantity(1, 10);
    }

    public function test_services_without_stock_and_unchanged_receipts_do_not_create_stock(): void
    {
        $util = new ProductUtil;
        $util->updateProductQuantity(1, 1, 1, 5, 5, null, false);
        $this->assertDatabaseCount('variation_location_details', 0);
        DB::table('products')->where('id', 1)->update(['enable_stock' => false]);
        $util->updateProductQuantity(1, 1, 1, 10, 0, null, false);
        $util->decreaseProductQuantity(1, 1, 1, 2);
        $this->assertDatabaseCount('variation_location_details', 0);
    }

    private function assertQuantity(int $location, float $expected): void
    {
        $this->assertEqualsWithDelta($expected,
            DB::table('variation_location_details')->where('location_id', $location)->value('qty_available'), 0.00001);
    }
}
