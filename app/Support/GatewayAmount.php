<?php

namespace App\Support;

class GatewayAmount
{
    public static function stripe($amount, string $currency): int
    {
        $currency = strtolower($currency);
        $zeroDecimal = ['bif', 'clp', 'djf', 'gnf', 'jpy', 'kmf', 'krw', 'mga', 'pyg', 'rwf', 'vnd', 'vuv', 'xaf', 'xof', 'xpf'];
        if (in_array($currency, ['isk', 'ugx'], true) && abs((float) $amount - round((float) $amount)) > 0.000001) {
            throw new \InvalidArgumentException('Currency does not support fractional charges.');
        }
        return self::minorUnits($amount, in_array($currency, $zeroDecimal, true) ? 1 : 100);
    }

    public static function minorUnits($amount, int $multiplier = 100): int
    {
        $minor = (float) $amount * $multiplier;
        if (!is_finite($minor) || $minor <= 0 || $minor >= PHP_INT_MAX || abs($minor - round($minor)) > 0.000001) {
            throw new \InvalidArgumentException('Amount cannot be represented exactly in gateway minor units.');
        }
        return (int) round($minor);
    }
}
