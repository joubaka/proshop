@extends('lights.layout')
@section('content')
@php
    $adminHardware = Auth::guard('lights')->user()->is_admin && config('lights.control.live_enabled') && !config('lights.control.customer_enabled') && app()->environment('acceptance');
    $paymentsReady = config('lights.mode') !== 'live' || config('lights.payfast.enabled');
    $sessionDuration = static function ($session, bool $hardware = false): string {
        if ($session->started_at === null) { return 'No billed time'; }
        if ($hardware) {
            $candidates = array_filter([$session->stop_requested_at, $session->deadline_at, $session->stopped_at], static fn ($value) => $value !== null);
            $endedAt = $candidates ? min($candidates) : time();
        } else {
            $endedAt = $session->stopped_at ?? min(time(), $session->deadline_at);
        }
        $seconds = max(0, (int) $endedAt - (int) $session->started_at);
        $hours = intdiv($seconds, 3600);
        $minutes = intdiv($seconds % 3600, 60);
        $remaining = $seconds % 60;
        return ($hours ? $hours.'h ' : '').($minutes ? $minutes.'m ' : '').$remaining.'s';
    };
@endphp
<div class="page-heading home-heading"><div><p class="eyebrow">READY WHEN YOU ARE</p><h1>Let’s play, {{ explode(' ', Auth::guard('lights')->user()->name)[0] }}.</h1><p class="muted">Choose a court. Switch on. Make the most of your time.</p></div><button class="button install-button" id="install-app" type="button" aria-controls="install-help" aria-expanded="false" hidden><span aria-hidden="true">↧</span> Install Court Lights</button></div>
<div class="notice install-help" id="install-help" role="status" tabindex="-1" hidden><strong>Add Court Lights to your Home Screen</strong><span id="install-help-copy">Use your browser menu and choose “Install app” or “Add to Home Screen”.</span></div>
@if(config('lights.require_verified_email') && !Auth::guard('lights')->user()->email_verified_at)
<div class="notice warning" id="verification-notice"><strong>Verify your email to continue.</strong> Payments and light controls stay locked until verification.
    <form method="POST" action="{{ route('lights.verification.send') }}" class="inline-form">@csrf<button class="text-button">Send a new verification link</button></form>
</div>
@endif
<div class="wallet panel"><div><p class="eyebrow">YOUR LIGHTS BALANCE</p><div class="balance" id="wallet-balance">R {{ number_format($state['balance_cents'] / 100, 2) }}</div><p>Pay as you play. No subscription.</p></div><a class="button light" href="#topup" data-open-home-tab="topup">+ Top up</a></div>
<div id="active-sessions">
        @foreach($state['sessions'] as $activeSession)
        <section class="panel active-panel active-session" data-session="{{ $activeSession->id }}" aria-label="Active session">
            <div class="section-title"><h2 class="session-heading"><span class="live-dot"></span>Lights are on</h2><span class="tag session-court"></span></div>
            <div class="session-metrics"><div><span>Session cost</span><strong class="session-cost">R 0.00</strong></div><div><span>Time remaining</span><strong class="session-remaining">—</strong></div></div>
            <p class="muted">The server keeps counting if you close this page. The displayed balance is an estimate between updates.</p>
            <form class="stop-session-form" method="POST" action="{{ route('lights.stop', $activeSession->id) }}" data-light-action>@csrf<button class="button danger full">Switch off {{ optional($state['courts']->firstWhere('id', $activeSession->court_id))->name ?? 'court' }} &amp; finish</button></form>
        </section>
        @endforeach
        </div>
<nav class="home-tabs" role="tablist" aria-label="My lights sections">
    <button type="button" role="tab" id="home-tab-courts" aria-controls="home-panel-courts" aria-selected="true" data-home-tab="courts">Courts</button>
    <button type="button" role="tab" id="home-tab-topup" aria-controls="home-panel-topup" aria-selected="false" tabindex="-1" data-home-tab="topup">Top up</button>
    <button type="button" role="tab" id="home-tab-activity" aria-controls="home-panel-activity" aria-selected="false" tabindex="-1" data-home-tab="activity">Activity</button>
</nav>
<div class="dashboard-grid home-tab-content">
    <section role="tabpanel" id="home-panel-courts" aria-labelledby="home-tab-courts" data-home-panel="courts">
        <div class="section-title"><h2>Choose your court</h2><span class="muted">{{ config('lights.control.customer_enabled') ? 'Shelly safety control' : 'Shelly simulator' }}</span></div>
        <div class="courts">
        @forelse($state['courts'] as $court)
            <article class="panel court {{ $court->is_on ? 'is-on' : '' }}" data-court="{{ $court->id }}"><div class="section-title"><h3>{{ $court->name }}</h3><span class="court-status tag">{{ !$court->active ? 'Unavailable' : ($court->hardware_output === true ? 'Lights ON' : ($court->in_use ? 'In use' : (!$court->control_ready ? 'Temporarily offline' : 'Available'))) }}</span></div><div class="mini-court" aria-hidden="true"><i></i></div><p><strong class="court-rate">R {{ number_format($court->rate_cents / 100, 2) }}</strong> <span class="muted">/ hour</span></p><small class="court-note muted">{{ $court->hardware_output === true ? 'Cloud last reported this relay ON'.($court->hardware_stale ? ' — status is older than two minutes' : '') : $court->control_reason }}</small>
                @if($adminHardware)
                    <a class="button primary full" href="{{ route('lights.admin.control') }}#court-{{ $court->id }}-arm">Open real {{ $court->name }} controls</a>
                    <small class="muted">Admin commissioning is active. This link cannot create a simulated light session.</small>
                @else
                    <form method="POST" action="{{ route('lights.start', $court->id) }}" data-light-action>@csrf<input type="hidden" name="request_key" value="{{ (string) Illuminate\Support\Str::uuid() }}"><input type="hidden" name="quoted_rate_cents" value="{{ $court->rate_cents }}"><button class="button primary full court-start" @disabled(!$state['email_verified'] || !$court->active || $court->in_use || !$court->control_ready || $state['balance_cents'] < 1)>{{ $court->hardware_output === true ? 'Lights already on' : 'Switch on' }}</button></form>
                @endif
                @if($court->hardware_output === true && Auth::guard('lights')->user()->is_admin)<a class="button secondary full" href="{{ route('lights.admin.control') }}">Open admin OFF control</a>@endif
                @if(!$court->control_ready && $court->hardware_output !== true && Auth::guard('lights')->user()->is_admin)<a class="button secondary full" href="{{ route('lights.admin.control') }}#court-{{ $court->id }}-arm">Arm {{ $court->name }} for ON</a>@endif
            </article>
        @empty<div class="panel"><p>No courts configured yet. An administrator can add them.</p></div>@endforelse
        </div>
    </section>
    <aside role="tabpanel" id="home-panel-topup" aria-labelledby="home-tab-topup" data-home-panel="topup">
        <section class="panel" id="topup"><p class="eyebrow">KEEP THE GAME GOING</p><h2>Top up your wallet</h2><p class="muted">PayFast is the only top-up method. {{ config('lights.mode') === 'live' ? ($paymentsReady ? 'Credit is added only after PayFast confirms the payment.' : 'Online payments are being commissioned and are not available yet.') : 'This demo uses a clearly labelled simulator.' }}</p>
            <form method="POST" action="{{ route('lights.topup') }}">@csrf<input type="hidden" name="request_key" value="{{ (string) Illuminate\Support\Str::uuid() }}">
                <div class="amounts">@foreach([50,100,200] as $amount)<button type="button" class="amount-button" data-amount="{{ $amount }}">R{{ $amount }}</button>@endforeach</div>
                <label>Amount in rand<input id="topup-amount" name="amount" inputmode="decimal" value="100.00" required pattern="[0-9]+(\.[0-9]{1,2})?" aria-describedby="topup-limit"></label><small id="topup-limit" class="muted">R10–R5,000 · ZAR only</small>
                <button class="button primary full" @disabled(!$state['email_verified'] || !$paymentsReady)>Continue to PayFast{{ config('lights.mode') === 'live' ? '' : ' demo' }} →</button>
            </form>
        </section>
        <section class="panel guide"><h3>A few things to know</h3><ol><li>You may run both courts at the same time.</li><li>Switch each court off separately to settle it.</li><li>Credit is reserved safely between concurrent sessions and funds an automatic cutoff.</li></ol><p class="muted">Top-ups during play are added to your wallet but do not extend a current cutoff. Stop and restart to use the new credit.</p><p class="muted" id="worker-status"></p></section>
    </aside>
</div>
<section class="panel history" role="tabpanel" id="home-panel-activity" aria-labelledby="home-tab-activity" data-home-panel="activity"><div class="section-title"><h2>Your activity</h2><span class="muted">Latest 20 of each</span></div>
    <div class="history-grid"><div><h3>Light sessions</h3>
    @foreach($hardwareSessions as $session)<div class="history-row"><div><strong>{{ $session->name ?? 'Court' }}</strong><small>{{ gmdate('d M Y H:i', $session->created_at + 7200) }} SAST · {{ str_replace('_', ' ', $session->state) }} · {{ $sessionDuration($session, true) }} used</small></div><strong>−R {{ number_format($session->charged_cents / 100, 2) }}</strong></div>@endforeach
    @foreach($sessions as $session)<div class="history-row"><div><strong>{{ $session->name }}</strong><small>{{ gmdate('d M Y H:i', $session->started_at + 7200) }} SAST · {{ $session->stopped_at ? str_replace('_', ' ', $session->stop_reason) : 'Active' }} · {{ $sessionDuration($session) }} used</small></div><strong>−R {{ number_format($session->charged_cents / 100, 2) }}</strong></div>@endforeach
    @if($hardwareSessions->isEmpty() && $sessions->isEmpty())<p class="muted">Your first session starts with a switch.</p>@endif</div>
    <div><h3>Wallet top-ups</h3>@forelse($topups as $topup)<div class="history-row"><div><strong>{{ $topup->gateway === 'payfast' ? 'PayFast' : 'PayFast demo' }}</strong><small>{{ gmdate('d M Y H:i', $topup->created_at + 7200) }} SAST · {{ ucfirst($topup->status) }}</small>@if($topup->status === 'pending')<a href="{{ route('lights.checkout', $topup->id) }}">Resume checkout</a>@endif</div><strong>R {{ number_format($topup->amount_cents / 100, 2) }}</strong></div>@empty<p class="muted">No top-ups yet.</p>@endforelse</div></div>
</section>
<script type="application/json" id="lights-state">{!! json_encode($state, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
@endsection
