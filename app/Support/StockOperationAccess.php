<?php

namespace App\Support;

use App\BusinessLocation;
use App\Transaction;

class StockOperationAccess
{
    public static function location($id): void
    {
        abort_unless(BusinessLocation::where('business_id', session('user.business_id'))->whereKey($id)->exists(), 404);
        $allowed = auth()->user()->permitted_locations();
        abort_unless($allowed === 'all' || in_array($id, $allowed), 403);
    }

    public static function transaction($id, string $type): Transaction
    {
        $transaction = Transaction::where('business_id', session('user.business_id'))->where('type', $type)->findOrFail($id);
        self::location($transaction->location_id);
        return $transaction;
    }

    public static function products(array $products, $sourceLocation, ?Transaction $transaction = null): void
    {
        foreach ($products as $input) {
            $product = \App\Product::where('business_id', session('user.business_id'))->findOrFail($input['product_id'] ?? null);
            $variation = $product->variations()->findOrFail($input['variation_id'] ?? null);
            if (!empty($input['lot_no_line_id'])) {
                abort_unless(\App\PurchaseLine::whereKey($input['lot_no_line_id'])->where('variation_id', $variation->id)
                    ->whereHas('transaction', fn ($query) => $query->where('business_id', session('user.business_id'))->where('location_id', $sourceLocation))
                    ->exists(), 404);
            }
            if (!empty($input['transaction_sell_lines_id'])) {
                abort_unless($transaction && $transaction->sell_lines()->whereKey($input['transaction_sell_lines_id'])
                    ->where('variation_id', $variation->id)->exists(), 404);
            }
        }
    }
}
