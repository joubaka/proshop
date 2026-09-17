<?php

namespace App\Shop;

use Illuminate\Database\Eloquent\Model;

class Cart extends Model
{
    protected $table = 'shop_carts';
    protected $guarded = ['id'];
    protected $casts = ['expires_at' => 'datetime'];

    public function channel() { return $this->belongsTo(Channel::class, 'shop_channel_id'); }
    public function items() { return $this->hasMany(CartItem::class, 'shop_cart_id'); }
}
