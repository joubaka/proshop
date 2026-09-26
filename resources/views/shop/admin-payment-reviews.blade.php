@extends('layouts.app')
@section('title', 'Online payment reviews')
@section('content')
<section class="content-header"><h1>Online payment reviews</h1><p>Verified exceptions stay quarantined until their amount and fulfilment state are safe to reconcile.</p></section>
<section class="content">
@if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
<div class="box box-primary"><div class="box-header"><h3 class="box-title">Order payments</h3></div><div class="box-body table-responsive"><table class="table table-bordered"><thead><tr><th>Order</th><th>Location</th><th>Expected</th><th>Reported</th><th>Reason</th><th>Provider reference</th><th></th></tr></thead><tbody>
@forelse($orders as $order)@php($payment = $order->payments->first())<tr><td><a href="{{ route('shop.admin.orders.show', $order->uuid) }}">{{ $order->order_number }}</a></td><td>{{ $order->channel->name }}</td><td>R {{ number_format(($payment?->expected_amount_cents ?? 0) / 100, 2) }}</td><td>R {{ number_format(($payment?->reported_amount_cents ?? 0) / 100, 2) }}</td><td>{{ str_replace('_', ' ', $payment?->failure_reason ?? 'unknown') }}</td><td>{{ $payment?->provider_reference }}</td><td>@if($payment && $payment->signature_verified && $payment->server_verified && $payment->reported_amount_cents === $payment->expected_amount_cents)<form method="POST" action="{{ route('shop.admin.payment-reviews.orders.retry', $payment) }}">@csrf<button class="btn btn-warning btn-sm">Retry safe finalization</button></form>@else<span class="text-muted">External reconciliation required</span>@endif</td></tr>@empty<tr><td colspan="7">No order payments need review.</td></tr>@endforelse
</tbody></table>{{ $orders->links() }}</div></div>
<div class="box box-info"><div class="box-header"><h3 class="box-title">Customer invoice payments</h3></div><div class="box-body table-responsive"><table class="table table-bordered"><thead><tr><th>Customer</th><th>Invoice</th><th>Expected</th><th>Reported</th><th>Reason</th><th>Provider reference</th></tr></thead><tbody>
@forelse($accountAttempts as $attempt)<tr><td>{{ $attempt->customer?->name }}</td><td>{{ $attempt->transaction?->invoice_no ?: '#'.$attempt->transaction_id }}</td><td>R {{ number_format($attempt->expected_amount_cents / 100, 2) }}</td><td>R {{ number_format(($attempt->reported_amount_cents ?? 0) / 100, 2) }}</td><td>{{ str_replace('_', ' ', $attempt->failure_reason ?? 'unknown') }}</td><td>{{ $attempt->provider_reference }}</td></tr>@empty<tr><td colspan="6">No invoice payments need review.</td></tr>@endforelse
</tbody></table>{{ $accountAttempts->links() }}</div></div>
</section>
@endsection
