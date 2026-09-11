<?php
namespace App;
use Illuminate\Database\Eloquent\Model;
class ProductPriceChange extends Model
{
    protected $fillable = ['business_id','variation_id','changed_by','source','source_reference','old_cost','new_cost','old_sell_price','new_sell_price','reason'];
    public function variation(){return $this->belongsTo(Variation::class);}
    public function user(){return $this->belongsTo(User::class,'changed_by');}
}
