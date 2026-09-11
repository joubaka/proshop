# Local audit plan: completion record

2026-09-03. Scope of this original record: the existing Proshop application. The user subsequently approved resuming Lights as a separate local simulation; see [lights-portal.md](lights-portal.md). No production deployment, real payment, hardware command or shop-database migration was performed.

## Phase status

| Phase | Local result | Remaining release evidence |
| --- | --- | --- |
| Original application findings | A01–A08 repaired; regression assertions retained | Production verification after a controlled release |
| PHP dependencies | Laravel 12-compatible lockfile; fresh locked audit completed: zero advisories and zero abandoned packages | Repeat advisory scan at release; the earlier approval-service blocker no longer prevents this check |
| MySQL rehearsal | 279 migrations on the separate loopback-only server | Staging backup/restore and production migration approval |
| Stock and transaction workflows | Purchases, POS, returns, purchase edit/delete, transfer completion/replay/delete, adjustments and register closure covered; tenant/location checks added | All role combinations, lot/unit/overselling policies and high-volume load |
| Concurrency and recovery | Four real two-process stock races and one invoice-payment race pass; provider-success/local-failure recovery covered | Actual provider test-mode failures, callback/reconciliation and operational review |
| Frontend replacement | Jodit + Chart.js; Bootstrap 5 behavior with existing layout; Driver.js tour; esbuild/Sass build; local browser regression tests | Remaining manually bundled plugins, optional-module compatibility, broad devices and visual/physical printing |
| npm dependencies | Final locked audit: zero reported vulnerabilities; no advisory suppression | Registry rescans at release; npm does not scan manually bundled source |
| Operations | Local runbooks and safety guards documented | Secret rotation, web-server upload rules, cron, mail, backups and deployment |

This closes the implemented local repair/test milestones, not every acceptance gate or full-application certification. Missing external integrations and operational approvals are not marked complete.

## Verified tests

- Default SQLite Unit/Feature/Audit: **167 tests / 1,335 assertions**.
- Separate MySQL acceptance: **29 tests / 344 assertions**, including parallel PHP processes.
- Chrome frontend: **12 tests passing** (one parent plus 11 journey checks), with no captured page JavaScript errors. See [frontend-upgrade.md](frontend-upgrade.md) for the covered journeys and build procedure.
- All **279** migration files recorded on the separate MySQL server. The new migration creates `invoice_payment_attempts`.

Composer follow-up: `composer audit --locked --format=json --no-cache --no-plugins --no-scripts` completed with exit code 0 and empty `advisories`/`abandoned` arrays through the existing approved command path. It did not update dependencies or boot the application. The installed Composer executable emitted a non-blocking PHP deprecation notice from its own bundled JSON-schema library; this is separate from application security advisories. No global Composer update was performed.

## Payment review procedure

Invoice link payment reservation is committed before contacting a provider. An active attempt blocks a second charge, including from another gateway. Confirmed charges can retry only their local ledger recording. Stripe requests use the attempt UUID as an idempotency key, but safety does not depend on the provider retaining that key forever. [Stripe idempotency documentation](https://docs.stripe.com/api/idempotent_requests).

On an authorized staging/production console (not an instruction to run against the shop now):

```text
php artisan payments:review --business=123
php artisan payments:review --business=123 --record=CONFIRMED-ATTEMPT-UUID
```

The first command is read-only. The second records an already confirmed payment locally and never contacts a gateway. It refuses an unknown outcome or a different business; completed attempts are idempotent. If the invoice balance changed, recording remains blocked for reconciliation.

`processing` and `review_required` attempts require operator verification with the provider. A timeout, declined/unconfirmed response or crash is not permission to charge again. Do not delete the reservation, rerun the charge or roll back its migration to clear a block. This patch deliberately does not add an automatic release/refund path for unknown outcomes. Establish that operational procedure and verify provider test-mode behavior before enabling the changed payment flow in production.

Tests substitute provider responses or the SDK HTTP transport; no real Stripe/Razorpay/PayFast request was made. This work does not implement the future PayFast lights wallet.

## Next release gate

Use [security-deployment.md](security-deployment.md) for a coordinated staging release: retained backup, new migration, PHP and frontend artifacts together, provider test-mode acceptance, and operational checks. The user deferred those external checks and approved local Lights simulation work; they remain required before either system goes live.
