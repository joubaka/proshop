@extends('shop.layout', ['channel' => app(\App\Shop\CatalogService::class)->channel()])
@section('title', 'Verify your email')
@section('content')
<div class="account-card"><p class="eyebrow">ONE MORE STEP</p><h1>Verify your email</h1><p>Use the secure link sent to your email before viewing orders or account balances.</p>@if(session('status'))<div class="shop-notice">{{ session('status') }}</div>@endif<form method="POST" action="{{ route('shop.account.verification.send') }}">@csrf<button class="shop-button" type="submit">Send another verification email</button></form><form method="POST" action="{{ route('shop.account.logout') }}">@csrf<button class="link-button" type="submit">Log out</button></form></div>
@endsection
