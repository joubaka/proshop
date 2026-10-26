import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

test('connection check disables duplicate starts and retains the facility warning on phone and desktop', async () => {
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const script = await readFile('public/lights-assets/portal.js', 'utf8');
    const css = await readFile('public/lights-assets/portal.css', 'utf8');
    const warning = 'We cannot reach the lights controller. Please ask the facility to check its power, Wi-Fi and internet connection, then try again. No session was started.';
    try {
        for (const width of [375, 1440]) {
            const page = await browser.newPage({ viewport: { width, height: 900 }, serviceWorkers: 'block' });
            const state = { server_time: 100, balance_cents: 10000, worker_seen_at: 100, email_verified: true, sessions: [],
                courts: [{ id: 1, active: true, rate_cents: 6000, control_ready: true, control_reason: 'Ready', in_use: false, pending_action: null }] };
            await page.setContent(`<style>${css}</style><main class="shell"><div id="connection-notice" class="notice error" role="alert" hidden></div><div id="wallet-balance"></div><div id="worker-status"></div>
                <article class="panel court" data-court="1"><h3>Court 3</h3><span class="court-status"></span><p class="court-rate"></p><p class="court-note"></p>
                <div class="court-switch-progress" role="status" hidden><div class="confirmation-ring"><strong></strong></div><small class="confirmation-step"></small></div>
                <form action="https://lights.test/lights/courts/1/start" data-light-action data-action-kind="start"><input type="hidden" name="request_key" value="test-key"><input type="hidden" name="quoted_rate_cents" value="6000"><button class="court-start button primary full">Switch on</button></form></article></main>
                <script id="lights-state" type="application/json">${JSON.stringify(state)}</script>`);
            await page.evaluate(({ state, warning }) => {
                window.postCount = 0;
                window.setInterval = () => 0;
                window.fetch = async (_url, options) => {
                    if (options.method === 'POST') {
                        window.postCount++;
                        await new Promise(resolve => { window.finishCheck = resolve; });
                        return { ok: false, status: 422, json: async () => ({ errors: { lights: [warning] } }) };
                    }
                    return { ok: true, status: 200, json: async () => state };
                };
            }, { state, warning });
            await page.addScriptTag({ content: script });
            await page.locator('.court-start').focus();
            await page.keyboard.press('Enter');
            await page.waitForFunction(() => document.querySelector('.court-start').textContent === 'Checking connection…');
            assert.equal(await page.locator('.court-start').isDisabled(), true);
            assert.equal(await page.locator('.confirmation-step').innerText(), 'Checking connection…');
            await page.locator('form').evaluate(form => form.dispatchEvent(new Event('submit', { cancelable: true })));
            assert.equal(await page.evaluate(() => window.postCount), 1);
            await page.evaluate(() => window.finishCheck());
            await page.waitForFunction(() => !document.querySelector('.court-start').disabled);
            assert.equal(await page.locator('[role=alert]').innerText(), warning);
            await page.waitForTimeout(1800); // The automatic reconciliation must not erase the warning.
            assert.equal(await page.locator('[role=alert]').isVisible(), true);
            assert.equal(await page.locator('[role=alert]').innerText(), warning);
            assert.equal(await page.locator('.court-switch-progress').isVisible(), false);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            await page.screenshot({ path: `tests/.cache/lights-connection-${width}.png` });
            await page.close();
        }
    } finally { await browser.close(); }
});

test('pending OFF, safety review and completed sessions render without misleading ON progress', async () => {
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const script = await readFile('public/lights-assets/portal.js', 'utf8');
    const css = await readFile('public/lights-assets/portal.css', 'utf8');
    try {
        for (const width of [375, 1440]) {
            const page = await browser.newPage({ viewport: { width, height: 900 }, serviceWorkers: 'block' });
            const session = { id: 'test-session', court_id: 1, control_state: 'stopping', billing_started: false, started_at: null, deadline_at: null, charged_cents: 0, budget_cents: 10000, rate_cents: 3600, stop_requested_at: 100 };
            const state = { server_time: 100, balance_cents: 10000, worker_seen_at: 100, email_verified: true, courts: [{ id: 1, active: true, control_ready: true, in_use: true, rate_cents: 6000, pending_action: null }], sessions: [session] };
            await page.setContent(`<style>${css}</style><div id="connection-notice" hidden></div><div id="wallet-balance"></div><div id="worker-status"></div>
                <section class="panel active-panel" data-session="test-session"><h2 class="session-heading"></h2><span class="session-court"></span><div class="session-metrics"><div><span>Session cost</span><strong class="session-cost"></strong></div><div><span class="session-time-label"></span><strong class="session-remaining"></strong></div></div>
                <div class="session-progress"><div class="confirmation-ring"><strong class="session-progress-value"></strong></div><small class="confirmation-step"></small></div><p class="session-status-note"></p><form class="stop-session-form"><button class="button danger full">Switch off court &amp; finish</button></form></section>
                <article class="panel court" data-court="1"><span class="court-status"></span><span class="court-rate"></span><form><button class="court-start button primary">Switch on</button></form></article>
                <script id="lights-state" type="application/json">${JSON.stringify(state)}</script>`);
            await page.evaluate(() => {
                window.testIntervals = [];
                window.setInterval = callback => window.testIntervals.push(callback);
                window.fetch = async () => ({ ok: true, json: async () => window.nextState });
            });
            await page.addScriptTag({ content: script });
            assert.equal(await page.locator('.court-start').isDisabled(), true);
            assert.equal(await page.locator('.session-progress').isVisible(), false);
            assert.match(await page.locator('.session-heading').innerText(), /Confirming lights are off/);
            assert.match(await page.locator('.session-status-note').innerText(), /Billing is frozen/);
            assert.equal(await page.locator('.stop-session-form button').isDisabled(), true);
            session.control_state = 'review'; session.uncertain = true;
            await page.evaluate(async next => { window.nextState = next; await window.testIntervals[1](); }, state);
            assert.match(await page.locator('.session-heading').innerText(), /Checking lights are off/);
            assert.match(await page.locator('.stop-session-form button').innerText(), /Waiting for safe recovery/);
            assert.match(await page.locator('.session-status-note').innerText(), /reset this session automatically/);
            assert.equal(await page.locator('.session-progress').isVisible(), false);
            session.control_state = 'running'; session.uncertain = false; session.billing_started = true; session.started_at = 50; session.deadline_at = 200;
            await page.evaluate(async next => { window.nextState = next; await window.testIntervals[1](); }, state);
            assert.equal(await page.locator('.session-cost').innerText(), 'R 0.50', 'estimate freezes at server stop timestamp');
            session.control_state = 'reserved'; session.billing_started = false; session.started_at = null; session.stop_requested_at = null;
            await page.evaluate(async next => { window.nextState = next; await window.testIntervals[1](); }, state);
            assert.equal(await page.locator('.session-progress').isVisible(), true);
            assert.match(await page.locator('.stop-session-form button').innerText(), /Switch off court/);
            assert.equal(await page.locator('.stop-session-form button').isEnabled(), true);
            state.worker_seen_at = 1;
            await page.evaluate(async next => { window.nextState = next; await window.testIntervals[1](); }, state);
            assert.match(await page.locator('.session-status-note').innerText(), /worker, which is not reporting/);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            state.sessions = []; state.courts[0].in_use = false; state.worker_seen_at = 100;
            await page.evaluate(async next => { window.nextState = next; await window.testIntervals[1](); }, state);
            assert.equal(await page.locator('[data-session]').count(), 0);
            assert.equal(await page.locator('.court-start').isEnabled(), true);
            await page.screenshot({ path: `tests/.cache/lights-recovered-${width}.png` });
            await page.close();
        }
    } finally { await browser.close(); }
});
