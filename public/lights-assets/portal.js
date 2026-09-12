(() => {
    'use strict';
    document.querySelectorAll('[data-amount]').forEach(button => button.addEventListener('click', () => { document.getElementById('topup-amount').value = Number(button.dataset.amount).toFixed(2); }));
    const initial = document.getElementById('lights-state');
    const notice = document.getElementById('connection-notice');
    let state = initial ? JSON.parse(initial.textContent) : null, received = performance.now(), busy = false, syncing = false, connected = true;
    const money = cents => 'R ' + (Math.max(0, cents) / 100).toFixed(2);
    const pause = milliseconds => new Promise(resolve => setTimeout(resolve, milliseconds));
    function showNotice(message, error = false) {
        notice.hidden = !message;
        notice.classList.toggle('error', error);
        notice.textContent = message || '';
    }
    function render() {
        if (!state) return;
        const age = (performance.now() - received) / 1000;
        const now = state.server_time + Math.floor(age);
        const sessions = state.sessions || (state.session ? [state.session] : []);
        let estimate = state.balance_cents;
        for (const session of sessions) {
            const billing = session.billing_started !== false && session.started_at !== null && session.deadline_at !== null;
            const elapsed = billing ? Math.max(0, Math.min(now, session.deadline_at) - session.started_at) : 0;
            const charged = billing ? Math.max(session.charged_cents, Math.min(session.budget_cents, Math.ceil(elapsed * session.rate_cents / 3600))) : session.charged_cents;
            estimate -= charged - session.charged_cents;
            const panel = document.querySelector('[data-session="' + session.id + '"]');
            if (!panel) continue;
            panel.querySelector('.session-cost').textContent = money(charged);
            const seconds = billing ? Math.max(0, session.deadline_at - now) : null;
            panel.querySelector('.session-remaining').textContent = seconds === null ? 'Pending' : Math.floor(seconds / 60) + ':' + String(seconds % 60).padStart(2, '0');
            panel.querySelector('.session-court').textContent = state.courts.find(c => c.id === session.court_id)?.name || 'Court';
            const heading = panel.querySelector('.session-heading');
            if (heading) heading.innerHTML = session.control_state && session.control_state !== 'running'
                ? '<span class="live-dot warning"></span>Status needs attention' : '<span class="live-dot"></span>Lights are on';
            panel.querySelector('.stop-session-form').action = '/lights/sessions/' + session.id + '/stop';
        }
        document.getElementById('wallet-balance').textContent = money(estimate);
        for (const court of state.courts) {
            const card = document.querySelector('[data-court="' + court.id + '"]');
            if (!card) continue;
            card.classList.toggle('is-on', !!court.is_on);
            card.querySelector('.court-rate').textContent = money(court.rate_cents);
            const quotedRate = card.querySelector('[name=quoted_rate_cents]');
            if (quotedRate) quotedRate.value = court.rate_cents;
            card.querySelector('.court-status').textContent = !court.active ? 'Unavailable' : court.hardware_output === true ? 'Lights ON' : court.in_use ? 'In use' : !court.control_ready ? 'Temporarily offline' : 'Available';
            const note = card.querySelector('.court-note');
            if (note) note.textContent = court.hardware_output === true
                ? 'Cloud last reported this relay ON' + (court.hardware_stale ? ' — status is older than two minutes' : '')
                : court.control_reason;
            const start = card.querySelector('.court-start');
            if (!start) continue;
            start.textContent = court.hardware_output === true ? 'Lights already on' : 'Switch on';
            start.disabled = busy || !state.email_verified || !connected || age > 15 || !court.active || court.in_use || !court.control_ready || estimate < 1;
        }
        document.getElementById('worker-status').textContent = state.worker_seen_at && now - state.worker_seen_at < 15
            ? 'Local accounting and safety worker is running.' : state.customer_control
                ? 'Light control is locked because the safety worker is not reporting.'
                : 'Local worker is not reporting. Page refreshes still reconcile the simulator; start the worker for background accounting.';
        if (age > 15) showNotice('Connection interrupted. Status may be out of date. Lights still have a server-side cutoff; reconnect to confirm or switch off.', true);
    }
    async function refresh() {
        if (!state || syncing || busy) return;
        syncing = true;
        try {
            const response = await fetch('/lights/state', { headers: { Accept: 'application/json' }, cache: 'no-store', signal: AbortSignal.timeout(8000) });
            if (response.status === 401) { location.assign('/lights/login'); return; }
            if (!response.ok) throw new Error('Could not refresh court status.');
            const next = await response.json();
            state = next; received = performance.now(); connected = true; showNotice(''); render();
        } catch (error) { connected = false; showNotice('Could not refresh status. Reconnect before starting a session.', true); render(); }
        finally { syncing = false; }
    }
    document.querySelectorAll('[data-light-action]').forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault(); if (busy) return;
        const button = form.querySelector('button');
        const starting = form.action.includes('/start');
        const beforeIds = new Set((state.sessions || (state.session ? [state.session] : [])).map(session => session.id));
        const stoppingId = starting ? null : form.closest('[data-session]')?.dataset.session;
        busy = true; button.disabled = true; button.dataset.label = button.textContent; button.textContent = starting ? 'Switching on…' : 'Switching off…'; render();
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(10000) });
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Request failed.');
            form.querySelector('[name=request_key]')?.setAttribute('value', crypto.randomUUID());
            busy = false;
            showNotice(starting ? 'Switch-on accepted. Confirming the court status…' : 'Switch-off accepted. Confirming the court status…');
            for (let attempt = 0; attempt < 8; attempt++) {
                await refresh();
                const activeIds = new Set((state.sessions || (state.session ? [state.session] : [])).map(session => session.id));
                if ((starting && [...activeIds].some(id => !beforeIds.has(id))) || (!starting && !activeIds.has(stoppingId))) break;
                await pause(750);
            }
            location.reload();
        } catch (error) {
            showNotice(error.name === 'TimeoutError' ? 'Request timed out. Refreshing to check its outcome—do not start another session.' : error.message, true);
            busy = false;
            // Fetch authoritative state after an uncertain result, never replay the mutation.
            setTimeout(refresh, 1500);
        } finally {
            button.textContent = button.dataset.label || button.textContent;
            render();
        }
    }));
    if (state) { render(); setInterval(render, 1000); setInterval(refresh, 5000); window.addEventListener('online', refresh); }
    let installPrompt;
    window.addEventListener('beforeinstallprompt', event => { event.preventDefault(); installPrompt = event; const button = document.getElementById('install-app'); if (button) button.hidden = false; });
    document.getElementById('install-app')?.addEventListener('click', async () => { if (installPrompt) { await installPrompt.prompt(); installPrompt = null; document.getElementById('install-app').hidden = true; } });
    const targetedControl = location.hash ? document.querySelector(location.hash) : null;
    if (targetedControl?.matches('details')) targetedControl.open = true;
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/lights/service-worker.js', { scope: '/lights/' }).catch(() => {});
})();
