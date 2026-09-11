<?php

namespace App\InvoiceScanning;

use App\Contact;
use App\InvoiceScan;
use App\InvoiceScanLine;
use App\Product;
use App\SupplierProductMapping;
use App\Variation;
use Illuminate\Support\Str;

class InvoiceMatcher
{
    public function __construct(private InvoiceAmounts $amounts) {}

    public function match(InvoiceScan $scan, array $payload): void
    {
        $supplier = $this->matchSupplier($scan->business_id, $payload);
        if ($supplier) {
            $scan->supplier_id = $supplier->id;
        }

        $scan->lines()->delete();
        foreach ($payload['lines'] ?? [] as $index => $item) {
            $match = $this->matchVariation($scan->business_id, $supplier?->id, $item);
            $variation = $match['variation'];
            $packSize = (float) ($match['mapping']?->pack_size ?? 1);
            $costs = $this->amounts->unitCosts((float) ($item['unit_price'] ?? 0), $packSize ?: 1, (float) ($item['tax_rate'] ?? 0), (bool) ($item['price_includes_tax'] ?? false));
            $newCost = $costs['inclusive'];
            $proposedSell = null;
            if ($variation && $newCost > 0) {
                $proposedSell = round($newCost * (1 + ((float) $variation->profit_percent / 100)), 2);
            }

            InvoiceScanLine::create([
                'invoice_scan_id' => $scan->id,
                'variation_id' => $variation?->id,
                'supplier_item_code' => $item['supplier_item_code'] ?? null,
                'description' => trim((string) ($item['description'] ?? 'Unidentified invoice line')),
                'quantity' => $item['quantity'] ?? null,
                'unit' => $item['unit'] ?? null,
                'pack_size' => $packSize ?: 1,
                'unit_price' => $item['unit_price'] ?? null,
                'price_includes_tax' => (bool) ($item['price_includes_tax'] ?? false),
                'tax_rate' => $item['tax_rate'] ?? 0,
                'line_total' => $item['line_total'] ?? null,
                'confidence' => $item['confidence'] ?? null,
                'match_method' => $match['method'],
                'match_confidence' => $match['confidence'],
                'proposed_sell_price' => $proposedSell,
                'line_order' => $index + 1,
            ]);
        }

        $scan->save();
    }

    public static function fingerprint(?string $value): string
    {
        return hash('sha256', Str::of((string) $value)->lower()->replaceMatches('/[^a-z0-9]+/', ' ')->squish()->toString());
    }

    private function matchSupplier(int $businessId, array $payload): ?Contact
    {
        $tax = preg_replace('/\D+/', '', (string) ($payload['supplier_tax_number'] ?? ''));
        if ($tax !== '') {
            $contacts = Contact::where('business_id', $businessId)->whereIn('type', ['supplier', 'both'])->get();
            $exact = $contacts->first(fn ($contact) => preg_replace('/\D+/', '', (string) $contact->tax_number) === $tax);
            if ($exact) return $exact;
        }

        $name = trim((string) ($payload['supplier_name'] ?? ''));
        return $name === '' ? null : Contact::where('business_id', $businessId)
            ->whereIn('type', ['supplier', 'both'])
            ->where(function ($query) use ($name) {
                $query->where('supplier_business_name', 'like', '%'.$name.'%')->orWhere('name', 'like', '%'.$name.'%');
            })->first();
    }

    private function matchVariation(int $businessId, ?int $supplierId, array $item): array
    {
        $code = trim((string) ($item['supplier_item_code'] ?? ''));
        $fingerprint = self::fingerprint($item['description'] ?? '');
        $mapping = null;
        if ($supplierId) {
            $mapping = SupplierProductMapping::where('business_id', $businessId)->where('supplier_id', $supplierId)
                ->where(function ($query) use ($code, $fingerprint) {
                    if ($code !== '') $query->where('supplier_item_code', $code)->orWhere('description_fingerprint', $fingerprint);
                    else $query->where('description_fingerprint', $fingerprint);
                })->first();
            if ($mapping) {
                $variation = Variation::whereKey($mapping->variation_id)->whereHas('product', fn ($q) => $q->where('business_id', $businessId))->first();
                if ($variation) return compact('variation', 'mapping') + ['method' => 'supplier_mapping', 'confidence' => 1];
            }
        }

        if ($code !== '') {
            $variation = Variation::where('sub_sku', $code)->whereHas('product', fn ($q) => $q->where('business_id', $businessId))->first();
            if ($variation) return ['variation' => $variation, 'mapping' => null, 'method' => 'sku', 'confidence' => 1];
        }

        $description = trim((string) ($item['description'] ?? ''));
        $best = null;
        $bestScore = 0;
        if ($description !== '') {
            $candidates = Product::where('business_id', $businessId)->active()->with('variations')->limit(250)->get();
            foreach ($candidates as $product) {
                similar_text(mb_strtolower($description), mb_strtolower($product->name), $score);
                if ($score > $bestScore) {
                    $bestScore = $score;
                    $best = $product->variations->count() === 1 ? $product->variations->first() : null;
                }
            }
        }
        $threshold = (float) config('invoice_scanning.match_threshold', .88) * 100;
        if ($best && $bestScore >= $threshold) {
            return ['variation' => $best, 'mapping' => null, 'method' => 'description', 'confidence' => $bestScore / 100];
        }

        return ['variation' => null, 'mapping' => null, 'method' => null, 'confidence' => null];
    }
}
