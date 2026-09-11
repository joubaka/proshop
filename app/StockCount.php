<?php
namespace App;
use Illuminate\Database\Eloquent\Model;
class StockCount extends Model
{
    protected $fillable = ['uuid','business_id','location_id','created_by','posted_by','status','name','notes','posted_at'];
    protected $casts = ['posted_at' => 'datetime'];
    public function lines() { return $this->hasMany(StockCountLine::class); }
    public function location() { return $this->belongsTo(BusinessLocation::class); }
}
