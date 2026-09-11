<?php
namespace App;
use Illuminate\Database\Eloquent\Model;
class ReplenishmentBatch extends Model
{
    protected $fillable=['uuid','business_id','location_id','created_by','confirmed_by','status','sales_window_days','proposal_fingerprint','purchase_order_ids','confirmed_at'];
    protected $casts=['confirmed_at'=>'datetime'];
    public function lines(){return $this->hasMany(ReplenishmentBatchLine::class);}
    public function location(){return $this->belongsTo(BusinessLocation::class);}
}
