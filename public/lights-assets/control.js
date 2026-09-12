(() => {
    'use strict';

    const feedback = document.getElementById('control-feedback');
    const activity = document.getElementById('manual-command-activity');
    const token = document.querySelector('meta[name="csrf-token"]')?.content || '';
    let trackedCommand = null;

    function message(text, error = false) {
        if (!feedback) return;
        feedback.textContent = text;
        feedback.hidden = false;
        feedback.classList.toggle('error', error);
    }

    function freshRequestKey(form) {
        const input = form.querySelector('[name="request_key"]');
        if (input && crypto.randomUUID) input.value = crypto.randomUUID();
    }

    function renderCommands(commands) {
        if (!activity) return;
        activity.replaceChildren();
        if (!commands.length) {
            const empty = document.createElement('p');
            empty.className = 'muted';
            empty.textContent = 'No manual switching commands have been requested.';
            activity.append(empty);
            return;
        }
        for (const command of commands) {
            const row = document.createElement('div');
            row.className = 'history-row';
            const body = document.createElement('div');
            const title = document.createElement('strong');
            title.textContent = `Court ${command.channel === 0 ? 3 : 4} · ${command.action.toUpperCase()}`;
            const meta = document.createElement('small');
            const when = new Intl.DateTimeFormat('en-ZA', {
                timeZone: 'Africa/Johannesburg', dateStyle: 'medium', timeStyle: 'medium',
            }).format(new Date(command.created_at * 1000));
            meta.textContent = `${when} SAST · ${command.state}`;
            body.append(title, meta);
            if (command.note) {
                const note = document.createElement('small');
                note.textContent = command.note;
                body.append(note);
            }
            row.append(body);
            activity.append(row);
        }
    }

    function renderControlState(report) {
        const commands = report.commands || [];
        for (const channel of [0, 1]) {
            const card = document.querySelector(`[data-control-channel="${channel}"]`);
            if (!card) continue;
            const state = (report.channels || []).find(item => item.channel === channel);
            const pending = commands.find(command => command.channel === channel && ['queued', 'sending'].includes(command.state));
            const latest = commands.find(command => command.channel === channel);
            const label = card.querySelector('[data-control-state]');
            if (label) {
                label.textContent = pending
                    ? `${pending.action.toUpperCase()} ${pending.state}`
                    : state?.output === true ? 'Currently ON' : state?.output === false ? 'Currently OFF' : 'Status unknown';
            }
            const onButton = card.querySelector('[data-control-button="on"]');
            const offButton = card.querySelector('[data-control-button="off"]');
            if (onButton) {
                onButton.disabled = !!pending || state?.output === true;
                onButton.textContent = pending?.action === 'on' ? `ON ${pending.state}…`
                    : state?.output === true ? `Court ${channel === 0 ? 3 : 4} is ON` : `Switch Court ${channel === 0 ? 3 : 4} ON — timed`;
            }
            if (offButton) {
                offButton.disabled = !!pending;
                offButton.textContent = pending?.action === 'off' ? `OFF ${pending.state}…`
                    : `Switch Court ${channel === 0 ? 3 : 4} OFF`;
            }
            if (trackedCommand && latest?.id === trackedCommand && !['queued', 'sending'].includes(latest.state)) {
                const successful = latest.state === 'completed';
                message(successful
                    ? `Court ${channel === 0 ? 3 : 4} switched ${latest.action.toUpperCase()} successfully.`
                    : (latest.note || `Command ended as ${latest.state}.`), !successful);
                trackedCommand = null;
            }
        }
        renderCommands(commands);
    }

    async function submit(form) {
        const button = form.querySelector('button[type="submit"], button:not([type])');
        const original = button?.textContent;
        if (button) { button.disabled = true; button.textContent = 'Queuing…'; }
        try {
            const response = await fetch(form.action, {
                method: 'POST', body: new FormData(form), cache: 'no-store',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'The command could not be queued.');
            message(data.message || 'Command queued.');
            trackedCommand = data.command?.id || null;
            const card = form.closest('[data-control-channel]');
            const state = card?.querySelector('[data-control-state]');
            if (state) state.textContent = `${form.dataset.action.toUpperCase()} queued`;
            freshRequestKey(form);
            window.dispatchEvent(new Event('lights:request-hardware-refresh'));
        } catch (error) {
            message(error.message || 'The request failed.', true);
        } finally {
            if (button) { button.disabled = false; button.textContent = original; }
        }
    }

    for (const form of document.querySelectorAll('[data-ajax-control]')) {
        form.addEventListener('submit', event => { event.preventDefault(); submit(form); });
    }

    for (const form of document.querySelectorAll('[data-ajax-arm]')) {
        form.addEventListener('submit', async event => {
            event.preventDefault();
            const button = form.querySelector('button'); const original = button.textContent;
            button.disabled = true; button.textContent = 'Checking court…';
            try {
                const response = await fetch(form.action, { method: 'POST', body: new FormData(form), cache: 'no-store',
                    headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token } });
                const data = await response.json().catch(() => ({}));
                if (!response.ok) throw new Error(Object.values(data.errors || {}).flat().join(' ') || data.message || 'Customer ON could not be enabled.');
                message(data.message);
                button.textContent = 'Next customer ON allowed';
                form.querySelectorAll('input[type=checkbox]').forEach(input => { input.checked = false; });
                return;
            } catch (error) { message(error.message || 'The request failed.', true); }
            finally { button.disabled = false; if (button.textContent !== 'Next customer ON allowed') button.textContent = original; }
        });
    }

    const refreshForm = document.querySelector('[data-ajax-status-refresh]');
    refreshForm?.addEventListener('submit', async event => {
        event.preventDefault();
        const button = refreshForm.querySelector('button');
        const original = button.textContent;
        button.disabled = true; button.textContent = 'Refreshing…';
        try {
            const response = await fetch(refreshForm.action, {
                method: 'POST', body: new FormData(refreshForm), cache: 'no-store',
                headers: { Accept: 'application/json', 'X-CSRF-TOKEN': token },
            });
            const data = await response.json().catch(() => ({}));
            if (!response.ok) throw new Error(data.message || 'Live status could not be refreshed.');
            message(data.message || 'Live status refreshed.');
            if (data.report) window.dispatchEvent(new CustomEvent('lights:hardware-state', { detail: data.report }));
        } catch (error) {
            message(error.message || 'The request failed.', true);
        } finally {
            button.disabled = false; button.textContent = original;
        }
    });

    window.addEventListener('lights:hardware-state', event => renderControlState(event.detail || {}));
})();
