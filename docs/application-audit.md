# Application audit and regression baseline

Date: 2026-09-03. Scope: current Proshop checkout, not the proposed lights system.

## Outcome

Latest local follow-up: **279 migrations**, **167 default tests / 1,335 assertions**, and **29 MySQL acceptance tests / 344 assertions**. Stock races, durable invoice-payment reservation/recovery, transfer/adjustment/register authorization and frontend replacements have been added and tested. The final npm lockfile audit reports zero vulnerabilities. See [phase-completion.md](phase-completion.md) for the current ledger and remaining release gates. The figures below describe earlier repair milestones.

The eight application findings below have been repaired following approval. The complete default Unit/Feature/Audit run passes **146 tests / 1,221 assertions** on PHP 8.2.29 and Laravel 12.69.1. The final locked Composer audit returns **zero advisories and zero abandoned packages**, with insecure-package blocking enabled and no ignored advisories. The original 48 advisory records are retained below as historical evidence, not current failures.

This is a broad code/security audit and automated regression foundation, **not full application certification or complete end-to-end coverage**. Application code and dependencies were changed locally; no shop database, live payment provider, device or deployed server was changed. Existing dirty-worktree changes were preserved. The existing activity logger was tested; no new application-wide activity-capture system was introduced.

## Remediation completed

| Finding | Implemented safeguard |
| --- | --- |
| A01 | Account and linked-transfer deletion are tenant-scoped, subtype-checked and atomic, with locked reads. Owned success and foreign-link rollback tests pass. |
| A02 | Unused payment-account scaffold retired: authorized listing redirects to the canonical account workspace; other legacy operations return 410; missing account permission returns 403. Legacy records are not deleted or remapped. |
| A03 | Shared authentication middleware rechecks fresh user/business eligibility, including API and routes outside the main group; revoked or mismatched sessions are invalidated. |
| A04 | Modifier prices and quantities are unformatted once, including comma-decimal fractional quantities. |
| A05 | Password changes require current password, a 6–72 character replacement and matching confirmation; invalid input cannot change the hash. Minimum length follows existing registration policy. |
| A06 | Unimplemented resource actions and dead explicit routes are excluded. Existing supported sales-detail and combined purchase-return routes remain available. |
| A07 | Five audited destructive GET actions now require POST/DELETE; backup creation also requires POST. Forms/AJAX are updated. Real CSRF tests verify missing-token rejection and a valid-token success path. |
| A08 | Missing optional Ecommerce module returns controlled 503; unavailable credentials return 401 when the module exists, rather than a class-loading failure. |

Adjacent checks also found and repaired cross-business expired-stock removal and local-date formatting that could shift midnight into the previous day. The stock removal now checks business/location, remaining quantity and locks the purchase line inside the transaction. This is not a proof of full inventory concurrency correctness.

### Dependency remediation

- Updated vulnerable HTTP, mail, PDF, spreadsheet, Markdown and cryptography libraries through targeted Composer updates. PHP remains 8.2-compatible.
- Laravel 11.56.1 still matched three advisory records; upgrading to **12.69.1** cleared them. The framework requirement now starts at 12.61.1. See the [Laravel 12 upgrade guide](https://laravel.com/docs/12.x/upgrade).
- Dompdf adapter is now 3.1.2 / engine 3.1.6, with remote-resource and embedded-PHP execution disabled by default; XLSX/CSV and PDF smoke tests pass.
- Barcode 12.1.0 and DataTables 12.7.2 support the framework upgrade. Replaced the Laravel-11-only form-builder fork with **konekt/html 6.8.0**, preserving the Collective Form/Html API. Its [maintainer describes it as a drop-in replacement](https://github.com/artkonekt/html); compatibility tests verify CSRF, method spoofing, escaping and selection.
- Removed the incompatible unused `unicodeveloper/laravel-paystack` adapter and its provider/alias. No Paystack application calls or routes exist in this checkout; its configuration file is retained. External modules not present here must be assessed separately before deployment. This does not change PayFast or other active gateway implementations.
- `composer validate` and local installed-platform requirements pass. Final `composer --no-cache audit --locked --format=json` returns empty `advisories` and `abandoned` arrays. These results describe this lockfile at scan time, not future advisories or deployed vendor state.
- Installation used `--no-scripts --no-plugins` to avoid bootstrapping the shop environment. The package manifest was rebuilt directly without loading `.env`; test package/service manifests are isolated under `tests/.cache`.
- Composer still reports an existing, unregistered Restaurant module scaffold with a mismatched PSR-4 namespace. It is not among the registered application routes and was not silently activated or rewritten as part of this repair.

## Original findings — historical reproductions, now resolved

### A01 — High: cross-business account transaction deletion

`app/Http/Controllers/AccountController.php:537` checks `delete_account_transaction`, reads the current business ID, but retrieves the target and linked transfer by unscoped ID. An authenticated user in business 1 with this permission can soft-delete a deposit belonging to an account in business 2.

Evidence: `AuthorizationAuditTest::test_account_transaction_deletion_cannot_cross_business_boundaries` creates two businesses and an account owned by business 2. The real HTTP controller request from business 1 deletes its transaction in the isolated database.

Fix direction: resolve the target through an account belonging to the authenticated business; validate any linked transfer's ownership; update both sides transactionally. Keep a same-business success case and cross-business denial cases, including a malicious linked-transfer ID.

### A02 — High: payment-account deletion requires no account permission

`app/Http/Controllers/PaymentAccountController.php:52` deletes by ID without a permission check. Its other actions also contain no explicit permission or ownership boundary; index lists all records.

Evidence: `AuthorizationAuditTest::test_staff_without_account_permissions_cannot_delete_payment_accounts` signs in a real user with no permissions and successfully reaches the deletion. The row-preservation assertion fails.

Important limitation: this checkout has no migration defining `payment_accounts`. The test uses a minimal model-contract table; it proves the controller's missing authorization when that table exists, not that this feature is deployed or operational on the live database. Decide whether this is obsolete scaffolding or a supported feature before adding fields/permissions or removing routes. Do not confuse it with the separate `AccountController` financial accounts.

### A03 — High: access revocation does not stop existing sessions

`app/Http/Middleware/CheckUserLogin.php:16` checks user type only. Login validates active status, allowed login and active business, but existing sessions are not rechecked by this middleware.

Evidence: three data sets in `AuthorizationAuditTest::test_revoked_access_stops_existing_sessions_from_mutating_data` each change one of user status, `allow_login`, or business activity after login. All three can still create a brand. The user is refreshed before the request; the business session retains the existing cached state, as a real existing session would.

Fix direction: a shared current-access check for authenticated application routes, including routes outside the main middleware group. Revoke/deny access using fresh authoritative state and clear stale session context. Test HTML, JSON, and any supported API/customer guard separately.

### A04 — High: comma-decimal invoice modifiers are overcharged

`app/Utils/ProductUtil.php:609` unformats modifier prices twice. With `.` as thousands separator and `,` as decimal separator, `1,50` becomes `1.5` and then `15`.

Evidence: `LocalizedInvoiceAuditTest` calculates a 10.00 item plus two 1.50 modifiers. Expected 13.00; actual 40.00. This affects the configured comma-decimal locale, not every invoice. Modifier quantity parsing also warrants review, but that is not covered by this reproduction.

Fix direction: unformat each monetary/quantity input once at the boundary; test both currency conventions, modifiers, fractions and discounts.

### A05 — Medium: password-change endpoint accepts an empty new password

`app/Http/Controllers/UserController.php:114` verifies the old password but hashes the submitted new value without validating it.

Evidence: `AuthorizationAuditTest::test_password_change_rejects_empty_new_password` supplies a correct current password and an empty replacement. The stored password changes. This requires an authenticated user and their current password; it is not an unauthenticated password reset.

Fix direction: explicit server-side new-password and confirmation validation, consistent with registration/reset policy. Add missing, empty, weak, mismatched and valid input tests without relying on browser validation.

### A06 — Medium: 18 registered controller actions do not exist

Evidence: `RouteIntegrityAuditTest::test_every_registered_controller_action_exists_and_is_public` inspects every first-party controller route. The following actions are absent:

- `SellController@update`, `SellController@destroy`
- `ReportController@getOpeningStock`
- `InvoiceLayoutController@destroy`
- `CashRegisterController@edit`, `@update`, `@destroy`
- `SalesCommissionAgentController@show`
- `CustomerGroupController@show`
- `SellReturnController@create`, `@edit`, `@update`, `@getProductRow`
- `BackUpController@store`
- `PurchaseReturnController@edit`, `@update`
- `DiscountController@show`
- `AccountController@destroy`

Some may be unused resource scaffolding. This proves route-definition defects, not that each corresponding UI journey is currently used or broken. Decide whether each route should be implemented or excluded; do not add empty success handlers just to make the audit green. Check literal-path/resource-route ordering while repairing this surface.

### A07 — Medium: destructive GET routes

The route-level audit identifies five destructive GET endpoints:

- `delete-media/{media_id}`
- `revert-sale-import/{batch}`
- `stock-adjustments/remove-expired-stock/{purchase_line_id}`
- `backup/delete/{file_name}`
- `account/delete-account-transaction/{id}`

GET requests do not receive the normal unsafe-method CSRF token verification. Some handlers require AJAX headers; that constrains an attack but is not a substitute for correct HTTP semantics and CSRF protection. This test inventories the route risk; it does not claim a demonstrated cross-origin exploit. Other GET state changes, such as activate/toggle actions, still need review.

Fix direction: change mutating routes to POST/DELETE, update every caller, enforce authorization, and test CSRF with middleware active. Standard Laravel feature tests bypass CSRF by default, so current request tests do not prove CSRF enforcement.

### A08 — Medium: public ecommerce routes reference an absent module

`routes/web.php:391` registers `/api/ecom` routes. Their `EcomApi` middleware directly references `Modules\Ecommerce\Entities\EcomApiSetting`, which cannot be autoloaded in this checkout.

Evidence: `RouteIntegrityAuditTest::test_ecommerce_boundary_dependency_is_available` fails. This is a dependency/availability defect, not evidence of an authentication bypass. A deployment containing additional modules may differ.

Fix direction: either restore a supported module or conditionally disable unsupported routes. Invalid credentials should produce a controlled denial rather than a missing-class error. The test should then verify that capability-aware behaviour instead of unconditionally requiring a retired module.

## Original approved dependency scan — before remediation, 2026-09-03

After the user explicitly approved disclosure of package/version metadata to Composer/Packagist, the read-only command `composer --no-cache audit --locked --format=json` completed. Exit code 1 indicates matched security advisories, not a connection failure. It checked the lockfile, including development dependencies; it does not establish the exact deployed vendor state. No packages or lockfiles were changed.

**48 advisory records across 15 locked packages:** 1 critical, 16 high, 26 medium, 3 low and 2 without a supplied severity. These are advisory records rather than 48 independently verified exploits; notably, two Laravel PKSA records refer to the same GHSA email-validation issue. No abandoned packages were returned, and no separate ignored-advisories section was returned by this Composer result.

| Locked package | Locked version | Advisory records |
| --- | --- | ---: |
| dompdf/dompdf | v2.0.8 | 6 |
| guzzlehttp/guzzle | 7.10.0 | 9 |
| guzzlehttp/psr7 | 2.9.0 | 4 |
| laravel/framework | v11.51.0 | 3 |
| league/commonmark | 2.8.2 | 10 |
| paragonie/sodium_compat | v2.5.0 | 1 |
| phpoffice/phpspreadsheet | 1.30.4 | 4 |
| phpseclib/phpseclib | 3.0.51 | 2 |
| setasign/fpdi | v2.6.6 | 1 |
| symfony/http-foundation | v7.4.8 | 1 |
| symfony/http-kernel | v7.4.8 | 1 |
| symfony/mailer | v7.4.8 | 1 |
| symfony/mime | v7.4.8 | 2 |
| symfony/polyfill-intl-idn | v1.36.0 | 1 |
| symfony/routing | v7.4.8 | 2 |

### Priority and interpretation

- **Spreadsheet handling:** PhpSpreadsheet 1.30.4 matches critical advisory `PKSA-x678-4z45-v3d5` / `GHSA-87m4-826x-3crx`. The advisory lists versions through 1.30.4 as affected and 1.30.5 as patched for this particular issue. Its impact depends on PHP version and downstream behaviour: the PHP 7 automatic PHAR deserialization RCE chain is not automatically applicable to this project's PHP 8.2 test runtime. Application-level exploitability and production runtime have not been established. Product, opening-stock, sales and purchase import paths use the spreadsheet library, making compatibility and untrusted-input tests a priority. [Publisher advisory](https://github.com/PHPOffice/PhpSpreadsheet/security/advisories/GHSA-87m4-826x-3crx)
- **Framework and HTTP/mail dependencies:** Laravel, Guzzle and Symfony matches include host-validation, email/header-injection, signed-URL and authorization-related issues. Verify which APIs the application actually calls, then resolve compatible fixed versions and rerun authentication, invoice/payment and integration tests. No exact whole-stack upgrade target has been selected.
- **PDF, Markdown and file processing:** Dompdf, FPDI, CommonMark and PhpSpreadsheet include input-processing/resource-exhaustion findings. Some CommonMark advisories require optional extensions; dependency presence alone does not demonstrate exposure. Test imports, exports/PDF and any untrusted Markdown paths after updates.
- `composer.json` already disables insecure-package blocking and lists ignored advisory IDs. Review those exceptions individually; a clean result with ignored advisories would not by itself certify safety. Do not remove exceptions or run an unrestricted update without a scoped compatibility plan.

These dependency findings are additional to the eight application findings above. The PHP regression/audit test counts are unchanged; passing behavioural tests do not clear dependency advisories.

Severity-source note: the scan/GitHub Advisory Database rates `GHSA-87m4-826x-3crx` critical, whereas the publisher's advisory page labels it high. The counts above preserve the scan's ratings rather than presenting the sources as unanimous. Both list 1.30.4 as affected and 1.30.5 as patched for that issue.

## Checks still outstanding

- Managed frontend dependencies, build and focused browser tests are now addressed; remaining manually bundled plugins and broad workflow/device acceptance are not certified. See [frontend-upgrade.md](frontend-upgrade.md).
- `docs/security-deployment.md` already records a historical committed-secret rotation requirement. This pass did not inspect secret values or verify that rotation, deployment, cron, mail, backups or web-server upload controls were completed.

## Next acceptance gates before lights work resumes

1. **Completed locally:** fixes for A01–A08, retained safety assertions and additional success/rollback/CSRF/dependency tests. Structural tests changed only for intentionally retired routes and capability-aware Ecommerce handling.
2. **Completed locally:** disposable MySQL process and identity guards, synthetic fixtures and complete 279-migration rehearsal.
3. **Expanded locally:** POS/purchase/return, purchase edit/delete, transfer/adjustment and register workflows, plus additional business/location denials. Full role/entity/ledger and accounting reconciliation coverage remains incomplete.
4. **Completed focused local checks:** real two-process invoice/stock races and provider-success/local-failure recording. Atomic stock deltas replace read-modify-save receipts. Provider calls remain simulated; actual provider test-mode verification is a separate gate.
5. **Expanded locally:** authenticated Chrome desktop/mobile editor, charts, exports and representative navigation/controls. Broad bookings, optional modules, notification delivery, visual/physical print and device testing remain.
6. **Dependency remediation completed locally:** fresh Composer locked audit completed with zero advisories/abandoned packages, plus the final npm zero-vulnerability scan. The earlier approval-service blocker no longer prevents the Composer check. Obtain fresh release scans, staging provider and deployment evidence separately.

Until those gates pass, do not label the whole application or the production system fully regression-tested.
