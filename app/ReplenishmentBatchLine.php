<?php
namespace App;
use Illuminate\Database\Eloquent\Model;
class ReplenishmentBatchLine extends Model
{
    protected $fillable=['replenishment_batch_id','product_id','variation_id','supplier_id','quantity','unit_cost','stock_snapshot','open_po_snapshot','daily_sales_snapshot'];
    public function product(){return $this->belongsTo(Product::class);}
    public function variation(){return $this->belongsTo(Variation::class);}
    public function supplier(){return $this->belongsTo(Contact::class,'supplier_id');}
}
