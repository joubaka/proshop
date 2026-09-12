<?php
namespace Tests\Feature;
use App\StockCount;
use App\StockCountLine;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\RegressionTestCase;

class StockCountPostingTest extends RegressionTestCase
{
    protected function setUp():void
    {
        parent::setUp();$this->createIdentitySchema();
        Schema::create('business_locations',fn(Blueprint $t)=>[$t->id(),$t->unsignedInteger('business_id'),$t->string('name'),$t->timestamps()]);
        Schema::create('products',fn(Blueprint $t)=>[$t->id(),$t->unsignedInteger('business_id'),$t->string('name'),$t->boolean('enable_stock'),$t->softDeletes()]);
        Schema::create('variations',fn(Blueprint $t)=>[$t->id(),$t->unsignedInteger('product_id'),$t->unsignedInteger('product_variation_id'),$t->string('sub_sku'),$t->softDeletes()]);
        Schema::create('variation_location_details',fn(Blueprint $t)=>[$t->id(),$t->unsignedInteger('product_id'),$t->unsignedInteger('variation_id'),$t->unsignedInteger('product_variation_id'),$t->unsignedInteger('location_id'),$t->decimal('qty_available',22,4),$t->timestamps()]);
        (require database_path('migrations/2026_09_11_000200_create_inventory_control_tables.php'))->up();
        DB::table('business_locations')->insert(['id'=>1,'business_id'=>1,'name'=>'Shop']);DB::table('products')->insert(['id'=>1,'business_id'=>1,'name'=>'Balls','enable_stock'=>1]);DB::table('variations')->insert(['id'=>1,'product_id'=>1,'product_variation_id'=>1,'sub_sku'=>'BALL']);DB::table('variation_location_details')->insert(['product_id'=>1,'variation_id'=>1,'product_variation_id'=>1,'location_id'=>1,'qty_available'=>10,'created_at'=>now(),'updated_at'=>now()]);
        $this->withoutMiddleware([\App\Http\Middleware\IsInstalled::class,\App\Http\Middleware\SetSessionData::class,\App\Http\Middleware\Language::class,\App\Http\Middleware\Timezone::class,\App\Http\Middleware\CheckUserLogin::class]);
    }
    public function test_unfinished_count_cannot_zero_stock_and_completed_count_posts_variance():void
    {
        $this->signInWithPermissions(['purchase.create','access_all_locations']);$count=StockCount::create(['uuid'=>'count-1','business_id'=>1,'location_id'=>1,'created_by'=>1,'status'=>'draft','name'=>'Test']);$line=StockCountLine::create(['stock_count_id'=>$count->id,'product_id'=>1,'variation_id'=>1,'system_quantity'=>10,'counted_quantity'=>null]);
        $this->post(route('inventory-control.counts.post',$count->uuid))->assertSessionHasErrors('count');$this->assertSame(10.0,(float)DB::table('variation_location_details')->value('qty_available'));
        $line->update(['counted_quantity'=>8]);$this->post(route('inventory-control.counts.post',$count->uuid))->assertSessionHasNoErrors();$this->assertSame(8.0,(float)DB::table('variation_location_details')->value('qty_available'));$this->assertSame('posted',$count->fresh()->status);
    }

    public function test_stock_report_viewer_cannot_change_a_draft_count():void
    {
        $this->signInWithPermissions(['stock_report.view','access_all_locations']);
        $count=StockCount::create(['uuid'=>'count-read-only','business_id'=>1,'location_id'=>1,'created_by'=>1,'status'=>'draft','name'=>'Read only']);
        $line=StockCountLine::create(['stock_count_id'=>$count->id,'product_id'=>1,'variation_id'=>1,'system_quantity'=>10,'counted_quantity'=>null]);

        $this->put(route('inventory-control.counts.update',$count->uuid),['lines'=>[$line->id=>8]])->assertForbidden();
        $this->postJson(route('inventory-control.counts.scan',$count->uuid),['barcode'=>'BALL'])->assertForbidden();

        $this->assertNull($line->fresh()->counted_quantity);
    }
}
