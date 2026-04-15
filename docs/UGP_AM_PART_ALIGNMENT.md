# UGP parts ↔ AM inventory alignment

## Goal

Parts that exist in **UGP** (as assembly components) should exist in **AM** as **Inventory** catalog rows. Quantity may be zero; the important part is a **stable, deduplicated catalog entry** per UGP part.

## Canonical key: `ugp_part_id`

- UGP should expose a **stable string id** per part (e.g. Firestore document id or a dedicated part code). AM stores it on `am_core_assets` as **`ugp_part_id`**.
- Sync logic **never creates a second AM row** if an asset already has the same `ugp_part_id`.

## Avoiding duplicates when spelling differs

1. **Primary:** match on **`ugp_part_id`** (exact).
2. **Secondary (optional):** if no row has that id, look for **exactly one** existing **Inventory** item in the **same country** with:
   - no `ugp_part_id` yet, and  
   - **normalized name** equal to UGP’s name (`am_ugp_normalize_key()` — lowercase, strip punctuation, collapse spaces).  
   Then AM **sets `ugp_part_id`** on that row (link), instead of creating a new document.
3. If **more than one** Inventory row matches normalized name → **ambiguous**; resolve manually in AM (merge/retire duplicates, then re-run sync).

UGP should **always send the same `ugp_part_id`** for a given part; descriptions can change without creating new AM rows.

## Implementation (AM)

| Piece | Location |
|-------|----------|
| Core logic | `web/config/ugp_parts.php` |
| HTTP API (batch) | `POST /api/ugp/parts-sync.php` |
| Admin UI (JSON paste) | `web/admin/ugp-parts.php` |

## UGP application responsibilities

1. Emit **`ugp_part_id`** (or `id`) and **`name`** (and optional **`description`**) for each part in sync payloads.
2. Call the AM API on a schedule or on part publish, with **`country_id`** set to the correct `pr_master_countries` id for that org’s catalog.
3. Do **not** change `ugp_part_id` for the same real-world part; if a part is truly replaced, use a **new** id and optionally retire the old AM line.

## Environment

- **`UGP_PARTS_SYNC_API_KEY`** — shared secret for `X-API-Key` (with server-side `FIREBASE_ADMIN_BEARER_TOKEN` on AM), or use **`Authorization: Bearer`** with a Firebase ID token from a Manager+ user.

## Firestore rules

No change required if existing AM asset rules already allow Managers to create/update `am_core_assets`; confirm in shared project before production sync jobs.
