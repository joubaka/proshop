<?php

namespace App\Console\Commands;

use App\InvoicePaymentAttempt;
use App\Support\InvoicePaymentCoordinator;
use Illuminate\Console\Command;

class ReviewInvoicePayments extends Command
{
    protected $signature = 'payments:review {--business= : Required business ID} {--record= : Retry local recording of a provider-confirmed attempt ID}';
    protected $description = 'List unresolved invoice payments, or retry confirmed local recording without charging a provider';

    public function handle(InvoicePaymentCoordinator $coordinator): int
    {
        $business = $this->option('business');
        if (!ctype_digit((string) $business) || (int) $business < 1) {
            $this->error('Supply an explicit positive --business ID.');
            return self::FAILURE;
        }
        if ($id = $this->option('record')) {
            $attempt = InvoicePaymentAttempt::where('business_id', $business)->whereKey($id)->first();
            if (!$attempt || !in_array($attempt->status, ['provider_succeeded', 'completed'], true)) {
                $this->error('No confirmed attempt for this business. Verify unknown outcomes with the provider; do not charge again.');
                return self::FAILURE;
            }
            try { $coordinator->record($id); } catch (\Throwable $error) {
                $this->error('Recording did not complete. The attempt is preserved for review.');
                return self::FAILURE;
            }
            $this->info('Payment recorded. No gateway request was made.');
        }
        $this->table(['Attempt', 'Invoice', 'Gateway', 'Amount', 'State', 'Provider ID'],
            InvoicePaymentAttempt::where('business_id', $business)->whereNotNull('active_transaction_id')
                ->get(['id', 'transaction_id', 'gateway', 'amount', 'status', 'provider_payment_id'])->toArray());
        return self::SUCCESS;
    }
}
