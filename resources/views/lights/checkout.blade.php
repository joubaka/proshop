@extends('lights.layout')
@section('title', $payment->gateway === 'payfast' ? 'PayFast checkout' : 'Demo checkout')
@section('content')
<section class="panel checkout">
    @if($payment->gateway === 'payfast')
        <p class="eyebrow">SECURE PAYFAST CHECKOUT</p><h1>Top up R {{ number_format($payment->amount_cents / 100, 2) }}</h1>
        <p class="lead">Continue to PayFast to complete your payment.</p>
        <p>Your wallet is credited only after a signed PayFast notification is independently verified by the server. Returning here does not credit it.</p>
        @if($payfast)
            <form method="POST" action="{{ $payfast['url'] }}">
                @foreach($payfast['fields'] as $name => $value)<input type="hidden" name="{{ $name }}" value="{{ $value }}">@endforeach
                <button class="button primary full">Pay securely with PayFast</button>
            </form>
        @else<div class="notice">This top-up is {{ $payment->status }}. It cannot credit your wallet again.</div>@endif
    @else
        <p class="eyebrow">PAYFAST FLOW · SIMULATION ONLY</p><h1>Top up R {{ number_format($payment->amount_cents / 100, 2) }}</h1><p class="lead">This is not a PayFast payment page.</p><p>No card details are collected and no real money moves. Choose a result to try the wallet flow.</p><p class="muted">Reference: {{ $payment->id }}</p>
        @if($payment->status === 'pending')<form action="{{ route('lights.simulate', $payment->id) }}" method="POST">@csrf<button class="button primary full" name="outcome" value="paid">Simulate successful payment</button><button class="button secondary full" name="outcome" value="cancelled">Simulate cancellation</button><button class="text-button full" name="outcome" value="failed">Simulate failed payment</button></form>
        @else<div class="notice">This top-up is {{ $payment->status }}. Reopening it cannot credit your wallet again.</div>@endif
    @endif
    <a class="back-link" href="{{ route('lights.home') }}">← Back to my lights</a>
</section>
@endsection
