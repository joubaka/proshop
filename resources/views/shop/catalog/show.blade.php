@extends('shop.layout')

@section('title', ($shopProduct->seo_title ?: $shopProduct->product->name).' · '.$channel->name)
@section('meta_description', $shopProduct->seo_description ?: $shopProduct->short_description)

@section('content')
<a class="back-link" href="{{ route('shop.home') }}">← Back to products</a>
<article class="product-detail">
    <img src="{{ $shopProduct->product->image_url }}" alt="{{ $shopProduct->product->name }}">
    <div><p class="eyebrow">{{ $shopProduct->product->brand?->name ?? 'PROSHOP' }}</p><h1>{{ $shopProduct->product->name }}</h1><p>{{ $shopProduct->web_description ?: $shopProduct->short_description }}</p><h2>Available options</h2><ul class="variation-list">@foreach($shopProduct->variations as $shopVariation)@php($variation = $shopVariation->variation)@php($available = $availability[$shopVariation->id] ?? 0)<li><span>{{ $shopVariation->display_name ?: $variation->name }}</span><strong>R {{ number_format($variation->sell_price_inc_tax, 2) }}</strong><small>{{ $available > 0 ? ($available.' available online') : 'Unavailable' }}</small>@if(config('shop.checkout_enabled') && $available > 0)<form method="POST" action="{{ route('shop.cart.items.store') }}">@csrf<input type="hidden" name="shop_variation_id" value="{{ $shopVariation->id }}"><label>Quantity<input type="number" name="quantity" value="1" min="1" max="{{ $available }}" required></label><button class="shop-button">Add to cart</button></form>@endif</li>@endforeach</ul></div>
</article>
@endsection
