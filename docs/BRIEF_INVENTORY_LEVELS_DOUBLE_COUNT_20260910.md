# Brief — `am_core_inventory_levels` double counts stock

**Raised:** 10 September 2026
**Severity:** High. Every consumer of `/api/v1/inventory` is reading inflated stock, and
nobody can currently answer "how much is in the store versus at site" from AM.
**Related:** `docs/RCA_DISPATCH_TRANSACTION_GAP_2026-08-05.md` — same collection, adjacent
cause, already fixed for the dispatch path. This is the location path.

---

## Symptom

For several stockable materials, the full asset quantity appears against **two or more**
location rows. Summing the collection therefore double counts.

`web/api/v1/inventory.php:11` reads `am_core_inventory_levels` directly with no
deduplication, so the API hands the inflated numbers to ugridpredict, Nexus and reporting.

## Live evidence, queried 10 Sep 2026 (project `pr-system-4ea55`)

Three assets, all with `asset.location_code = LSO-HQ`:

**ABC/Bundle conductor — `1PWR-MAT-LSO-000008`, `am_core_assets.quantity` = 92,260**

| levels doc id | `location_id` | `quantity_on_hand` | `updated_at` |
|---|---|---:|---|
| `gQx9i79IFSIG8BRmYQ3f` | LSO-HQ | 92,260 | 2026-09-10 |
| `Vyud5NIDOWeAgPrZvQ90` | LSO-MAK | 92,260 | 2026-09-09 |
| `qkzZNXY6UT7JTMxSObaL` | LSO-MAS | 6,812 | 2026-06-17 |
| `12358a4ab8f4ba0dc8c1` | LSO-TOS | 0 | 2026-09-09 |

Sum on hand **191,332** against an asset quantity of **92,260**.

**Squirrel conductor — `1PWR-MAT-LSO-000142`, quantity = 119,317**

| levels doc id | `location_id` | `quantity_on_hand` | `updated_at` |
|---|---|---:|---|
| `FZ4FyRxkUTTekXHk2gAX` | LSO-MAK | 119,317 | 2026-09-09 |
| `HhKMly0i0lvXKyX80C37` | LSO-HQ | 119,316 | 2026-06-02 |
| `IDGcJKlMaA5RMzGf3GwF` | LSO-MAS | 0 | 2026-07-13 |

Sum **238,633** against **119,317**. Note the two large rows differ by exactly 1, so one is
a stale snapshot rather than a mirror.

**10mm CNE Airdac — `1PWR-MAT-LSO-000002`, quantity = 93,480**

| levels doc id | `location_id` | `quantity_on_hand` | `updated_at` |
|---|---|---:|---|
| `eWiPKMosZFvqiSbOcUQq` | LSO-HQ | 93,480 | 2026-06-18 |
| `KMCb3iCNFuh3QjZLRe3W` | **`site1`** | 93,480 | 2026-07-21 |
| `dTaIsFSrvwU4OIshDV6A` | LSO-TOS | 2,500 | 2026-06-15 |

Sum **189,460** against **93,480**. `site1` is not a canonical location code.

Same pattern on `1PWR-MAT-LSO-000153` (8mm Airdac), `-000033` (house wire 6mm),
`-000116` and `-000117` (stay wire). Assume it is not limited to these.

## Three distinct causes, do not conflate them

**1. Orphaned source row after a site change.**
The ABC asset was moved LSO-MAK → LSO-HQ on 10 Sep 17:47 by Mofokeng Maqelepo
(`am_core_transactions`, `transaction_type` = Transfer, `quantity_before` =
`quantity_after` = 92,260, notes "Site changed from LSO-MAK to 1pwr_lesotho_hq"). The
destination row was written correctly. **The source row was left carrying the full
quantity.** Start at `web/assets/edit.php:190-245` — the canonicalise/find/update/delete
block. Note the transaction records `to_location_id` as the PR site id
`1pwr_lesotho_hq` while the level row is keyed `LSO-HQ`, so verify which identifier the
cleanup path matches on.

**2. Non-canonical location ids create parallel rows.**
`am_canonical_location_code()` (`web/config/inventory_levels.php:25`) returns `$rawId`
unchanged when `$locByAnyKey` is empty (line 29-31) and on final fallback. An id like
`site1` therefore persists as its own location key forever, and the dedupe in `edit.php`
never matches it. Decide explicitly: reject unresolvable ids at write time, or quarantine
them. Silently keying on an unresolved id is what created `KMCb3iCNFuh3QjZLRe3W`.

**3. Stale snapshots never reconciled.**
The Squirrel LSO-HQ row (119,316, last touched 2 June) is a frozen copy that predates the
9 Sep dispatch. Nothing sweeps these.

## What to build

**Invariant.** For any stockable asset (`item_class` in Material, Consumable, Inventory):

```
sum(quantity_on_hand across am_core_inventory_levels for that asset)
  == am_core_assets.quantity
```

and **at most one levels row per (asset_id, canonical location_id)**.

Enforce it in three places:

1. **Write path.** Any code creating or updating a levels row must resolve the location to
   a canonical code first and fail loudly if it cannot. A site change must move the
   quantity, not copy it: decrement the source row inside the same Firestore commit that
   increments the destination, and write the matching `am_core_transactions` event. This is
   the same invariant the 5 August RCA already established for dispatch — extend it to
   transfers and site edits rather than writing a second mechanism.

2. **Read path.** `web/api/v1/inventory.php` should not be the place the bug is papered
   over, but it should refuse to serve an asset whose rows violate the invariant. Return
   the row set plus an explicit `reconciliation_status` field so consumers can tell good
   data from bad. Silent summing is how this reached ugridpredict unnoticed.

3. **Reconciler.** `scripts/reconcile_inventory_levels.php` already exists and already
   compares `assets.quantity` to `inventory_levels.quantity_on_hand`. Extend it rather than
   writing a new one: it must detect duplicate (asset, location) pairs and unresolvable
   location ids, not only totals. Keep it dry-run-first, in line with the RCA's historical
   reconciler.

## Backfill, and the part that needs a human

Do **not** guess which row is real. For the three assets above the totals are corroborated
by an external source — Sanhe proforma SH251011007 and the packing list for MBL
177DFNFNA8667V, the consignment that landed at the Lesotho state warehouse in late April
2026:

| Item | Landed | AM `assets.quantity` | Reconciles |
|---|---:|---:|---|
| Squirrel ACSR | 131,248 m | 119,317 m | Yes — 11,931 m consumed on dispatch AMW-2026-00137, 9 Sep. Exact to the metre. |
| ABC 1-phase | 80,800 m | 92,260 m | Yes — plus ~9,760 m legacy stock, inside 2% |
| Airdac 10mm CNE | 87,980 m | 93,480 m | Yes — plus 25,492 m legacy, less ~20,000 m issued |

So **`am_core_assets.quantity` is the trustworthy figure and the levels rows are what is
wrong.** Reconcile toward the asset quantity, not the other way.

Which location the stock physically sits in is **not** derivable from the data and must not
be inferred. Phoka Raphoka's team should do a drum count in the Maseru store and at site,
and that count becomes the seed. Until it lands, mark the affected assets
`reconciliation_status: unverified` rather than picking a row.

## Acceptance

- `php scripts/reconcile_inventory_levels.php --all --dry-run` reports zero violations of
  both the total invariant and the one-row-per-location rule.
- A regression test alongside `php tests/inventory_dispatch_test.php` covers: site change
  moves rather than copies; an unresolvable location id is rejected at write; a duplicate
  (asset, location) pair cannot be created.
- `/api/v1/inventory` exposes `reconciliation_status` and a consumer can distinguish
  verified from unverified stock.
- The ten level docs named above are either corrected or explicitly quarantined, with the
  reasoning recorded.

## Why this matters right now

The 3,300-connection Milestone Completion Date under the Aluwani facility (cl. 2.1.93)
turns on how much conductor is on hand. AM says roughly 305 km of line conductor against a
140 km requirement, which is the number going into a lender conversation. The headline
survives the bug because it rests on `assets.quantity` and on the supplier paperwork. The
in-store versus at-site split does not, and that is the operational question the field
teams actually need answered.
