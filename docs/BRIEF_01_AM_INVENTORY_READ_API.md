# Brief 01 — Asset Management: inventory read API

**Agents starting 8 Sep 2026:** read [`FORECAST_PROGRAMME_REMAINING.md`](FORECAST_PROGRAMME_REMAINING.md) first. The inventory API is live; remaining work is mapping, units, and the consumer key — not a rebuild.

**Target repo**: `AI Projects/1PWR AM` (`onepowerLS/onestop-asset-shop`)
**Live at**: am.1pwrafrica.com, EC2 `16.28.64.221` · **Stack**: PHP + Firestore
**Read first**: `00_PROGRAMME_BRIEF.md`, then this repo's `CROSS_REPO_API_CONTRACT.md` and
`nexus-portal/docs/CANONICAL_DATA_OWNERSHIP.md` (master ownership map).
**Priority**: CRITICAL PATH. Nothing material-constrained can be forecast until this exists.

---

## 0. Correction to earlier scoping — read this first

An earlier assessment recorded AM as having "no API-key auth and no asset API". **That is out of date.**
Per this repo's `CROSS_REPO_API_CONTRACT.md`, AM already exposes:

| Method | Path | Auth | Consumers |
|---|---|---|---|
| GET | `/api/vehicles` | `X-API-Key` | FM, PR, Nexus |
| GET | `/api/loadout-manifests/index.php` | Bearer / api_key | FM |
| GET | `/api/mutations/index.php` | `X-API-Key` | Nexus, reporting |

So this brief is **smaller than originally scoped**. Do not build a new auth mechanism and do not
reimplement loadouts. Three consequences:

1. **Extend the existing `X-API-Key` scheme.** It resolves to `FIREBASE_ADMIN_BEARER_TOKEN` via
   `am_firestore_admin_access_token()`. Add a consumer key for `ugridpredict`; do not invent a new header.
2. **`/api/mutations/index.php` may already be the movement ledger this brief asks for.** Inspect it
   before building anything new — if it carries signed quantity, part, store, timestamp and reference, the
   `movements` requirement in §4 collapses into extending that endpoint's query surface. **This was the
   single biggest unknown in the original brief and it may already be solved.**
3. **`/api/loadout-manifests/index.php` already exists.** Assess what it returns; extend rather than
   duplicate.

Also already solved: **the site join key.** PR's `fanoutSiteChanges` pushes to AM's
`POST /api/sync/site-ingest.php`, keeping `am_reference_sites` current in real time. Use that site id
everywhere — do not introduce another. Employees, departments, organisations, countries and vehicles are
likewise already cached in `am_reference_*` on a 15-minute incremental timer plus a 03:00 UTC full sync.

## 1. Problem

AM owns assets, inventory, allocations, loadout manifests and SIM cards. It is the only system that knows
what material is physically on hand and where. What is **missing** is the inventory position itself:
there is no endpoint returning stock on hand, allocated and available by part and site.

The consequence at executive level: the SMP cash model assumes a connection ramp with no reference to
material availability, and our BOM analysis says the funded material supports roughly 5,844 connections
against a modelled 9,352. Nobody can reconcile those two numbers automatically because the inventory
position is not readable by a machine.

The consequence at field level: crew targets are set without knowing whether the material to hit them is
in the store.

## 2. Scope

Build a **read-only, key-authenticated integration API** on AM. No write endpoints in this phase. No UI
changes. No schema migration unless section 6 forces one.

## 3. Authentication

**Use the existing `X-API-Key` mechanism** already serving `/api/vehicles` and `/api/mutations`. Do not
introduce a new header or a parallel key store.

- Provision a consumer key for `ugridpredict`; `nexus` and `reporting` keys already exist.
- Rate limit per key. Log every call with key name, endpoint, response code and duration.
- **Do not** accept the integration key for anything mutating, now or later, without a separate review.
- New AM-owned collections, if any are needed, follow the existing prefixes: `am_core_*`,
  `am_reference_*`, `am_canonical_sync_*`. AM does not write to PR/HR/FM-owned collections.

## 4. Endpoints

All responses JSON, ISO-8601 UTC timestamps, `Etag` where cheap, cursor pagination (`?cursor=&limit=`,
default 100, max 1000).

### `GET /api/v1/inventory`
Current stock position. **The single most important endpoint in this brief.**

Query: `site_id`, `store_id`, `part_id`, `category`, `updated_since`.

```json
{
  "items": [{
    "part_id": "pole-wooden-11m",
    "part_name": "Wooden pole 11m",
    "category": "Poles",
    "store_id": "MAS-STORE-01",
    "site_id": "MAS",
    "qty_on_hand": 412,
    "qty_allocated": 260,
    "qty_available": 152,
    "unit": "each",
    "as_of": "2026-09-07T06:00:00Z",
    "last_movement_at": "2026-09-05T14:22:00Z"
  }],
  "next_cursor": null
}
```

`qty_available` must be computed as on-hand minus allocated, server-side. Do not make the consumer
derive it — that is exactly the redundancy the sponsor has ruled out.

### `GET /api/v1/allocations`
What is committed to which site or work package but not yet consumed. Fields: `allocation_id`, `part_id`,
`qty`, `site_id`, `work_package_id` (nullable), `allocated_at`, `allocated_by`, `status`
(`reserved` / `issued` / `consumed` / `returned`).

### Movement history — **assess `/api/mutations/index.php` before building**
Append-only movement history is what makes consumption rate measurable, and consumption rate is what lets
the forecast engine predict when a store runs dry. The mutation log endpoint already exists and may
already carry this. Required fields: `movement_id`, `part_id`, `qty` (signed), `from_store`, `to_store`,
`site_id`, `movement_type` (`receipt` / `issue` / `transfer` / `adjustment` / `return`), `occurred_at`,
`recorded_at`, `reference` (PR/PO or loadout id), `recorded_by`. Query by `occurred_since` at minimum.

**First task: document what `/api/mutations` actually returns.** If it covers the above, extend its query
surface and stop. If it is an audit log of record edits rather than a stock ledger, that is a different
object and the gap must be reported before any schema change is proposed — see section 6.

`reference` matters beyond AM: it is the join that lets brief 02 derive real delivery dates from receipts
where PR itself does not record `delivered_at`.

### Loadouts — **extend `/api/loadout-manifests/index.php`**
Already exists and is consumed by FM. Assess its payload against what the forecast needs:
`loadout_id`, `site_id`, `crew_id` (nullable), `dispatched_at`, `received_at`, `status`, `lines[]` of
`{part_id, qty}`. This is the bridge between inventory and crew productivity. Extend, do not duplicate.

### `GET /api/v1/parts`
The AM part master as AM holds it: `part_id`, `name`, `category`, `unit`, `active`, plus any
alternate/legacy identifiers. Needed for the reconciliation in section 5.

### `GET /api/v1/health`
`{"status":"ok","db":"ok","version":"...","server_time":"..."}` — unauthenticated, for monitoring.

## 5. Part identity — the reconciliation that will actually cost the time

UGP uses kebab-case part IDs (`wire-abc-3ph-50`, `pole-wooden-11m`). PR describes items in **free text
with no part numbers**. AM has its own identifiers. These three must reconcile or the whole programme
produces plausible nonsense.

Required in this brief:
1. Expose AM's identifiers exactly as they are (`GET /api/v1/parts`). **Do not silently rename anything.**
2. Add an optional `ugp_part_id` field to the AM part master, nullable, with an admin UI to set it.
3. Expose it in the parts payload so the forecast service can join AM stock to UGP BOM lines.
4. Report unmapped parts: `GET /api/v1/parts?unmapped=true`. This becomes a data-quality worklist.

Do **not** attempt automated fuzzy matching in this brief. A wrong mapping is worse than a null, because
it will silently misstate available material. The AI-assisted mapping work belongs with brief 02, which
already has a plan for it against PR free text.

**Known trap**: UGP parts catalogues still carry SparkMeter metering, which is obsolete pricing — the
in-house 1MTR at ~ZAR 900/connection replaced it. Do not propagate SparkMeter part costs into anything.

## 6. Questions to resolve before coding — answered 2026-09-07

- **Is `/api/mutations` a stock movement ledger or a record-edit audit log?**
  **Audit log.** `am_core_mutation_logs` records create/update/delete of any AM document (`operation`,
  `target_collection`, `updated_fields`). It does not carry signed quantity, from/to store, or a PR
  reference. Consumption rate is **not** measurable from `/api/mutations`. The closest existing object
  is `am_core_transactions` (workflow ledger). Brief 01 therefore adds `am_core_inventory_movements`
  and `GET /api/v1/movements`, which also **projects** historical transactions so a month of MAS
  history is available immediately.
- **Is stock held per store, per site, or both?**
  **Per location.** `am_core_inventory_levels` is keyed by `asset_id` + `location_id` (+ `country_id`).
  There is no separate store entity. `location_id` resolves to `am_reference_sites`; the API exposes
  that as both `site_id` and `store_id` (canonical `location_code`, e.g. `LSO-MAS`). Filter `site_id=MAS`
  matches `LSO-MAS`.
- **Are allocations modelled?**
  **Yes, two layers.** `am_core_allocations` is employee check-out/in (`Active` / `Returned` /
  `Overdue`). `am_core_inventory_levels.quantity_allocated` is a stored projection updated by the
  dispatch workflow (Approved request reserves; Fulfilled/Cancelled releases). They can drift.
  `GET /api/v1/allocations` returns both employee allocations and Approved/Fulfilled dispatch lines.
  Inventory `qty_allocated` reads the projection.
- **Units?**
  `am_core_assets.unit_of_measure` is the enum `EA` / `M` / `KG` / `L` / `BOX` / `ROLL` / `SET`.
  Conductor is `M`. Application-enforced, not Firestore-rules-enforced; trustworthy when set via the
  AM forms. Exposed as `unit` on `/api/v1/inventory` and `/api/v1/parts`.

## 6a. Register the result

When endpoints ship, update **both**: this repo's `CROSS_REPO_API_CONTRACT.md` (the "Exposed APIs" table)
and `nexus-portal/docs/CANONICAL_DATA_OWNERSHIP.md`. An API that is not in the ownership map will be
rediscovered as missing by the next person — which is exactly what happened to this brief.

## 7. Acceptance

- All six endpoints respond with a valid key and reject without one, verified by test.
- `qty_available` reconciles to `qty_on_hand - qty_allocated` for every row.
- A consumer can, in one pass, obtain stock for a named site joined to UGP part IDs, and identify which
  lines are unmapped.
- `movements` returns a full month of history for one pilot site, or the brief is formally reduced and
  the gap reported upward.
- Zero changes to existing AM UI behaviour; existing Firebase-session access is unaffected.
- Read-only under audit: no endpoint in this brief mutates state.

## 8. Pilot

Use **MAS** (Matsoaing) as the pilot site — it has an active store, active construction and existing
EcoCash/ops records to sanity-check against.
