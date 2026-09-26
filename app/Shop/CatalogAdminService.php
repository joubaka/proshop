<?php

namespace App\Shop;

use App\Product;
use App\Support\SafeUpload;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    public function replacePrimaryImage(ShopProduct $shopProduct, UploadedFile $image): ProductImage
    {
        $filename = SafeUpload::filename($image, true);
        $directory = 'shop/products/'.$shopProduct->channel->business_id.'/'.$shopProduct->id;
        $disk = Storage::disk('public');
        $path = $disk->putFileAs($directory, $image, $filename);

        if ($path === false) {
            throw ValidationException::withMessages(['online_image' => 'The online product image could not be saved.']);
        }

        try {
            $oldImages = DB::transaction(function () use ($shopProduct, $path) {
                $shopProduct = ShopProduct::query()->lockForUpdate()->findOrFail($shopProduct->id);
                $oldImages = $shopProduct->images()->where('is_primary', true)->get();
                $shopProduct->images()->where('is_primary', true)->update(['is_primary' => false]);
                $shopProduct->images()->create([
                    'disk' => 'public',
                    'path' => $path,
                    'alt_text' => $shopProduct->product->name,
                    'sort_order' => 0,
                    'is_primary' => true,
                ]);
                $shopProduct->images()->whereKey($oldImages->pluck('id'))->delete();

                return $oldImages;
            }, 3);
        } catch (\Throwable $e) {
            $disk->delete($path);
            throw $e;
        }

        foreach ($oldImages as $oldImage) {
            Storage::disk($oldImage->disk)->delete($oldImage->path);
        }

        return $shopProduct->images()->where('is_primary', true)->firstOrFail();
    }
}
