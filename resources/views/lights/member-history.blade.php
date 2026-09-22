@extends('lights.layout')
@section('title', 'Transaction history')
@section('content')
<div class="page-heading"><div><p class="eyebrow">MEMBER WALLET</p><h1>Transaction history</h1><p class="muted">{{ $account->name }} · {{ $account->email }}</p></div><a class="button secondary" href="{{ route('lights.admin') }}#members">Back to members</a></div>
<section class="panel">
    <div class="section-title"><div><h2>{{ $account->name }}</h2><p class="muted">All wallet credits, debits and light charges, newest first.</p></div><div class="member-wallet"><small>Current wallet balance</small><strong>R {{ number_format($account->balance_cents / 100, 2) }}</strong></div></div>
    @forelse($history as $entry)
        <div class="member-history-row">
            <div><strong>{{ $entry->label }}</strong><small>{{ gmdate('d M Y H:i', $entry->created_at + 7200) }} SAST{{ $entry->reason ? ' · '.$entry->reason : '' }}</small></div>
            <div class="{{ $entry->amount_cents < 0 ? 'debit' : 'credit' }}"><strong>{{ $entry->amount_cents < 0 ? '−' : '+' }} R {{ number_format(abs($entry->amount_cents) / 100, 2) }}</strong><small>Balance R {{ number_format($entry->balance_after_cents / 100, 2) }}</small></div>
        </div>
    @empty
        <div class="quiet-state"><strong>No wallet transactions yet</strong><span>Credits, debits and light charges will appear here.</span></div>
    @endforelse
    @if($history->hasPages())
        <nav class="member-pagination" aria-label="Transaction history pages"><p>Showing {{ $history->firstItem() }}–{{ $history->lastItem() }} of {{ $history->total() }} transactions</p><div>@if($history->onFirstPage())<span aria-disabled="true">Previous</span>@else<a href="{{ $history->previousPageUrl() }}" rel="prev">Previous</a>@endif<span aria-current="page">Page {{ $history->currentPage() }} of {{ $history->lastPage() }}</span>@if($history->hasMorePages())<a href="{{ $history->nextPageUrl() }}" rel="next">Next</a>@else<span aria-disabled="true">Next</span>@endif</div></nav>
    @elseif($history->total() > 0)<p class="member-page-summary">Showing all {{ $history->total() }} transactions</p>@endif
</section>
@endsection
