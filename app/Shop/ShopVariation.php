<?php

namespace App\Shop;

use App\Variation;
use Illuminate\Database\Eloquent\Model;

class ShopVariation extends Model
{
    protected $table = 'shop_variations';
    protected $guarded = ['id'];
    protected $casts = ['published' => 'boolean'];

    public function shopProduct()
    {
        return $this->belongsTo(ShopProduct::class, 'shop_product_id');
    }

    public function variation()
    {
        return $this->belongsTo(Variation::class);
    }
}
