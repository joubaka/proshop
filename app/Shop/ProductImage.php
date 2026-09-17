<?php

namespace App\Shop;

use Illuminate\Database\Eloquent\Model;

class ProductImage extends Model
{
    protected $table = 'shop_product_images';
    protected $guarded = ['id'];
    protected $casts = ['is_primary' => 'boolean'];
}
