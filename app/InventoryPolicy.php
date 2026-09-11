<?php
namespace App;
use Illuminate\Database\Eloquent\Model;
class InventoryPolicy extends Model
{
    protected $fillable = ['business_id','location_id','variation_id','supplier_id','lead_time_days','safety_stock_days','minimum_order_quantity','order_multiple'];
    public function supplier() { return $this->belongsTo(Contact::class, 'supplier_id'); }
}
