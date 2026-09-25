@extends('shop.layout', ['channel' => app(\App\Shop\CatalogService::class)->channel()])
@section('title', 'Choose a new password')
@section('content')
<div class="account-card"><p class="eyebrow">CUSTOMER ACCOUNT</p><h1>Choose a new password</h1>@if($errors->any())<div class="shop-notice error">{{ $errors->first() }}</div>@endif<form method="POST" action="{{ route('shop.account.password.update') }}">@csrf<input type="hidden" name="token" value="{{ $token }}"><label>Email<input type="email" name="email" value="{{ $email }}" required></label><label>New password<input type="password" name="password" required></label><label>Confirm password<input type="password" name="password_confirmation" required></label><button class="shop-button">Reset password</button></form></div>
@endsection
