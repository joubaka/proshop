@extends('layouts.app')
@section('title', 'Online products')
@section('content')
<section class="content-header"><h1>{{ $channel->name }} products</h1><p>Only explicitly published products and variations are visible online.</p></section><section class="content"><div class="box box-primary"><div class="box-body table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>POS product</th><th>SKU</th><th>Online status</th><th></th></tr></thead><tbody>
@forelse($products as $product)@php($online = $configured->get($product->id))<tr><td>{{ $product->name }}</td><td>{{ $product->sku }}</td><td>{{ $online?->published_at && !$online?->unpublished_at ? 'Published' : ($online ? 'Configured, hidden' : 'Not configured') }}</td><td><a class="btn btn-sm btn-primary" href="{{ route('shop.admin.catalog.products.edit', [$channel, $product]) }}">Configure</a></td></tr>@empty<tr><td colspan="4">No eligible POS products at this location.</td></tr>@endforelse
</tbody></table></div><div class="box-footer">{{ $products->links() }}</div></div></section>
@endsection
