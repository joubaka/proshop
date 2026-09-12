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
    const filterMembers = () => {
        const query = memberSearch.value.trim().toLowerCase(); let visible = 0;
        document.querySelectorAll('[data-member-search]').forEach(row => { row.hidden = !row.dataset.memberSearch.includes(query); if (!row.hidden) visible++; });
        const empty = document.getElementById('member-search-empty'); if (empty) empty.hidden = visible !== 0;
        const count = document.getElementById('member-result-count'); if (count) count.textContent = visible;
        const clear = document.getElementById('member-search-clear'); if (clear) clear.hidden = query === '';
    };
    memberSearch?.addEventListener('input', filterMembers);
    document.getElementById('member-search-clear')?.addEventListener('click', () => {
        memberSearch.value = ''; filterMembers(); memberSearch.focus();
    });
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
