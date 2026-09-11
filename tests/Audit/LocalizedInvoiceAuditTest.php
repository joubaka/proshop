<?php

namespace Tests\Audit;

use App\Utils\ProductUtil;
use Tests\Support\RegressionTestCase;

class LocalizedInvoiceAuditTest extends RegressionTestCase
{
    public function test_comma_decimal_modifier_prices_are_unformatted_exactly_once(): void
    {
        session()->put('currency', ['thousand_separator' => '.', 'decimal_separator' => ',']);
        $result = (new ProductUtil)->calculateInvoiceTotal([
            ['unit_price_inc_tax' => '10,00', 'quantity' => '1',
                'modifier_price' => ['1,50'], 'modifier_quantity' => [2]],
        ], null);
        $this->assertEqualsWithDelta(13, $result['final_total'], 0.00001,
            'A 10.00 item plus two 1.50 modifiers must total 13.00, not 40.00.');
    }
}
