# Frontend replacement and verification

2026-09-03. The user chose replacements rather than commercial-license upgrades.

## Replacements

- Jodit 4 replaces the TinyMCE runtime. The thin `ProshopRichText` adapter retains the existing textarea names, saved HTML and first-party `tinymce`/`tinyMCE` initialization/save/remove calls. It is not a full TinyMCE API implementation for external modules. Editor HTML is sanitized with DOMPurify; that client-side safeguard is not a substitute for server-side validation of every rich-text input. [Jodit license/source](https://github.com/xdan/jodit).
- Chart.js 4 is bundled locally; the older CDN runtime and unused Highcharts dependency are removed. Shared chart options now use Chart.js 4 scales/plugins; inline chart JSON hex-escapes HTML-significant characters. Unused legacy chart templates remain as source, not loaded runtime. [Chart.js documentation](https://www.chartjs.org/docs/latest/getting-started/installation).
- Bootstrap 5 JavaScript replaces the vulnerable Bootstrap 3 runtime. `bootstrap-compat.js` translates existing data attributes and jQuery calls, tabs, dropdowns and collapse state; the existing Bootstrap 3/AdminLTE CSS remains for presentation. Popovers/tooltips cannot disable sanitization through the compatibility wrapper. [Bootstrap JavaScript API](https://getbootstrap.com/docs/5.3/getting-started/javascript/).
- Driver.js replaces Bootstrap Tour while keeping the existing first-party tour steps. [Driver.js API](https://driverjs.com/docs/api).
- esbuild and Sass replace Laravel Mix/webpack 4. The ordered static inputs are in `frontend.assets.json`. Unused Vue 2/Passport demo components remain as source but are not shipped in the runtime.
- Managed versions now provide DataTables and CSV/XLSX/PDF exporters, JSZip, pdfmake, jQuery UI and jQuery validation. The npm lockfile scan reports zero vulnerabilities. The transitive semver override selects a patched version; no advisories are ignored.

Jodit, Chart.js and Driver.js are MIT-licensed replacements. Retain dependency license notices when distributing builds; no paid editor/chart key was introduced. This is not a legal review of all existing application licenses.

## Build and test

From the repository, with Node and dependencies installed:

```powershell
# Deterministic dependency install; build tools have explicit platform packages.
npm ci --ignore-scripts

# Preview only in the separate acceptance environment.
npm run build:acceptance
.\scripts\start-local-acceptance.ps1
npm test

# Generate the local public artifacts after acceptance.
npm run production
```

The build never boots PHP or opens a database. `npm run production` means an optimized **local asset build**, not deployment. Generated `public/js/vendor.js`, CSS, fonts and manifest are Git-ignored: the release pipeline must build/copy them, not assume Git contains them. Deploy `public/js/common.js` and all PHP/Blade changes with the artifacts. Asset version 479 invalidates older client assets.

The sandbox router prefers `.local-acceptance/frontend` files when present. Rebuild that preview whenever source changes. Before handoff, compare SHA-256 hashes for the preview and `public` vendor/init JS and CSS to ensure the same assets were promoted.

Handoff verification: all five generated JS/CSS files (`init.js`, `vendor.js`, `init.css`, `vendor.css`, `rtl.css`) have matching preview/public SHA-256 hashes. The production-mode local build completed successfully.

## Browser coverage and limits

The Node/Playwright suite uses installed Chrome, a fresh context and synthetic local credentials. It checks the sandbox health identity first and blocks requests to every origin except `127.0.0.1:8097`. It does not manipulate an unrelated browser session.

Final run: **12 tests passing** (one parent and 11 journey checks), no skipped tests and no captured page JavaScript errors. Animated modal checks wait for Bootstrap's shown/hidden events rather than racing the transition.

Covered journeys: dashboard/report charts, product rich HTML and table save/reload, script rejection, mobile editor layout, duplicate initialization, destroy/recreate, source/full-screen controls, modal editor/AJAX serialization, real CSV/XLSX/PDF downloads, legacy menu/tab/collapse/alert/popover behavior, sanitized popovers, tour lifecycle, POS product search, purchase calendar and modal controls.

Artifacts are under `.local-acceptance/screenshots` and `.local-acceptance/exports`. The test updates synthetic product 1's description and may open its synthetic cash register; it does not finalize a sale or contact a provider. Do not run it concurrently with MySQL acceptance tests or edit the same fixtures during a run.

Some manually bundled plugins and the legacy presentation stylesheet remain. A clean npm scan covers registry dependencies, not that entire source tree. Full optional-module, booking, notification/email, RTL, accessibility, large imports, physical printing and broad mobile-device acceptance remain release work. The suite does not claim complete frontend compatibility or security certification.
