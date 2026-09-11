# Supplier invoice scanning

## Workflow

Users with `purchase.create` can open **Purchases > Scan invoice**, photograph or upload a supplier invoice, review extracted fields, and approve it as a received purchase. Extraction never writes stock, purchase lines, supplier mappings, or product prices. Those changes happen together only after **Approve and receive**.

The review must identify a supplier, location and product variation for every line. It also checks that:

`subtotal - discount + VAT + freight = invoice total`

Confirmed supplier codes/descriptions and pack sizes are retained as supplier-specific mappings for later invoices.

## Production configuration

Set these only in the server environment, never in source control:

```dotenv
INVOICE_SCANNING_ENABLED=true
INVOICE_SCANNING_DRIVER=openai
OPENAI_API_KEY=...
OPENAI_INVOICE_MODEL=gpt-5.4-mini
```

The API request uses the Responses API with `store: false`. Invoice image/file inputs are nevertheless subject to the provider's current data controls; review those controls before enabling production.

Use a real asynchronous queue in production:

```dotenv
QUEUE_CONNECTION=database
```

Create the queue tables if this installation does not already have them, migrate, and supervise `php artisan queue:work --queue=default --tries=2 --timeout=120`. A sync queue works for local testing but holds the browser request open during extraction.

## Storage and access

Scans use the `invoice_scans` private disk under `storage/app/private/invoice-scans`. They are downloaded only through an authenticated, business-scoped route. Do not move these files to `public/uploads`.

## Release checks

1. Back up the database and run migrations.
2. Configure the API key and queue worker.
3. Confirm the storage path is writable and excluded from public web serving.
4. Test with representative multi-page PDF, JPEG and PNG invoices.
5. Confirm duplicate invoice numbers are rejected per supplier.
6. Confirm an unmatched product cannot be posted.
7. Confirm another business cannot view a scan or document.
8. Confirm stock and approved pricing change only after final approval.
