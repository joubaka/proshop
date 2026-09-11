<?php

namespace App\InvoiceScanning;

use App\Business;
use App\InvoiceScan;
use App\PurchaseLine;
use App\ProductPriceChange;
use App\SupplierProductMapping;
use App\TaxRate;
use App\Transaction;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class PostScannedPurchase
{
    public function __construct(private ProductUtil $products, private TransactionUtil $transactions, private InvoiceAmounts $amounts) {}

    public function post(InvoiceScan $scan, int $actorId): Transaction
    {
        return DB::transaction(function () use ($scan, $actorId) {
            $scan = InvoiceScan::whereKey($scan->id)->lockForUpdate()->with('lines.variation.product')->firstOrFail();
            if ($scan->status === 'posted') return $scan->purchase;
            if ($scan->status !== 'ready') throw new RuntimeException('Save and review the invoice before posting it.');
            if (!$scan->supplier_id || !$scan->location_id || !$scan->invoice_date || !$scan->invoice_total) {
                throw new RuntimeException('Supplier, location, invoice date and total are required before posting.');
            }
            if ($scan->lines->isEmpty() || $scan->lines->contains(fn ($line) => !$line->variation_id || $line->quantity <= 0 || $line->unit_price < 0)) {
                throw new RuntimeException('Every invoice line must have a product, positive quantity and valid price.');
            }
            if($scan->lines->contains(fn($line)=>$line->price_change_approved&&(!$line->price_approved_by||!$line->price_change_reason)))throw new RuntimeException('Every selling-price change needs a recorded approver and reason.');
            if (!$this->amounts->isBalanced((float) $scan->subtotal, (float) $scan->discount_total, (float) $scan->tax_total, (float) $scan->freight_total, (float) $scan->invoice_total)) {
                throw new RuntimeException('Invoice totals no longer balance. Review the invoice again.');
            }
            $lineSubtotal = $scan->lines->sum(fn ($line) => (float) $line->line_total);
            if (abs($lineSubtotal - (float) $scan->subtotal) > max(0.05, abs($lineSubtotal) * 0.002)) {
                throw new RuntimeException('Invoice lines do not add up to the reviewed subtotal.');
            }

            $duplicate = Transaction::where('business_id', $scan->business_id)->where('type', 'purchase')
                ->where('contact_id', $scan->supplier_id)->where('ref_no', $scan->invoice_number)->exists();
            if ($duplicate) throw new RuntimeException('This supplier invoice number has already been posted.');

            $reference = $scan->invoice_number;
            if (!$reference) {
                $count = $this->products->setAndGetReferenceCount('purchase', $scan->business_id);
                $reference = $this->products->generateReferenceNumber('purchase', $count, $scan->business_id);
            }

            $transaction = Transaction::create([
                'business_id' => $scan->business_id,
                'location_id' => $scan->location_id,
                'contact_id' => $scan->supplier_id,
                'created_by' => $actorId,
                'type' => 'purchase',
                'status' => 'received',
                'payment_status' => 'due',
                'ref_no' => $reference,
                'transaction_date' => $scan->invoice_date->startOfDay(),
                'total_before_tax' => $scan->subtotal ?? $scan->lines->sum('line_total'),
                'tax_amount' => $scan->tax_total ?? 0,
                'shipping_charges' => $scan->freight_total ?? 0,
                'discount_type' => 'fixed',
                'discount_amount' => $scan->discount_total ?? 0,
                'final_total' => $scan->invoice_total,
                'exchange_rate' => 1,
                'additional_notes' => 'Created from invoice scan '.$scan->uuid.'. Original document retained privately.',
            ]);

            $currency = $this->transactions->purchaseCurrencyDetails($scan->business_id);
            $business = Business::findOrFail($scan->business_id);
            $purchaseLines = [];
            $purchaseOrderIds = [];
            foreach ($scan->lines as $line) {
                $oldCost = (float) $line->variation->default_purchase_price;
                $oldSell = (float) $line->variation->sell_price_inc_tax;
                $pack = max(0.0001, (float) $line->pack_size);
                $taxRate = (float) $line->tax_rate;
                $costs = $this->amounts->unitCosts((float) $line->unit_price, $pack, $taxRate, (bool) $line->price_includes_tax);
                $taxId = TaxRate::where('business_id', $scan->business_id)->where('amount', $taxRate)->value('id');
                if (!$this->amounts->isLineBalanced((float)$line->quantity,(float)$line->unit_price,(float)$line->tax_rate,(bool)$line->price_includes_tax,(float)$line->line_total)) {
                    throw new RuntimeException('An invoice line total no longer matches its quantity and unit price.');
                }
                $poLine = null;
                if ($line->purchase_order_line_id) {
                    $poLine = PurchaseLine::whereKey($line->purchase_order_line_id)->lockForUpdate()
                        ->where('variation_id', $line->variation_id)
                        ->whereHas('transaction', fn ($q) => $q->where('business_id', $scan->business_id)
                            ->where('contact_id', $scan->supplier_id)->where('location_id', $scan->location_id)
                            ->where('type', 'purchase_order')->whereNotIn('status', ['completed', 'cancelled']))
                        ->first();
                    if (!$poLine) throw new RuntimeException('A selected purchase-order line is no longer valid for this supplier, location or product.');
                    $receivedUnits = (float) $line->quantity * $pack;
                    $remaining = (float) $poLine->quantity - (float) $poLine->po_quantity_purchased;
                    if ($receivedUnits > $remaining + 0.0001) throw new RuntimeException('Received quantity exceeds the remaining quantity on a purchase order.');
                    $purchaseOrderIds[] = $poLine->transaction_id;
                }
                $purchaseLines[] = [
                    'product_id' => $line->variation->product_id,
                    'variation_id' => $line->variation_id,
                    'quantity' => (float) $line->quantity * $pack,
                    'product_unit_id' => $line->variation->product->unit_id,
                    'pp_without_discount' => $costs['exclusive'],
                    'discount_percent' => 0,
                    'purchase_price' => $costs['exclusive'],
                    'purchase_price_inc_tax' => $costs['inclusive'],
                    'item_tax' => $costs['tax'],
                    'purchase_line_tax_id' => $taxId,
                    'purchase_order_line_id' => $poLine?->id,
                    'default_sell_price' => $line->price_change_approved ? $line->proposed_sell_price : $line->variation->sell_price_inc_tax,
                ];
                $line->setAttribute('_old_cost', $oldCost);
                $line->setAttribute('_old_sell', $oldSell);
            }
            if ($purchaseOrderIds) $transaction->update(['purchase_order_ids' => implode(',', array_unique($purchaseOrderIds))]);

            $this->products->createOrUpdatePurchaseLines($transaction, $purchaseLines, $currency, (bool) $business->enable_editing_product_from_purchase);
            if(!$business->enable_editing_product_from_purchase){
                foreach($scan->lines->where('price_change_approved',true) as $line)$this->products->updateProductFromPurchase(['variation_id'=>$line->variation_id,'pp_without_discount'=>$line->getAttribute('_old_cost'),'sell_price_inc_tax'=>$line->proposed_sell_price]);
            }
            $this->products->adjustStockOverSelling($transaction);
            $this->transactions->activityLog($transaction, 'added', null, ['source' => 'invoice_scan', 'invoice_scan_uuid' => $scan->uuid]);

            foreach ($scan->lines as $line) {
                $line->variation->refresh();
                if ($line->price_change_approved || abs((float)$line->getAttribute('_old_cost') - (float)$line->variation->default_purchase_price) > 0.0001) {
                    ProductPriceChange::create([
                        'business_id'=>$scan->business_id,'variation_id'=>$line->variation_id,'changed_by'=>$actorId,
                        'source'=>'invoice_scan','source_reference'=>$scan->uuid,
                        'old_cost'=>$line->getAttribute('_old_cost'),'new_cost'=>$line->variation->default_purchase_price,
                        'old_sell_price'=>$line->getAttribute('_old_sell'),'new_sell_price'=>$line->variation->sell_price_inc_tax,
                        'reason'=>$line->price_change_reason ?: 'Supplier invoice cost update',
                    ]);
                }
            }

            foreach ($scan->lines as $line) {
                SupplierProductMapping::updateOrCreate([
                    'business_id' => $scan->business_id,
                    'supplier_id' => $scan->supplier_id,
                    'description_fingerprint' => InvoiceMatcher::fingerprint($line->description),
                ], [
                    'variation_id' => $line->variation_id,
                    'supplier_item_code' => $line->supplier_item_code,
                    'supplier_description' => $line->description,
                    'pack_size' => $line->pack_size,
                    'confirmed_by' => $actorId,
                    'last_confirmed_at' => now(),
                ]);
            }

            $scan->update(['status' => 'posted', 'purchase_transaction_id' => $transaction->id, 'posted_at' => now()]);
            return $transaction;
        }, 3);
    }
}
