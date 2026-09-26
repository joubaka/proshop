<?php

namespace App\Shop;

use App\Shop\PayFast\Gateway;
use App\Shop\PayFast\InvalidNotification;
use App\Transaction;
use App\TransactionPayment;
use App\Utils\TransactionUtil;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class AccountPaymentService
{
    public function __construct(
        private Gateway $gateway,
        private TransactionUtil $transactions,
        private ProviderPaymentClaim $providerClaims,
    ) {}

    public function checkout(Customer $customer, Transaction $authorized): array
    {
        abort_unless(config('shop.account_payments_enabled') && config('shop.payfast.enabled'), 404);
        $attempt = DB::transaction(function () use ($customer, $authorized) {
            $invoice = Transaction::query()->where('business_id', $authorized->business_id)->lockForUpdate()->findOrFail($authorized->id);
            $owns = CustomerContactLink::query()->where('shop_customer_id', $customer->id)
                ->where('business_id', $invoice->business_id)->where('contact_id', $invoice->contact_id)
                ->where('status', 'verified')->exists();
            abort_unless($owns && $invoice->type === 'sell' && $invoice->status === 'final', 404);
            $active = AccountPaymentAttempt::query()->where('active_transaction_id', $invoice->id)->first();
            abort_if($active, 409, 'A payment is already processing or needs review for this invoice.');
            $balance = round((float) $invoice->final_total - (float) $this->transactions->getTotalPaid($invoice->id), 2);
            abort_unless($invoice->payment_status !== 'paid' && $balance > 0, 422, 'This invoice has no outstanding balance.');
            $id = (string) Str::uuid();
            return AccountPaymentAttempt::create([
                'id' => $id, 'shop_customer_id' => $customer->id,
                'business_id' => $invoice->business_id, 'contact_id' => $invoice->contact_id,
                'transaction_id' => $invoice->id, 'active_transaction_id' => $invoice->id,
                'merchant_payment_id' => 'ACCOUNT-'.$id,
                'expected_amount_cents' => (int) round($balance * 100), 'status' => 'provider_pending',
            ]);
        }, 3);
        return $this->gateway->accountCheckout($attempt, $customer);
    }

    public function receive(Request $request): string
    {
        $verified = $this->gateway->verify($request);
        return DB::transaction(function () use ($verified) {
            $attempt = AccountPaymentAttempt::query()->where('merchant_payment_id', $verified['merchant_payment_id'])->lockForUpdate()->first();
            if (!$attempt) { throw new InvalidNotification('Account payment reference is unknown.'); }
            if ($attempt->status === 'completed') { return 'already_paid'; }
            if (!in_array($attempt->status, ['provider_pending', 'customer_cancelled'], true)) { return 'requires_attention'; }
            $this->providerClaims->claim('payfast', $verified['provider_reference'], 'account_payment', $attempt->id);
            if (Payment::query()->where('provider_reference', $verified['provider_reference'])->exists()
                || AccountPaymentAttempt::query()->where('provider_reference', $verified['provider_reference'])
                    ->whereKeyNot($attempt->id)->exists()) {
                throw new InvalidNotification('PayFast reference has already been used.');
            }
            if ((int) $attempt->expected_amount_cents !== (int) $verified['amount_cents']) {
                $attempt->update([
                    'provider_reference' => $verified['provider_reference'], 'reported_amount_cents' => $verified['amount_cents'],
                    'notification_hash' => $verified['notification_hash'], 'received_at' => now(),
                    'status' => 'review_required', 'failure_reason' => 'amount_mismatch',
                ]);
                return 'amount_mismatch';
            }
            $invoice = Transaction::query()->where('business_id', $attempt->business_id)->lockForUpdate()->findOrFail($attempt->transaction_id);
            $balanceCents = (int) round(((float) $invoice->final_total - (float) $this->transactions->getTotalPaid($invoice->id)) * 100);
            if ($invoice->type !== 'sell' || $invoice->status !== 'final' || $balanceCents !== (int) $attempt->expected_amount_cents) {
                $attempt->update([
                    'provider_reference' => $verified['provider_reference'], 'reported_amount_cents' => $verified['amount_cents'],
                    'notification_hash' => $verified['notification_hash'], 'received_at' => now(),
                    'status' => 'review_required', 'failure_reason' => 'invoice_balance_changed',
                ]);
                return 'requires_attention';
            }
            $before = $invoice->replicate();
            $count = $this->transactions->setAndGetReferenceCount('sell_payment', $invoice->business_id);
            $payment = TransactionPayment::create([
                'paid_on' => now(), 'transaction_id' => $invoice->id,
                'amount' => $attempt->expected_amount_cents / 100, 'payment_for' => $invoice->contact_id,
                'method' => 'other', 'note' => 'PayFast '.$verified['provider_reference'],
                'paid_through_link' => 1, 'gateway' => 'payfast', 'business_id' => $invoice->business_id,
                'payment_ref_no' => $this->transactions->generateReferenceNumber('sell_payment', $count, $invoice->business_id),
            ]);
            $invoice->payment_status = $this->transactions->updatePaymentStatus($invoice->id, $invoice->final_total);
            $this->transactions->activityLog($invoice, 'payment_edited', $before);
            $attempt->update([
                'provider_reference' => $verified['provider_reference'], 'reported_amount_cents' => $verified['amount_cents'],
                'notification_hash' => $verified['notification_hash'], 'received_at' => now(),
                'status' => 'completed', 'active_transaction_id' => null,
                'transaction_payment_id' => $payment->id, 'completed_at' => now(), 'failure_reason' => null,
            ]);
            return 'paid';
        }, 3);
    }
}
