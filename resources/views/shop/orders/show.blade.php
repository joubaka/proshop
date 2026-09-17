@extends('shop.layout')
@section('title', 'Order '.$order->order_number.' · '.$channel->name)
@section('content')
<div class="order-confirmation">
<p class="eyebrow">{{ $order->payment_status === 'paid' ? 'ORDER CONFIRMED' : 'ORDER RESERVED' }}</p><h1>Thank you, {{ $order->customer_name }}.</h1>
@if($order->payment_status === 'paid')<p>Payment for <strong>{{ $order->order_number }}</strong> is confirmed. We will prepare it for {{ strtolower($order->fulfilment_label) }}.</p>
@elseif($order->payment_status === 'pending')<p>Your order <strong>{{ $order->order_number }}</strong> is awaiting payment. Stock is reserved until {{ $order->reservation_expires_at->format('H:i') }}.</p>
@elseif($order->payment_status === 'payment_exception')<p>We received a payment update for <strong>{{ $order->order_number }}</strong> that needs staff review. Please do not pay again.</p>
@else<p>Order <strong>{{ $order->order_number }}</strong> is {{ str_replace('_', ' ', $order->order_status) }}.</p>@endif
@if(session('payment_cancelled'))<p>{{ session('payment_cancelled') }}</p>@endif @if($errors->any())<p>{{ $errors->first() }}</p>@endif
<div class="order-summary"><h2>Order summary</h2>@foreach($order->items as $item)<div><span>{{ $item->quantity }} × {{ $item->product_name }} {{ $item->variation_name }}</span><strong>R {{ number_format($item->line_total_cents / 100, 2) }}</strong></div>@endforeach<div class="summary-total"><span>Total</span><strong>R {{ number_format($order->total_cents / 100, 2) }}</strong></div><p>Payment status: {{ ucfirst(str_replace('_', ' ', $order->payment_status)) }}</p>@if($payUrl)<form method="post" action="{{ $payUrl }}">@csrf<button class="shop-button" type="submit">Pay securely with PayFast</button></form>@endif</div></div>
@endsection
