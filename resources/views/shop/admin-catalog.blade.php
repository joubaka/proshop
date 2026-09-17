@extends('layouts.app')
@section('title', 'Online catalogue')
@section('content')
<section class="content-header"><h1>Online catalogue</h1><p>Create a channel for a POS location, then explicitly choose what may appear online.</p></section>
<section class="content"><div class="row"><div class="col-md-7"><div class="box box-primary"><div class="box-header"><h3 class="box-title">Shop channels</h3></div><div class="box-body table-responsive"><table class="table table-bordered"><thead><tr><th>Name</th><th>Slug</th><th>Products</th><th>Status</th><th></th></tr></thead><tbody>
@forelse($channels as $channel)<tr><td>{{ $channel->name }}</td><td>{{ $channel->slug }}</td><td>{{ $channel->products->count() }}</td><td>{{ $channel->enabled ? 'Enabled' : 'Disabled' }}</td><td><a class="btn btn-primary btn-sm" href="{{ route('shop.admin.catalog.products', $channel) }}">Manage products</a></td></tr>@empty<tr><td colspan="5">No shop channel configured.</td></tr>@endforelse
</tbody></table></div></div></div><div class="col-md-5"><div class="box box-info"><div class="box-header"><h3 class="box-title">Create channel</h3></div><form method="post" action="{{ route('shop.admin.catalog.channels.store') }}">@csrf<div class="box-body">
<div class="form-group"><label>POS location<select class="form-control" name="location_id" required>@foreach($locations as $id => $name)<option value="{{ $id }}">{{ $name }}</option>@endforeach</select></label></div>
<div class="form-group"><label>Public name<input class="form-control" name="name" required maxlength="191"></label></div><div class="form-group"><label>Channel slug<input class="form-control" name="slug" value="main" required maxlength="80"></label></div>
<label><input type="checkbox" name="enabled" value="1"> Enable channel</label><p class="help-block">The global SHOP_ENABLED and SHOP_CHECKOUT_ENABLED switches still control public access.</p>
</div><div class="box-footer"><button class="btn btn-primary">Create channel</button></div></form></div></div></div></section>
@endsection
