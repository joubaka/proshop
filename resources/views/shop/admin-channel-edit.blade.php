@extends('layouts.app')
@section('title', 'Edit shop channel')
@section('content')
<section class="content-header">
    <h1>Edit shop channel</h1>
    <p>Update the customer-facing name or temporarily disable this storefront channel.</p>
</section>
<section class="content">
    <div class="row">
        <div class="col-md-7">
            <div class="box box-primary">
                <div class="box-header"><h3 class="box-title">{{ $channel->name }}</h3></div>
                <form method="post" action="{{ route('shop.admin.catalog.channels.update', $channel) }}">
                    @csrf
                    @method('PATCH')
                    <div class="box-body">
                        <div class="form-group">
                            <label>POS location</label>
                            <p class="form-control-static">{{ $location->name }}</p>
                            <p class="help-block">The stock location cannot be changed after the channel is created.</p>
                        </div>
                        <div class="form-group">
                            <label>Channel slug</label>
                            <p class="form-control-static"><code>{{ $channel->slug }}</code></p>
                            <p class="help-block">The slug is kept stable because it identifies the configured storefront.</p>
                        </div>
                        <div class="form-group{{ $errors->has('name') ? ' has-error' : '' }}">
                            <label for="channel-name">Public name</label>
                            <input id="channel-name" class="form-control" name="name" value="{{ old('name', $channel->name) }}" required maxlength="191">
                            @if($errors->has('name'))<span class="help-block">{{ $errors->first('name') }}</span>@endif
                        </div>
                        <input type="hidden" name="enabled" value="0">
                        <label><input type="checkbox" name="enabled" value="1" {{ old('enabled', $channel->enabled) ? 'checked' : '' }}> Enable channel</label>
                        <p class="help-block">Disabling this channel immediately makes its public catalogue unavailable.</p>
                    </div>
                    <div class="box-footer">
                        <button class="btn btn-primary">Save channel</button>
                        <a class="btn btn-default" href="{{ route('shop.admin.catalog.index') }}">Cancel</a>
                    </div>
                </form>
            </div>
        </div>
    </div>
</section>
@endsection
