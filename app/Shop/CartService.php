<?php

namespace App\Shop;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class CartService
{
    public function create(Channel $channel): array
    {
        $token = Str::random(64);
        $cart = Cart::create([
            'uuid' => (string) Str::uuid(), 'shop_channel_id' => $channel->id,
            'token_hash' => hash('sha256', $token), 'status' => 'active',
            'expires_at' => now()->addMinutes(max(30, (int) config('shop.cart_lifetime_minutes', 10080))),
        ]);

        return [$cart, $token];
    }

    public function find(Channel $channel, string $token): ?Cart
    {
        return Cart::query()->where('shop_channel_id', $channel->id)
            ->where('token_hash', hash('sha256', $token))->where('status', 'active')
            ->where('expires_at', '>', now())->first();
    }

    public function put(Cart $cart, int $shopVariationId, int $quantity): CartItem
    {
        if ($quantity < 1 || $quantity > 100) {
            throw ValidationException::withMessages(['quantity' => 'Choose a quantity between 1 and 100.']);
        }

        return DB::transaction(function () use ($cart, $shopVariationId, $quantity) {
            $cart = Cart::query()->whereKey($cart->id)->lockForUpdate()->firstOrFail();
            if ($cart->status !== 'active' || $cart->expires_at->isPast()) {
                throw ValidationException::withMessages(['cart' => 'This cart has expired.']);
            }
            $shopVariation = ShopVariation::query()->whereKey($shopVariationId)->where('published', true)
                ->whereHas('shopProduct', fn ($query) => $query->where('shop_channel_id', $cart->shop_channel_id)->published())
                ->firstOrFail();
            if ($shopVariation->maximum_order_quantity !== null && $quantity > (int) $shopVariation->maximum_order_quantity) {
                throw ValidationException::withMessages(['quantity' => 'The requested quantity exceeds the online order limit.']);
            }

            return CartItem::updateOrCreate(
                ['shop_cart_id' => $cart->id, 'shop_variation_id' => $shopVariation->id], ['quantity' => $quantity]
            );
        });
    }
}
