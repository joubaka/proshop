<?php

namespace App\Shop;

use App\Product;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

class ShopProduct extends Model
{
    protected $table = 'shop_products';
    protected $guarded = ['id'];
    protected $casts = ['featured' => 'boolean', 'published_at' => 'datetime', 'unpublished_at' => 'datetime'];

    public function channel()
    {
        return $this->belongsTo(Channel::class, 'shop_channel_id');
    }

    public function product()
    {
        return $this->belongsTo(Product::class);
    }

    public function variations()
    {
        return $this->hasMany(ShopVariation::class, 'shop_product_id');
    }

    public function images()
    {
        return $this->hasMany(ProductImage::class, 'shop_product_id');
    }

    public function getDisplayImageUrlAttribute(): string
    {
        $images = $this->relationLoaded('images') ? $this->images : $this->images()->get();
        $primary = $images->sortBy([
            ['is_primary', 'desc'],
            ['sort_order', 'asc'],
            ['id', 'asc'],
        ])->first();

        return $primary?->url ?? $this->product->image_url;
    }

    public function scopePublished(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')
            ->where('published_at', '<=', now())
            ->where(fn (Builder $query) => $query->whereNull('unpublished_at')->orWhere('unpublished_at', '>', now()));
    }
}
