<?php

namespace App\Shop;

use Illuminate\Database\Eloquent\Model;

class CartItem extends Model
{
    protected $table = 'shop_cart_items';
    protected $guarded = ['id'];

    public function cart() { return $this->belongsTo(Cart::class, 'shop_cart_id'); }
    public function shopVariation() { return $this->belongsTo(ShopVariation::class, 'shop_variation_id'); }
}
