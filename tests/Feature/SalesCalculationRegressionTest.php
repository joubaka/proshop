<?php

namespace Tests\Feature;

use App\Utils\ProductUtil;
use App\Utils\Util;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\RegressionTestCase;

class SalesCalculationRegressionTest extends RegressionTestCase
{
    public static function invoices(): array
    {
        return [
            'no discount' => [null, 0, 200],
            'fixed discount' => [['discount_type' => 'fixed', 'discount_amount' => 25], 25, 175],
            'percentage discount' => [['discount_type' => 'percentage', 'discount_amount' => 10], 20, 180],
            'fully discounted' => [['discount_type' => 'percentage', 'discount_amount' => 100], 200, 0],
        ];
    }

    #[DataProvider('invoices')]
    public function test_invoice_discount_calculation(?array $discount, float $amount, float $total): void
    {
        $result = (new ProductUtil)->calculateInvoiceTotal([
            ['unit_price_inc_tax' => 80, 'quantity' => 2],
            ['unit_price_inc_tax' => 40, 'quantity' => 1],
        ], null, $discount, false);
        $this->assertEqualsWithDelta(200, $result['total_before_tax'], 0.00001);
        $this->assertEqualsWithDelta($amount, $result['discount'], 0.00001);
        $this->assertEqualsWithDelta($total, $result['final_total'], 0.00001);
    }

    public function test_invoice_level_tax_is_applied_after_discount(): void
    {
        Schema::create('tax_rates', function ($table) {
            $table->id(); $table->decimal('amount', 8, 4); $table->softDeletes();
        });
        DB::table('tax_rates')->insert(['id' => 1, 'amount' => 15]);
        $result = (new ProductUtil)->calculateInvoiceTotal([
            ['unit_price_inc_tax' => 100, 'quantity' => 2],
        ], 1, ['discount_type' => 'fixed', 'discount_amount' => 20], false);
        $this->assertEqualsWithDelta(27, $result['tax'], 0.00001);
        $this->assertEqualsWithDelta(207, $result['final_total'], 0.00001);
    }

    public function test_modifiers_and_fractional_quantities_are_included(): void
    {
        $result = (new ProductUtil)->calculateInvoiceTotal([
            ['unit_price_inc_tax' => 20, 'quantity' => 1.5, 'modifier_price' => [5, 2], 'modifier_quantity' => [2, 3]],
        ], null, null, false);
        $this->assertEqualsWithDelta(46, $result['final_total'], 0.00001);
    }

    public function test_empty_invoice_is_not_treated_as_a_sale(): void
    {
        $this->assertFalse((new ProductUtil)->calculateInvoiceTotal([], null));
    }

    public function test_localised_number_round_trip_and_separate_quantity_precision(): void
    {
        $util = new Util;
        $currency = (object) ['thousand_separator' => '.', 'decimal_separator' => ','];
        $this->assertEqualsWithDelta(1234.56, $util->num_uf('1.234,56', $currency), 0.00001);
        config(['constants.currency_precision' => 2, 'constants.quantity_precision' => 3]);
        $this->assertSame('1.234,56', $util->num_f(1234.56, false, $currency));
        $this->assertSame('1,235', $util->num_f(1.23456, false, $currency, true));
        $this->assertEqualsWithDelta(100, $util->calc_percentage_base(115, 15), 0.00001);
        $this->assertEquals(0, $util->get_percent(0, 100));
    }
}
