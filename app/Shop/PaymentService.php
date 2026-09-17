<?php

namespace App\Shop;

use App\Shop\PayFast\Gateway;
use App\Shop\PayFast\InvalidNotification;
use App\Shop\Exceptions\StockUnavailableAfterPayment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class PaymentService
{
    public function __construct(private Gateway $gateway, private PaidOrderFinalizer $finalizer) {}

    public function checkout(Order $order): array
    {
        $payment = DB::transaction(function () use ($order) {
            $order = Order::query()->whereKey($order->id)->lockForUpdate()->firstOrFail();
            if ($order->payment_status === 'paid') {
                throw ValidationException::withMessages(['payment' => 'This order has already been paid.']);
            }
            if ($order->payment_status !== 'pending' || $order->reservation_expires_at->isPast()) {
                throw ValidationException::withMessages(['payment' => 'The payment window for this order has expired.']);
            }
            $existing = Payment::query()->where('shop_order_id', $order->id)->where('status', 'pending')->latest('attempt')->first();
            if ($existing) {
                return $existing;
            }
            $attempt = ((int) Payment::query()->where('shop_order_id', $order->id)->max('attempt')) + 1;
            $uuid = (string) Str::uuid();
            return Payment::create([
                'uuid' => $uuid, 'shop_order_id' => $order->id, 'gateway' => 'payfast',
                'attempt' => $attempt, 'merchant_payment_id' => $uuid,
                'expected_amount_cents' => $order->total_cents, 'status' => 'pending',
            ]);
        }, 3);

        return $this->gateway->checkout($payment, $order->fresh());
    }

    public function receive(Request $request): string
    {
        $verified = $this->gateway->verify($request);

        return DB::transaction(function () use ($verified) {
            $reference = Payment::query()->where('merchant_payment_id', $verified['merchant_payment_id'])
                ->first(['id', 'shop_order_id']);
            if (!$reference) {
                throw new InvalidNotification('Payment reference is unknown.');
            }
            $order = Order::query()->whereKey($reference->shop_order_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::query()->whereKey($reference->id)->lockForUpdate()->firstOrFail();

            if ($payment->status === 'paid' && $order->payment_status === 'paid') {
                return 'already_paid';
            }
            $duplicateReference = Payment::query()->where('provider_reference', $verified['provider_reference'])
                ->whereKeyNot($payment->id)->exists();
            if ($duplicateReference) {
                throw new InvalidNotification('PayFast reference has already been used.');
            }
            if ((int) $verified['amount_cents'] !== (int) $payment->expected_amount_cents
                || (int) $verified['amount_cents'] !== (int) $order->total_cents) {
                $this->recordException($payment, $order, $verified, 'amount_mismatch');
                return 'amount_mismatch';
            }

            $payment->fill([
                'provider_reference' => $verified['provider_reference'],
                'reported_amount_cents' => $verified['amount_cents'],
                'signature_verified' => true, 'server_verified' => true,
                'notification_hash' => $verified['notification_hash'], 'received_at' => now(),
            ]);

            $activeReservations = $order->reservations()->where('status', 'active')
                ->where('expires_at', '>', now())->count();
            if ($order->reservation_expires_at->isPast() || $activeReservations !== $order->items()->count()) {
                $this->recordException($payment, $order, $verified, 'reservation_expired');
                return 'requires_attention';
            }

            try {
                $this->finalizer->finalize($order->load(['items', 'reservations', 'channel']), $payment);
            } catch (StockUnavailableAfterPayment $e) {
                $this->recordException($payment, $order, $verified, 'stock_unavailable');
                return 'requires_attention';
            }
            $payment->fill(['status' => 'paid', 'failure_reason' => null, 'finalized_at' => now()])->save();
            $order->update(['payment_status' => 'paid', 'order_status' => 'confirmed', 'paid_at' => now()]);
            $order->reservations()->where('status', 'active')->update(['status' => 'confirmed', 'confirmed_at' => now()]);
            $order->events()->create([
                'event_type' => 'payment_confirmed',
                'metadata' => ['gateway' => 'payfast', 'provider_reference' => $verified['provider_reference']],
            ]);

            return 'paid';
        }, 3);
    }

    private function recordException(Payment $payment, Order $order, array $verified, string $reason): void
    {
        $payment->fill([
            'provider_reference' => $verified['provider_reference'],
            'reported_amount_cents' => $verified['amount_cents'],
            'signature_verified' => true, 'server_verified' => true,
            'notification_hash' => $verified['notification_hash'], 'received_at' => now(),
            'status' => 'requires_attention', 'failure_reason' => $reason,
        ])->save();
        $order->update(['payment_status' => 'payment_exception', 'order_status' => 'payment_review']);
        $order->events()->create(['event_type' => 'payment_exception', 'metadata' => ['reason' => $reason]]);
    }
}
