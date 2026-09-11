<?php
namespace App;
use Illuminate\Database\Eloquent\Model;
class StockCountLine extends Model
{
    protected $fillable = ['stock_count_id','product_id','variation_id','system_quantity','counted_quantity','counted_by','counted_at'];
    protected $casts = ['counted_at' => 'datetime'];
    public function variation() { return $this->belongsTo(Variation::class); }
    public function product() { return $this->belongsTo(Product::class); }
}
