# Proshop project context

## Engineering agent collaboration

Shopster is the user's single engineering front door. For substantial cross-layer work or when the user asks the Proshop agents to work together, Shopster may coordinate bounded specialist agents using `.agents/skills/shopster/references/agent-roster.md`. Shopster owns scope and the final outcome; authorization, quality, caretaker, and release roles remain independent within their assigned boundaries.

Only one agent may own edits to a file or feature boundary at a time. Parallel work should normally be read-only. Concurrent database-refresh or browser suites require separate isolated databases and runtime paths, or must run serially. Every specialist inherits the original task's authority limits.

## Product purpose and surfaces

Proshop is a Laravel point-of-sale and business-operations application. Its active surfaces include:

- POS, sales, purchases, contacts, products, stock, accounting, reports, and business administration.
- Inventory control, replenishment, stock counts, scanning, and purchasing workflows.
- A native customer-facing online shop with catalogue, cart, checkout, PayFast, order fulfilment, and admin operations.
- Court Lights membership, wallet/top-up accounting, PayFast, operational control, and Shelly hardware integration.
- Invoice scanning and other operator-assisted workflows documented under `docs/`.

Preserve working business operations and existing records. Prefer narrow changes that fit established services, permissions, audit trails, and tests.

## Security and data boundaries

- Enforce `business_id`, location, user, role, permission, order, account, member, and other resource ownership on every applicable read and write. Route middleware or a hidden control alone is not authorization.
- Treat POS staff, business administrators, shop customers, Lights members, and Lights administrators as distinct actors. A relationship in one surface does not grant access in another.
- Protect customer and supplier details, invoices, payment records, wallet entries, credentials, device identifiers, webhooks, tokens, and operational controls from unintended audiences.
- Calculate prices, tax, discounts, totals, wallet balances, and payment state on the server from canonical records. Never trust client-supplied monetary state.
- Keep payment and wallet operations idempotent and auditable. Validate provider callbacks independently of browser return URLs, and do not mark an order or top-up paid without verified provider evidence.
- Preserve stock and accounting history. Use transactions and appropriate locking for multi-record inventory, order, payment, and wallet transitions.
- Court Lights customer control must fail safe. Simulator success is not Shelly commissioning, and local tests do not authorize or prove real relay switching.
- Do not expose `.env`, secrets, PayFast credentials, Shelly credentials, personal data, or full production configuration in output, logs, tests, or committed files.

## Lifecycle and operational rules

- Trace and preserve explicit order, payment, fulfilment, replenishment, count, invoice-scan, light-session, top-up, and hardware-control states before changing them.
- Reject stale, duplicate, cross-business, cross-account, cross-order, and cross-member actions. Make retries safe where requests, jobs, callbacks, or operators may repeat work.
- Preview/review workflows are not confirmation. Posting a stock count, confirming replenishment, finalizing a sale, collecting/cancelling an order, adjusting a wallet, energizing a relay, and sending external communications are consequential actions.
- Do not silently substitute simulated providers or devices in production paths. Disabled production capabilities must remain disabled until their documented commissioning and acceptance gates pass.
- Use the repository documentation under `docs/` as operational context, then verify drift-prone claims against current code, configuration, and tests.

## Working conventions

- Inspect `git status --short` before work. Existing changes are user-owned; do not discard, rewrite, stage, or include them accidentally.
- An audit, review, diagnosis, or plan is read-only unless implementation is explicitly requested.
- Inspect routes, middleware, permissions, controllers, services, models, migrations, views/scripts, jobs, integrations, and tests relevant to the change.
- Use isolated test databases and writable runtime paths. Never run destructive database tests against the developer's normal or production database.
- For UI changes, verify the rendered workflow at relevant desktop and phone widths, including loading, empty, error, disabled, overflow, focus, keyboard, and touch behavior. Source review or PHPUnit alone is not browser evidence.
- Do not add or update dependencies, stage, commit, push, deploy, migrate production, send communications, charge/refund payments, alter live business data, or operate real lights without the matching authorization.
- Keep local implementation, local tests, browser acceptance, commit, push, CI, deployment, production migration, provider acceptance, hardware commissioning, and live verification as separate evidence levels.
- Report user-visible outcome, exact changed files, tests/checks and results, pre-existing changes left untouched, and anything still unverified.

## Durable agent knowledge

Record only verified, reusable rules. Put broad product and safety rules here, agent behavior in the relevant skill, conditional procedures in a skill reference, and executable invariants in regression tests. Never persist secrets, personal data, transient production values, guesses, or one-off safeguard exceptions.
