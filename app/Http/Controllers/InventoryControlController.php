<?php

namespace App\Http\Controllers;

use App\BusinessLocation;
use App\Contact;
use App\InventoryControl\ReplenishmentService;
use App\InventoryPolicy;
use App\ProductPriceChange;
use App\ReplenishmentBatch;
use App\Transaction;
use App\Utils\TransactionUtil;
use App\StockCount;
use App\StockCountLine;
use App\Utils\ProductUtil;
use App\Variation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class InventoryControlController extends Controller
{
    public function index(Request $request, ReplenishmentService $service)
    {
        $this->authorizeView();
        $businessId = (int) $request->session()->get('user.business_id');
        $locations = BusinessLocation::forDropdown($businessId, false, false);
        $locationId = (int) ($request->integer('location_id') ?: array_key_first($locations->toArray()));
        $this->authorizeLocation($locationId);
        $recommendations = $locationId ? $service->recommendations($businessId, $locationId, $request->integer('days', 30)) : collect();
        $suppliers = Contact::suppliersDropdown($businessId, false);
        $counts = StockCount::where('business_id', $businessId)->with('location')->latest()->limit(15)->get();
        $priceChanges=ProductPriceChange::where('business_id',$businessId)->with(['variation.product','user'])->latest()->limit(25)->get();
        return view('inventory_control.index', compact('locations','locationId','recommendations','suppliers','counts','priceChanges'));
    }

    public function savePolicies(Request $request)
    {
        abort_unless(auth()->user()->can('purchase_order.create'), 403);
        $businessId = (int) $request->session()->get('user.business_id');
        $data = $request->validate(['location_id'=>'required|integer','policies'=>'required|array','policies.*.variation_id'=>'required|integer','policies.*.supplier_id'=>'nullable|integer','policies.*.lead_time_days'=>'required|integer|min:0|max:365','policies.*.safety_stock_days'=>'required|integer|min:0|max:365','policies.*.minimum_order_quantity'=>'required|numeric|min:0.0001','policies.*.order_multiple'=>'required|numeric|min:0.0001']);
        $this->authorizeLocation((int) $data['location_id']);
        DB::transaction(function () use ($data, $businessId) {
            foreach ($data['policies'] as $policy) {
                Variation::whereKey($policy['variation_id'])->whereHas('product', fn ($q) => $q->where('business_id',$businessId))->firstOrFail();
                if (!empty($policy['supplier_id'])) Contact::where('business_id',$businessId)->whereIn('type',['supplier','both'])->findOrFail($policy['supplier_id']);
                InventoryPolicy::updateOrCreate(['business_id'=>$businessId,'location_id'=>$data['location_id'],'variation_id'=>$policy['variation_id']], $policy);
            }
        });
        return back()->with('status',['success'=>1,'msg'=>'Replenishment policies saved. Recommendations were recalculated; no purchase order was created.']);
    }

    public function previewReplenishment(Request $request, ReplenishmentService $service)
    {
        abort_unless(auth()->user()->can('purchase_order.create'),403);
        $businessId=(int)$request->session()->get('user.business_id');
        $data=$request->validate(['location_id'=>'required|integer','days'=>'required|integer|min:7|max:365','selected'=>'required|array|min:1','selected.*'=>'nullable|boolean','quantity'=>'required|array','supplier'=>'required|array']);
        $this->authorizeLocation((int)$data['location_id']);
        $current=$service->recommendations($businessId,(int)$data['location_id'],(int)$data['days']);
        $selected=$current->filter(fn($row)=>!empty($data['selected'][$row->variation_id]));
        if($selected->isEmpty()) return back()->withErrors(['selected'=>'Select at least one recommendation.']);
        foreach($selected as $row){
            $row->order_quantity=(float)($data['quantity'][$row->variation_id]??0);
            $row->selected_supplier_id=(int)($data['supplier'][$row->variation_id]??0);
            if($row->order_quantity<=0||!$row->selected_supplier_id)return back()->withErrors(['selected'=>'Every selected line needs a supplier and positive order quantity.']);
            Contact::where('business_id',$businessId)->whereIn('type',['supplier','both'])->findOrFail($row->selected_supplier_id);
        }
        $batch=DB::transaction(function()use($selected,$current,$service,$businessId,$data){
            $batch=ReplenishmentBatch::create(['uuid'=>(string)Str::uuid(),'business_id'=>$businessId,'location_id'=>$data['location_id'],'created_by'=>auth()->id(),'status'=>'preview','sales_window_days'=>$data['days'],'proposal_fingerprint'=>$service->fingerprint($current)]);
            foreach($selected as $row)$batch->lines()->create(['product_id'=>$row->product_id,'variation_id'=>$row->variation_id,'supplier_id'=>$row->selected_supplier_id,'quantity'=>$row->order_quantity,'unit_cost'=>$row->default_purchase_price,'stock_snapshot'=>$row->qty_available,'open_po_snapshot'=>$row->open_po,'daily_sales_snapshot'=>$row->daily_sales]);
            return $batch;
        });
        return redirect()->route('inventory-control.replenishment.show',$batch->uuid);
    }

    public function showReplenishment(Request $request,string $uuid)
    {
        $batch=$this->batch($request,$uuid)->load(['location','lines.product','lines.variation','lines.supplier']);
        return view('inventory_control.replenishment',compact('batch'));
    }

    public function confirmReplenishment(Request $request,string $uuid,ReplenishmentService $service,ProductUtil $products,TransactionUtil $transactions)
    {
        abort_unless(auth()->user()->can('purchase_order.create'),403);
        $batch=$this->batch($request,$uuid);
        try{$orders=DB::transaction(function()use($batch,$service,$products,$transactions){
            $batch=ReplenishmentBatch::whereKey($batch->id)->lockForUpdate()->with(['lines.variation.product'])->firstOrFail();
            if($batch->status!=='preview')throw new RuntimeException('This replenishment preview is no longer available.');
            $current=$service->recommendations($batch->business_id,$batch->location_id,$batch->sales_window_days);
            if(!hash_equals($batch->proposal_fingerprint,$service->fingerprint($current)))throw new RuntimeException('Stock, sales or open orders changed. Discard this stale preview and calculate it again.');
            $currency=$transactions->purchaseCurrencyDetails($batch->business_id);$orderIds=[];
            foreach($batch->lines->groupBy('supplier_id') as $supplierId=>$lines){
                $refCount=$products->setAndGetReferenceCount('purchase_order',$batch->business_id);
                $ref=$products->generateReferenceNumber('purchase_order',$refCount,$batch->business_id);
                $total=$lines->sum(fn($line)=>(float)$line->quantity*(float)$line->unit_cost);
                $order=Transaction::create(['business_id'=>$batch->business_id,'location_id'=>$batch->location_id,'contact_id'=>$supplierId,'created_by'=>auth()->id(),'type'=>'purchase_order','status'=>'ordered','payment_status'=>'due','ref_no'=>$ref,'transaction_date'=>now(),'total_before_tax'=>$total,'tax_amount'=>0,'shipping_charges'=>0,'discount_type'=>'fixed','discount_amount'=>0,'final_total'=>$total,'exchange_rate'=>1,'additional_notes'=>'Created from replenishment preview '.$batch->uuid]);
                $purchaseLines=$lines->map(fn($line)=>['product_id'=>$line->product_id,'variation_id'=>$line->variation_id,'quantity'=>$line->quantity,'product_unit_id'=>$line->variation->product->unit_id,'pp_without_discount'=>$line->unit_cost,'discount_percent'=>0,'purchase_price'=>$line->unit_cost,'purchase_price_inc_tax'=>$line->unit_cost,'item_tax'=>0,'purchase_line_tax_id'=>null])->all();
                $products->createOrUpdatePurchaseLines($order,$purchaseLines,$currency,false);
                $transactions->activityLog($order,'added',null,['source'=>'replenishment_batch','batch_uuid'=>$batch->uuid]);$orderIds[]=$order->id;
            }
            $batch->update(['status'=>'confirmed','confirmed_by'=>auth()->id(),'confirmed_at'=>now(),'purchase_order_ids'=>implode(',',$orderIds)]);return $orderIds;
        });}catch(RuntimeException $e){return back()->withErrors(['batch'=>$e->getMessage()]);}
        return redirect()->route('inventory-control.index')->with('status',['success'=>1,'msg'=>count($orders).' purchase order(s) created from the confirmed preview.']);
    }

    public function discardReplenishment(Request $request,string $uuid){$batch=$this->batch($request,$uuid);abort_unless($batch->created_by===auth()->id()||auth()->user()->can('purchase_order.create'),403);if($batch->status==='preview')$batch->update(['status'=>'discarded']);return redirect()->route('inventory-control.index')->with('status',['success'=>1,'msg'=>'Replenishment preview discarded. No orders were created.']);}

    public function createCount(Request $request)
    {
        abort_unless(auth()->user()->can('purchase.create'), 403);
        $businessId = (int) $request->session()->get('user.business_id');
        $data = $request->validate(['location_id'=>'required|integer','name'=>'required|string|max:255','notes'=>'nullable|string|max:2000']);
        $this->authorizeLocation((int) $data['location_id']);
        $count = DB::transaction(function () use ($data, $businessId) {
            $count = StockCount::create($data + ['uuid'=>(string) Str::uuid(),'business_id'=>$businessId,'created_by'=>auth()->id(),'status'=>'draft']);
            $rows = DB::table('variation_location_details as vld')->join('products as p','p.id','=','vld.product_id')
                ->where('p.business_id',$businessId)->where('vld.location_id',$data['location_id'])->where('p.enable_stock',1)
                ->select('vld.product_id','vld.variation_id','vld.qty_available')->get();
            foreach ($rows as $row) StockCountLine::create(['stock_count_id'=>$count->id,'product_id'=>$row->product_id,'variation_id'=>$row->variation_id,'system_quantity'=>$row->qty_available,'counted_quantity'=>null]);
            return $count;
        });
        return redirect()->route('inventory-control.counts.show',$count->uuid);
    }

    public function showCount(Request $request, string $uuid)
    {
        $count = $this->count($request,$uuid)->load(['location','lines.product','lines.variation']);
        return view('inventory_control.count',compact('count'));
    }

    public function scan(Request $request, string $uuid)
    {
        $count = $this->count($request,$uuid); abort_if($count->status !== 'draft',409,'Count is closed.');
        $data=$request->validate(['barcode'=>'required|string|max:255','quantity'=>'nullable|numeric|min:0.0001']);
        $matches=Variation::where('sub_sku',trim($data['barcode']))->whereHas('product',fn($q)=>$q->where('business_id',$count->business_id))->with('product')->limit(2)->get();
        if($matches->isEmpty()) return response()->json(['message'=>'Barcode/SKU not found.'],422);
        if($matches->count()>1) return response()->json(['message'=>'Barcode/SKU is duplicated. Correct the catalogue before counting it.'],422);
        $variation=$matches->first();
        $line=$count->lines()->where('variation_id',$variation->id)->first();
        if(!$line) return response()->json(['message'=>'Product is not stocked at this count location.'],422);
        $line->update(['counted_quantity'=>(float)($line->counted_quantity??0)+(float)($data['quantity']??1),'counted_by'=>auth()->id(),'counted_at'=>now()]);
        return response()->json(['line_id'=>$line->id,'counted_quantity'=>(float)$line->fresh()->counted_quantity,'name'=>$variation->product->name]);
    }

    public function updateCount(Request $request, string $uuid)
    {
        $count=$this->count($request,$uuid); abort_if($count->status!=='draft',409);
        $data=$request->validate(['lines'=>'required|array','lines.*'=>'required|numeric|min:0']);
        foreach($data['lines'] as $id=>$quantity) $count->lines()->whereKey($id)->update(['counted_quantity'=>$quantity,'counted_by'=>auth()->id(),'counted_at'=>now()]);
        return back()->with('status',['success'=>1,'msg'=>'Count saved. Stock has not been adjusted.']);
    }

    public function postCount(Request $request, string $uuid, ProductUtil $products)
    {
        abort_unless(auth()->user()->can('purchase.create'),403);
        $count=$this->count($request,$uuid);
        try { DB::transaction(function() use($count,$products){
            $locked=StockCount::whereKey($count->id)->lockForUpdate()->with('lines')->firstOrFail();
            if($locked->status!=='draft') throw new RuntimeException('This count has already been posted.');
            if($locked->lines->contains(fn($line)=>$line->counted_quantity===null))throw new RuntimeException('Every product must be counted before posting. Enter zero when no stock is physically present.');
            foreach($locked->lines as $line){
                $current=(float)DB::table('variation_location_details')->where('location_id',$locked->location_id)->where('variation_id',$line->variation_id)->lockForUpdate()->value('qty_available');
                if(abs($current-(float)$line->system_quantity)>0.0001) throw new RuntimeException('Stock changed after this count began. Start a fresh count before posting.');
                $delta=(float)$line->counted_quantity-$current;
                if($delta>0)$products->updateProductQuantity($locked->location_id,$line->product_id,$line->variation_id,$delta);
                elseif($delta<0)$products->decreaseProductQuantity($line->product_id,$line->variation_id,$locked->location_id,abs($delta));
            }
            $locked->update(['status'=>'posted','posted_by'=>auth()->id(),'posted_at'=>now()]);
        }); } catch(RuntimeException $e){ return back()->withErrors(['count'=>$e->getMessage()]); }
        return back()->with('status',['success'=>1,'msg'=>'Count posted and stock adjusted with a permanent count record.']);
    }

    public function discardCount(Request $request,string $uuid)
    {
        $count=$this->count($request,$uuid);abort_unless($count->created_by===auth()->id()||auth()->user()->can('purchase.create'),403);
        if($count->status==='draft')$count->update(['status'=>'discarded']);
        return redirect()->route('inventory-control.index')->with('status',['success'=>1,'msg'=>'Cycle count discarded. Stock was not changed.']);
    }

    private function count(Request $request,string $uuid): StockCount { $this->authorizeView(); $count=StockCount::where('business_id',(int)$request->session()->get('user.business_id'))->where('uuid',$uuid)->firstOrFail();$this->authorizeLocation($count->location_id);return $count; }
    private function batch(Request $request,string $uuid): ReplenishmentBatch { abort_unless(auth()->user()->can('purchase_order.create'),403);$batch=ReplenishmentBatch::where('business_id',(int)$request->session()->get('user.business_id'))->where('uuid',$uuid)->firstOrFail();$this->authorizeLocation($batch->location_id);return $batch; }
    private function authorizeView(): void { abort_unless(auth()->user()?->can('stock_report.view')||auth()->user()?->can('purchase.create'),403); }
    private function authorizeLocation(int $id): void { $locations=auth()->user()->permitted_locations(); abort_unless($locations==='all'||in_array($id,array_map('intval',$locations),true),403); }
}
