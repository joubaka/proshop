@extends('layouts.app')
@section('title', 'Review scanned invoice')

@section('content')
<style>
.scan-lines .form-control { min-width:90px; }
.scan-lines .scan-product-select { min-width:180px; }
.scan-lines td { white-space:normal !important; overflow-wrap:anywhere; }
.scan-lines label, .scan-attention-toggle { display:inline-flex; align-items:center; min-height:44px; gap:6px; }
.scan-review .btn, .scan-review .form-control { min-height:44px; }
.scan-review .btn { white-space:normal; }
.scan-review input:focus, .scan-review button:focus, .scan-review a:focus { outline:2px solid #2475ad; outline-offset:2px; }
.scan-review .select2-container { max-width:100%; }
@media (max-width: 767px) {
    .scan-review .table-responsive { border:0; overflow:visible; }
    .scan-review .select2-container { width:100% !important; }
    .scan-lines thead { display:none; }
    .scan-lines, .scan-lines tbody, .scan-lines tr, .scan-lines td { display:block; width:100%; }
    .scan-lines tr { margin:0 0 16px; border:1px solid #ddd; padding:8px; }
    .scan-lines td { border:0 !important; padding:6px !important; }
    .scan-lines td:before { content:attr(data-label); display:block; font-size:11px; font-weight:700; color:#666; text-transform:uppercase; }
}
</style>
<section class="content-header">
    <h1>Review scanned invoice <small>{{ $scan->invoice_number ?: $scan->uuid }}</small></h1>
</section>

<section class="content scan-review">
    @if($errors->any())<div class="alert alert-danger"><ul>@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>@endif
    @php
        $busy = in_array($scan->status, ['uploaded', 'queued', 'processing'], true);
        $editable = !in_array($scan->status, ['posted', 'queued', 'processing'], true);
        $attentionCount = count(array_filter($review['lines']));
    @endphp
    @if($busy)
        <div class="alert alert-info" role="status">{{ $scan->status === 'uploaded' ? 'Your slip is saved. Process it when scanning is enabled.' : 'Reading your slip. This page will refresh when the scan is ready.' }} Stock has not changed.</div>
    @elseif($scan->status !== 'posted')
        <div class="alert alert-{{ count($review['header']) || $attentionCount ? 'warning' : 'success' }}" role="status">
            <strong>{{ $attentionCount }} {{ $attentionCount === 1 ? 'item needs' : 'items need' }} attention.</strong> Review the highlighted issues, then save. Stock changes only after Approve and receive.
            @if($review['header'])<ul>@foreach($review['header'] as $issue)<li>{{ $issue }}</li>@endforeach</ul>@endif
        </div>
    @endif

    <div class="row">
        <div class="col-md-4">
            <div class="box box-default">
                <div class="box-header"><h3 class="box-title">Original document</h3></div>
                <div class="box-body">
                    @foreach($scan->documents as $document)
                        <p><a class="btn btn-default btn-block" target="_blank" rel="noopener" href="{{ route('invoice-scans.document', [$scan->uuid, $document->id]) }}"><i class="fas fa-file"></i> Page {{ $document->page_order }} — {{ $document->original_name }}</a></p>
                    @endforeach
                    <dl>
                        <dt>Status</dt><dd>{{ str_replace('_', ' ', ucfirst($scan->status)) }}</dd>
                        <dt>Extraction confidence</dt><dd>{{ $scan->confidence !== null ? number_format($scan->confidence * 100, 0).'%' : 'Not available' }}</dd>
                    </dl>
                    @if($scan->failure_message)<div class="alert alert-danger">{{ $scan->failure_message }}</div>@endif
                    @if($editable)
                        <form method="POST" action="{{ route('invoice-scans.process', $scan->uuid) }}">@csrf
                            <button class="btn btn-info btn-block" type="submit" @disabled(!config('invoice_scanning.enabled'))><i class="fas fa-sync"></i> {{ $scan->processed_at ? 'Scan again' : 'Process invoice' }}</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-md-8">
            <form id="scan-review-form" method="POST" action="{{ route('invoice-scans.update', $scan->uuid) }}">
                @csrf @method('PUT')
                <fieldset @disabled(!$editable) style="min-width:0">
                <div class="box box-primary">
                    <div class="box-header"><h3 class="box-title">Invoice details</h3></div>
                    <div class="box-body">
                        <div class="row">
                            <div class="col-sm-6 form-group"><label>Supplier *</label><select name="supplier_id" class="form-control select2" required><option value="">Choose supplier</option>@foreach($suppliers as $id=>$name)<option value="{{ $id }}" @selected(old('supplier_id', $scan->supplier_id)==$id)>{{ $name }}</option>@endforeach</select></div>
                            <div class="col-sm-6 form-group"><label>Location *</label><select name="location_id" class="form-control select2" required>@foreach($locations as $id=>$name)<option value="{{ $id }}" @selected(old('location_id', $scan->location_id)==$id)>{{ $name }}</option>@endforeach</select></div>
                            <div class="col-sm-6 form-group"><label>Supplier invoice number *</label><input class="form-control" name="invoice_number" value="{{ old('invoice_number', $scan->invoice_number) }}" required></div>
                            <div class="col-sm-6 form-group"><label>Invoice date *</label><input class="form-control" type="date" name="invoice_date" value="{{ old('invoice_date', optional($scan->invoice_date)->format('Y-m-d')) }}" required></div>
                        </div>
                    </div>
                </div>

                <div class="box box-primary">
                    <div class="box-header">
                        <h3 class="box-title">Products and pricing</h3>
                        <button type="button" class="btn btn-default btn-sm pull-right btn-modal" data-href="{{ action('App\Http\Controllers\ProductController@quickAdd') }}" data-container=".quick_add_product_modal"><i class="fas fa-plus"></i> Add missing product</button>
                        <p class="help-block">Quantity × pack size is the number of stock units received. Selling-price changes require separate approval.</p>
                        <label class="scan-attention-toggle"><input id="scan-attention-only" type="checkbox"> Show only items needing attention ({{ $attentionCount }})</label>
                    </div>
                    <div class="table-responsive">
                        <table class="table table-bordered table-condensed scan-lines">
                            <thead><tr><th>Scanned item</th><th>Matched product *</th><th>Purchase order</th><th>Qty</th><th>Pack</th><th>Invoice unit cost</th><th>VAT %</th><th>Line subtotal (ex VAT)</th><th>Proposed sell</th></tr></thead>
                            <tbody>
                            @forelse($scan->lines as $line)
                                @php
                                    $oldCost = optional($line->variation)->default_purchase_price;
                                    $unitCost = $line->pack_size > 0 ? app(\App\InvoiceScanning\InvoiceAmounts::class)->unitCosts(max(0, (float) $line->unit_price), (float) $line->pack_size, max(0, (float) $line->tax_rate), (bool) $line->price_includes_tax)['exclusive'] : 0;
                                    $change = $oldCost > 0 ? (($unitCost - $oldCost) / $oldCost) * 100 : null;
                                @endphp
                                <tr data-needs-attention="{{ count($review['lines'][$line->id]) ? '1' : '0' }}" class="{{ !$line->variation_id ? 'danger' : (count($review['lines'][$line->id]) ? 'warning' : '') }}">
                                    <td data-label="Scanned item"><strong>{{ $line->description }}</strong><br><small>{{ $line->supplier_item_code ?: 'No supplier code' }} · OCR {{ $line->confidence !== null ? round($line->confidence*100).'%' : 'n/a' }}</small>@if($review['lines'][$line->id])<ul class="help-block">@foreach($review['lines'][$line->id] as $issue)<li>{{ $issue }}</li>@endforeach</ul>@endif</td>
                                    <td data-label="Matched product"><select name="lines[{{ $line->id }}][variation_id]" class="form-control scan-product-select" required><option value="">Choose product</option>@if($line->variation)<option value="{{$line->variation_id}}" selected>{{$line->variation->product->name}} · {{$line->variation->sub_sku}}</option>@endif</select></td>
                                    <td data-label="Purchase order"><select name="lines[{{ $line->id }}][purchase_order_line_id]" class="form-control select2"><option value="">Not linked</option>@foreach($purchaseOrderLines as $id=>$name)<option value="{{ $id }}" @selected(old("lines.{$line->id}.purchase_order_line_id", $line->purchase_order_line_id)==$id)>{{ $name }}</option>@endforeach</select></td>
                                    <td data-label="Quantity"><input class="form-control input-sm" type="number" step="0.0001" min="0.0001" name="lines[{{ $line->id }}][quantity]" value="{{ old("lines.{$line->id}.quantity", $line->quantity) }}" required></td>
                                    <td data-label="Pack size"><input class="form-control input-sm" type="number" step="0.0001" min="0.0001" name="lines[{{ $line->id }}][pack_size]" value="{{ old("lines.{$line->id}.pack_size", $line->pack_size) }}" required><small>stock units</small></td>
                                    <td data-label="Invoice unit cost"><input class="form-control input-sm" type="number" step="0.0001" min="0" name="lines[{{ $line->id }}][unit_price]" value="{{ old("lines.{$line->id}.unit_price", $line->unit_price) }}" required><input type="hidden" name="lines[{{ $line->id }}][price_includes_tax]" value="0"><label><input type="checkbox" name="lines[{{ $line->id }}][price_includes_tax]" value="1" @checked(old("lines.{$line->id}.price_includes_tax", $line->price_includes_tax))> VAT incl.</label>@if($change !== null)<br><small class="{{ abs($change) >= config('invoice_scanning.price_change_warning_percent') ? 'text-danger' : 'text-muted' }}">{{ $change >= 0 ? '+' : '' }}{{ number_format($change,1) }}% vs old cost</small>@endif</td>
                                    <td data-label="VAT %"><input class="form-control input-sm" type="number" step="0.01" min="0" max="100" name="lines[{{ $line->id }}][tax_rate]" value="{{ old("lines.{$line->id}.tax_rate", $line->tax_rate) }}" required></td>
                                    <td data-label="Line subtotal (ex VAT)"><input class="form-control input-sm" type="number" step="0.01" min="0" name="lines[{{ $line->id }}][line_total]" value="{{ old("lines.{$line->id}.line_total", $line->line_total) }}" required></td>
                                    <td data-label="Proposed selling price"><input class="form-control input-sm" type="number" step="0.01" min="0" name="lines[{{ $line->id }}][proposed_sell_price]" value="{{ old("lines.{$line->id}.proposed_sell_price", $line->proposed_sell_price) }}"><input type="hidden" name="lines[{{ $line->id }}][price_change_approved]" value="0">@can('product.update')<label><input type="checkbox" name="lines[{{ $line->id }}][price_change_approved]" value="1" @checked(old("lines.{$line->id}.price_change_approved", $line->price_change_approved))> Apply</label><input class="form-control input-sm" name="lines[{{ $line->id }}][price_change_reason]" value="{{old("lines.{$line->id}.price_change_reason",$line->price_change_reason)}}" placeholder="Required reason">@else<small class="text-muted">Product-update permission required to apply a price.</small>@endcan</td>
                                </tr>
                            @empty<tr><td colspan="9" class="text-center text-muted">No lines extracted. Process the invoice or enter it through the normal purchase screen.</td></tr>@endforelse
                            </tbody>
                        </table>
                    </div>
                </div>

                <div class="box box-default"><div class="box-body"><div class="row">
                    <div class="col-sm-2 form-group"><label>Subtotal *</label><input class="form-control" type="number" step="0.01" min="0" name="subtotal" value="{{ old('subtotal',$scan->subtotal) }}" required></div>
                    <div class="col-sm-2 form-group"><label>Discount</label><input class="form-control" type="number" step="0.01" min="0" name="discount_total" value="{{ old('discount_total',$scan->discount_total ?? 0) }}"></div>
                    <div class="col-sm-2 form-group"><label>VAT *</label><input class="form-control" type="number" step="0.01" min="0" name="tax_total" value="{{ old('tax_total',$scan->tax_total ?? 0) }}" required></div>
                    <div class="col-sm-3 form-group"><label>Freight</label><input class="form-control" type="number" step="0.01" min="0" name="freight_total" value="{{ old('freight_total',$scan->freight_total ?? 0) }}"></div>
                    <div class="col-sm-3 form-group"><label>Invoice total *</label><input class="form-control" type="number" step="0.01" min="0.01" name="invoice_total" value="{{ old('invoice_total',$scan->invoice_total) }}" required><small>Subtotal − discount + VAT + freight</small></div>
                </div></div></div>

                @if($editable)<button class="btn btn-primary btn-lg" type="submit"><i class="fas fa-check"></i> Save reviewed invoice</button>@endif
                </fieldset>
            </form>

            @if($scan->status === 'ready')
                <form id="scan-receive-form" method="POST" action="{{ route('invoice-scans.post', $scan->uuid) }}" style="display:inline-block" onsubmit="return confirm('Receive this stock and create the supplier purchase?');">@csrf
                    <button class="btn btn-success btn-lg" type="submit"><i class="fas fa-boxes"></i> Approve and receive</button>
                </form>
            @elseif($scan->status === 'posted' && $scan->purchase)
                <a class="btn btn-success btn-lg" href="{{ url('/purchases/'.$scan->purchase->id) }}">View created purchase</a>
            @endif
            <a class="btn btn-default btn-lg" href="{{ route('invoice-scans.index') }}">Back to scans</a>
        </div>
    </div>
    <div class="modal fade quick_add_product_modal" tabindex="-1" role="dialog"></div>
</section>
@endsection
@section('javascript')
<script>
$('#scan-review-form input:not([type="hidden"]), #scan-review-form select').each(function () {
    var name = this.name || '';
    if (!this.id) this.id = 'scan-field-' + name.replace(/[^a-zA-Z0-9]/g, '-');
    if (!$(this).attr('aria-label')) $(this).attr('aria-label', $(this).closest('td').attr('data-label') || $(this).closest('.form-group').find('label').first().text() || name);
});
$('#scan-attention-only').on('change', function () {
    $('.scan-lines tr[data-needs-attention="0"]').toggle(!this.checked);
});
$('#scan-review-form').on('input change', ':input', function () {
    if (this.id === 'scan-attention-only') return;
    $('#scan-receive-form button').prop('disabled', true).text('Save your changes before receiving stock');
});
$('#scan-review-form').on('submit', function () {
    $(this).find('button[type="submit"]').prop('disabled', true).text('Saving review…');
});
$('.scan-product-select').select2({width:'100%',minimumInputLength:2,ajax:{url:'/purchases/get_products',dataType:'json',delay:250,data:function(p){return {term:p.term,only_variations:true};},processResults:function(data){return {results:data.map(function(row){return {id:row.variation_id,text:row.text};})};}}});
$(document).on('quickProductAdded', function (event) {
    if (!event.variation) return;
    var label = (event.product && event.product.name ? event.product.name : 'New product') + ' (' + (event.variation.sub_sku || '') + ')';
    $('select[name$="[variation_id]"]').each(function () {
        if (!$(this).find('option[value="' + event.variation.id + '"]').length) {
            $(this).append(new Option(label, event.variation.id, false, false));
        }
    });
    $('select[name$="[variation_id]"]').filter(function () { return !this.value; }).first().val(event.variation.id).trigger('change');
});
@if(in_array($scan->status, ['queued', 'processing'], true))
window.setTimeout(function () { window.location.reload(); }, 4000);
@endif
</script>
@endsection
