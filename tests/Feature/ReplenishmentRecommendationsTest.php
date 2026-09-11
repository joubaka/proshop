<?php
namespace Tests\Feature;

use App\InventoryControl\ReplenishmentService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class ReplenishmentRecommendationsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach(['products','variations','variation_location_details','transactions','transaction_sell_lines','purchase_lines','inventory_policies'] as $table) Schema::dropIfExists($table);
        Schema::create('products',fn(Blueprint $t)=>[$t->id(),$t->unsignedInteger('business_id'),$t->string('name'),$t->decimal('alert_quantity',22,4)->nullable(),$t->boolean('enable_stock'),$t->boolean('is_inactive')->default(false),$t->softDeletes()]);
        Schema::create('variations',fn(Blueprint $t)=>[$t->id(),$t->unsignedInteger('product_id'),$t->string('sub_sku'),$t->decimal('sell_price_inc_tax',22,4),$t->decimal('default_purchase_price',22,4),$t->softDeletes()]);
        Schema::create('variation_location_details',fn(Blueprint $t)=>[$t->id(),$t->unsignedInteger('product_id'),$t->unsignedInteger('variation_id'),$t->unsignedInteger('location_id'),$t->decimal('qty_available',22,4)]);
        Schema::create('transactions',fn(Blueprint $t)=>[$t->id(),$t->unsignedInteger('business_id'),$t->unsignedInteger('location_id'),$t->string('type'),$t->string('status'),$t->dateTime('transaction_date')]);
        Schema::create('transaction_sell_lines',fn(Blueprint $t)=>[$t->id(),$t->unsignedInteger('transaction_id'),$t->unsignedInteger('variation_id'),$t->decimal('quantity',22,4),$t->decimal('quantity_returned',22,4)->default(0)]);
        Schema::create('purchase_lines',fn(Blueprint $t)=>[$t->id(),$t->unsignedInteger('transaction_id'),$t->unsignedInteger('variation_id'),$t->decimal('quantity',22,4),$t->decimal('po_quantity_purchased',22,4)->default(0)]);
        Schema::create('inventory_policies',fn(Blueprint $t)=>[$t->id(),$t->unsignedInteger('business_id'),$t->unsignedInteger('location_id'),$t->unsignedInteger('variation_id'),$t->unsignedInteger('supplier_id')->nullable(),$t->unsignedSmallInteger('lead_time_days'),$t->unsignedSmallInteger('safety_stock_days'),$t->decimal('minimum_order_quantity',22,4),$t->decimal('order_multiple',22,4),$t->timestamps()]);
    }

    public function test_recommendations_subtract_stock_and_unreceived_purchase_orders(): void
    {
        DB::table('products')->insert(['id'=>1,'business_id'=>1,'name'=>'Ball case','alert_quantity'=>0,'enable_stock'=>1]);
        DB::table('variations')->insert(['id'=>1,'product_id'=>1,'sub_sku'=>'BALL-CASE','sell_price_inc_tax'=>150,'default_purchase_price'=>100]);
        DB::table('variation_location_details')->insert(['product_id'=>1,'variation_id'=>1,'location_id'=>1,'qty_available'=>3]);
        DB::table('inventory_policies')->insert(['business_id'=>1,'location_id'=>1,'variation_id'=>1,'supplier_id'=>4,'lead_time_days'=>10,'safety_stock_days'=>5,'minimum_order_quantity'=>6,'order_multiple'=>6,'created_at'=>now(),'updated_at'=>now()]);
        DB::table('transactions')->insert([['id'=>1,'business_id'=>1,'location_id'=>1,'type'=>'sell','status'=>'final','transaction_date'=>now()],['id'=>2,'business_id'=>1,'location_id'=>1,'type'=>'purchase_order','status'=>'ordered','transaction_date'=>now()]]);
        DB::table('transaction_sell_lines')->insert(['transaction_id'=>1,'variation_id'=>1,'quantity'=>30,'quantity_returned'=>0]);
        DB::table('purchase_lines')->insert(['transaction_id'=>2,'variation_id'=>1,'quantity'=>12,'po_quantity_purchased'=>6]);
        $row=(new ReplenishmentService)->recommendations(1,1,30)->first();
        $this->assertSame(1.0,$row->daily_sales);$this->assertSame(6.0,(float)$row->open_po);$this->assertSame(6.0,(float)$row->suggested_order);
    }
}
