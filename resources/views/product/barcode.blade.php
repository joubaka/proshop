@extends('layouts.app')
@section('title', 'Update product barcode')
@section('content')
<style>
.barcode-editor { max-width:680px; }
.barcode-editor .form-control, .barcode-editor .btn { min-height:44px; }
.barcode-editor .btn { white-space:normal; }
.barcode-editor input:focus, .barcode-editor button:focus, .barcode-editor a:focus { outline:2px solid #2475ad; outline-offset:2px; }
.barcode-editor code { overflow-wrap:anywhere; }
</style>
<section class="content-header"><h1>Update barcode <small>{{ $product->name }}</small></h1></section>
<section class="content barcode-editor">
    @if($errors->any())<div class="alert alert-danger">{{ $errors->first() }}</div>@endif
    <div class="box box-primary"><div class="box-body">
        <h2 class="box-title">{{ $product->name }}</h2>
        <p>Choose the product variation, scan its barcode and press Enter. A scanner that sends Enter saves automatically. You can also type the code and tap Save barcode.</p>
        <form id="barcode-update-form" method="POST" action="{{ route('products.barcode.update', $product->id) }}">
            @csrf @method('PUT')
            <div class="form-group">
                <label for="barcode-variation">Product variation</label>
                <select id="barcode-variation" name="variation_id" class="form-control" required>
                    @if($product->variations->count() !== 1)<option value="">Choose the variation to update</option>@endif
                    @foreach($product->variations as $variation)
                        <option value="{{ $variation->id }}" data-barcode="{{ $variation->sub_sku }}" data-name="{{ $product->type === 'variable' ? $variation->name : $product->name }}">{{ $product->type === 'variable' ? $variation->name : $product->name }} — {{ $variation->sub_sku ?: 'No barcode' }}</option>
                    @endforeach
                </select>
            </div>
            <p>Current barcode: <code id="barcode-current"></code></p>
            <input id="barcode-previous" type="hidden" name="previous_barcode" value="">
            <input id="barcode-previous-sku" type="hidden" name="previous_sku" value="{{ $product->sku }}">
            <div class="form-group">
                <label for="barcode-new">Scan replacement barcode</label>
                <input id="barcode-new" name="barcode" type="text" class="form-control input-lg" autocomplete="off" autocapitalize="off" spellcheck="false" maxlength="64" required aria-describedby="barcode-save-status">
            </div>
            <div id="barcode-save-status" role="status" aria-live="polite"></div>
            <button id="barcode-save" type="submit" class="btn btn-primary btn-lg"><i class="fa fa-barcode"></i> Save barcode</button>
            <a class="btn btn-default" href="{{ action('App\Http\Controllers\ProductController@index') }}">Back to products</a>
        </form>
        <p class="help-block">For a single product, this updates both its SKU and till barcode. For a variable product, it updates only the selected variation’s barcode. Barcode labels use Code 128. Stock quantities and prices stay unchanged.</p>
    </div></div>
</section>
@endsection
@section('javascript')
<script>
(function () {
    var form = $('#barcode-update-form'), variation = $('#barcode-variation'), input = $('#barcode-new'), status = $('#barcode-save-status');
    var saving = false;
    function selectVariation() {
        var barcode = variation.find(':selected').attr('data-barcode') || '';
        $('#barcode-current').text(barcode || 'Not set');
        $('#barcode-previous').val(barcode);
        input.val('').prop('disabled', !variation.val());
        $('#barcode-save').prop('disabled', !variation.val());
        status.removeClass('alert alert-success alert-danger').text('');
        if (variation.val()) input.trigger('focus');
    }
    variation.on('change', selectVariation);
    selectVariation();
    form.on('submit', function (event) {
        event.preventDefault();
        if (saving || !variation.val()) return;
        saving = true;
        var data = form.serialize();
        form.find('button, select, input:not([type="hidden"])').prop('disabled', true);
        status.removeClass('alert alert-success alert-danger').text('Saving barcode…');
        $.ajax({url:form.attr('action'), method:'POST', data:data, dataType:'json', headers:{Accept:'application/json'}})
            .done(function (result) {
                variation.find(':selected').attr('data-barcode', result.barcode);
                variation.find(':selected').text(variation.find(':selected').attr('data-name') + ' — ' + result.barcode);
                $('#barcode-previous').val(result.barcode);
                $('#barcode-previous-sku').val(result.sku);
                $('#barcode-current').text(result.barcode);
                status.addClass('alert alert-success').text(result.message);
                input.val('');
            })
            .fail(function (xhr) {
                var errors = xhr.responseJSON && xhr.responseJSON.errors;
                var message = errors ? Object.values(errors)[0][0] : 'Save could not be confirmed. Reload this page to check the current barcode before trying again.';
                status.addClass('alert alert-danger').text(message);
                input.trigger('select');
            })
            .always(function () {
                saving = false;
                form.find('button, select, input:not([type="hidden"])').prop('disabled', false);
                input.trigger('focus');
            });
    });
})();
</script>
@endsection
