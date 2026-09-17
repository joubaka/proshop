<?php

namespace App\Shop;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;

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
