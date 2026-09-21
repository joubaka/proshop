(() => {
    'use strict';
    document.querySelectorAll('[data-amount]').forEach(button => button.addEventListener('click', () => { document.getElementById('topup-amount').value = Number(button.dataset.amount).toFixed(2); }));
    const initial = document.getElementById('lights-state');
    const notice = document.getElementById('connection-notice');
    let state = initial ? JSON.parse(initial.textContent) : null, received = performance.now(), syncing = false, connected = true;
    const pendingActions = new Set();
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
            const stopButton = panel.querySelector('.stop-session-form button');
            const stopPending = pendingActions.has('stop:' + session.id) || session.control_state === 'stopping';
            if (stopButton) {
                stopButton.disabled = stopPending;
                stopButton.textContent = stopPending ? 'Switching off…' : (stopButton.dataset.label || stopButton.textContent);
            }
        }
        document.getElementById('wallet-balance').textContent = money(estimate);
        for (const court of state.courts) {
            const card = document.querySelector('[data-court="' + court.id + '"]');
            if (!card) continue;
            const startPending = pendingActions.has('start:' + court.id) || court.pending_action === 'on';
            const stopPending = court.pending_action === 'off';
            card.classList.toggle('is-on', !!court.is_on);
            card.classList.toggle('is-pending', startPending || stopPending);
            card.querySelector('.court-rate').textContent = money(court.rate_cents);
            const quotedRate = card.querySelector('[name=quoted_rate_cents]');
            if (quotedRate) quotedRate.value = court.rate_cents;
            card.querySelector('.court-status').textContent = startPending ? 'Switching on…' : stopPending ? 'Switching off…' : !court.active ? 'Unavailable' : court.is_on ? 'Lights ON' : court.in_use ? 'In use' : !court.control_ready ? 'Temporarily offline' : 'Available';
            const note = card.querySelector('.court-note');
            if (note) note.textContent = court.hardware_output === true
                ? 'Cloud last reported this relay ON' + (court.hardware_stale ? ' — status is older than two minutes' : '')
                : court.control_reason;
            const start = card.querySelector('.court-start');
            if (!start) continue;
            start.textContent = startPending ? 'Switching on…' : court.is_on ? 'Lights already on' : 'Switch on';
            start.disabled = startPending || !state.email_verified || !connected || age > 15 || !court.active || court.in_use || !court.control_ready || estimate < 1;
        }
        document.getElementById('worker-status').textContent = state.worker_seen_at && now - state.worker_seen_at < 15
            ? 'Local accounting and safety worker is running.' : state.customer_control
                ? 'Light control is locked because the safety worker is not reporting.'
                : 'Local worker is not reporting. Page refreshes still reconcile the simulator; start the worker for background accounting.';
        if (age > 15) showNotice('Connection interrupted. Status may be out of date. Lights still have a server-side cutoff; reconnect to confirm or switch off.', true);
    }
    async function refresh() {
        if (!state || syncing) return;
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
        event.preventDefault();
        const button = form.querySelector('button');
        const starting = form.action.includes('/start');
        const beforeIds = new Set((state.sessions || (state.session ? [state.session] : [])).map(session => session.id));
        const stoppingId = starting ? null : form.closest('[data-session]')?.dataset.session;
        const courtId = starting ? form.closest('[data-court]')?.dataset.court : null;
        const actionKey = starting ? 'start:' + courtId : 'stop:' + stoppingId;
        if (pendingActions.has(actionKey)) return;
        pendingActions.add(actionKey);
        button.disabled = true; button.dataset.label = button.textContent; button.textContent = starting ? 'Switching on…' : 'Switching off…'; render();
        let accepted = false;
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(10000) });
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Request failed.');
            form.querySelector('[name=request_key]')?.setAttribute('value', crypto.randomUUID());
            accepted = true;
            showNotice(starting ? 'Switch-on accepted. Confirming the court status…' : 'Switch-off accepted. Confirming the court status…');
            for (let attempt = 0; attempt < 8; attempt++) {
                await refresh();
                const activeIds = new Set((state.sessions || (state.session ? [state.session] : [])).map(session => session.id));
                if ((starting && [...activeIds].some(id => !beforeIds.has(id))) || (!starting && !activeIds.has(stoppingId))) break;
                await pause(750);
            }
        } catch (error) {
            showNotice(error.name === 'TimeoutError' ? 'Request timed out. Refreshing to check its outcome—do not start another session.' : error.message, true);
            // Fetch authoritative state after an uncertain result, never replay the mutation.
            setTimeout(refresh, 1500);
        } finally {
            pendingActions.delete(actionKey);
            button.textContent = button.dataset.label || button.textContent;
            render();
            if (accepted && pendingActions.size === 0) location.reload();
        }
    }));
    if (state) { render(); setInterval(render, 1000); setInterval(refresh, 5000); window.addEventListener('online', refresh); }
    let installPrompt;
    const installButton = document.getElementById('install-app');
    const installHelp = document.getElementById('install-help');
    const installHelpCopy = document.getElementById('install-help-copy');
    const isStandalone = window.matchMedia('(display-mode: standalone)').matches || window.navigator.standalone === true;
    const isIos = /iphone|ipad|ipod/i.test(navigator.userAgent);
    if (installButton && !isStandalone && isIos) installButton.hidden = false;
    window.addEventListener('beforeinstallprompt', event => {
        event.preventDefault(); installPrompt = event;
        if (installButton && !isStandalone) installButton.hidden = false;
    });
    window.addEventListener('appinstalled', () => {
        installPrompt = null;
        if (installButton) installButton.hidden = true;
        if (installHelp) installHelp.hidden = true;
    });
    installButton?.addEventListener('click', async () => {
        if (installPrompt) {
            await installPrompt.prompt();
            const choice = await installPrompt.userChoice;
            installPrompt = null;
            if (choice.outcome === 'accepted') installButton.hidden = true;
            return;
        }
        if (installHelpCopy) installHelpCopy.textContent = isIos
            ? 'On iPhone or iPad, tap Share, then choose “Add to Home Screen”.'
            : 'Open your browser menu and choose “Install app” or “Add to Home Screen”.';
        if (installHelp) { installHelp.hidden = false; installHelp.focus(); }
        installButton.setAttribute('aria-expanded', 'true');
    });
    const homeTabs = [...document.querySelectorAll('[data-home-tab]')];
    const homePanels = [...document.querySelectorAll('[data-home-panel]')];
    const compactHome = window.matchMedia('(max-width: 800px)');
    function selectHomeTab(name, focus = false) {
        if (!homeTabs.length) return;
        homeTabs.forEach(tab => {
            const selected = tab.dataset.homeTab === name;
            tab.setAttribute('aria-selected', String(selected));
            tab.tabIndex = selected ? 0 : -1;
            if (selected && focus) tab.focus();
        });
        homePanels.forEach(panel => { panel.hidden = compactHome.matches && panel.dataset.homePanel !== name; });
    }
    if (homeTabs.length) {
        const initialHomeTab = location.hash === '#topup' ? 'topup' : 'courts';
        selectHomeTab(initialHomeTab);
        homeTabs.forEach((tab, index) => {
            tab.addEventListener('click', () => selectHomeTab(tab.dataset.homeTab));
            tab.addEventListener('keydown', event => {
                if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
                event.preventDefault();
                const next = event.key === 'Home' ? 0 : event.key === 'End' ? homeTabs.length - 1
                    : (index + (event.key === 'ArrowRight' ? 1 : -1) + homeTabs.length) % homeTabs.length;
                selectHomeTab(homeTabs[next].dataset.homeTab, true);
            });
        });
        document.querySelectorAll('[data-open-home-tab]').forEach(link => link.addEventListener('click', () => selectHomeTab(link.dataset.openHomeTab)));
        compactHome.addEventListener('change', () => {
            const selected = document.querySelector('[data-home-tab][aria-selected="true"]')?.dataset.homeTab || 'courts';
            selectHomeTab(selected);
        });
    }
    const targetedControl = location.hash ? document.querySelector(location.hash) : null;
    if (targetedControl?.matches('details')) targetedControl.open = true;
    const loginPanel = document.getElementById('login');
    function revealLogin() {
        if (!loginPanel) return;
        loginPanel.focus({ preventScroll: true });
        loginPanel.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }
    if (targetedControl === loginPanel) revealLogin();
    document.querySelectorAll('a[href$="#login"]').forEach(link => link.addEventListener('click', event => {
        if (!loginPanel || new URL(link.href).pathname !== location.pathname) return;
        event.preventDefault();
        history.replaceState(null, '', '#login');
        revealLogin();
    }));
    document.querySelectorAll('[data-password-toggle]').forEach(button => button.addEventListener('click', () => {
        const input = document.getElementById(button.dataset.passwordToggle);
        if (!input) return;
        const revealing = input.type === 'password';
        input.type = revealing ? 'text' : 'password';
        button.textContent = revealing ? 'Hide' : 'Show';
        button.setAttribute('aria-pressed', String(revealing));
        const subject = button.getAttribute('aria-label')?.replace(/^(Show|Hide) /, '') || 'password';
        button.setAttribute('aria-label', `${revealing ? 'Hide' : 'Show'} ${subject}`);
        input.focus({ preventScroll: true });
    }));
    if ('serviceWorker' in navigator) navigator.serviceWorker.register('/lights/service-worker.js', { scope: '/lights/' }).catch(() => {});
})();
