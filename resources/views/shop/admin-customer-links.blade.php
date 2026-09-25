@extends('layouts.app')
@section('title', 'Customer account links')
@section('content')
<section class="content-header"><h1>Customer account links</h1><p>Approve only after confirming that the portal customer owns the in-shop contact record.</p></section>
<section class="content"><div class="box box-primary"><div class="box-body table-responsive"><table class="table table-bordered table-striped"><thead><tr><th>Portal customer</th><th>POS contact</th><th>Status</th><th>Requested</th><th></th></tr></thead><tbody>
@forelse($links as $link)<tr><td>{{ $link->customer->name }}<br><small>{{ $link->customer->email }}</small></td><td>{{ $link->contact->name }}<br><small>{{ $link->contact->email }}</small></td><td>{{ ucfirst($link->status) }}</td><td>{{ $link->created_at->format('Y-m-d H:i') }}</td><td>@if($link->status === 'pending')<form method="POST" action="{{ route('shop.admin.customer-links.verify', $link) }}">@csrf<button class="btn btn-success btn-sm">Verify</button></form>@elseif($link->status === 'verified')<form method="POST" action="{{ route('shop.admin.customer-links.revoke', $link) }}">@csrf<button class="btn btn-danger btn-sm">Revoke</button></form>@endif</td></tr>@empty<tr><td colspan="5">No customer account links.</td></tr>@endforelse
</tbody></table></div><div class="box-footer">{{ $links->links() }}</div></div></section>
@endsection
