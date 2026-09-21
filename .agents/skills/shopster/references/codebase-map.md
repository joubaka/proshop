# Proshop codebase map

- Laravel 12/PHP 8.2 application: `app/`, `routes/`, `config/`, `database/`, and `tests/`.
- Server-rendered UI and assets: `resources/views/`, `resources/js/`, `resources/sass/`, `public/`, and feature-local scripts/styles.
- Build and browser checks: `scripts/build-frontend.mjs`, `package.json`, and `tests/Frontend/`.
- Core POS/business routes: `routes/web.php`; inventory: `routes/inventory_control.php`; online shop: `routes/shop.php`; Court Lights: `routes/lights.php`.
- Operational truth and acceptance boundaries: `docs/`, especially inventory, online shop, invoice scanning, local acceptance, security/deployment, and Lights documents.

Trace features vertically from route and middleware through permissions, request/controller, service/transaction, models and constraints, external jobs/providers, views/scripts, and tests. Search by route name, label, model/table, service method, provider callback, and assertion. Read callers before changing shared helpers.

Re-check both sides of high-risk seams: business context versus resource ownership; permissions versus location/account scope; cart/order versus payment; provider callback versus return URL; stock preview versus posting; wallet ledger versus displayed balance; Lights member versus admin controls; simulator versus live PayFast/Shelly; local state versus queues, workers, and deployment configuration.
