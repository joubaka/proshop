<?php

namespace App\Shop;

use Illuminate\Database\Eloquent\Model;

class Channel extends Model
{
    protected $table = 'shop_channels';
    protected $guarded = ['id'];
    protected $casts = ['enabled' => 'boolean', 'settings' => 'array'];

    public function products()
    {
        return $this->hasMany(ShopProduct::class, 'shop_channel_id');
    }
}
