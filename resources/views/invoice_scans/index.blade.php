@extends('layouts.app')
@section('title', 'Scan supplier invoices')

@section('content')
<style>
#invoice_photo, #invoice_documents { min-height:44px; height:auto; }
</style>
<section class="content-header">
    <h1><i class="fas fa-camera"></i> Scan supplier invoices</h1>
</section>

<section class="content">
    <p>Photograph a stock slip, review the filled-in purchase details and resolve highlighted issues. Stock is received only after your final approval.</p>
    @if($errors->any())
        <div class="alert alert-danger"><ul class="mb-0">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></div>
    @endif

    <div class="row">
        <div class="col-md-5">
            <div class="box box-primary">
                <div class="box-header"><h3 class="box-title">New invoice scan</h3></div>
                <form method="POST" action="{{ route('invoice-scans.store') }}" enctype="multipart/form-data">
                    @csrf
                    <div class="box-body">
                        <div class="form-group">
                            <label for="scan_location">Stock location *</label>
                            <select id="scan_location" name="location_id" class="form-control select2" required>
                                <option value="">Choose location</option>
                                @foreach($locations as $id => $name)<option value="{{ $id }}" @selected(old('location_id') == $id)>{{ $name }}</option>@endforeach
                            </select>
                        </div>
                        <div class="form-group">
                            <label for="invoice_photo">Take a photo of the slip</label>
                            <input id="invoice_photo" type="file" name="documents[]" class="form-control" accept="image/jpeg,image/png" capture="environment">
                            <label for="invoice_documents">Or choose photos / a PDF</label>
                            <input id="invoice_documents" type="file" name="documents[]" class="form-control" accept="image/jpeg,image/png,application/pdf" multiple>
                            <p class="help-block">Use a clear, straight photo in good light. You can select multiple pages or one PDF.</p>
                            <p id="scan-upload-status" class="help-block" role="status"></p>
                        </div>
                        @unless(config('invoice_scanning.enabled'))
                            <div class="alert alert-warning">Scanning is not enabled on this server yet. Uploads will be stored safely and can be processed after configuration.</div>
                        @endunless
                    </div>
                    <div class="box-footer">
                        <button class="btn btn-primary btn-lg btn-block" type="submit"><i class="fas fa-camera"></i> Upload and scan</button>
                    </div>
                </form>
            </div>
        </div>
        <div class="col-md-7">
            <div class="box box-default">
                <div class="box-header"><h3 class="box-title">Recent scans</h3></div>
                <div class="table-responsive">
                    <table class="table table-striped">
                        <thead><tr><th>Uploaded</th><th>Supplier</th><th>Invoice</th><th>Total</th><th>Status</th><th></th></tr></thead>
                        <tbody>
                        @forelse($scans as $scan)
                            <tr>
                                <td>{{ $scan->created_at->format('Y-m-d H:i') }}</td>
                                <td>{{ optional($scan->supplier)->supplier_business_name ?: $scan->supplier_name ?: 'Not matched' }}</td>
                                <td>{{ $scan->invoice_number ?: '—' }}</td>
                                <td>{{ $scan->invoice_total !== null ? number_format($scan->invoice_total, 2) : '—' }}</td>
                                <td><span class="label label-{{ $scan->status === 'posted' ? 'success' : ($scan->status === 'failed' ? 'danger' : 'warning') }}">{{ str_replace('_', ' ', $scan->status) }}</span></td>
                                <td><a class="btn btn-xs btn-default" href="{{ route('invoice-scans.show', $scan->uuid) }}">Review</a></td>
                            </tr>
                        @empty
                            <tr><td colspan="6" class="text-muted text-center">No invoice scans yet.</td></tr>
                        @endforelse
                        </tbody>
                    </table>
                </div>
                <div class="box-footer">{{ $scans->links() }}</div>
            </div>
        </div>
    </div>
</section>
@endsection
@section('javascript')
<script>
$('#invoice_photo, #invoice_documents').on('change', function () {
    var count = $('#invoice_photo')[0].files.length + $('#invoice_documents')[0].files.length;
    $('#scan-upload-status').text(count ? count + ' page(s) selected for scanning.' : 'Choose a photo or PDF to scan.');
});
$('#invoice_photo').closest('form').on('submit', function (event) {
    if (!$('#invoice_photo')[0].files.length && !$('#invoice_documents')[0].files.length) {
        event.preventDefault();
        $('#scan-upload-status').text('Choose a photo or PDF to scan.');
        $('#invoice_photo').trigger('focus');
        return;
    }
    $(this).find('button[type="submit"]').prop('disabled', true).text('Uploading slip…');
});
</script>
@endsection
