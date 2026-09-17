<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Shop\Cart;
use App\Shop\CartService;
use App\Shop\CatalogService;
use App\Shop\Channel;
use Illuminate\Http\Request;

class CartController extends Controller
{
    public function index(Request $request, CatalogService $catalog, CartService $carts)
    {
        $channel = $catalog->channel();
        $cart = $this->current($request, $channel, $carts);
        $items = $cart ? $this->items($cart) : collect();
        $subtotalCents = $items->sum(fn ($item) => $this->unitCents($item) * $item->quantity);

        return response()->view('shop.cart.index', compact('channel', 'cart', 'items', 'subtotalCents'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, CatalogService $catalog, CartService $carts)
    {
        abort_unless(config('shop.checkout_enabled'), 404);
        $data = $request->validate(['shop_variation_id' => ['required', 'integer'], 'quantity' => ['required', 'integer', 'min:1', 'max:100']]);
        $channel = $catalog->channel();
        $cart = $this->current($request, $channel, $carts);
        $token = null;
        if (!$cart) { [$cart, $token] = $carts->create($channel); }
        $carts->put($cart, (int) $data['shop_variation_id'], (int) $data['quantity']);

        $response = redirect()->route('shop.cart')->with('status', 'Product added to your cart.');
        return $token ? $response->cookie($this->cookie($request, $token)) : $response;
    }

    public function update(Request $request, int $item, CatalogService $catalog, CartService $carts)
    {
        abort_unless(config('shop.checkout_enabled'), 404);
        $quantity = (int) $request->validate(['quantity' => ['required', 'integer', 'min:1', 'max:100']])['quantity'];
        $cart = $this->current($request, $catalog->channel(), $carts) ?? abort(404);
        $cartItem = $cart->items()->whereKey($item)->firstOrFail();
        $carts->put($cart, $cartItem->shop_variation_id, $quantity);
        return redirect()->route('shop.cart')->with('status', 'Cart updated.');
    }

    public function destroy(Request $request, int $item, CatalogService $catalog, CartService $carts)
    {
        abort_unless(config('shop.checkout_enabled'), 404);
        $cart = $this->current($request, $catalog->channel(), $carts) ?? abort(404);
        $carts->remove($cart, $item);
        return redirect()->route('shop.cart')->with('status', 'Product removed.');
    }

    private function current(Request $request, Channel $channel, CartService $carts): ?Cart
    {
        $token = $request->cookie(CartService::COOKIE);
        return is_string($token) && $token !== '' ? $carts->find($channel, $token) : null;
    }

    private function items(Cart $cart)
    {
        return $cart->items()->with(['shopVariation.shopProduct.product', 'shopVariation.variation'])->get();
    }

    private function unitCents($item): int
    {
        return (int) round((float) $item->shopVariation->variation->sell_price_inc_tax * 100, 0, PHP_ROUND_HALF_UP);
    }

    private function cookie(Request $request, string $token)
    {
        return cookie(CartService::COOKIE, $token, max(30, (int) config('shop.cart_lifetime_minutes', 10080)), '/', null,
            $request->isSecure(), true, false, 'lax');
    }
}
