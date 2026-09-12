<?php

namespace App\Jobs;

use App\InvoiceScan;
use App\InvoiceScanning\InvoiceExtractorManager;
use App\InvoiceScanning\InvoiceMatcher;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Throwable;

class ProcessInvoiceScan implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 2;
    public int $timeout = 120;

    public function __construct(public int $scanId) {}

    public function handle(InvoiceExtractorManager $extractors, InvoiceMatcher $matcher): void
    {
        $scan = InvoiceScan::findOrFail($this->scanId);
        if ($scan->status === 'posted') return;

        $claimed = InvoiceScan::whereKey($this->scanId)
            ->whereIn('status', ['uploaded', 'queued', 'failed', 'needs_review', 'ready'])
            ->update(['status' => 'processing', 'failure_message' => null, 'provider' => config('invoice_scanning.driver')]);
        if (!$claimed) return;

        $scan->refresh();
        try {
            $payload = $extractors->driver()->extract($scan);
            $date = !empty($payload['invoice_date']) ? Carbon::parse($payload['invoice_date'])->toDateString() : null;
            DB::transaction(function () use ($matcher, $payload, $date) {
                $scan = InvoiceScan::whereKey($this->scanId)->lockForUpdate()->firstOrFail();
                if ($scan->status !== 'processing') return;
                $scan->fill([
                    'supplier_name' => $payload['supplier_name'] ?? null,
                    'supplier_tax_number' => $payload['supplier_tax_number'] ?? null,
                    'invoice_number' => $payload['invoice_number'] ?? null,
                    'invoice_date' => $date,
                    'currency' => $payload['currency'] ?? null,
                    'subtotal' => $payload['subtotal'] ?? null,
                    'discount_total' => $payload['discount_total'] ?? 0,
                    'tax_total' => $payload['tax_total'] ?? null,
                    'freight_total' => $payload['freight_total'] ?? 0,
                    'invoice_total' => $payload['invoice_total'] ?? null,
                    'confidence' => $payload['confidence'] ?? null,
                    'provider_reference' => $payload['_provider_reference'] ?? null,
                    'extracted_payload' => $payload,
                    'processed_at' => now(),
                    'status' => 'needs_review',
                ])->save();
                $matcher->match($scan, $payload);
            });
        } catch (Throwable $e) {
            InvoiceScan::whereKey($this->scanId)->where('status', 'processing')->update(['status' => 'failed', 'failure_message' => mb_substr($e->getMessage(), 0, 2000)]);
            throw $e;
        }
    }
}
