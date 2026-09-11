<?php

namespace Tests\Unit;

use App\InvoiceScanning\InvoiceAmounts;
use PHPUnit\Framework\TestCase;
use RuntimeException;

class InvoiceAmountsTest extends TestCase
{
    public function test_it_normalises_pack_price_excluding_tax(): void
    {
        $costs = (new InvoiceAmounts)->unitCosts(480, 24, 15, false);
        $this->assertEqualsWithDelta(20, $costs['exclusive'], .0001);
        $this->assertEqualsWithDelta(23, $costs['inclusive'], .0001);
        $this->assertEqualsWithDelta(3, $costs['tax'], .0001);
    }

    public function test_it_normalises_pack_price_including_tax(): void
    {
        $costs = (new InvoiceAmounts)->unitCosts(552, 24, 15, true);
        $this->assertEqualsWithDelta(20, $costs['exclusive'], .0001);
        $this->assertEqualsWithDelta(23, $costs['inclusive'], .0001);
    }

    public function test_it_reconciles_discount_vat_and_freight(): void
    {
        $amounts = new InvoiceAmounts;
        $this->assertTrue($amounts->isBalanced(1000, 100, 135, 50, 1085));
        $this->assertFalse($amounts->isBalanced(1000, 100, 135, 50, 1100));
    }

    public function test_it_rejects_an_invalid_pack_size(): void
    {
        $this->expectException(RuntimeException::class);
        (new InvoiceAmounts)->unitCosts(100, 0, 15, false);
    }

    public function test_it_reconciles_exclusive_and_tax_inclusive_invoice_lines(): void
    {
        $amounts=new InvoiceAmounts;
        $this->assertTrue($amounts->isLineBalanced(2,100,15,false,200));
        $this->assertTrue($amounts->isLineBalanced(2,115,15,true,200));
        $this->assertFalse($amounts->isLineBalanced(2,115,15,true,230));
    }
}
