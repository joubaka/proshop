@extends('layouts.app')
@section('title','Review replenishment')
@section('content')
<section class="content-header"><h1>Review purchase-order preview <small>{{$batch->location->name}}</small></h1></section>
<section class="content">@if($errors->any())<div class="alert alert-danger">{{$errors->first()}}</div>@endif
<div class="alert alert-info">This is a stored preview. No purchase order or stock change has occurred. Confirmation rechecks stock, sales and open orders; stale previews are rejected.</div>
<div class="box box-primary"><div class="box-body table-responsive"><table class="table table-bordered"><thead><tr><th>Supplier</th><th>Product</th><th>SKU</th><th>Stock snapshot</th><th>Open PO</th><th>Daily sales</th><th>Quantity</th><th>Estimated cost</th></tr></thead><tbody>@foreach($batch->lines as $line)<tr><td>{{$line->supplier->supplier_business_name ?: $line->supplier->name}}</td><td>{{$line->product->name}}</td><td>{{$line->variation->sub_sku}}</td><td>{{$line->stock_snapshot}}</td><td>{{$line->open_po_snapshot}}</td><td>{{number_format($line->daily_sales_snapshot,2)}}</td><td>{{$line->quantity}}</td><td>@format_currency($line->quantity*$line->unit_cost)</td></tr>@endforeach</tbody></table></div></div>
@if($batch->status==='preview')<form method="POST" action="{{route('inventory-control.replenishment.confirm',$batch->uuid)}}" style="display:inline" onsubmit="return confirm('Create supplier purchase orders from this reviewed preview?');">@csrf<button class="btn btn-success btn-lg">Confirm and create purchase orders</button></form> <form method="POST" action="{{route('inventory-control.replenishment.discard',$batch->uuid)}}" style="display:inline">@csrf<button class="btn btn-default btn-lg">Discard preview</button></form>@else<div class="alert alert-default">Batch status: {{$batch->status}}</div>@endif
</section>
@endsection
