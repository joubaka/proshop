<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Shop\CartService;
use App\Shop\CatalogService;
use App\Shop\Channel;
use App\Shop\DeliveryQuote;
use App\Shop\Order;
use App\Shop\OrderPlacementService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class CheckoutController extends Controller
{
    public function create(Request $request, CatalogService $catalog, CartService $carts)
    {
        abort_unless(config('shop.checkout_enabled'), 404);
        $channel = $catalog->channel();
        $cart = $this->cart($request, $channel, $carts);
        $items = $cart->items()->with(['shopVariation.shopProduct.product', 'shopVariation.variation'])->get();
        abort_if($items->isEmpty(), 404);
        $subtotalCents = $items->sum(fn ($item) => (int) round((float) $item->shopVariation->variation->sell_price_inc_tax * 100) * $item->quantity);
        return response()->view('shop.checkout.create', compact('channel', 'cart', 'items', 'subtotalCents'))
            ->header('Cache-Control', 'no-store, private');
    }

    public function store(Request $request, CatalogService $catalog, CartService $carts, OrderPlacementService $orders)
    {
        abort_unless(config('shop.checkout_enabled'), 404);
        $channel = $catalog->channel();
        $cart = $this->cart($request, $channel, $carts);
        $data = $request->validate([
            'name' => ['required', 'string', 'max:191'], 'email' => ['required', 'email', 'max:191'],
            'mobile' => ['required', 'string', 'max:40'], 'address_line_1' => ['required', 'string', 'max:191'],
            'address_line_2' => ['nullable', 'string', 'max:191'], 'city' => ['required', 'string', 'max:100'],
            'province' => ['required', 'string', 'max:100'], 'postal_code' => ['required', 'string', 'max:20'],
            'terms' => ['accepted'],
        ]);
        $address = collect($data)->only(['address_line_1', 'address_line_2', 'city', 'province', 'postal_code'])->all();
        $order = $orders->place($cart, [
            'name' => $data['name'], 'email' => $data['email'], 'mobile' => $data['mobile'], 'billing_address' => $address,
        ], DeliveryQuote::collection());
        $url = URL::temporarySignedRoute('shop.orders.show', now()->addDays(7), ['uuid' => $order->uuid]);
        return redirect($url)->withoutCookie(CartService::COOKIE);
    }

    public function show(string $uuid, CatalogService $catalog)
    {
        $channel = $catalog->channel();
        $order = Order::query()->where('shop_channel_id', $channel->id)->where('uuid', $uuid)->with('items')->firstOrFail();
        $payUrl = null;
        if (config('shop.payfast.enabled') && $order->payment_status === 'pending' && $order->reservation_expires_at->isFuture()) {
            $payUrl = URL::temporarySignedRoute('shop.payfast.start', $order->reservation_expires_at, ['uuid' => $order->uuid]);
        }
        return response()->view('shop.orders.show', compact('channel', 'order', 'payUrl'))->header('Cache-Control', 'no-store, private');
    }

    private function cart(Request $request, Channel $channel, CartService $carts)
    {
        $token = $request->cookie(CartService::COOKIE);
        abort_unless(is_string($token) && $token !== '', 404);
        return $carts->find($channel, $token) ?? abort(404);
    }
}
