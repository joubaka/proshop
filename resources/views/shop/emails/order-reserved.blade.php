<p>Hello {{ $order->customer_name }},</p>
<p>Your order <strong>{{ $order->order_number }}</strong> has been reserved for {{ strtolower($order->fulfilment_label) }}.</p>
<p>Total: <strong>R {{ number_format($order->total_cents / 100, 2) }}</strong></p>
<p><a href="{{ $orderUrl }}">Open your order and payment page</a></p>
<p>The payment option is available only while the stock reservation remains active.</p>
