<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class SupplierProductMapping extends Model
{
    protected $fillable = [
        'business_id', 'supplier_id', 'variation_id', 'supplier_item_code', 'description_fingerprint',
        'supplier_description', 'pack_size', 'confirmed_by', 'last_confirmed_at',
    ];
    protected $casts = ['last_confirmed_at' => 'datetime'];
}
