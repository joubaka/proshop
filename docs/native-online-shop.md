# Native ProShop online shop

The online shop is built into ProShop and uses the existing POS catalogue, variation prices, location stock, customers, sales and payments. It does not depend on WooCommerce or another commerce platform.

## Safety and activation

The storefront and checkout are disabled by default. Configure a catalogue channel and explicitly publish selected POS products before enabling either public flag.

```dotenv
SHOP_ENABLED=false
SHOP_CHECKOUT_ENABLED=false
SHOP_CHANNEL=main
SHOP_CART_LIFETIME_MINUTES=10080
SHOP_RESERVATION_MINUTES=20

SHOP_PAYFAST_ENABLED=false
SHOP_PAYFAST_SANDBOX=true
SHOP_PAYFAST_MERCHANT_ID=
SHOP_PAYFAST_MERCHANT_KEY=
SHOP_PAYFAST_PASSPHRASE=
```

Apply only the three reviewed shop migrations for the first pilot:

```powershell
php artisan migrate --path=database/migrations/2026_09_17_000100_create_shop_catalog_tables.php
php artisan migrate --path=database/migrations/2026_09_17_000200_create_shop_commerce_tables.php
php artisan migrate --path=database/migrations/2026_09_17_000300_create_shop_payments_table.php
```

Do not enable checkout until the public application URL is HTTPS, the PayFast notification URL is reachable, and sandbox credentials have been verified. PayFast notifications—not browser returns—finalize payment.

## Catalogue workflow

1. Sign in to the POS as an authorized administrator.
2. Open **Online catalogue** and create the `main` channel for the correct business and stock location.
3. Keep the channel disabled while preparing the pilot.
4. Select a POS product and publish only the intended variations.
5. Set safety stock and maximum online quantity for each variation.
6. Review the customer-facing name, description, price and exact online availability.
7. Enable the channel, then set `SHOP_ENABLED=true` only when the catalogue review is complete.

Online availability is physical location stock minus active reservations and configured safety stock. Checkout rechecks the price, publication, maximum quantity and available stock inside the order transaction. A reservation never deducts physical POS stock. Expired unpaid orders release their reservations through the scheduled expiry job.

## Payment and POS finalization

Checkout creates a server-priced order and a short-lived stock reservation. The customer then explicitly continues to PayFast. The notification handler verifies the source range, signature, merchant, `COMPLETE` status, server-calculated amount and PayFast server confirmation. Processing is locked and idempotent.

Only a verified notification marks the order paid. Finalization creates or reuses the POS customer, creates the POS sale and payment records, records the online-order mapping and deducts location stock once. A late valid payment that can no longer be fulfilled is retained for staff review rather than silently losing the payment or overselling.

## Fulfilment and delivery boundary

The first pilot supports collection. Staff use **Online orders** to move a paid order from `not ready` to `ready for collection` and then `collected`; transitions are ordered, idempotent and audited. Unpaid orders can be cancelled and release reserved stock. Paid cancellation intentionally requires a future refund workflow.

Delivery is isolated behind `DeliveryQuote` and the order fields `fulfilment_method`, `fulfilment_label`, `delivery_quote`, `delivery_address` and `delivery_cents`. A future courier adapter can produce a server-trusted quote without changing catalogue, cart, payment or POS finalization. Never accept a browser-submitted delivery price.

## Controlled pilot

The isolated local-acceptance environment contains synthetic products, customers, orders and dummy PayFast settings only. It must never use production credentials.

```powershell
.\scripts\start-local-acceptance.ps1 -Initialize -RestartWeb
php artisan test --testsuite=Feature
node --test --test-concurrency=1 tests/Frontend/local-browser.test.mjs
```

The browser pilot proves the full local sequence: public catalogue, product availability, cart, checkout, signed simulated PayFast notification, confirmed order, POS stock decrement, staff readiness and collection. A real PayFast sandbox transaction remains a separate external acceptance gate and requires sandbox credentials plus a reachable HTTPS callback.

## First external sandbox checklist

- Back up the database and verify only the three shop migrations are pending.
- Use one low-risk product with known stock, safety stock of at least one and maximum online quantity of one.
- Keep the catalogue channel and global flags off while configuring it.
- Configure PayFast sandbox credentials outside source control and rebuild the configuration cache.
- Expose only the reviewed HTTPS application and PayFast notification endpoint.
- Enable the channel, storefront, checkout and PayFast in that order.
- Place one order, complete PayFast sandbox payment and verify a single payment, POS sale, stock deduction and online-order mapping.
- Mark the order ready and collected in the staff queue.
- Repeat the notification to confirm idempotency, then test an abandoned order and reservation expiry.
- Disable checkout immediately if any amount, stock, callback or reconciliation result differs from the order record.

Production activation, real credentials, customer communication, refunds and courier integration are not part of the local pilot and require their own review.
