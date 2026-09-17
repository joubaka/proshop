<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('meta_description', $channel->name.' online shop')">
    <title>@yield('title', $channel->name)</title>
    <link rel="stylesheet" href="/shop-assets/storefront.css?v=1">
    <link rel="stylesheet" href="/shop-assets/checkout.css?v=1">
</head>
<body>
<header class="shop-header"><a href="{{ route('shop.home') }}">{{ $channel->name }}</a><nav><a href="{{ route('shop.home') }}">Products</a><a href="{{ route('shop.cart') }}">Cart</a></nav></header>
<main class="shop-shell">@yield('content')</main>
</body>
</html>
