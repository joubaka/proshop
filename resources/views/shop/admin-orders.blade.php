@extends('layouts.app')
@section('title', 'Online orders')
@section('content')
<section class="content-header"><h1>Online orders</h1><p>Payment and collection queue for the native ProShop storefront.</p>@can('customer.update')<a class="btn btn-default" href="{{ route('shop.admin.customer-links.index') }}">Customer account links</a>@endcan @if($canReview)<a class="btn btn-warning" href="{{ route('shop.admin.payment-reviews.index') }}">Payment reviews</a>@endif</section>
<section class="content">
<div class="box box-primary"><div class="box-body table-responsive">
<table class="table table-bordered table-striped"><thead><tr><th>Order</th><th>Customer</th><th>Placed</th><th>Total</th><th>Payment</th><th>Fulfilment</th><th></th></tr></thead><tbody>
@forelse($orders as $order)<tr>
<td>{{ $order->order_number }}</td><td>{{ $order->customer_name }}<br><small>{{ $order->customer_mobile }}</small></td>
<td>{{ $order->created_at->format('Y-m-d H:i') }}</td><td>R {{ number_format($order->total_cents / 100, 2) }}</td>
<td>{{ ucfirst(str_replace('_', ' ', $order->payment_status)) }}</td><td>{{ ucfirst(str_replace('_', ' ', $order->fulfilment_status)) }}</td>
<td><a class="btn btn-sm btn-primary" href="{{ route('shop.admin.orders.show', $order->uuid) }}">Open</a></td>
</tr>@empty<tr><td colspan="7">No online orders yet.</td></tr>@endforelse
</tbody></table></div><div class="box-footer">{{ $orders->links() }}</div></div>
</section>
@endsection
