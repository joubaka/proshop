@extends('shop.layout')

@section('title', $channel->name.' · Online shop')

@section('content')
<header class="catalog-heading"><p class="eyebrow">ONLINE SHOP</p><h1>Sports equipment from {{ $channel->name }}</h1><p>Browse the products currently published from our ProShop catalogue.</p></header>
<section class="product-grid" aria-label="Products">
@forelse($products as $shopProduct)
    @php($firstVariation = $shopProduct->variations->first()?->variation)
    <article class="product-card">
        <a href="{{ route('shop.products.show', $shopProduct->slug) }}">
            <img src="{{ $shopProduct->display_image_url }}" alt="{{ $shopProduct->product->name }}">
            <div><p class="product-meta">{{ $shopProduct->product->brand?->name ?? 'ProShop' }}</p><h2>{{ $shopProduct->product->name }}</h2><p>{{ $shopProduct->short_description }}</p>@if($firstVariation)<strong>R {{ number_format($firstVariation->sell_price_inc_tax, 2) }}</strong>@endif</div>
        </a>
    </article>
@empty
    <div class="empty-state"><h2>No products published yet</h2><p>Please check again soon.</p></div>
@endforelse
</section>
{{ $products->links() }}
@endsection
