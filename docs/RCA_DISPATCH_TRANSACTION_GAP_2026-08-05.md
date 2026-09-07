# RCA — Inventory dispatch balance without transaction history (2026-08-05)

## Incident

Consumable `1PWR-CON-LSO-000276` displayed **Allocated: 175 EA** and **No transactions recorded**.

## Verified live evidence

- Asset document: `d9e7b3cc-8f4a-4895-b542-0bff66713854`
- `am_core_allocations` rows for the asset: **0**
- `am_core_transactions` rows for the asset before repair: **0**
- `am_core_inventory_levels`: on hand **382**, allocated **175**
- The 175 consists exactly of three fulfilled same-site dispatches:
  - `AMW-2026-00058`: 24 EA
  - `AMW-2026-00059`: 61 EA
  - `AMW-2026-00116`: 90 EA

## Root cause

Inventory-dispatch approval wrote `quantity_allocated` directly to `am_core_inventory_levels`, but the workflow did not write `am_core_transactions`. Fulfillment returned early when source and destination location codes were equal. That early return skipped both releasing the reservation and issuing the stock. The request was still marked Fulfilled.

The detail page behaved as implemented: it read the balance from `am_core_inventory_levels` and history only from `am_core_transactions`. Switching the history UI to `am_core_allocations` would not have solved the incident because no allocation documents existed; dispatch reservations were stored in the request payload and inventory projection.

## Durable correction

- Same-site fulfillment is now treated as an issue to the receiver: stockable on-hand and catalog quantity decrease, and the reservation is released.
- Cross-site fulfillment remains a transfer from source to destination.
- Reserve, fulfill, and release operations atomically commit their balance changes with an immutable transaction event.
- Transaction document IDs are deterministic per request, line, and phase, making retries idempotent.
- A failed atomic commit does not advance request status and produces a visible error.
- The historical reconciler is dry-run-first and refuses to operate when live balances do not satisfy its safety checks.

## Invariant for future changes

Any code that changes dispatch-related `quantity_on_hand` or `quantity_allocated` must create the corresponding immutable `am_core_transactions` event in the same Firestore commit. `quantity_allocated` is a current-state projection; it is never an audit source.

Regression coverage: `php tests/inventory_dispatch_test.php` and the Inventory dispatch checklist in `TESTING.md`.
