<?php

namespace App;

use Illuminate\Database\Eloquent\Model;

class InvoiceScanDocument extends Model
{
    protected $fillable = ['invoice_scan_id', 'disk', 'path', 'original_name', 'mime_type', 'size_bytes', 'sha256', 'page_order'];
    public function scan() { return $this->belongsTo(InvoiceScan::class, 'invoice_scan_id'); }
}
