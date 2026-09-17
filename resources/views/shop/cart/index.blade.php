@extends('shop.layout')
@section('title', 'Your cart · '.$channel->name)
@section('content')
<p class="eyebrow">YOUR CART</p><h1>Review your products</h1>
@if(session('status'))<p class="shop-notice" role="status">{{ session('status') }}</p>@endif
@if($items->isEmpty())
<div class="empty-state"><h2>Your cart is empty</h2><p>Add a product to begin.</p><a class="shop-button" href="{{ route('shop.home') }}">Browse products</a></div>
@else
<div class="cart-layout"><section class="cart-lines">@foreach($items as $item)<article><div><strong>{{ $item->shopVariation->shopProduct->product->name }}</strong><span>{{ $item->shopVariation->display_name ?: $item->shopVariation->variation->name }}</span></div><strong>R {{ number_format($item->shopVariation->variation->sell_price_inc_tax * $item->quantity, 2) }}</strong><form method="POST" action="{{ route('shop.cart.items.update', $item->id) }}">@csrf @method('PATCH')<label>Quantity<input type="number" name="quantity" value="{{ $item->quantity }}" min="1" max="100"></label><button>Update</button></form><form method="POST" action="{{ route('shop.cart.items.destroy', $item->id) }}">@csrf @method('DELETE')<button class="link-button">Remove</button></form></article>@endforeach</section><aside class="order-summary"><h2>Summary</h2><div><span>Subtotal</span><strong>R {{ number_format($subtotalCents / 100, 2) }}</strong></div><p>Collection from ProShop. Delivery options will be added later.</p><a class="shop-button" href="{{ route('shop.checkout') }}">Continue to checkout</a></aside></div>
@endif
@endsection
