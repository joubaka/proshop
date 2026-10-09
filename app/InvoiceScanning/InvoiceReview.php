<?php

namespace App\InvoiceScanning;

use App\InvoiceScan;
use App\InvoiceScanLine;

class InvoiceReview
{
    public function __construct(private InvoiceAmounts $amounts) {}

    public function issues(InvoiceScan $scan): array
    {
        $header = [];
        if (!$scan->supplier_id) $header[] = 'Choose the supplier: no supplier was matched.';
        if (!$scan->invoice_number) $header[] = 'Enter the slip or invoice reference.';
        if (!$scan->invoice_date) $header[] = 'Confirm the purchase date.';
        if (!$scan->reviewed_at && ($scan->confidence === null || $scan->confidence < .8)) $header[] = 'Check the original slip: some details may be unclear.';
        foreach (['subtotal', 'tax_total', 'invoice_total'] as $field) {
            if ($scan->$field === null) $header[] = 'Confirm the '.str_replace('_', ' ', $field).'.';
        }
        if (!$this->amounts->isBalanced((float) $scan->subtotal, (float) $scan->discount_total, (float) $scan->tax_total, (float) $scan->freight_total, (float) $scan->invoice_total)) {
            $header[] = 'Invoice totals do not balance. Check discount, VAT and freight against the slip.';
        }
        $sum = $scan->lines->sum(fn ($line) => (float) $line->line_total);
        if (abs($sum - (float) $scan->subtotal) > max(.05, abs($sum) * .002)) $header[] = 'Product line subtotals do not add up to the invoice subtotal.';
        if ($scan->lines->isEmpty()) $header[] = 'No stock items were extracted. Try a clearer photo or use Add Purchase.';

        return ['header' => $header, 'lines' => $scan->lines->mapWithKeys(fn ($line) => [$line->id => $this->lineIssues($line)])->all()];
    }

    private function lineIssues(InvoiceScanLine $line): array
    {
        $issues = [];
        if (!$line->variation_id) $issues[] = 'Choose the stock product.';
        if ($line->match_method !== 'reviewed') {
            if ($line->confidence === null || $line->confidence < .8) $issues[] = 'Check unclear text or numbers against the slip.';
            if ($line->variation_id && ($line->match_confidence === null || $line->match_confidence < .95)) $issues[] = 'Confirm the suggested product match.';
            if ($line->match_method !== 'supplier_mapping') $issues[] = 'Confirm pack size: how many stock units are in each purchased pack?';
        }
        if ($line->quantity === null || $line->quantity <= 0) $issues[] = 'Enter a positive quantity.';
        if ($line->pack_size === null || $line->pack_size <= 0) $issues[] = 'Enter a positive pack size.';
        if ($line->unit_price === null || $line->unit_price < 0) $issues[] = 'Confirm the unit cost.';
        if ($line->line_total === null || !$this->amounts->isLineBalanced((float) $line->quantity, (float) $line->unit_price, (float) $line->tax_rate, (bool) $line->price_includes_tax, (float) $line->line_total)) $issues[] = 'Check quantity, cost and VAT: the line subtotal does not balance.';
        $oldCost = (float) optional($line->variation)->default_purchase_price;
        if ($oldCost > 0 && $line->pack_size > 0 && $line->unit_price !== null) {
            $cost = $this->amounts->unitCosts(max(0, (float) $line->unit_price), (float) $line->pack_size, max(0, (float) $line->tax_rate), (bool) $line->price_includes_tax)['exclusive'];
            if (abs(($cost - $oldCost) / $oldCost * 100) >= config('invoice_scanning.price_change_warning_percent', 10)) $issues[] = 'Purchase cost differs significantly from the previous cost.';
        }
        return $issues;
    }
}
