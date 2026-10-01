import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFile } from 'node:fs/promises';
import { chromium } from 'playwright';

test('pending OFF, safety review and completed sessions render without misleading ON progress', async () => {
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const script = await readFile('public/lights-assets/portal.js', 'utf8');
    const css = await readFile('public/lights-assets/portal.css', 'utf8');
    try {
        for (const width of [375, 1440]) {
            const page = await browser.newPage({ viewport: { width, height: 900 }, serviceWorkers: 'block' });
            const session = { id: 'test-session', court_id: 1, control_state: 'stopping', billing_started: false, started_at: null, deadline_at: null, charged_cents: 0, budget_cents: 10000, rate_cents: 3600, stop_requested_at: 100 };
            const state = { server_time: 100, balance_cents: 10000, worker_seen_at: 100, courts: [], sessions: [session] };
            await page.setContent(`<style>${css}</style><div id="connection-notice" hidden></div><div id="wallet-balance"></div><div id="worker-status"></div>
                <section class="panel active-panel" data-session="test-session"><h2 class="session-heading"></h2><span class="session-court"></span><div class="session-metrics"><div><span>Session cost</span><strong class="session-cost"></strong></div><div><span class="session-time-label"></span><strong class="session-remaining"></strong></div></div>
                <div class="session-progress"><div class="confirmation-ring"><strong class="session-progress-value"></strong></div><small class="confirmation-step"></small></div><p class="session-status-note"></p><form class="stop-session-form"><button class="button danger full">Switch off court &amp; finish</button></form></section>
                <script id="lights-state" type="application/json">${JSON.stringify(state)}</script>`);
            await page.evaluate(() => {
                window.testIntervals = [];
                window.setInterval = callback => window.testIntervals.push(callback);
                window.fetch = async () => ({ ok: true, json: async () => window.nextState });
            });
            await page.addScriptTag({ content: script });
            assert.equal(await page.locator('.session-progress').isVisible(), false);
            assert.match(await page.locator('.session-heading').innerText(), /Confirming lights are off/);
            assert.match(await page.locator('.session-status-note').innerText(), /Billing is frozen/);
            assert.equal(await page.locator('button').isDisabled(), true);
            session.control_state = 'review'; session.uncertain = true;
            await page.evaluate(async next => { window.nextState = next; await window.testIntervals[1](); }, state);
            assert.match(await page.locator('.session-heading').innerText(), /Safety review required/);
            assert.match(await page.locator('button').innerText(), /administrator review/);
            assert.equal(await page.locator('.session-progress').isVisible(), false);
            session.control_state = 'running'; session.uncertain = false; session.billing_started = true; session.started_at = 50; session.deadline_at = 200;
            await page.evaluate(async next => { window.nextState = next; await window.testIntervals[1](); }, state);
            assert.equal(await page.locator('.session-cost').innerText(), 'R 0.50', 'estimate freezes at server stop timestamp');
            session.control_state = 'reserved'; session.billing_started = false; session.started_at = null; session.stop_requested_at = null;
            await page.evaluate(async next => { window.nextState = next; await window.testIntervals[1](); }, state);
            assert.equal(await page.locator('.session-progress').isVisible(), true);
            assert.match(await page.locator('button').innerText(), /Switch off court/);
            assert.equal(await page.locator('button').isEnabled(), true);
            state.worker_seen_at = 1;
            await page.evaluate(async next => { window.nextState = next; await window.testIntervals[1](); }, state);
            assert.match(await page.locator('.session-status-note').innerText(), /worker, which is not reporting/);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
            state.sessions = [];
            await page.evaluate(async next => { window.nextState = next; await window.testIntervals[1](); }, state);
            assert.equal(await page.locator('[data-session]').count(), 0);
            await page.close();
        }
    } finally { await browser.close(); }
});
