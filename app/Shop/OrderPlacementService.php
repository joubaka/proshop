<?php

namespace App\Shop;

use App\Shop\Exceptions\InsufficientStock;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class OrderPlacementService
{
    public function place(Cart $cart, array $customer, DeliveryQuote $delivery): Order
    {
        $this->validateCustomer($customer, $delivery);

        return DB::transaction(function () use ($cart, $customer, $delivery) {
            $cart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if ($cart->status !== 'active' || $cart->expires_at->isPast()) {
                throw ValidationException::withMessages(['cart' => 'This cart has expired.']);
            }
            $channel = Channel::query()->whereKey($cart->shop_channel_id)->where('enabled', true)->firstOrFail();
            $items = CartItem::query()->where('shop_cart_id', $cart->id)->orderBy('shop_variation_id')
                ->with(['shopVariation.shopProduct.product.product_tax', 'shopVariation.variation.product_variation'])->get();
            if ($items->isEmpty()) {
                throw ValidationException::withMessages(['cart' => 'Your cart is empty.']);
            }

            $expiresAt = now()->addMinutes(max(5, (int) config('shop.reservation_minutes', 20)));
            $prepared = []; $subtotalCents = 0; $taxCents = 0;
            foreach ($items as $item) {
                $shopVariation = $item->shopVariation; $shopProduct = $shopVariation->shopProduct;
                $product = $shopProduct->product; $variation = $shopVariation->variation;
                if (!$shopVariation->published || !$shopProduct->published_at || $shopProduct->published_at->isFuture()
                    || ($shopProduct->unpublished_at && $shopProduct->unpublished_at->isPast())
                    || $product->business_id !== $channel->business_id || $product->is_inactive || $product->not_for_selling
                    || $product->type === 'combo') {
                    throw ValidationException::withMessages(['cart' => 'A product in your cart is no longer available.']);
                }
                if ($shopVariation->maximum_order_quantity !== null
                    && $item->quantity > (int) $shopVariation->maximum_order_quantity) {
                    throw ValidationException::withMessages(['cart' => 'A product in your cart exceeds its online order limit.']);
                }
                $stock = DB::table('variation_location_details')->where('location_id', $channel->location_id)
                    ->where('variation_id', $variation->id)->lockForUpdate()->first();
                if (!$stock) { throw new InsufficientStock($variation->id, 0); }
                $reserved = (int) DB::table('shop_stock_reservations')->where('location_id', $channel->location_id)
                    ->where('variation_id', $variation->id)->where('status', 'active')
                    ->where('expires_at', '>', now())->sum('quantity');
                $available = max(0, (int) floor((float) $stock->qty_available - (float) $shopVariation->safety_stock) - $reserved);
                if ($item->quantity > $available) { throw new InsufficientStock($variation->id, $available); }

                $unitCents = (int) round((float) $variation->sell_price_inc_tax * 100, 0, PHP_ROUND_HALF_UP);
                if ($unitCents < 1) {
                    throw ValidationException::withMessages(['cart' => 'A product in your cart has no valid online price.']);
                }
                $rate = (float) ($product->product_tax?->amount ?? 0);
                $unitTaxCents = $rate > 0 ? (int) round($unitCents - ($unitCents / (1 + $rate / 100)), 0, PHP_ROUND_HALF_UP) : 0;
                $lineTotal = $unitCents * $item->quantity;
                $subtotalCents += $lineTotal; $taxCents += $unitTaxCents * $item->quantity;
                $prepared[] = compact('item', 'shopVariation', 'product', 'variation', 'unitCents', 'unitTaxCents', 'lineTotal');
            }

            $order = Order::create([
                'uuid' => (string) Str::uuid(), 'order_number' => $this->orderNumber(),
                'shop_channel_id' => $channel->id, 'shop_cart_id' => $cart->id, 'currency' => $channel->currency,
                'shop_customer_id' => $customer['shop_customer_id'] ?? null,
                'contact_id' => $this->verifiedContactId($customer['shop_customer_id'] ?? null, $channel->business_id),
                'customer_name' => trim($customer['name']), 'customer_email' => Str::lower(trim($customer['email'])),
                'customer_mobile' => trim($customer['mobile']), 'billing_address' => $customer['billing_address'],
                'delivery_address' => $customer['delivery_address'] ?? null, 'fulfilment_method' => $delivery->method,
                'fulfilment_label' => $delivery->label, 'delivery_quote' => $delivery->metadata,
                'subtotal_cents' => $subtotalCents, 'tax_cents' => $taxCents,
                'delivery_cents' => $delivery->amountCents, 'discount_cents' => 0,
                'total_cents' => $subtotalCents + $delivery->amountCents,
                'payment_status' => 'pending', 'order_status' => 'awaiting_payment',
                'fulfilment_status' => 'not_ready', 'reservation_expires_at' => $expiresAt,
            ]);
            foreach ($prepared as $line) {
                $order->items()->create([
                    'product_id' => $line['product']->id, 'variation_id' => $line['variation']->id,
                    'product_name' => $line['product']->name,
                    'variation_name' => $line['shopVariation']->display_name ?: $line['variation']->name,
                    'sku' => $line['variation']->sub_sku, 'quantity' => $line['item']->quantity,
                    'unit_price_inc_tax_cents' => $line['unitCents'], 'unit_tax_cents' => $line['unitTaxCents'],
                    'line_total_cents' => $line['lineTotal'],
                ]);
                $order->reservations()->create([
                    'location_id' => $channel->location_id, 'product_id' => $line['product']->id,
                    'variation_id' => $line['variation']->id, 'quantity' => $line['item']->quantity,
                    'status' => 'active', 'expires_at' => $expiresAt,
                ]);
            }
            $order->events()->create(['event_type' => 'order_reserved', 'metadata' => ['cart_uuid' => $cart->uuid]]);
            $cart->update(['status' => 'converted']);

            return $order->load(['items', 'reservations', 'events']);
        }, 3);
    }

    private function validateCustomer(array $customer, DeliveryQuote $delivery): void
    {
        $validator = validator($customer, [
            'name' => ['required', 'string', 'max:191'], 'email' => ['required', 'email', 'max:191'],
            'mobile' => ['required', 'string', 'max:40'], 'billing_address' => ['required', 'array'],
            'delivery_address' => [$delivery->method === 'collection' ? 'nullable' : 'required', 'array'],
        ]);
        if ($validator->fails()) { throw new ValidationException($validator); }
    }

    private function verifiedContactId(?int $customerId, int $businessId): ?int
    {
        if (!$customerId) { return null; }
        return CustomerContactLink::query()->where('shop_customer_id', $customerId)
            ->where('business_id', $businessId)->where('status', 'verified')->value('contact_id');
    }

    private function orderNumber(): string
    {
        do { $number = 'WEB-'.now()->format('ymd').'-'.Str::upper(Str::random(8)); }
        while (Order::where('order_number', $number)->exists());

        return $number;
    }
}
