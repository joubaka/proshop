<?php
namespace Tests\Acceptance;

use App\Contact;
use App\InventoryPolicy;
use App\Product;
use App\ReplenishmentBatch;
use App\Transaction;
use App\User;
use Illuminate\Support\Facades\DB;

class InventoryControlWorkflowTest extends \Tests\TestCase
{
    public function createApplication(){ $app=require dirname(__DIR__,2).'/scripts/local-acceptance/bootstrap.php';$app['config']->set('session.driver','array');return $app; }
    protected function setUp():void{parent::setUp();$this->assertSame('proshop_acceptance',DB::connection()->getDatabaseName());DB::beginTransaction();}
    protected function tearDown():void{while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}
    private function login():User{$user=User::where('username','local.admin')->firstOrFail();$this->withSession(['_token'=>'inventory-token'])->post('/login',['_token'=>'inventory-token','username'=>'local.admin','password'=>'LocalAcceptance!2026'])->assertRedirect();$this->get('/home');return $user;}

    public function test_replenishment_preview_is_non_mutating_and_confirmation_creates_one_purchase_order():void
    {
        $user=$this->login();$businessId=$user->business_id;$location=\App\BusinessLocation::where('business_id',$businessId)->firstOrFail();$product=Product::where('business_id',$businessId)->where('name','Test Tennis Balls')->firstOrFail();$variation=$product->variations()->firstOrFail();$supplier=Contact::where('business_id',$businessId)->where('type','supplier')->firstOrFail();
        $product->update(['alert_quantity'=>10]);DB::table('variation_location_details')->updateOrInsert(['product_id'=>$product->id,'variation_id'=>$variation->id,'location_id'=>$location->id],['product_variation_id'=>$variation->product_variation_id,'qty_available'=>0,'created_at'=>now(),'updated_at'=>now()]);
        InventoryPolicy::updateOrCreate(['business_id'=>$businessId,'location_id'=>$location->id,'variation_id'=>$variation->id],['supplier_id'=>$supplier->id,'lead_time_days'=>7,'safety_stock_days'=>7,'minimum_order_quantity'=>5,'order_multiple'=>5]);
        $before=Transaction::where('business_id',$businessId)->where('type','purchase_order')->count();
        $response=$this->withSession(['_token'=>'inventory-token'])->post(route('inventory-control.replenishment.preview'),['_token'=>'inventory-token','location_id'=>$location->id,'days'=>30,'selected'=>[$variation->id=>1],'quantity'=>[$variation->id=>10],'supplier'=>[$variation->id=>$supplier->id]]);
        $batch=ReplenishmentBatch::where('business_id',$businessId)->latest('id')->firstOrFail();$response->assertRedirect(route('inventory-control.replenishment.show',$batch->uuid));$this->assertSame($before,Transaction::where('business_id',$businessId)->where('type','purchase_order')->count());
        $this->withSession(['_token'=>'inventory-token'])->post(route('inventory-control.replenishment.confirm',$batch->uuid),['_token'=>'inventory-token'])->assertRedirect(route('inventory-control.index'));
        $this->assertSame($before+1,Transaction::where('business_id',$businessId)->where('type','purchase_order')->count());$this->assertSame('confirmed',$batch->fresh()->status);$this->assertSame(0.0,(float)DB::table('variation_location_details')->where('variation_id',$variation->id)->where('location_id',$location->id)->value('qty_available'));
        $this->withSession(['_token'=>'inventory-token'])->post(route('inventory-control.replenishment.confirm',$batch->uuid),['_token'=>'inventory-token'])->assertSessionHasErrors('batch');$this->assertSame($before+1,Transaction::where('business_id',$businessId)->where('type','purchase_order')->count());
    }

    public function test_stale_replenishment_preview_is_rejected_when_stock_changes():void
    {
        $user=$this->login();$businessId=$user->business_id;$location=\App\BusinessLocation::where('business_id',$businessId)->firstOrFail();$product=Product::where('business_id',$businessId)->where('name','Test Tennis Balls')->firstOrFail();$variation=$product->variations()->firstOrFail();$supplier=Contact::where('business_id',$businessId)->where('type','supplier')->firstOrFail();$product->update(['alert_quantity'=>10]);
        DB::table('variation_location_details')->updateOrInsert(['product_id'=>$product->id,'variation_id'=>$variation->id,'location_id'=>$location->id],['product_variation_id'=>$variation->product_variation_id,'qty_available'=>0,'created_at'=>now(),'updated_at'=>now()]);InventoryPolicy::updateOrCreate(['business_id'=>$businessId,'location_id'=>$location->id,'variation_id'=>$variation->id],['supplier_id'=>$supplier->id,'lead_time_days'=>7,'safety_stock_days'=>7,'minimum_order_quantity'=>5,'order_multiple'=>5]);
        $this->withSession(['_token'=>'inventory-token'])->post(route('inventory-control.replenishment.preview'),['_token'=>'inventory-token','location_id'=>$location->id,'days'=>30,'selected'=>[$variation->id=>1],'quantity'=>[$variation->id=>10],'supplier'=>[$variation->id=>$supplier->id]]);$batch=ReplenishmentBatch::where('business_id',$businessId)->latest('id')->firstOrFail();$before=Transaction::where('business_id',$businessId)->where('type','purchase_order')->count();
        DB::table('variation_location_details')->where('variation_id',$variation->id)->where('location_id',$location->id)->update(['qty_available'=>2]);
        $this->withSession(['_token'=>'inventory-token'])->post(route('inventory-control.replenishment.confirm',$batch->uuid),['_token'=>'inventory-token'])->assertSessionHasErrors('batch');$this->assertSame('preview',$batch->fresh()->status);$this->assertSame($before,Transaction::where('business_id',$businessId)->where('type','purchase_order')->count());
    }
}
