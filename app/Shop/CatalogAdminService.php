<?php

namespace App\Shop;

use App\Product;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CatalogAdminService
{
    public function saveProduct(Channel $channel, Product $product, array $data): ShopProduct
    {
        if ((int) $product->business_id !== (int) $channel->business_id
            || $product->type === 'combo' || $product->is_inactive || $product->not_for_selling
            || !$product->product_locations()->whereKey($channel->location_id)->exists()) {
            throw ValidationException::withMessages(['product' => 'This POS product is not eligible for this shop channel.']);
        }

        return DB::transaction(function () use ($channel, $product, $data) {
            $shopProduct = ShopProduct::query()->updateOrCreate(
                ['shop_channel_id' => $channel->id, 'product_id' => $product->id],
                [
                    'slug' => $data['slug'], 'short_description' => $data['short_description'] ?? null,
                    'web_description' => $data['web_description'] ?? null, 'featured' => !empty($data['featured']),
                    'sort_order' => (int) ($data['sort_order'] ?? 0),
                    'published_at' => !empty($data['published']) ? now() : null,
                    'unpublished_at' => !empty($data['published']) ? null : now(),
                ]
            );

            $allowedVariations = $product->variations()->pluck('id')->map(fn ($id) => (int) $id)->all();
            foreach ((array) ($data['variations'] ?? []) as $variationId => $settings) {
                $variationId = (int) $variationId;
                if (!in_array($variationId, $allowedVariations, true)) {
                    throw ValidationException::withMessages(['variations' => 'A selected variation does not belong to this product.']);
                }
                ShopVariation::query()->updateOrCreate(
                    ['shop_product_id' => $shopProduct->id, 'variation_id' => $variationId],
                    [
                        'display_name' => $settings['display_name'] ?? null,
                        'published' => !empty($settings['published']),
                        'sort_order' => (int) ($settings['sort_order'] ?? 0),
                        'safety_stock' => (float) ($settings['safety_stock'] ?? 0),
                        'maximum_order_quantity' => isset($settings['maximum_order_quantity'])
                            && $settings['maximum_order_quantity'] !== '' ? (float) $settings['maximum_order_quantity'] : null,
                    ]
                );
            }
            if (!empty($data['published']) && !$shopProduct->variations()->where('published', true)->exists()) {
                throw ValidationException::withMessages(['variations' => 'Publish at least one variation before publishing the product.']);
            }
            return $shopProduct->fresh(['variations']);
        }, 3);
    }
}
