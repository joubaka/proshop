(() => {
    'use strict';
    const cards = document.querySelectorAll('[data-hardware-channel]');
    if (!cards.length) return;

    const number = (value, divisor, digits) => value === null || value === undefined ? '—' : (Number(value) / divisor).toFixed(digits);
    async function refresh() {
        try {
            const response = await fetch('/lights/admin/hardware-state', {
                headers: { Accept: 'application/json' }, cache: 'no-store', signal: AbortSignal.timeout(3000),
            });
            if (!response.ok) return;
            const report = await response.json();
            window.dispatchEvent(new CustomEvent('lights:hardware-state', { detail: report }));
            const online = document.getElementById('hardware-online');
            if (online) {
                online.textContent = report.online ? 'Device online' : 'Device offline';
                online.classList.toggle('status-online', !!report.online);
                online.classList.toggle('status-offline', !report.online);
            }
            for (const state of report.channels || []) {
                const card = document.querySelector('[data-hardware-channel="' + state.channel + '"]');
                if (!card) continue;
                card.classList.remove('is-on', 'is-off', 'is-unknown');
                card.classList.add(state.output === true ? 'is-on' : state.output === false ? 'is-off' : 'is-unknown');
                card.querySelector('[data-relay-state]').textContent = state.output === null ? 'UNKNOWN' : state.output ? 'ON' : 'OFF';
                card.querySelector('[data-watts]').textContent = number(state.watts, 1000, 2);
                card.querySelector('[data-volts]').textContent = number(state.volts, 1, 1);
            }
            const checked = document.getElementById('hardware-checked');
            if (checked && report.checked_at) {
                checked.textContent = 'Checked ' + new Date((report.checked_at + 7200) * 1000).toISOString().slice(0, 19).replace('T', ' ') + ' SAST. Cloud values may be cached and are not independent physical confirmation.';
            }
        } catch (_) {}
    }
    setInterval(refresh, 3000);
    window.addEventListener('lights:request-hardware-refresh', refresh);
    refresh();
})();
