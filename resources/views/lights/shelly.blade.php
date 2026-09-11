@extends('lights.layout')
@section('title', 'Shelly connection')
@section('content')
@if(session('shelly_error'))<div class="notice error" role="alert">{{ session('shelly_error') }}</div>@endif
<div class="page-heading"><div><p class="eyebrow">PRIVATE LOCAL SETUP</p><h1>Shelly Cloud connection</h1><p>Read-only commissioning. No physical ON/OFF controls are enabled.</p></div></div>
<section class="panel">
    <h2>Confirmed device</h2>
    <p>Server: <strong>{{ \App\Lights\Shelly\PrivateSettings::SERVER }}</strong></p>
    <p>Shelly Pro 2 PM · device {{ \App\Lights\Shelly\PrivateSettings::DEVICE }}.</p>
    <p><strong>Court 3: channel 0 · Court 4: channel 1.</strong></p>
    <p class="muted">Both assignments confirmed from your Shelly device information. These details do not change the demo court mappings or enable physical switching.</p>
    <form method="POST" action="{{ route('lights.admin.shelly.save') }}" autocomplete="off">@csrf
        <label>Cloud authorization key <input type="password" name="shelly_key" autocomplete="new-password" required minlength="16" maxlength="4096" spellcheck="false" autocapitalize="none"></label>
        <p>{{ $configured ? 'A key is saved. It is never displayed; enter a new key only to replace it.' : 'No key saved yet.' }}</p>
        <p class="muted">Stored encrypted outside public files using a separate random local key. Protect this computer and its private files: this is not a production secret vault. Never paste the key into chat.</p>
        <button class="button primary">{{ $configured ? 'Replace saved key' : 'Save key privately' }}</button>
    </form>
</section>
<section class="panel">
    <h2>Read-only connection check</h2>
    <p>This sends the saved key only to the confirmed Shelly HTTPS server and requests this device's status. No switch, timer, firmware or configuration command is sent.</p>
    <form method="POST" action="{{ route('lights.admin.shelly.check') }}">@csrf<button class="button primary" @disabled(!$configured)>Check connection — status only</button></form>
    @if($report = session('shelly_report'))
        <h3>{{ $report['online'] ? 'Cloud reports device online' : 'Cloud reports device offline' }}</h3>
        <p>Checked {{ gmdate('Y-m-d H:i:s', $report['checked_at'] + 7200) }} SAST.</p>
        <p class="muted">Cloud-reported values may be cached. They are not proof of a fresh relay acknowledgement or a working automatic cutoff.</p>
        @foreach($report['channels'] as $channel)<div class="history-row"><div><strong>Channel {{ $channel['channel'] }}{{ match ($channel['channel']) { 0 => ' · Court 3', 1 => ' · Court 4', default => ' · court unconfirmed' } }}</strong><small>Reported output: {{ $channel['output'] === null ? 'Unknown' : ($channel['output'] ? 'ON' : 'OFF') }} · {{ $channel['watts'] === null ? 'Power unavailable' : $channel['watts'].' W' }} · {{ $channel['volts'] === null ? 'Voltage unavailable' : $channel['volts'].' V' }}</small>@if($channel['has_errors'])<small>Device reports a fault. Investigate before commissioning.</small>@endif</div></div>@endforeach
    @endif
    <p class="muted">Physical switching remains disabled. Weak court Wi-Fi, device-side cutoff, reboot behavior, safe billing and on-site acceptance must be addressed separately.</p>
</section>
<a href="{{ route('lights.admin') }}">Back to Lights administration</a>
<section class="panel"><h2>Admin court controls</h2><p>Open the direct Court 3 and Court 4 controls. Admin ON is timed automatically; admin OFF is explicit and neither action uses the customer wallet.</p><a class="button primary" href="{{ route('lights.admin.control') }}">Open admin controls</a></section>
@endsection
