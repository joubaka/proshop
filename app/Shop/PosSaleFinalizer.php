<?php

namespace App\Shop;

use App\Business;
use App\Contact;
use App\Product;
use App\Shop\Exceptions\StockUnavailableAfterPayment;
use App\Utils\ProductUtil;
use App\Utils\TransactionUtil;
use App\Variation;
use Illuminate\Support\Facades\DB;

class PosSaleFinalizer implements PaidOrderFinalizer
{
    public function __construct(private TransactionUtil $transactions, private ProductUtil $products) {}

    public function finalize(Order $order, Payment $payment): void
    {
        if ($order->transaction_id) {
            return;
        }

        $channel = $order->channel;
        $business = Business::query()->whereKey($channel->business_id)->firstOrFail();
        if (!$business->owner_id) {
            throw new \RuntimeException('The shop business has no owner for POS audit attribution.');
        }

        $productRows = Product::query()->whereIn('id', $order->items->pluck('product_id'))
            ->where('business_id', $channel->business_id)->get()->keyBy('id');
        $variationRows = Variation::query()->whereIn('id', $order->items->pluck('variation_id'))
            ->get()->keyBy('id');

        foreach ($order->items as $item) {
            $product = $productRows->get($item->product_id);
            $variation = $variationRows->get($item->variation_id);
            if (!$product || !$variation || (int) $variation->product_id !== (int) $product->id || $product->type === 'combo') {
                throw new StockUnavailableAfterPayment('An ordered product can no longer be finalised.');
            }
            if ($product->enable_stock) {
                $available = DB::table('variation_location_details')->where('location_id', $channel->location_id)
                    ->where('product_id', $product->id)->where('variation_id', $variation->id)
                    ->lockForUpdate()->value('qty_available');
                if ($available === null || (float) $available < (int) $item->quantity) {
                    throw new StockUnavailableAfterPayment('Reserved stock is no longer available in the POS.');
                }
            }
        }

        $contact = $this->contact($order, $business->id, $business->owner_id);
        $lines = [];
        foreach ($order->items as $item) {
            $product = $productRows->get($item->product_id);
            $lines[] = [
                'product_id' => $product->id,
                'variation_id' => $item->variation_id,
                'quantity' => $item->quantity,
                'unit_price' => ($item->unit_price_inc_tax_cents - $item->unit_tax_cents) / 100,
                'unit_price_inc_tax' => $item->unit_price_inc_tax_cents / 100,
                'item_tax' => $item->unit_tax_cents / 100,
                'tax_id' => $product->tax,
                'enable_stock' => (bool) $product->enable_stock,
                'product_type' => $product->type,
                'line_discount_type' => null,
                'line_discount_amount' => 0,
            ];
        }

        $input = [
            'location_id' => $channel->location_id,
            'contact_id' => $contact->id,
            'status' => 'final',
            'type' => 'sell',
            'source' => 'native_shop',
            'transaction_date' => now()->toDateTimeString(),
            'final_total' => $order->total_cents / 100,
            'discount_type' => 'fixed',
            'discount_amount' => $order->discount_cents / 100,
            'tax_rate_id' => null,
            'is_direct_sale' => 1,
            'sale_note' => 'Online order '.$order->order_number,
            'shipping_details' => $order->fulfilment_label,
            'shipping_address' => $order->delivery_address ? json_encode($order->delivery_address) : null,
            'shipping_status' => $order->fulfilment_method === 'collection' ? 'ordered' : null,
            'shipping_charges' => $order->delivery_cents / 100,
        ];
        $invoiceTotal = [
            'total_before_tax' => ($order->subtotal_cents - $order->tax_cents) / 100,
            'tax' => $order->tax_cents / 100,
        ];
        $transaction = $this->transactions->createSellTransaction(
            $business->id, $input, $invoiceTotal, $business->owner_id, false
        );
        $this->transactions->createOrUpdateSellLines($transaction, $lines, $channel->location_id, false, null, [], false);

        foreach ($lines as $line) {
            if ($line['enable_stock']) {
                $this->products->decreaseProductQuantity(
                    $line['product_id'], $line['variation_id'], $channel->location_id, $line['quantity']
                );
            }
        }

        $this->transactions->createOrUpdatePaymentLines($transaction, [[
            'amount' => $order->total_cents / 100,
            'method' => 'other',
            'paid_on' => now()->toDateTimeString(),
            'note' => 'PayFast '.$payment->provider_reference,
        ]], $business->id, $business->owner_id, false);
        $this->transactions->updatePaymentStatus($transaction->id, $transaction->final_total);

        $posSettings = is_array($business->pos_settings) ? $business->pos_settings : json_decode($business->pos_settings ?: '{}', true);
        $this->transactions->mapPurchaseSell([
            'id' => $business->id,
            'accounting_method' => $business->accounting_method,
            'location_id' => $channel->location_id,
            'pos_settings' => $posSettings ?: [],
        ], $transaction->sell_lines, 'purchase', false);

        $order->update(['contact_id' => $contact->id, 'transaction_id' => $transaction->id]);
    }

    private function contact(Order $order, int $businessId, int $ownerId): Contact
    {
        $email = strtolower(trim($order->customer_email));
        $contact = Contact::query()->where('business_id', $businessId)
            ->whereRaw('LOWER(email) = ?', [$email])->whereIn('type', ['customer', 'both'])->first();
        if ($contact) {
            return $contact;
        }
        $address = $order->billing_address ?: [];
        return Contact::create([
            'business_id' => $businessId, 'type' => 'customer', 'name' => $order->customer_name,
            'email' => $email, 'mobile' => $order->customer_mobile, 'created_by' => $ownerId,
            'address_line_1' => $address['address_line_1'] ?? null,
            'address_line_2' => $address['address_line_2'] ?? null,
            'city' => $address['city'] ?? null, 'state' => $address['province'] ?? null,
            'zip_code' => $address['postal_code'] ?? null,
        ]);
    }
}
