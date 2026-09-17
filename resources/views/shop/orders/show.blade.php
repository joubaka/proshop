@extends('shop.layout')
@section('title', 'Order '.$order->order_number.' · '.$channel->name)
@section('content')
<div class="order-confirmation"><p class="eyebrow">ORDER RESERVED</p><h1>Thank you, {{ $order->customer_name }}.</h1><p>Your order <strong>{{ $order->order_number }}</strong> is awaiting payment. Stock is reserved until {{ $order->reservation_expires_at->format('H:i') }}.</p><div class="order-summary"><h2>Order summary</h2>@foreach($order->items as $item)<div><span>{{ $item->quantity }} × {{ $item->product_name }} {{ $item->variation_name }}</span><strong>R {{ number_format($item->line_total_cents / 100, 2) }}</strong></div>@endforeach<div class="summary-total"><span>Total</span><strong>R {{ number_format($order->total_cents / 100, 2) }}</strong></div><p>Payment status: {{ ucfirst($order->payment_status) }}</p></div></div>
@endsection
