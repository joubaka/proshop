import { test } from 'node:test';
import assert from 'node:assert/strict';
import { chromium } from 'playwright';
import { mkdir, readFile } from 'node:fs/promises';

const base = 'http://127.0.0.1:8097';
test('isolated local frontend acceptance', { timeout: 120000 }, async t => {
    const health = await (await fetch(base+'/__local_acceptance_health')).json();
    assert.equal(health.environment, 'local-acceptance');
    assert.equal(health.database, 'proshop_acceptance');
    const browser = await chromium.launch({ executablePath: 'C:/Program Files/Google/Chrome/Application/chrome.exe', headless: true });
    const context = await browser.newContext({ viewport: { width: 1366, height: 900 } });
    await context.route('**/*', route => new URL(route.request().url()).origin === base ? route.continue() : route.abort());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => { errors.push(error.message); console.error(error.stack); });
    try {
        await page.goto(base+'/pos/login');
        await page.locator('[name="username"]').fill('local.admin');
        await page.locator('[name="password"]').fill('LocalAcceptance!2026');
        await Promise.all([page.waitForURL('**/home'), page.locator('button[type="submit"]').click()]);
        await t.test('dashboard renders local Chart.js 4 charts without JavaScript errors', async () => {
            await page.waitForLoadState('networkidle');
            const charts = await page.evaluate(() => ({ version: Chart.version, count: Object.keys(Chart.instances).length }));
            assert.match(charts.version, /^4\./);
            assert.equal(charts.count, 2);
            assert.deepEqual(errors, []);
        });
        await t.test('product editor preserves rich HTML, rejects scripts, saves and reloads', async () => {
            await page.goto(base+'/products/1/edit');
            await page.locator('.jodit-wysiwyg').waitFor({ state: 'visible' });
            assert.deepEqual(errors, []);
            const html = '<p><strong>Local editor acceptance</strong></p><table><tbody><tr><td>Racket</td><td>Grip</td></tr></tbody></table><script>window.badEditorScript=true</script>';
            await page.evaluate(value => { ProshopRichText.get('product_description').value = value; ProshopRichText.triggerSave(); }, html);
            const stored = await page.locator('#product_description').inputValue();
            assert.match(stored, /<strong>Local editor acceptance<\/strong>/);
            assert.match(stored, /<table/);
            assert.doesNotMatch(stored, /<script/i);
            assert.equal(await page.evaluate(() => window.badEditorScript), undefined);
            await page.locator('button.submit_product_form[value="submit"]').click();
            await page.waitForURL('**/products', { timeout: 15000 });
            await page.goto(base+'/products/1/edit');
            await page.locator('.jodit-wysiwyg').waitFor({ state: 'visible' });
            assert.match(await page.locator('#product_description').inputValue(), /Local editor acceptance/);
            assert.deepEqual(errors, []);
        });
        await t.test('mobile editor and duplicate initialization have one live editor', async () => {
            await page.setViewportSize({ width: 390, height: 844 });
            await page.evaluate(async () => { await tinymce.init({ selector: '#product_description' }); });
            assert.equal(await page.locator('.jodit-container').count(), 1);
            await page.locator('.jodit-container').scrollIntoViewIfNeeded();
            await mkdir('.local-acceptance/screenshots', { recursive: true });
            await page.screenshot({ path: '.local-acceptance/screenshots/mobile-editor.png' });
            assert.deepEqual(errors, []);
        });
        await t.test('editor destroy/recreate preserves textarea content', async () => {
            await page.evaluate(async () => { tinymce.remove('#product_description'); await tinymce.init({ selector: '#product_description' }); });
            assert.equal(await page.locator('.jodit-container').count(), 1);
            assert.match(await page.locator('#product_description').inputValue(), /Local editor acceptance/);
            assert.deepEqual(errors, []);
        });
        await t.test('source editing and full-screen controls are functional', async () => {
            await page.evaluate(() => { const editor = ProshopRichText.get('product_description'); editor.toggleMode(); });
            assert.equal(await page.evaluate(() => ProshopRichText.get('product_description').getMode()), 2);
            await page.evaluate(() => { const editor = ProshopRichText.get('product_description'); editor.toggleMode(); editor.toggleFullSize(); });
            assert.equal(await page.evaluate(() => ProshopRichText.get('product_description').isFullSize), true);
            await page.evaluate(() => ProshopRichText.get('product_description').toggleFullSize());
            assert.deepEqual(errors, []);
        });
        await t.test('modal editor synchronizes AJAX form serialization and cleans up', async () => {
            await page.setViewportSize({ width: 1366, height: 900 });
            await page.evaluate(() => {
                const modal = document.createElement('div');
                modal.id = 'editor-acceptance-modal'; modal.className = 'modal view_modal';
                modal.innerHTML = '<div class="modal-dialog"><div class="modal-content"><div class="modal-body"><form><textarea id="email_body" name="email_body"></textarea></form></div></div></div>';
                document.body.appendChild(modal); $(modal).modal('show');
            });
            await page.locator('#editor-acceptance-modal .jodit-wysiwyg').waitFor({ state: 'visible' });
            await page.evaluate(() => { ProshopRichText.get('email_body').value = '<p>Modal test content</p>'; tinyMCE.triggerSave(); });
            assert.match(await page.evaluate(() => $('#editor-acceptance-modal form').serialize()), /Modal\+test\+content|Modal%20test%20content/);
            await page.evaluate(() => $('#editor-acceptance-modal').modal('hide'));
            assert.equal(await page.evaluate(() => ProshopRichText.get('email_body')), null);
            await page.evaluate(() => document.getElementById('editor-acceptance-modal').remove());
            assert.deepEqual(errors, []);
        });
        await t.test('catalogue exports contain real rows in CSV, XLSX and PDF', async () => {
            await page.goto(base+'/products');
            await page.locator('#product_table').getByText('Test Tennis Balls', { exact: false }).first().waitFor();
            await mkdir('.local-acceptance/exports', { recursive: true });
            for (const [button, extension, signature] of [['Export to CSV', 'csv', null], ['Export to Excel', 'xlsx', 'PK'], ['Export to PDF', 'pdf', '%PDF-']]) {
                const event = page.waitForEvent('download');
                await page.getByText(button, { exact: false }).first().click();
                const download = await event;
                const filename = `.local-acceptance/exports/products.${extension}`;
                await download.saveAs(filename);
                const bytes = await readFile(filename);
                assert.ok(bytes.length > 100, `${extension} is not empty`);
                if (signature) assert.equal(bytes.subarray(0, signature.length).toString(), signature);
                else assert.match(bytes.toString(), /Test Tennis Balls/);
            }
            assert.deepEqual(errors, []);
        });
        await t.test('legacy menus, tabs, collapse, dismiss and sanitized popovers work with Bootstrap 5', async () => {
            await page.evaluate(() => {
                const fixture = document.createElement('section'); fixture.id = 'compat-fixture';
                fixture.style.cssText = 'position:fixed;top:60px;left:250px;background:white;z-index:1040;padding:20px;width:500px';
                fixture.innerHTML = '<div class="dropdown"><button id="compat-menu" data-toggle="dropdown">Menu</button><ul class="dropdown-menu"><li><a href="#">Menu item</a></li></ul></div>'
                    + '<ul class="nav nav-tabs"><li class="active"><a href="#compat-tab1" data-toggle="tab">First</a></li><li><a href="#compat-tab2" data-toggle="tab">Second</a></li></ul>'
                    + '<div class="tab-content"><div class="tab-pane active" id="compat-tab1">First panel</div><div class="tab-pane" id="compat-tab2">Second panel</div></div>'
                    + '<button id="compat-collapse" data-toggle="collapse" data-target="#compat-panel">Expand</button><div class="collapse" id="compat-panel">Expanded content</div>'
                    + '<button id="compat-popover">Help</button><div class="alert alert-info" id="compat-alert"><button data-dismiss="alert">Dismiss</button>Notice</div>';
                document.body.appendChild(fixture);
                $('#compat-popover').popover({ html: true, trigger: 'click', content: '<strong>Safe help</strong><img src=x onerror="window.badPopover=true"><script>window.badPopover=true</script>' });
            });
            await page.locator('#compat-menu').click();
            await page.locator('#compat-fixture .dropdown-menu').waitFor({ state: 'visible' });
            assert.equal(await page.locator('#compat-fixture .dropdown').evaluate(el => el.classList.contains('open')), true);
            await page.locator('#compat-menu').click();
            await page.locator('#compat-fixture .dropdown-menu').waitFor({ state: 'hidden' });
            await page.locator('#compat-fixture a[href="#compat-tab2"]').click();
            await page.locator('#compat-tab2').waitFor({ state: 'visible' });
            assert.equal(await page.locator('#compat-tab1').isVisible(), false);
            await page.locator('#compat-collapse').click();
            await page.locator('#compat-panel.show').waitFor({ state: 'visible' });
            await page.locator('#compat-collapse').click();
            await page.locator('#compat-panel').waitFor({ state: 'hidden' });
            await page.locator('#compat-popover').click();
            await page.locator('.popover-body').waitFor({ state: 'visible' });
            assert.equal(await page.locator('.popover.show').evaluate(el => getComputedStyle(el).opacity), '1');
            assert.doesNotMatch(await page.locator('.popover-body').innerHTML(), /onerror|<script/i);
            assert.equal(await page.evaluate(() => window.badPopover), undefined);
            await page.locator('#compat-fixture [data-dismiss="alert"]').click();
            assert.equal(await page.locator('#compat-alert').count(), 0);
            await page.evaluate(() => { $('#compat-popover').popover('dispose'); document.getElementById('compat-fixture').remove(); });
            assert.deepEqual(errors, []);
        });
        await t.test('replacement guided tour renders and closes', async () => {
            await page.evaluate(() => {
                window.acceptanceTour = new Tour({ steps: [{ element: '#product_table', title: 'Catalogue tour', content: '<strong>Product catalogue</strong>' }] });
                acceptanceTour.init(); acceptanceTour.restart();
            });
            await page.locator('.driver-popover-title').waitFor({ state: 'visible' });
            assert.match(await page.locator('.driver-popover-description').innerHTML(), /Product catalogue/);
            await page.locator('.driver-popover-next-btn').click();
            await page.locator('.driver-popover').waitFor({ state: 'hidden' });
            assert.deepEqual(errors, []);
        });
        await t.test('report chart and POS search render after frontend replacement', async () => {
            await page.goto(base+'/reports/trending-products');
            assert.equal(await page.evaluate(() => Object.keys(Chart.instances).length), 1);
            await page.goto(base+'/pos/create');
            if (await page.locator('#amount').count()) {
                await page.locator('#amount').fill('0');
                await page.locator('button[type="submit"]').click();
            }
            await page.locator('#search_product').waitFor({ state: 'visible' });
            await page.locator('#search_product').fill('Test Tennis');
            // A single match is automatically selected by the existing POS flow.
            await page.locator('#pos_table tbody').getByText('Test Tennis Balls', { exact: false }).first().waitFor({ state: 'visible' });
            await page.screenshot({ path: '.local-acceptance/screenshots/desktop-pos.png' });
            assert.deepEqual(errors, []);
        });
        await t.test('purchase calendar switches date/time panels and legacy modal dismisses', async () => {
            await page.goto(base+'/purchases/create');
            await page.locator('#receiving_barcode').waitFor({ state: 'visible' });
            if (!await page.locator('#location_id').inputValue()) {
                const location = await page.locator('#location_id option:not([value=""])').first().getAttribute('value');
                assert.ok(location);
                await page.locator('#location_id').selectOption(location);
            }
            const stockItem = await page.evaluate(async () => (await (await fetch('/purchases/get_products?term=Test%20Tennis%20Balls&only_variations=true',{headers:{'X-Requested-With':'XMLHttpRequest'}})).json())[0]);
            assert.ok(stockItem.sub_sku);
            await page.locator('#receiving_barcode').fill(stockItem.sub_sku);
            await page.locator('#receiving_barcode').press('Enter');
            const receivedRow = page.locator('#purchase_entry_table tbody tr').filter({ hasText: 'Test Tennis Balls' }).first();
            await receivedRow.waitFor({ state: 'visible' });
            assert.equal(Number(await receivedRow.locator('.purchase_quantity').inputValue()), 1);
            await page.locator('#receiving_barcode').fill(stockItem.sub_sku);
            await page.locator('#receiving_barcode').press('Enter');
            await page.waitForFunction(() => Number(document.querySelector('#purchase_entry_table tbody .purchase_quantity')?.value) === 2);
            assert.equal(Number(await receivedRow.locator('.purchase_quantity').inputValue()), 2);
            await page.locator('#transaction_date').click();
            await page.locator('.bootstrap-datetimepicker-widget').waitFor({ state: 'visible' });
            await page.locator('.bootstrap-datetimepicker-widget [data-action="togglePicker"]').click();
            await page.locator('.bootstrap-datetimepicker-widget .timepicker').waitFor({ state: 'visible' });
            await page.locator('.bootstrap-datetimepicker-widget .datepicker').waitFor({ state: 'hidden' });
            await page.evaluate(() => $('#transaction_date').data('DateTimePicker').hide());
            const shown = page.evaluate(() => new Promise(resolve => $('#import_purchase_products_modal').one('shown.bs.modal', () => resolve(true))));
            await page.locator('[data-target="#import_purchase_products_modal"]').click();
            await shown;
            await page.locator('#import_purchase_products_modal').waitFor({ state: 'visible' });
            assert.equal(await page.locator('#import_purchase_products_modal').evaluate(el => getComputedStyle(el).opacity), '1');
            const hidden = page.evaluate(() => new Promise(resolve => $('#import_purchase_products_modal').one('hidden.bs.modal', () => resolve(true))));
            await page.locator('#import_purchase_products_modal [data-dismiss="modal"]').first().click();
            await hidden;
            await page.locator('#import_purchase_products_modal').waitFor({ state: 'hidden' });
            assert.equal(await page.locator('.modal-backdrop').count(), 0);
            assert.deepEqual(errors, []);
        });
        await t.test('stock control renders recommendations and creates a non-posting cycle-count snapshot', async () => {
            await page.goto(base+'/inventory-control');
            await page.getByRole('heading',{name:'Stock control',exact:false}).waitFor();
            await page.getByText('Reorder recommendations',{exact:true}).waitFor();
            await page.locator('input[name="name"]').fill('Browser count '+Date.now());
            await Promise.all([page.waitForURL('**/inventory-control/counts/**'),page.getByRole('button',{name:'Create count snapshot'}).click()]);
            await page.locator('#stock_count_barcode').waitFor({state:'visible'});
            assert.match(page.url(),/\/inventory-control\/counts\//);
            assert.deepEqual(errors,[]);
        });
    } finally { await browser.close(); }
});
