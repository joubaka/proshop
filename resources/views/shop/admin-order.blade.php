@extends('layouts.app')
@section('title', 'Online order '.$order->order_number)
@section('content')
<section class="content-header"><h1>{{ $order->order_number }}</h1><p>{{ $order->customer_name }} · {{ $order->customer_email }} · {{ $order->customer_mobile }}</p></section>
<section class="content">
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="row"><div class="col-md-8"><div class="box box-primary"><div class="box-header"><h3 class="box-title">Items</h3></div><div class="box-body table-responsive">
<table class="table table-bordered"><thead><tr><th>Item</th><th>SKU</th><th>Qty</th><th>Total</th></tr></thead><tbody>@foreach($order->items as $item)<tr><td>{{ $item->product_name }} {{ $item->variation_name }}</td><td>{{ $item->sku }}</td><td>{{ $item->quantity }}</td><td>R {{ number_format($item->line_total_cents / 100, 2) }}</td></tr>@endforeach</tbody><tfoot><tr><th colspan="3">Order total</th><th>R {{ number_format($order->total_cents / 100, 2) }}</th></tr></tfoot></table>
</div></div></div><div class="col-md-4"><div class="box box-info"><div class="box-header"><h3 class="box-title">Status</h3></div><div class="box-body">
<p><strong>Payment:</strong> {{ ucfirst(str_replace('_', ' ', $order->payment_status)) }}</p><p><strong>Order:</strong> {{ ucfirst(str_replace('_', ' ', $order->order_status)) }}</p><p><strong>Fulfilment:</strong> {{ ucfirst(str_replace('_', ' ', $order->fulfilment_status)) }}</p><p><strong>Method:</strong> {{ $order->fulfilment_label }}</p>
@if($order->payment_status === 'payment_exception')@php($exceptionPayment = $order->payments->firstWhere('status', 'requires_attention'))<div class="alert alert-warning"><strong>Payment review required:</strong> {{ str_replace('_', ' ', $exceptionPayment?->failure_reason ?? 'unknown') }}.@if($canReview) <a href="{{ route('shop.admin.payment-reviews.index') }}">Open payment reviews</a>@endif</div>@endif
@if($canFulfil && $order->payment_status === 'paid' && $order->fulfilment_status === 'not_ready')<form method="post" action="{{ route('shop.admin.orders.ready', $order->uuid) }}">@csrf<button class="btn btn-success btn-block">Mark ready for collection</button></form>@endif
@if($canFulfil && $order->payment_status === 'paid' && $order->fulfilment_status === 'ready_for_collection')<form method="post" action="{{ route('shop.admin.orders.collected', $order->uuid) }}">@csrf<button class="btn btn-success btn-block">Mark collected</button></form>@endif
@if($canFulfil && $order->payment_status === 'pending')<hr><form method="post" action="{{ route('shop.admin.orders.cancel', $order->uuid) }}">@csrf<label>Cancellation reason<input class="form-control" name="reason" minlength="5" maxlength="500" required></label><button class="btn btn-danger btn-block" type="submit">Cancel unpaid order</button></form>@endif
</div></div></div></div>
<div class="box"><div class="box-header"><h3 class="box-title">Audit trail</h3></div><div class="box-body"><ul>@foreach($order->events->sortByDesc('created_at') as $event)<li>{{ $event->created_at->format('Y-m-d H:i:s') }} — {{ str_replace('_', ' ', $event->event_type) }}</li>@endforeach</ul></div></div>
</section>
@endsection
