<?php

namespace Tests\Feature;

use App\InvoiceScan;
use App\InvoiceScanLine;
use App\InvoiceScanning\InvoiceReview;
use App\Variation;
use Tests\TestCase;

class InvoiceReviewTest extends TestCase
{
    private function review(array $attributes = [], array $header = []): array
    {
        $scan = new InvoiceScan($header + ['supplier_id' => 1, 'invoice_number' => 'SLIP-1', 'invoice_date' => '2026-10-09', 'confidence' => .99, 'subtotal' => 100, 'tax_total' => 15, 'invoice_total' => 115]);
        $line = new InvoiceScanLine($attributes + ['variation_id' => 1, 'quantity' => 2, 'pack_size' => 1, 'unit_price' => 57.5, 'price_includes_tax' => true, 'tax_rate' => 15, 'line_total' => 100, 'confidence' => .99, 'match_method' => 'supplier_mapping', 'match_confidence' => 1]);
        $line->id = 1;
        $line->setRelation('variation', new Variation(['default_purchase_price' => 50]));
        $scan->setRelation('lines', collect([$line]));
        return app(InvoiceReview::class)->issues($scan);
    }

    public function test_known_pack_and_tax_inclusive_cost_do_not_create_false_warnings(): void
    {
        $this->assertSame(['header' => [], 'lines' => [1 => []]], $this->review());
    }

    public function test_ambiguous_matching_and_unlearned_packs_need_attention(): void
    {
        $issues = $this->review(['match_method' => 'description', 'match_confidence' => .89, 'confidence' => .6])['lines'][1];
        $this->assertCount(3, $issues);
        $this->assertStringContainsString('product match', implode(' ', $issues));
        $this->assertStringContainsString('pack size', implode(' ', $issues));
    }

    public function test_missing_values_and_inconsistent_totals_are_flagged(): void
    {
        $review = $this->review(['variation_id' => null, 'quantity' => null, 'unit_price' => null, 'line_total' => null], ['supplier_id' => null, 'invoice_total' => 200]);
        $this->assertStringContainsString('Choose the stock product', implode(' ', $review['lines'][1]));
        $this->assertStringContainsString('unit cost', implode(' ', $review['lines'][1]));
        $this->assertStringContainsString('do not balance', implode(' ', $review['header']));
    }

    public function test_reviewed_lines_clear_reading_prompts_but_retain_arithmetic_warnings(): void
    {
        $issues = $this->review(['match_method' => 'reviewed', 'confidence' => .2, 'line_total' => 90])['lines'][1];
        $this->assertCount(1, $issues);
        $this->assertStringContainsString('does not balance', $issues[0]);
    }
}
