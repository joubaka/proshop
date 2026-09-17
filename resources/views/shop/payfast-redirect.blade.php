@extends('shop.layout')
@section('title', 'Continue to PayFast')
@section('content')
<div class="order-confirmation">
    <p class="eyebrow">SECURE PAYMENT</p>
    <h1>Continue to PayFast</h1>
    <p>You are leaving ProShop briefly to complete payment securely.</p>
    <form id="payfast-checkout" method="post" action="{{ $checkout['url'] }}">
        @foreach($checkout['fields'] as $name => $value)
            <input type="hidden" name="{{ $name }}" value="{{ $value }}">
        @endforeach
        <button class="shop-button" type="submit">Pay securely with PayFast</button>
    </form>
</div>
@endsection
