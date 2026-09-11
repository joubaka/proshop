@php
    $shellyReport = session('shelly_report') ?? app(\App\Lights\Shelly\HardwareStatus::class)->latest();
    $courtNames = [0 => 'Court 3', 1 => 'Court 4'];
@endphp
<section class="panel hardware-status" aria-labelledby="hardware-status-title">
    <div class="section-title">
        <div>
            <p class="eyebrow">LIVE HARDWARE</p>
            <h2 id="hardware-status-title">Current light status</h2>
        </div>
        @if($shellyReport)
            <span id="hardware-online" class="tag {{ $shellyReport['online'] ? 'status-online' : 'status-offline' }}">
                {{ $shellyReport['online'] ? 'Device online' : 'Device offline' }}
            </span>
        @endif
    </div>

    @if(session('shelly_error'))
        <div class="notice error" role="alert">{{ session('shelly_error') }}</div>
    @endif

    @if($shellyReport)
        <div class="hardware-grid">
            @foreach($shellyReport['channels'] as $channel)
                @php
                    $output = $channel['output'];
                    $stateClass = $output === true ? 'is-on' : ($output === false ? 'is-off' : 'is-unknown');
                @endphp
                <article class="hardware-card {{ $stateClass }}" data-hardware-channel="{{ $channel['channel'] }}">
                    <div class="hardware-card-heading">
                        <div>
                            <small>Channel {{ $channel['channel'] }}</small>
                            <h3>{{ $courtNames[$channel['channel']] ?? 'Unassigned court' }}</h3>
                        </div>
                        <strong class="relay-state" data-relay-state>{{ $output === null ? 'UNKNOWN' : ($output ? 'ON' : 'OFF') }}</strong>
                    </div>
                    <div class="hardware-metrics">
                        <span><strong data-watts>{{ $channel['watts'] === null ? '—' : number_format($channel['watts'] / 1000, 2) }}</strong><small>{{ $channel['watts'] === null ? 'Power unavailable' : 'kW now' }}</small></span>
                        <span><strong data-volts>{{ $channel['volts'] === null ? '—' : number_format($channel['volts'], 1) }}</strong><small>{{ $channel['volts'] === null ? 'Voltage unavailable' : 'volts' }}</small></span>
                    </div>
                    @if($channel['has_errors'])
                        <p class="hardware-warning">Fault reported — investigate before use.</p>
                    @elseif($channel['timer_started_at'] === null || $channel['timer_duration'] === null)
                        <p class="muted">No automatic cutoff timer reported.</p>
                    @else
                        <p class="muted">Automatic cutoff timer reported by device.</p>
                    @endif
                </article>
            @endforeach
        </div>
        <p class="status-freshness" id="hardware-checked">Checked {{ gmdate('Y-m-d H:i:s', $shellyReport['checked_at'] + 7200) }} SAST. Cloud values may be cached and are not independent physical confirmation.</p>
    @else
        <p class="muted">Press refresh to request the latest read-only status from the confirmed Shelly device.</p>
    @endif

    <div class="status-actions">
        <form method="POST" action="{{ route('lights.admin.shelly.check') }}" @if($returnTo === 'control') data-ajax-status-refresh @endif>
            @csrf
            <input type="hidden" name="return_to" value="{{ $returnTo }}">
            <button class="button primary">Refresh live status</button>
        </form>
        <span class="muted">Read-only: this does not switch either court.</span>
    </div>
</section>
