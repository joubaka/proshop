# Local acceptance environment

Verified 2026-09-03, Windows/WAMP PHP 8.2.29, MySQL 8.4.7, Laravel 12.69.1.

Lights follow-up: [lights-portal.md](lights-portal.md) documents the separate `proshop_lights_acceptance` database, Lights migrations, demo accounts, worker and phone portal. Current combined results are 222 PHP tests / 1,882 assertions, 33 MySQL tests / 380 assertions, and 20 browser tests. The shop-only milestone below remains historical evidence; its migration history remains 279.

## Open and run

```powershell
# Existing sandbox: start/reuse its separate database and web server.
.\scripts\start-local-acceptance.ps1

# Only for a first setup: initialize the separate MySQL directory, migrate and seed.
.\scripts\start-local-acceptance.ps1 -Initialize

# Focused regression/audit suite (SQLite, no MySQL server needed).
.\scripts\test-application.ps1

# Real MySQL schema and application HTTP-kernel workflows.
.\scripts\test-local-acceptance.ps1

# Rebuild the isolated frontend preview and run Chrome browser regressions.
npm run build:acceptance
npm test
```

Open <http://127.0.0.1:8097/login>. Synthetic users: `local.admin`, `local.cashier`, `local.other`. Each uses the test-only password `LocalAcceptance!2026`. Never reuse this password for a real account.

The starter launches hidden local processes and checks both MySQL identity and the portal health endpoint. If this desktop session or a restart stops those processes, run the starter again. It preserves the sandbox records; it does not reset or drop databases.

## Isolation

- Separate MySQL **process**, bound to `127.0.0.1:13317`, with its own data directory `.local-acceptance/mysql`. The normal WAMP database service is not used, queried or migrated.
- Before application boot or database creation, the bootstrap checks `@@datadir` against that exact directory. A different server is rejected. The only application database is `proshop_acceptance`.
- No shop `.env`, config cache or customer dump is loaded. Fixtures are entirely synthetic. Storage, logs, uploads, compiled views and sessions are under the ignored `.local-acceptance` directory. Static assets prefer `.local-acceptance/frontend` when built, falling back to `public`; `/uploads` resolves only to sandbox uploads.
- Mail uses the array transport; notifications and queued jobs are faked; gateway configuration is empty/dummy. Laravel HTTP stray requests are rejected. Normal acceptance and browser runs leave both hardware flags off, even when a private Shelly key exists. Do not add live gateway credentials or run the production queue/scheduler here. This is not an OS-level network sandbox.
- Backup, installation and Ecommerce routes are intentionally blocked by the sandbox web router. Their production middleware is covered separately by the existing regression suite.
- This isolated MySQL instance has an empty local root password and contains **test data only**. Keep it loopback-only and do not deploy or expose this harness. The normal shop's MySQL credentials are unchanged.
- HTTP-kernel MySQL tests wrap fixtures/mutations in transactions and roll them back. Parallel-process tests instead create dedicated `Concurrency test ...` locations/invoices and remove only those fixtures afterwards; synthetic reference counters may advance. Browser-created synthetic transactions remain available for inspection. Browser tests also update synthetic product 1's description. Do not run the browser and MySQL suites simultaneously or edit the same fixtures during a run.

## Verified evidence

| Check | Result |
| --- | --- |
| Full migration history on the separate MySQL server | All 279 migration files recorded as completed, including invoice payment attempts |
| Default regression/audit suite | 167 tests / 1,335 assertions passing |
| Local MySQL acceptance suite | 29 tests / 344 assertions passing |
| Authenticated pages | 19 page renders with real schema, sidebar, session and permission middleware |
| Automated business workflow | Purchase + partial payment, POS sale, sales/purchase returns, purchase edit/delete, transfer completion/replay/delete, adjustment/delete, register close and receipt rendering |
| Security acceptance | CSRF, cashier restrictions, POST sign-out, foreign transfer/adjustment/register/location/product denial |
| Parallel processes | Four stock receipt/sale races including initially absent location rows; one invoice-payment race, with exactly one simulated charge/ledger payment |
| Browser | Administrator login, loaded dashboard, received purchase of 10 × R50, open cash register, R100 cash sale, stock 10 → 9, sign-out to login |
| Mobile viewport | Purchase form inspected at 390 × 844; corrected afternoon time shown as 16:06; viewport restored afterwards |
| Replacement frontend | Automated Chrome editor/chart/modal/control/export/POS checks; 390 × 844 editor and desktop POS screenshots |

The browser fixture purchase initially exposed an invalid numeric ENUM value in the test seed's time-format field. Fixture creation now uses the string `'24'`, and a setup assertion catches invalid formats. The synthetic purchase created before that correction is retained as test evidence; no real transaction dates were changed.

## Repairs found during acceptance

- Mobile detection and the layout no longer crash when User-Agent or remote-address metadata is missing.
- Time/date Blade directives choose each business's 12/24-hour format when rendering, not when compiling a shared template. The default agrees with client-side 24-hour formatting. Regression tests verify both formats against the same compiled expression.
- The header's Sign Out control now submits a CSRF-protected POST, matching the existing logout route. Both rendered-form tests and browser logout pass.
- Atomic stock updates now serialize missing location rows and use database increments. Invoice payments have durable reservations and a no-new-charge recovery path. Transfer completion is replay-safe; transfers, adjustments and register operations have additional tenant/location validation.
- Licensed editor/chart upgrade dependencies were replaced following the user's choice. The modern build and compatibility adapters are described in [frontend-upgrade.md](frontend-upgrade.md).

For a future deployment, ship these PHP/Blade changes together and clear/rebuild compiled views through the normal deployment process. Only sandbox views were cleared during this local pass.

## Still not certified

No deployment or production secret rotation was performed. Actual provider-sandbox payments, every role/location/entity combination, broad stock/lot/unit/overselling policies, large imports, visual PDF/physical printing, optional modules/bookings, legacy manually bundled plugins, cron/mail/backup operation and broad mobile-device testing remain separate acceptance work. Focused parallel races and actual CSV/XLSX/PDF downloads now pass, but do not certify every application path. See [phase-completion.md](phase-completion.md).
