<?php
namespace Tests\Acceptance;

use App\Contact;
use App\InvoiceScan;
use App\InvoiceScanLine;
use App\InvoiceScanning\PostScannedPurchase;
use App\Product;
use App\ProductPriceChange;
use App\PurchaseLine;
use App\Transaction;
use App\User;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class InvoiceScanPostingWorkflowTest extends \Tests\TestCase
{
    public function createApplication(){ $app=require dirname(__DIR__,2).'/scripts/local-acceptance/bootstrap.php';$app['config']->set('session.driver','array');return $app; }
    protected function setUp():void{parent::setUp();$this->assertSame('proshop_acceptance',DB::connection()->getDatabaseName());DB::beginTransaction();}
    protected function tearDown():void{while(DB::transactionLevel()>0)DB::rollBack();parent::tearDown();}

    public function test_reviewed_scan_receives_against_po_updates_stock_and_records_approved_price():void
    {
        $user=User::where('username','local.admin')->firstOrFail();$businessId=$user->business_id;$location=\App\BusinessLocation::where('business_id',$businessId)->firstOrFail();$supplier=Contact::where('business_id',$businessId)->where('type','supplier')->firstOrFail();$product=Product::where('business_id',$businessId)->where('name','Test Tennis Balls')->firstOrFail();$variation=$product->variations()->firstOrFail();$oldSell=(float)$variation->sell_price_inc_tax;
        DB::table('variation_location_details')->updateOrInsert(['product_id'=>$product->id,'variation_id'=>$variation->id,'location_id'=>$location->id],['product_variation_id'=>$variation->product_variation_id,'qty_available'=>5,'created_at'=>now(),'updated_at'=>now()]);
        $po=Transaction::create(['business_id'=>$businessId,'location_id'=>$location->id,'contact_id'=>$supplier->id,'created_by'=>$user->id,'type'=>'purchase_order','status'=>'ordered','payment_status'=>'due','ref_no'=>'PO-SCAN-TEST','transaction_date'=>now(),'total_before_tax'=>200,'final_total'=>200,'exchange_rate'=>1]);
        $poLine=PurchaseLine::create(['transaction_id'=>$po->id,'product_id'=>$product->id,'variation_id'=>$variation->id,'quantity'=>4,'po_quantity_purchased'=>0,'pp_without_discount'=>50,'discount_percent'=>0,'purchase_price'=>50,'purchase_price_inc_tax'=>50,'item_tax'=>0]);
        $scan=InvoiceScan::create(['uuid'=>'scan-post-test','business_id'=>$businessId,'location_id'=>$location->id,'supplier_id'=>$supplier->id,'created_by'=>$user->id,'reviewed_by'=>$user->id,'status'=>'ready','document_hash'=>hash('sha256','scan-post-test'),'invoice_number'=>'INV-SCAN-TEST','invoice_date'=>now()->toDateString(),'subtotal'=>100,'discount_total'=>0,'tax_total'=>0,'freight_total'=>0,'invoice_total'=>100]);
        InvoiceScanLine::create(['invoice_scan_id'=>$scan->id,'variation_id'=>$variation->id,'purchase_order_line_id'=>$poLine->id,'description'=>'Test Tennis Balls','quantity'=>2,'pack_size'=>1,'unit_price'=>50,'price_includes_tax'=>false,'tax_rate'=>0,'line_total'=>100,'proposed_sell_price'=>$oldSell+10,'price_change_approved'=>true,'price_change_reason'=>'Maintain target margin after supplier increase','price_approved_by'=>$user->id,'line_order'=>1]);
        session(['currency'=>['thousand_separator'=>',','decimal_separator'=>'.','symbol'=>'R']]);$purchase=app(PostScannedPurchase::class)->post($scan,$user->id);
        $this->assertSame('purchase',$purchase->type);$this->assertSame('received',$purchase->status);$this->assertSame($poLine->id,$purchase->purchase_lines()->first()->purchase_order_line_id);$this->assertSame(2.0,(float)$poLine->fresh()->po_quantity_purchased);$this->assertSame(7.0,(float)DB::table('variation_location_details')->where('variation_id',$variation->id)->where('location_id',$location->id)->value('qty_available'));$this->assertSame($oldSell+10,(float)$variation->fresh()->sell_price_inc_tax);
        $change=ProductPriceChange::where('source_reference',$scan->uuid)->firstOrFail();$this->assertSame($user->id,$change->changed_by);$this->assertSame('Maintain target margin after supplier increase',$change->reason);$this->assertSame($purchase->id,app(PostScannedPurchase::class)->post($scan->fresh(),$user->id)->id);$this->assertSame(1,Transaction::where('ref_no','INV-SCAN-TEST')->count());
    }

    public function test_scan_cannot_over_receive_a_purchase_order_line():void
    {
        $user=User::where('username','local.admin')->firstOrFail();$businessId=$user->business_id;$location=\App\BusinessLocation::where('business_id',$businessId)->firstOrFail();$supplier=Contact::where('business_id',$businessId)->where('type','supplier')->firstOrFail();$product=Product::where('business_id',$businessId)->where('name','Test Tennis Balls')->firstOrFail();$variation=$product->variations()->firstOrFail();$po=Transaction::create(['business_id'=>$businessId,'location_id'=>$location->id,'contact_id'=>$supplier->id,'created_by'=>$user->id,'type'=>'purchase_order','status'=>'ordered','payment_status'=>'due','ref_no'=>'PO-OVER-TEST','transaction_date'=>now(),'total_before_tax'=>50,'final_total'=>50,'exchange_rate'=>1]);$poLine=PurchaseLine::create(['transaction_id'=>$po->id,'product_id'=>$product->id,'variation_id'=>$variation->id,'quantity'=>1,'po_quantity_purchased'=>0,'pp_without_discount'=>50,'discount_percent'=>0,'purchase_price'=>50,'purchase_price_inc_tax'=>50,'item_tax'=>0]);$scan=InvoiceScan::create(['uuid'=>'scan-over-test','business_id'=>$businessId,'location_id'=>$location->id,'supplier_id'=>$supplier->id,'created_by'=>$user->id,'status'=>'ready','document_hash'=>hash('sha256','scan-over-test'),'invoice_number'=>'INV-OVER-TEST','invoice_date'=>now()->toDateString(),'subtotal'=>100,'discount_total'=>0,'tax_total'=>0,'freight_total'=>0,'invoice_total'=>100]);InvoiceScanLine::create(['invoice_scan_id'=>$scan->id,'variation_id'=>$variation->id,'purchase_order_line_id'=>$poLine->id,'description'=>'Balls','quantity'=>2,'pack_size'=>1,'unit_price'=>50,'tax_rate'=>0,'line_total'=>100]);
        $this->expectException(RuntimeException::class);$this->expectExceptionMessage('exceeds');app(PostScannedPurchase::class)->post($scan,$user->id);
    }
}
