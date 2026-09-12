import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';

const base = 'http://127.0.0.1:8097';
test('separate local lights portal', { timeout: 120000 }, async t => {
    const health = await (await fetch(base + '/__local_acceptance_health')).json();
    assert.equal(health.environment, 'local-acceptance');
    assert.equal(health.customer_hardware, false, 'browser regressions must never run against customer hardware control');
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await context.newPage(); const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    // Every run creates its own synthetic member, preserving the demo player's balance/history.
    const email = 'browser-' + Date.now() + '@lights.test';
    try {
        await t.test('shared home opens lights with a discreet working POS entrance', async () => {
            await page.goto(base + '/');
            await page.waitForURL('**/lights/login');
            assert.equal(await page.locator('.intro h1').innerText(), 'A little more time on court.');
            await page.getByRole('link', { name: 'POS staff access' }).click();
            await page.waitForURL('**/pos/login');
            await page.getByText('POS staff login', { exact: true }).waitFor();
            await page.locator('#username').waitFor({ state: 'visible' });
            await page.getByRole('link', { name: 'Back to Court Lights', exact: false }).click();
            await page.waitForURL('**/lights/login');
            assert.equal(await page.locator('.login-panel [name=email]').count(), 3);
            await mkdir('.local-acceptance/screenshots', { recursive: true });
            await page.screenshot({ path: '.local-acceptance/screenshots/lights-shared-home.png', fullPage: true });
        });
        await t.test('register a separate member and render the mobile wallet', async () => {
            await page.goto(base + '/lights/login');
            await page.locator('details:has(form[action$="/lights/register"]) summary').click();
            const form = page.locator('form[action$="/lights/register"]');
            await form.locator('[name=name]').fill('Browser Player');
            await form.locator('[name=email]').fill(email);
            await form.locator('[name=password]').fill('LocalBrowser!2026');
            await form.locator('[name=password_confirmation]').fill('LocalBrowser!2026');
            await form.locator('[name=terms]').check();
            await Promise.all([page.waitForURL(/\/lights\/?$/), form.locator('button').click()]);
            assert.equal(await page.locator('#wallet-balance').textContent(), 'R 0.00');
            assert.equal(await page.locator('.court-start:enabled').count(), 0);
            assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        });
        await t.test('simulate a PayFast topup without a real provider and reject replay', async () => {
            await page.getByRole('tab', { name: 'Top up' }).click();
            await page.locator('#topup-amount').fill('10.00');
            await page.locator('#topup form button[type=submit], #topup form button:not([type])').click();
            await page.waitForURL('**/lights/topups/**');
            const checkout = page.url();
            await page.getByRole('button', { name: 'Simulate successful payment' }).click();
            await page.waitForURL(/\/lights\/?$/);
            assert.equal(await page.locator('#wallet-balance').textContent(), 'R 10.00');
            await page.goto(checkout);
            assert.equal(await page.getByRole('button', { name: 'Simulate successful payment' }).count(), 0);
            await page.getByRole('link', { name: 'Back to my lights', exact: false }).click();
        });
        await t.test('switch on and show live mobile countdown', async () => {
            await Promise.all([page.waitForResponse(r => r.url().endsWith('/lights/courts/1/start')), page.locator('[data-court="1"] .court-start').click()]);
            await page.locator('.active-session').waitFor({ state: 'visible' });
            await page.waitForFunction(() => /Court 3/.test(document.querySelector('.session-court')?.textContent || ''));
            assert.match(await page.locator('.session-court').textContent(), /Court 3/);
            assert.equal(await page.locator('.court-start:enabled').count(), 1, 'the second court remains available to the same funded member');
            await mkdir('.local-acceptance/screenshots', { recursive: true });
            await page.screenshot({ path: '.local-acceptance/screenshots/lights-mobile.png', fullPage: true });
        });
        await t.test('worker keeps charging after closing the page; stop settles once', async () => {
            const before = await (await context.request.get(base + '/lights/state')).json();
            assert.ok(before.worker_seen_at >= before.server_time - 15, 'local worker heartbeat');
            await page.goto('about:blank');
            await new Promise(resolve => setTimeout(resolve, 2500));
            await page.goto(base + '/lights/');
            const resumed = await (await context.request.get(base + '/lights/state')).json();
            assert.ok(resumed.session.charged_cents > before.session.charged_cents);
            const session = resumed.session.id;
            await Promise.all([page.waitForResponse(r => r.url().endsWith('/sessions/' + session + '/stop')), page.locator('.stop-session-form button').click()]);
            await page.locator('.active-session').waitFor({ state: 'hidden' });
            const finished = await (await context.request.get(base + '/lights/state')).json();
            assert.equal(finished.session, null);
            assert.ok(finished.balance_cents < 1000 && finished.balance_cents > 950);
            await page.getByRole('tab', { name: 'Activity' }).click();
            assert.match(await page.locator('.history-row small').first().textContent(), /\d+(?:h |m |s).*used/);
            assert.match(await page.locator('.history-row').first().textContent(), /−R \d+\.\d{2}/);
            await page.setViewportSize({ width: 1366, height: 900 });
            await page.screenshot({ path: '.local-acceptance/screenshots/lights-desktop.png', fullPage: true });
        });
        await t.test('member cannot open administration; administrator can', async () => {
            assert.equal((await context.request.get(base + '/lights/admin')).status(), 403);
            await page.getByRole('button', { name: 'Sign out' }).click();
            await page.waitForURL('**/lights/login');
            const form = page.locator('.login-panel > form');
            await form.locator('[name=email]').fill('admin@lights.test');
            await form.locator('[name=password]').fill('LocalLights!2026');
            await form.locator('button').click();
            await page.getByRole('link', { name: 'Admin', exact: true }).click();
            await page.getByRole('tab', { name: 'Activity' }).click();
            await page.getByRole('heading', { name: 'Wallet ledger' }).waitFor();
            assert.deepEqual(errors, []);
        });
        await t.test('administrator can record cash received from the member list', async () => {
            await page.getByRole('tab', { name: /Members/ }).click();
            await page.locator('#member-search').fill(email);
            const row = page.locator('[data-member-search]:visible');
            const cashForm = row.locator('form:has-text("Cash wallet top-up")');
            await cashForm.locator('[name=amount]').fill('12.50');
            await cashForm.locator('[name=reason]').fill('Browser cash receipt');
            const responsePromise = page.waitForResponse(response => response.url().includes('/adjustment') && response.request().method() === 'POST');
            await cashForm.getByRole('button', { name: 'Add cash to wallet' }).click();
            const response = await responsePromise;
            assert.equal(response.status(), 200);
            await page.getByText('Cash received and wallet credited.', { exact: true }).waitFor();
            assert.match(await row.locator('[data-member-balance]').textContent(), /^R \d+\.\d{2}$/);
            assert.deepEqual(errors, []);
        });
        await t.test('phone app manifest and offline shell never cache wallet pages', async () => {
            const appContext = await browser.newContext({ serviceWorkers: 'allow' });
            const appPage = await appContext.newPage();
            try {
                await appPage.goto(base + '/lights/login');
                await appPage.evaluate(async () => {
                    await navigator.serviceWorker.register('/lights/service-worker.js', { scope: '/lights/' });
                    await navigator.serviceWorker.ready;
                });
                const manifest = await (await appContext.request.get(base + '/lights-assets/manifest.webmanifest')).json();
                assert.equal(manifest.id, '/lights/'); assert.equal(manifest.start_url, '/lights/');
                assert.equal(manifest.scope, '/lights/'); assert.equal(manifest.display, 'standalone');
                await appPage.reload();
                assert.equal(await appPage.evaluate(() => !!navigator.serviceWorker.controller), true);
                assert.deepEqual(await appPage.evaluate(() => caches.keys()), []);
                await appContext.setOffline(true);
                await appPage.reload();
                await appPage.getByRole('heading', { name: 'You are offline' }).waitFor();
                assert.equal(await appPage.locator('#wallet-balance').count(), 0);
            } finally { await appContext.close(); }
        });
    } finally {
        // Keep synthetic history for inspection, but never leave our own session running.
        await page.goto(base + '/lights/login');
        const form = page.locator('.login-panel > form');
        await form.locator('[name=email]').fill(email);
        await form.locator('[name=password]').fill('LocalBrowser!2026');
        await form.locator('button').click();
        const state = await (await context.request.get(base + '/lights/state')).json();
        if (state.session) {
            const token = await page.locator('meta[name=csrf-token]').getAttribute('content');
            await context.request.post(base + '/lights/sessions/' + state.session.id + '/stop', { headers: { 'X-CSRF-TOKEN': token, Accept: 'application/json' } });
        }
        await browser.close();
    }
});
