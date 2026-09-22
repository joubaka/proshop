(() => {
    'use strict';
    const notice = document.getElementById('connection-notice');
    const show = (message, error = false) => { notice.hidden = false; notice.textContent = message; notice.classList.toggle('error', error); };
    const tabs = [...document.querySelectorAll('[data-admin-tab]')];
    const panels = [...document.querySelectorAll('[data-admin-panel]')];
    const activateTab = (name, focus = false) => {
        if (!tabs.some(tab => tab.dataset.adminTab === name)) name = 'overview';
        tabs.forEach(tab => { const active = tab.dataset.adminTab === name; tab.setAttribute('aria-selected', active); tab.tabIndex = active ? 0 : -1; if (active && focus) tab.focus(); });
        panels.forEach(panel => { panel.hidden = panel.dataset.adminPanel !== name; });
        history.replaceState(null, '', '#' + name);
    };
    tabs.forEach((tab, index) => {
        tab.addEventListener('click', () => activateTab(tab.dataset.adminTab));
        tab.addEventListener('keydown', event => {
            if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
            event.preventDefault();
            const next = event.key === 'Home' ? 0 : event.key === 'End' ? tabs.length - 1 : (index + (event.key === 'ArrowRight' ? 1 : -1) + tabs.length) % tabs.length;
            activateTab(tabs[next].dataset.adminTab, true);
        });
    });
    activateTab(location.hash.slice(1) || 'overview');
    window.addEventListener('hashchange', () => activateTab(location.hash.slice(1) || 'overview'));
    const memberSearch = document.getElementById('member-search');
    const memberSearchForm = document.querySelector('[data-member-search-form]');
    let memberSearchTimer;
    memberSearch?.addEventListener('input', () => {
        const query = memberSearch.value.trim();
        const clear = document.getElementById('member-search-clear'); if (clear) clear.hidden = query === '';
        clearTimeout(memberSearchTimer);
        if (query.length === 1) return;
        memberSearchTimer = setTimeout(() => memberSearchForm?.requestSubmit(), 350);
    });
    document.getElementById('member-search-clear')?.addEventListener('click', () => {
        memberSearch.value = '';
        memberSearchForm?.requestSubmit();
    });
    const historyMoney = cents => (cents < 0 ? '− R ' : '+ R ') + (Math.abs(cents) / 100).toFixed(2);
    document.querySelectorAll('[data-member-history]').forEach(history => history.addEventListener('toggle', async () => {
        if (!history.open || history.dataset.loaded === 'true' || history.dataset.loading === 'true') return;
        const content = history.querySelector('.member-history-content');
        history.dataset.loading = 'true';
        content.textContent = 'Loading transaction history…';
        try {
            const response = await fetch(history.dataset.url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error(response.status === 404 ? 'Member account not found.' : 'Could not load transaction history.');
            const data = await response.json();
            content.replaceChildren();
            if (!data.entries.length) {
                const empty = document.createElement('p'); empty.className = 'muted'; empty.textContent = 'No wallet transactions yet.'; content.append(empty);
            } else {
                const list = document.createElement('div'); list.className = 'member-history-list'; content.append(list);
                data.entries.forEach(entry => {
                    const row = document.createElement('div'); row.className = 'member-history-row';
                    const description = document.createElement('div');
                    const label = document.createElement('strong'); label.textContent = entry.label;
                    const detail = document.createElement('small');
                    const date = new Date(entry.created_at * 1000).toLocaleString('en-ZA', { dateStyle: 'medium', timeStyle: 'short' });
                    detail.textContent = date + (entry.reason ? ' · ' + entry.reason : '');
                    description.append(label, detail);
                    const values = document.createElement('div'); values.className = entry.amount_cents < 0 ? 'debit' : 'credit';
                    const amount = document.createElement('strong'); amount.textContent = historyMoney(entry.amount_cents);
                    const balance = document.createElement('small'); balance.textContent = 'Balance R ' + (entry.balance_after_cents / 100).toFixed(2);
                    values.append(amount, balance); row.append(description, values); list.append(row);
                });
                if (data.last_page > 1) {
                    const note = document.createElement('p'); note.className = 'muted member-history-note'; note.textContent = 'Showing the latest 20 of ' + data.total + ' transactions.'; content.append(note);
                }
            }
            history.dataset.loaded = 'true';
        } catch (error) { content.textContent = error.message; }
        finally { delete history.dataset.loading; }
    }));
    document.querySelectorAll('[data-admin-action]').forEach(form => form.addEventListener('submit', async event => {
        event.preventDefault();
        const button = form.querySelector('button'); if (button.disabled) return;
        button.disabled = true;
        try {
            const response = await fetch(form.action, { method: 'POST', body: new FormData(form), headers: { Accept: 'application/json' }, signal: AbortSignal.timeout(10000) });
            const data = await response.json();
            if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Request failed.');
            show(data.message);
            const row = form.closest('[data-member]');
            if ('active' in data) {
                row.querySelector('[data-member-status]').textContent = data.active ? 'enabled' : 'disabled';
                form.querySelector('[name=active]').value = data.active ? 0 : 1;
                button.textContent = data.active ? 'Disable' : 'Enable';
            }
            if ('balance_cents' in data) {
                row.querySelector('[data-member-balance]').textContent = 'R ' + (data.balance_cents / 100).toFixed(2);
                form.querySelector('[name=request_key]').value = crypto.randomUUID();
            }
        } catch (error) { show(error.name === 'TimeoutError' ? 'Request timed out. Refresh before trying again.' : error.message, true); }
        finally { button.disabled = false; }
    }));
    document.getElementById('refresh-health')?.addEventListener('click', async event => {
        const button = event.currentTarget; button.disabled = true;
        try {
            const response = await fetch(button.dataset.url, { headers: { Accept: 'application/json' }, cache: 'no-store' });
            if (!response.ok) throw new Error('Health check failed.');
            const data = await response.json();
            const values = { worker: [data.worker.healthy, data.worker.healthy ? 'Healthy' : 'Not reporting'], hardware: [!!data.hardware?.online, data.hardware?.online ? 'Online' : 'Offline'], uncertain: [data.attention.uncertain_controls + data.attention.uncertain_manual_commands === 0, data.attention.uncertain_controls + data.attention.uncertain_manual_commands], payments: [data.attention.payfast_pending_over_one_hour === 0, data.attention.payfast_pending_over_one_hour] };
            Object.entries(values).forEach(([key, value]) => { const node = document.querySelector('[data-health=' + key + ']'); node.textContent = value[1]; node.className = value[0] ? 'ok' : 'bad'; });
            show(data.healthy ? 'All monitored services are healthy.' : 'Health refreshed. One or more items need attention.', !data.healthy);
        } catch (error) { show(error.message, true); } finally { button.disabled = false; }
    });
})();
