<?php

namespace App\Shop;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class CatalogService
{
    public function channel(): Channel
    {
        abort_unless(config('shop.enabled'), 404);

        return Channel::query()
            ->where('slug', config('shop.channel'))
            ->where('enabled', true)
            ->firstOrFail();
    }

    public function products(Channel $channel): LengthAwarePaginator
    {
        return $this->baseQuery($channel)
            ->orderByDesc('featured')
            ->orderBy('sort_order')
            ->orderBy('id')
            ->paginate(max(1, min(60, (int) config('shop.products_per_page', 24))));
    }

    public function product(Channel $channel, string $slug): ShopProduct
    {
        return $this->baseQuery($channel)->where('slug', $slug)->firstOrFail();
    }

    public function availability(Channel $channel, ShopProduct $product): array
    {
        $reserved = DB::table('shop_stock_reservations')->where('location_id', $channel->location_id)
            ->where('status', 'active')->where('expires_at', '>', now())
            ->whereIn('variation_id', $product->variations->pluck('variation_id'))
            ->selectRaw('variation_id, SUM(quantity) as reserved_quantity')
            ->groupBy('variation_id')->pluck('reserved_quantity', 'variation_id');

        return $product->variations->mapWithKeys(function (ShopVariation $shopVariation) use ($reserved) {
            $physical = (float) ($shopVariation->variation->variation_location_details->first()?->qty_available ?? 0);
            $available = max(0, (int) floor($physical - (float) $shopVariation->safety_stock)
                - (int) ($reserved[$shopVariation->variation_id] ?? 0));
            if ($shopVariation->maximum_order_quantity !== null) {
                $available = min($available, (int) $shopVariation->maximum_order_quantity);
            }
            return [$shopVariation->id => $available];
        })->all();
    }

    private function baseQuery(Channel $channel)
    {
        return ShopProduct::query()
            ->where('shop_channel_id', $channel->id)
            ->published()
            ->whereHas('product', function ($query) use ($channel) {
                $query->where('business_id', $channel->business_id)
                    ->where('is_inactive', false)
                    ->where('not_for_selling', false)
                    ->whereHas('product_locations', fn ($query) => $query->where('business_locations.id', $channel->location_id));
            })
            ->whereHas('variations', function ($query) use ($channel) {
                $query->where('published', true)
                    ->whereHas('variation', function ($query) use ($channel) {
                        $query->whereNotNull('sell_price_inc_tax')
                            ->whereHas('variation_location_details', fn ($query) => $query->where('location_id', $channel->location_id));
                    });
            })
            ->with([
                'product.brand', 'product.category',
                'variations' => fn ($query) => $query->where('published', true)->orderBy('sort_order')->orderBy('id'),
                'variations.variation.product_variation',
                'variations.variation.variation_location_details' => fn ($query) => $query->where('location_id', $channel->location_id),
                'images' => fn ($query) => $query->orderByDesc('is_primary')->orderBy('sort_order')->orderBy('id'),
            ]);
    }
}
