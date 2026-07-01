# Instructions for the UGP codebase (`ugp.1pwrafrica.com`)

**Audience:** Developer or agent implementing / maintaining integration with **Asset Management** (`am.1pwrafrica.com`).  
**Shared Firebase project:** `pr-system-4ea55` (same as PR, AM, Job Cards, etc.).

This document describes **what must happen in UGP** so assembly **parts** stay aligned with **AM inventory** without duplicate catalog rows. The AM-side implementation is already in the OneStop Asset Shop repo; UGP must **call AM** and/or **expose stable part identities** consistently.

---

## 1. Business outcome

- Every **part** that UGP treats as a first-class assembly component should have a matching **`am_core_assets`** row in AM, classified as **`Inventory`**, with optional **quantity 0**.
- **One real-world part → one `ugp_part_id` → at most one AM inventory row** (after sync). AM deduplicates using `ugp_part_id` and optional normalized-name linking (see AM docs).

---

## 2. Non-negotiable: stable `ugp_part_id`

AM stores the link on each inventory asset as **`ugp_part_id`** (string).

| Rule | Detail |
|------|--------|
| **Stability** | Once a part is identified in UGP, its **`ugp_part_id` must not change** for that same physical/catalog part. If the part is truly replaced by a new SKU, use a **new** id and treat it as a new sync target. |
| **Format** | AM accepts any non-empty string. Recommended: UGP’s **Firestore document id** for the part, or a namespaced code e.g. `ugp-part-{docId}`. Avoid embedding only human-readable labels (they change). |
| **Uniqueness** | Globally unique per part **within your product model**; AM assumes one AM row per `ugp_part_id`. |

If UGP already has a part collection (e.g. `parts/{partId}`), **`partId` is the natural `ugp_part_id`** to send to AM.

---

## 3. Payload AM expects (per part)

Each part in a sync batch should map to:

| Field | Required | Notes |
|-------|----------|--------|
| `ugp_part_id` or `id` | **Yes** | Stable id (see §2). AM accepts either key in JSON. |
| `name` | **Yes** | Display name; used for normalized-name **linking** when `ugp_part_id` is not yet on AM. |
| `description` | No | AM may fill empty description or append to notes if descriptions differ. |
| `quantity` | No | Defaults to **0** on create in AM. |
| `unit_of_measure` | No | e.g. `EA`, `M`, `BOX`; default `EA`. |
| `location_id` | No | AM `pr_master_locations` / site id if you have one; usually empty at catalog level. |

**Batch-level field (required in API body):**

| Field | Required | Notes |
|-------|----------|--------|
| `country_id` | **Yes** | Must be a **`pr_master_countries` document id** (numeric/string as stored in Firestore). This scopes the catalog to Lesotho / Zambia / Benin (or whichever org country you use). **Wrong country = wrong inventory bucket.** |

Obtain valid `country_id` values from AM admins or by reading `pr_master_countries` in the same Firebase project (read-only).

---

## 4. HTTP API (primary integration path)

**Endpoint (production):**

`POST https://am.1pwrafrica.com/api/ugp/parts-sync.php`

**Headers — choose one authentication method:**

1. **Firebase ID token (recommended for jobs running as a signed-in user)**  
   `Authorization: Bearer <Firebase ID token>`  
   The user must have **Manager-level** (or higher) AM permissions in the shared `users/{uid}` profile so Firestore rules allow writes to `am_core_assets`.

2. **Server-to-server**  
   `X-API-Key: <UGP_PARTS_SYNC_API_KEY>`  
   plus AM server env **`FIREBASE_ADMIN_BEARER_TOKEN`** (long-lived token or service-account-backed token configured **only on the AM server**).  
   **Never** expose the admin bearer token in the UGP frontend or public repos.

**Request body (JSON):**

```json
{
  "country_id": "<pr_master_countries id for this catalog>",
  "parts": [
    {
      "ugp_part_id": "abcPartDocId123",
      "name": "Single-phase meter",
      "description": "Optional long text",
      "quantity": 0,
      "unit_of_measure": "EA"
    }
  ],
  "link_on_normalized_name": true,
  "dry_run": false
}
```

| Body field | Notes |
|------------|--------|
| `link_on_normalized_name` | Default **true**. If **false**, AM only matches on existing `ugp_part_id` or **creates** new rows (no name-based link). |
| `dry_run` | If **true**, AM returns what it **would** do without writing (for validation in CI/staging). |

**Success response (shape):**

```json
{
  "success": true,
  "stats": {
    "updated": 0,
    "linked": 0,
    "created": 0,
    "ambiguous": 0,
    "errors": 0
  },
  "results": [ { "ok": true, "action": "created|linked|updated", "asset_id": "...", "message": "..." } ],
  "timestamp": "2026-04-13T12:00:00+00:00"
}
```

**`action` values:**

- `updated` — AM row already had this `ugp_part_id`; metadata refreshed.
- `linked` — No row had the id, but **exactly one** Inventory item in that country matched **normalized name**; AM set `ugp_part_id` on it.
- `created` — New Inventory asset created.
- `ambiguous` — Multiple name matches; **no write**; resolve duplicates in AM and re-sync.
- `error` — Validation or Firestore error; check `message`.

---

## 5. When to call the API

Implement at least one of:

| Trigger | Suggestion |
|---------|------------|
| **Scheduled job** | Nightly or hourly: export all active parts for each country org and `POST` batches (chunk size ≤ practical limit; AM loads up to ~2000 assets per run—keep batches reasonable). |
| **On part save** | After a part is created/updated in UGP, enqueue a sync for that single part (idempotent). |
| **Manual admin** | “Push catalog to AM” button that calls the same endpoint with `dry_run` first optional. |

Always send the **current** `name` / `description` so AM can refresh metadata where policy allows.

---

## 6. Idempotency and retries

- Calling sync **multiple times** with the same `ugp_part_id` is **safe**: AM updates the same row or no-ops as appropriate.
- Use **exponential backoff** on HTTP 5xx / network failures.
- Log **`asset_id`** returned by AM if you need cross-system support tickets.

---

## 7. Country and permissions

- **Country:** UGP must use the correct **`country_id`** for each org (LS vs ZM vs BN catalogs). If UGP is multi-country, run **separate sync jobs** per `country_id` or include only parts belonging to that country in each batch.
- **Auth:** The Firebase user or service account used for sync must be allowed to **create/update** `am_core_assets` per shared Firestore rules (typically PR **permissionLevel** Manager+). If sync gets `permission-denied`, fix the user profile or rules in coordination with the platform admin—**do not** weaken rules only on UGP.

---

## 8. What not to do

- Do **not** generate a **new** `ugp_part_id` for the same part on every sync.
- Do **not** embed secrets (AM admin bearer, API keys) in UGP client-side bundles.
- Do **not** assume AM rows exist without a successful sync response; create flows should tolerate **created** vs **linked** vs **updated**.

---

## 9. Local / staging testing

1. Use AM **staging** or a test tenant with a dedicated **`country_id`** if available.
2. Run with **`dry_run: true`** and inspect `results`.
3. Run a real sync with one test part; verify in AM UI: **Admin → UGP parts alignment** (import tool) or **Catalog** filtered to **Inventory** — item shows **`ugp_part_id`** on the asset detail page.
4. Re-run the **same** payload → expect **`updated`**, not a second row.

---

## 10. Reference documentation (AM repo)

| Doc | Content |
|-----|---------|
| `docs/UGP_AM_PART_ALIGNMENT.md` | Conceptual alignment and dedup rules |
| `docs/FIRESTORE_SCHEMA.md` | `ugp_part_id`, `ugp_last_sync_at` on `am_core_assets` |
| `web/config/ugp_parts.php` | PHP implementation of matching logic |
| `web/api/ugp/parts-sync.php` | API entrypoint |

---

## 11. Checklist before marking UGP work “done”

- [ ] Every part document in UGP has a **stable id** used as `ugp_part_id`.
- [ ] Sync job(s) send **`country_id`** correctly per org.
- [ ] Auth path chosen (user token vs server key) and secrets stored in env / secret manager.
- [ ] Handled **`ambiguous`** in logs/alerts for manual AM cleanup.
- [ ] Documented runbook for on-call (where logs live, who adjusts AM duplicates).

---

*Update this file when the AM API path, fields, or auth method changes; keep UGP README or internal wiki in sync.*
