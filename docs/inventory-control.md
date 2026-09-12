# Inventory control and purchasing workflow

## End-to-end operating routine

1. Open **Purchases > Stock control**, choose a location and sales window, and recalculate.
2. Review days cover, stock on hand, open purchase orders, supplier lead time, safety stock, MOQ and order multiple.
3. Save policy changes. This never creates an order.
4. Select proposed lines and create a purchase-order preview. Review supplier grouping, quantities and estimated costs.
5. Confirm the preview to create purchase orders. Confirmation rejects the batch if stock, sales, supplier policy or open orders changed.
6. When goods arrive, either scan each SKU on the normal purchase screen or photograph/upload the supplier invoice.
7. For an invoice scan, reconcile every line, pack size, tax treatment and optional purchase-order line. Save the review before receiving.
8. Selling-price changes require product-update permission, a per-line approval and a reason. The price ledger remains visible in Stock control.
9. Approve and receive. Stock and the purchase are committed together; over-receipt against a PO is rejected.
10. Run periodic cycle counts. Scans save immediately, uncounted lines never default to zero, and posting rejects a stale snapshot.

## Safety invariants

- Recommendations and preview batches do not change stock or create orders.
- Invoice extraction does not change stock, supplier mappings or prices.
- Uploaded invoices remain private and business scoped.
- Purchase receipt and stock posting are transactional.
- A confirmed replenishment preview is idempotent and cannot be confirmed twice.
- A stock count cannot be posted until every line has an explicit count, including zero.
- If stock changes after a count begins, the count must be restarted.

## Production checklist

- Back up the database and run the pending migrations.
- Use an asynchronous supervised queue for invoice extraction.
- Configure the invoice provider secret only in the server environment.
- Disable application debug mode in production.
- Pilot genuine invoices from each major supplier and record header and line accuracy.
- Test USB/Bluetooth scanners and the phone layout at the actual receiving desk.
- Assign purchase-order creation, purchase receiving and product-update permissions to the correct roles. Stock-count entry and posting use the existing `purchase.create` permission.
- Review initial supplier lead times, pack sizes, MOQ values and order multiples before confirming recommendations.
