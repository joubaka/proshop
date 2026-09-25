@extends('shop.layout', ['channel' => app(\App\Shop\CatalogService::class)->channel()])
@section('title', 'Reset password')
@section('content')
<div class="account-card"><p class="eyebrow">CUSTOMER ACCOUNT</p><h1>Reset password</h1>@if(session('status'))<div class="shop-notice">{{ session('status') }}</div>@endif<form method="POST" action="{{ route('shop.account.password.email') }}">@csrf<label>Email<input type="email" name="email" value="{{ old('email') }}" required></label><button class="shop-button">Send reset link</button></form></div>
@endsection
