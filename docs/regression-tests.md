# Running application regression and audit tests

## Commands

Lights follow-up: the current default suite is **184 tests / 1,500 assertions**, MySQL acceptance is **33 tests / 380 assertions**, and the combined browser run passed **19 tests**. Its separate database setup, simulation coverage and commands are documented in [lights-portal.md](lights-portal.md). The earlier milestone counts below are retained as historical evidence.

For the separate MySQL sandbox and its **29 tests / 344 assertions**, see [local-acceptance.md](local-acceptance.md). It is intentionally opt-in and never substitutes the MySQL connection into the default SQLite suite. Frontend build/Chrome commands and limits are in [frontend-upgrade.md](frontend-upgrade.md).

From `C:\wamp64\www\proshop`:

```powershell
# Default: all regression and audit checks.
.\scripts\test-application.ps1

# Existing working behaviour only. Not a full application security approval.
.\scripts\test-application.ps1 -Suite Regression

# Security safeguards and route integrity; repaired findings stay covered.
.\scripts\test-application.ps1 -Suite Audit
```

The runner prefers the installed WAMP PHP 8.2.29 runtime, disables Xdebug overhead, preserves PHPUnit's exit code, and does not install packages, migrate the shop database or contact live payment providers. Override the executable with `-PhpPath` if required. Dependencies must already be installed. It defaults to **All**, so known defects are not silently hidden.

Equivalent direct command for CI or another shell:

```text
php -d xdebug.mode=off vendor/phpunit/phpunit/phpunit --do-not-cache-result
```

## Safety and test design

- `phpunit.xml` forces testing configuration, SQLite `:memory:`, isolated config/route/package/service-cache paths, array cache/session/mail, and disabled web installation.
- `Tests\CreatesApplication` does not load the shop `.env`; its existing testing setup is preserved. Some legacy installation helpers still inspect whether the root `.env` file exists. These tests were verified in this configured checkout; a clean checkout/CI bootstrap has not yet been validated.
- New focused suites extend `Tests\Support\RegressionTestCase`, which refuses a non-memory/non-SQLite connection before creating fixtures and fakes mail, notifications and queued jobs. Laravel HTTP stray requests are blocked. This is not a universal network sandbox for direct cURL/Guzzle/provider SDK calls; do not add live gateway calls to these tests.
- Authentication/role tests use actual users, database-backed Spatie permissions and real HTTP routes. Only the presentation-only sidebar middleware is disabled for authenticated focused fixtures. Login's activity call is mocked and the actual activity logger is covered separately.
- Identity/financial/stock fixtures intentionally contain only the columns needed for focused tests. Brand and activity-log tests use selected real application migrations. This does **not** validate the complete production migration chain.
- The payment query tests register an SQLite `IF` function solely to execute the existing MySQL-style aggregate. They verify net payment arithmetic and status, not full MySQL behaviour or database concurrency.
- Laravel normally bypasses CSRF in testing; the dedicated destructive-request suite explicitly enables real CSRF enforcement after bootstrap, proves six mutation routes reject missing tokens and verifies an accepted token reaches the media-delete handler. It does not reload the shop environment.
- Audit tests assert the intended safe outcome. They are neither skipped nor rewritten to assert that unsafe behaviour should persist.

## Coverage map

| Area | Automated evidence now | Important remaining work |
| --- | --- | --- |
| Routing | First-party controller boundary inventory; missing-action and destructive-GET audits | Complete route matching/navigation and optional-capability handling |
| Guest access | 21 representative module endpoints plus browser login redirect | Every endpoint/verb, API/customer guards |
| Staff permissions | 13 restricted actions; brand create/update/delete with real permissions | Full role/location matrix and all write actions |
| Authentication | Username login, inactive/disabled accounts at login and during existing web/API sessions, stale business context, throttling, validated password change, logout | Reset flow, full token lifecycle and browser journeys |
| Business isolation | Brand forgery, account transaction/linked transfer deletion and expired-stock foreign ownership prevention | Contacts, reports, every financial/document/stock entity and full location matrix |
| Sales arithmetic | Fixed/percentage discounts, invoice tax, modifiers, fractions, formatting; repaired comma-decimal modifier regression | Full POS save/edit/void/refund, boundary validation and rounding policy |
| Payments | Token/gateway, net balances, durable reservation and recording retry, one-invoice parallel race, SDK request amount/currency/idempotency contract | Actual provider test-mode verification; unknown-outcome operational reconciliation; all payment paths |
| Inventory | Delta/rollback/non-stock tests plus actual purchase/sale/return/transfer/adjustment flows, replay guards and four two-process races | Overselling rules, lots/units and high-volume multi-operation concurrency |
| Accounting | Credit/debit directions; permission and tenant deletion audits | Transfers/deposits/ledgers, reconciliation and reports end to end |
| Activity log | Existing logger's actor, subject, business, before/after and background records | Complete action coverage, redaction, retention, report permissions, tamper resistance |
| Installer/uploads/errors/scheduler | Existing 28-test baseline retained | Deployment/web-server rules, real scheduler/mail/storage operation |
| Dependencies/imports/printing | CSV import, XLSX formula roundtrip, Dompdf rendering/options, mPDF/FPDI page import, Collective forms, DataTables escaping, barcode SVG, local dates | Representative document visual QA, full imports and large files; third-party modules |
| Optional modules and bookings | Guest boundaries; controlled Ecommerce unavailability | Positive workflows, schema/module compatibility and browser checks |

## Verified baseline

- Before changes: 28 tests / 139 assertions passing.
- Original expanded regression suite: 99 tests / 1,007 assertions passing, with ten additional failing audit cases across eight findings.
- Current repair baseline: **167 tests / 1,335 assertions passing**, including Audit, on PHP 8.2.29 / Laravel 12.69.1; separate MySQL **29 tests / 344 assertions**.
- Fresh locked Composer audit: zero advisories and zero abandoned packages, exit code 0, with plugins/scripts disabled and no cache. The earlier approval-service blocker no longer prevents this check. Composer validation and installed-platform requirements previously passed; those checks were not rerun for the audit-only follow-up. Final npm lockfile audit: zero vulnerabilities.
- No test-coverage percentage is claimed. Test counts and route-boundary assertions are not measures of complete business-workflow coverage.

See `docs/application-audit.md` for findings, risk ranking, evidence limitations and next acceptance gates.
