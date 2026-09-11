<?php

namespace App\Support;

use App\InvoicePaymentAttempt;
use App\Transaction;
use App\TransactionPayment;
use App\Utils\TransactionUtil;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class InvoicePaymentCoordinator
{
    public function __construct(private TransactionUtil $util) {}

    public function pay(Transaction $authorized, string $gateway, callable $charge): void
    {
        [$attempt, $callProvider] = DB::transaction(function () use ($authorized, $gateway) {
            $invoice = Transaction::where('business_id', $authorized->business_id)->lockForUpdate()->findOrFail($authorized->id);
            abort_unless($invoice->type === 'sell' && $invoice->status === 'final'
                && hash_equals((string) $invoice->invoice_token, (string) $authorized->invoice_token), 404);
            $active = InvoicePaymentAttempt::where('active_transaction_id', $invoice->id)->first();
            if ($active) {
                abort_unless($active->status === 'provider_succeeded', 409,
                    'Payment is processing or needs review. Do not pay again; contact the shop.');
                return [$active, false];
            }
            $balance = $invoice->final_total - $this->util->getTotalPaid($invoice->id);
            abort_unless($invoice->payment_status !== 'paid' && $balance > 0, 422, 'This invoice has no outstanding balance.');
            return [InvoicePaymentAttempt::create([
                'id' => (string) Str::uuid(), 'business_id' => $invoice->business_id,
                'transaction_id' => $invoice->id, 'active_transaction_id' => $invoice->id,
                'gateway' => $gateway, 'amount' => $balance, 'status' => 'processing',
            ]), true];
        });

        if ($callProvider) {
            try {
                // Reservation is durable BEFORE a network call. No automatic re-charge
                // after a crash/timeout, even if the provider idempotency window expires.
                $providerId = $charge($attempt);
                if (!is_string($providerId) || $providerId === '') {
                    throw new \RuntimeException('Provider did not confirm payment.');
                }
                $attempt->update(['provider_payment_id' => $providerId, 'status' => 'provider_succeeded']);
            } catch (\Throwable $error) {
                try { $attempt->update(['status' => 'review_required']); } catch (\Throwable $ignored) {
                    // The already committed processing reservation still prevents retry.
                }
                throw new \RuntimeException('Payment needs review. Do not pay again; contact the shop.', 0, $error);
            }
        }

        $this->record($attempt->id);
    }

    // Retry only the local recording step: this method never calls a gateway.
    public function record(string $id): void
    {
        $attempt = InvoicePaymentAttempt::findOrFail($id);
        DB::transaction(function () use ($attempt) {
            $invoice = Transaction::where('business_id', $attempt->business_id)->lockForUpdate()->findOrFail($attempt->transaction_id);
            $attempt = InvoicePaymentAttempt::lockForUpdate()->findOrFail($attempt->id);
            if ($attempt->status === 'completed') { return; }
            abort_unless($attempt->status === 'provider_succeeded' && $attempt->provider_payment_id, 409, 'Provider outcome requires manual verification.');
            $balance = $invoice->final_total - $this->util->getTotalPaid($invoice->id);
            abort_unless($invoice->type === 'sell' && $invoice->status === 'final'
                && $balance + 0.00001 >= (float) $attempt->amount, 409,
                'Invoice balance changed. Reconcile the confirmed charge with the shop before continuing.');
            $before = $invoice->replicate();
            $count = $this->util->setAndGetReferenceCount('sell_payment', $invoice->business_id);
            $payment = TransactionPayment::create([
                'paid_on' => now()->toDateTimeString(), 'transaction_id' => $invoice->id,
                'amount' => $attempt->amount, 'payment_for' => $invoice->contact_id,
                'method' => 'cash', 'note' => $attempt->provider_payment_id,
                'paid_through_link' => 1, 'gateway' => $attempt->gateway,
                'business_id' => $invoice->business_id,
                'payment_ref_no' => $this->util->generateReferenceNumber('sell_payment', $count, $invoice->business_id),
            ]);
            $invoice->payment_status = $this->util->updatePaymentStatus($invoice->id, $invoice->final_total);
            $this->util->activityLog($invoice, 'payment_edited', $before);
            $attempt->update(['status' => 'completed', 'active_transaction_id' => null, 'transaction_payment_id' => $payment->id]);
        });
    }
}
