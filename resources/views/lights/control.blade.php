@extends('lights.layout')
@section('title', 'Court controls')
@section('content')
<div class="page-heading"><div><p class="eyebrow">ADMIN HARDWARE CONTROL</p><h1>Court lights</h1><p>Direct controls for the two Shelly Pro 2 PM channels. Customer billing is separate.</p></div></div>
@include('lights.partials.shelly-status', ['returnTo' => 'control'])
@php $pending = $manualCommands->first(fn ($command) => in_array($command->state, ['queued', 'sending'], true)); @endphp
@if($pending)<div id="control-pending" data-state="{{ $pending->state }}" data-server-time="{{ now()->timestamp }}" hidden></div>@endif
<div id="control-feedback" class="notice" role="status" aria-live="polite" hidden></div>
<section class="panel"><h2>Manual court controls</h2>
<p>Press ON or OFF for the required court. There is no admin wallet, arming checklist, or billing-session review lock.</p>
<p class="muted">Each ON command includes an automatic cutoff on the Shelly itself and is shortened when necessary so it cannot run past 00:00 SAST. At midnight the worker also sends explicit OFF to both channels. Commands are queued once; live status updates automatically.</p>
<div class="switch-grid">
@foreach([0 => 'Court 3', 1 => 'Court 4'] as $channel => $name)
@php $observedOutput = $hardwareChannels->get($channel)['output'] ?? null; @endphp
<article class="switch-card" data-control-channel="{{ $channel }}">
<div class="section-title"><div><small>Channel {{ $channel }}</small><h3>{{ $name }}</h3></div><span class="tag" data-control-state>{{ $observedOutput === true ? 'Currently ON' : ($observedOutput === false ? 'Currently OFF' : 'Status unknown') }}</span></div>
<form method="POST" action="{{ route('lights.admin.control.manual-on') }}" data-ajax-control data-action="on" data-court="{{ $name }}">@csrf
<input type="hidden" name="channel" value="{{ $channel }}"><input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<button class="button switch-on full" data-control-button="on">Switch {{ $name }} ON — timed</button></form>
<form method="POST" action="{{ route('lights.admin.control.emergency-off') }}" data-ajax-control data-action="off" data-court="{{ $name }}">@csrf
<input type="hidden" name="channel" value="{{ $channel }}"><input type="hidden" name="request_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
<button class="button danger full" data-control-button="off">Switch {{ $name }} OFF</button></form>
</article>
@endforeach
</div>
</section>
@if(config('lights.control.customer_enabled'))
<section class="panel"><p class="eyebrow">CUSTOMER HARDWARE ACCESS</p><h2>Allow the next customer switch-on</h2>
<p>This verifies that Shelly currently reports the selected relay online, OFF and fault-free. Approval lasts 10 minutes and is consumed by one ON attempt.</p>
<div class="switch-grid">
@foreach([0 => 'Court 3', 1 => 'Court 4'] as $channel => $name)
<form method="POST" action="{{ route('lights.admin.control.arm-customer') }}" class="switch-card" data-ajax-arm>@csrf
    <input type="hidden" name="channel" value="{{ $channel }}"><h3>{{ $name }}</h3>
    <label class="check-label"><input type="checkbox" name="empty_court" value="1" required><span>I have confirmed this court is empty.</span></label>
    <label class="check-label"><input type="checkbox" name="operator_onsite" value="1" required><span>An operator is on site.</span></label>
    <label class="check-label"><input type="checkbox" name="reboot_off_checked" value="1" required><span>Reboot defaults and physical overrides have been checked.</span></label>
    <button class="button primary full">Allow next {{ $name }} ON</button>
</form>
@endforeach
</div></section>
@endif
<section class="panel"><h2>Manual command activity</h2><p class="muted">Latest direct admin ON/OFF requests. “Completed” means the cloud acknowledged the command; live status appears above.</p>
<div id="manual-command-activity">@forelse($manualCommands as $command)<div class="history-row"><div><strong>Court {{ $command->channel === 0 ? 3 : 4 }} · {{ strtoupper($command->action) }}</strong><small>{{ gmdate('d M Y H:i:s', $command->created_at + 7200) }} SAST · {{ $command->state }}</small>@if($command->note)<small>{{ $command->note }}</small>@endif</div></div>@empty<p class="muted">No manual switching commands have been requested.</p>@endforelse</div></section>
<p><a href="{{ route('lights.admin') }}">Back to Lights administration</a> · <a href="{{ route('lights.admin.shelly') }}">Shelly connection settings</a></p>
@endsection
