@extends('shop.layout', ['channel' => app(\App\Shop\CatalogService::class)->channel()])
@section('title', 'Customer login')
@section('content')
<div class="account-card"><p class="eyebrow">CUSTOMER ACCOUNT</p><h1>Log in</h1>
@if(session('status'))<div class="shop-notice">{{ session('status') }}</div>@endif
@if($errors->any())<div class="shop-notice error" role="alert">{{ $errors->first() }}</div>@endif
<form method="POST" action="{{ route('shop.account.login.store') }}">@csrf<label>Email<input type="email" name="email" value="{{ old('email') }}" required autocomplete="email"></label><label>Password<input type="password" name="password" required autocomplete="current-password"></label><label class="terms"><input type="checkbox" name="remember" value="1"> Keep me logged in on this device</label><button class="shop-button" type="submit">Log in</button></form>
<p><a href="{{ route('shop.account.password.request') }}">Forgot password?</a> · <a href="{{ route('shop.account.register') }}">Create an account</a></p></div>
@endsection
