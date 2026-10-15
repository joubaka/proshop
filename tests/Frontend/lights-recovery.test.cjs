const { test } = require('node:test');
const assert = require('node:assert/strict');
const { readFileSync } = require('node:fs');
const { chromium } = require('playwright');

test('member recovery counts down without promising OFF at zero', async () => {
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    try {
        for (const width of [390, 1280]) {
            const page = await browser.newPage({ viewport: { width, height: 844 } });
            const errors = [];
            page.on('pageerror', error => errors.push(error.message));
            await page.route('**/*', route => route.abort());
            for (const remaining of [30, 0]) {
                const state = { server_time: 1000, worker_seen_at: 1000, balance_cents: 1000, courts: [], sessions: [{
                    id: 'recovery', court_id: 1, control_state: 'review', recovery_check_after: 1000 + remaining,
                    billing_started: false, started_at: null, deadline_at: null, charged_cents: 0,
                }] };
                await page.setContent(`<div id="connection-notice" hidden></div><div id="wallet-balance"></div><div id="worker-status"></div>
                    <script id="lights-state" type="application/json">${JSON.stringify(state)}</script>
                    <section data-session="recovery"><h2 class="session-heading"></h2><span class="session-cost"></span>
                    <span class="session-time-label"></span><strong class="session-remaining"></strong><span class="session-court"></span>
                    <p class="session-status-note"></p><form class="stop-session-form"><button>Switch off</button></form></section>`);
                await page.addScriptTag({ content: readFileSync('public/lights-assets/portal.js', 'utf8') });
                assert.equal(await page.locator('.session-remaining').innerText(), remaining ? '30s' : 'Checking OFF…');
                assert.equal(await page.locator('button').isDisabled(), true);
                assert.match(await page.locator('.session-status-note').innerText(), remaining ? /safety window ends in 30 seconds/ : /Waiting for Shelly to reconnect and confirm OFF/);
                assert.doesNotMatch(await page.locator('section').innerText(), /administrator review/);
            }
            assert.deepEqual(errors, []);
            await page.close();
        }
    } finally { await browser.close(); }
});
