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
<header class="shop-header"><a href="{{ route('shop.home') }}">{{ $channel->name }}</a><nav><a href="{{ route('shop.home') }}">Products</a><a href="{{ route('shop.cart') }}">Cart</a>@if(config('shop.customer_accounts_enabled'))<a href="{{ auth('shop_customer')->check() ? route('shop.account.dashboard') : route('shop.account.login') }}">{{ auth('shop_customer')->check() ? 'My account' : 'Log in' }}</a>@endif</nav></header>
<main class="shop-shell">@yield('content')</main>
</body>
</html>
