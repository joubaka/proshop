import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir } from 'node:fs/promises';

const liveStatusCheck = process.env.SHELLY_LIVE_READONLY_CHECK === '1';
test(liveStatusCheck ? 'explicit live Shelly read-only status check through the local page' : 'local Shelly setup renders without saving credentials or contacting hardware', { timeout: 45000 }, async () => {
    const base = 'http://127.0.0.1:8097';
    const health = await (await fetch(base + '/__local_acceptance_health')).json();
    assert.equal(health.environment, 'local-acceptance');
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    try {
        const context = await browser.newContext({ viewport: { width: 390, height: 844 }, serviceWorkers: 'block' });
        await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
        const page = await context.newPage();
        const errors = [];
        page.on('pageerror', error => errors.push(error.message));
        await page.goto(base + '/lights/login');
        const login = page.locator('form[action$="/lights/login"]');
        await login.locator('[name=email]').fill('admin@lights.test');
        await login.locator('[name=password]').fill('LocalLights!2026');
        await Promise.all([page.waitForURL(/\/lights\/?$/), login.locator('button').click()]);
        await page.getByRole('link', { name: 'Admin', exact: true }).click();
        await page.getByRole('link', { name: 'Shelly connection settings' }).click();
        await page.getByRole('heading', { name: 'Shelly Cloud connection' }).waitFor();
        assert.equal(await page.locator('[name=shelly_key]').getAttribute('type'), 'password');
        assert.equal(await page.locator('[name=shelly_key]').inputValue(), '');
        assert.match(await page.locator('.simulation').innerText(), /READ-ONLY SETUP/);
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        assert.equal(await page.locator('.court-start').count(), 0);
        await page.getByRole('link', { name: 'Open admin controls' }).click();
        await page.getByRole('heading', { name: 'Court lights' }).waitFor();
        assert.match(await page.locator('.simulation').innerText(), health.hardware_commissioning ? /ADMIN HARDWARE CONTROL/ : /SAFE SIMULATION/);
        assert.equal(await page.getByRole('button', { name: /Switch Court 3 ON/ }).count(), 1);
        assert.equal(await page.getByRole('button', { name: 'Switch Court 3 OFF' }).count(), 1);
        assert.equal(await page.getByText('arming checklist', { exact: false }).count(), 1);
        const controlUrl = page.url();
        await page.route('**/lights/admin/control/manual-on', route => route.fulfill({
            status: 200, contentType: 'application/json',
            body: JSON.stringify({ message: 'Synthetic AJAX command queued.', command: { state: 'queued' } }),
        }));
        await page.getByRole('button', { name: /Switch Court 3 ON/ }).click();
        await page.getByText('Synthetic AJAX command queued.').waitFor();
        assert.equal(page.url(), controlUrl, 'AJAX control must not navigate');
        await page.unroute('**/lights/admin/control/manual-on');
        assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
        await page.getByRole('link', { name: 'Shelly connection settings' }).click();
        if (liveStatusCheck) {
            // Manual opt-in only. Uses the privately saved key through the server, never reads it.
            // The fixed endpoint supports status only; no relay/configuration command exists.
            const checkButton = page.getByRole('button', { name: 'Check connection — status only' });
            assert.equal(await checkButton.isEnabled(), true, 'A private cloud key must already be saved');
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'domcontentloaded' }),
                checkButton.click(),
            ]);
            const notices = await page.locator('.notice').allTextContents();
            assert.equal(await page.getByRole('heading', { name: /Cloud reports device (online|offline)/ }).count(), 1, notices.join(' '));
            assert.equal(await page.locator('[role=alert]:visible').count(), 0);
            assert.equal(await page.locator('[name=shelly_key]').inputValue(), '');
        }
        await mkdir('.local-acceptance/screenshots', { recursive: true });
        await page.screenshot({ path: '.local-acceptance/screenshots/shelly-setup-mobile.png', fullPage: true });
        await page.setViewportSize({ width: 1280, height: 900 });
        await page.screenshot({ path: '.local-acceptance/screenshots/shelly-setup-desktop.png', fullPage: true });
        assert.deepEqual(errors, []);
        // Never save a fake key over a user's key. Ordinary runs never click the real check button.
    } finally { await browser.close(); }
});
