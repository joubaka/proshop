<?php

namespace App\InvoiceScanning;

use RuntimeException;

class InvoiceAmounts
{
    public function unitCosts(float $invoiceUnitPrice, float $packSize, float $taxRate, bool $includesTax): array
    {
        if ($packSize <= 0 || $invoiceUnitPrice < 0 || $taxRate < 0) {
            throw new RuntimeException('Invoice cost, pack size or tax rate is invalid.');
        }
        $price = $invoiceUnitPrice / $packSize;
        $inclusive = $includesTax ? $price : $price * (1 + $taxRate / 100);
        $exclusive = $includesTax && $taxRate > 0 ? $price / (1 + $taxRate / 100) : $price;
        return ['exclusive' => $exclusive, 'inclusive' => $inclusive, 'tax' => max(0, $inclusive - $exclusive)];
    }

    public function isBalanced(float $subtotal, float $discount, float $tax, float $freight, float $total, float $tolerance = .05): bool
    {
        return abs(($subtotal - $discount + $tax + $freight) - $total) <= $tolerance;
    }

    public function lineSubtotal(float $quantity, float $invoiceUnitPrice, float $taxRate, bool $includesTax): float
    {
        $unit = $includesTax && $taxRate > 0 ? $invoiceUnitPrice / (1 + $taxRate / 100) : $invoiceUnitPrice;
        return $quantity * $unit;
    }

    public function isLineBalanced(float $quantity, float $invoiceUnitPrice, float $taxRate, bool $includesTax, float $lineTotal): bool
    {
        $expected=$this->lineSubtotal($quantity,$invoiceUnitPrice,$taxRate,$includesTax);
        return abs($expected-$lineTotal)<=max(.05,abs($expected)*.002);
    }
}
