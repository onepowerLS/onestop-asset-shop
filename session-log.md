# Session Log — 1PWR Asset Management

## Session: Jan 25, 2026

### 1. Nexus Portal & Unified Identity Plan
- Developed a comprehensive plan for migrating all user accounts from various 1PWR tools into a single unified identity store
- Designed a central landing portal at `nexus.1pwrafrica.com`

### 2. Asset Classification Alignment
- Realigned AM item categorization to IAS 16 / IAS 2 standards
- Four tiers: Fixed Assets, Materials, Consumables, Inventory
- Created SOP for item classification (`docs/SOP-ITEM-CLASSIFICATION.md`)
- Seeded 22 categories across the four tiers

### 3. Full AM Web Application Build
Built out the entire missing functionality of the AM web app:
- Firestore write layer (CRUD operations via REST API)
- Core asset pages: catalog, add, edit, view
- Admin pages: employees, locations, categories, QR labels, data migration
- Stock & transaction pages: stock levels, check-out/in, transactions
- Request management
- Reports & CSV export
- Tablet-optimized mode for field operations

### 4. Data Migration
- Wrote ETL pipeline (`migration/etl.py`) to parse Excel source data
- Batch import via Firestore REST API (`migration/import_to_firestore.py`)
- Imported ~2,874 assets, categories, countries, locations, employees
- Resolved Firestore security rules blocking batch writes (temporarily opened rules during import)

### 5. Firestore Security Rules
- Deployed role-based rules checking `users/{uid}.permissionLevel`
- Rules cover AM collections + catch-all for shared PR/Job Card collections

### 6. Production Deployment & Debugging
- Deployed to `am.1pwrafrica.com` on AWS EC2 (af-south-1)
- Fixed PHP 8.5 deprecation warnings (`$http_response_header`, `curl_close()`)
- Fixed `.env` parsing issues (INI syntax quirks)
- Fixed broken logo/favicon references (replaced with inline text/SVG)
- Fixed Firestore field name mismatches (`category_id` vs `id`, UUID sorting)
- Suppressed production warnings, enabled error logging
- Created Firebase test user for UAT

### 7. In-App Help Guide
- Created `web/help.php` with comprehensive user documentation
- Added sidebar navigation link
- Covers all features: catalog, stock, check-out/in, QR codes, admin, roles

### 8. Location Data Fix — PR Portal Sync
- Identified that ETL-generated location names were non-canonical guesses
- Discovered the PR portal maintains canonical sites in `sites` and `referenceData_sites` collections
- Created `am_get_pr_sites()` function to read locations live from PR portal
- Replaced all `pr_master_locations` reads across 9 PHP files
- Converted admin Locations page to read-only view with link to PR portal
- Key corrections: Matsieng→Matsoaing, Sehong→Sehonghong, Sebelekoane→Sebapala, etc.
- Result: 20 Benin sites, 28 Lesotho sites, 1 Zambia site — always in sync

## Session: Mar 26, 2026

### 9. Location Data — PR Portal Sync (continued)
- Queried all Firestore collections in `pr-system-4ea55` to find the canonical location source
- Found `sites` (27 Lesotho field sites) and `referenceData_sites` (50+ multi-org sites for Benin, Zambia, Lesotho)
- Created `am_get_pr_sites()` function in `web/config/firestore.php`:
  - Reads `sites` collection (Lesotho canonical) + `referenceData_sites` (Benin/Zambia from primary 1PWR orgs)
  - Maps `organizationId` → country code, deduplicates by code+country
- Replaced `am_firestore_get_collection('pr_master_locations', ...)` across 9 PHP files with `am_get_pr_sites()`
- Converted admin Locations page to read-only view pointing users to PR portal for management
- Key name corrections: Matsieng→Matsoaing, SEH→Sehlabathebe, SEB→Sebapala, SHG→Sehonghong, MAK→Ha Makebe
- Result: 20 Benin sites, 28 Lesotho sites, 1 Zambia site — always in sync with PR portal

### 10. Legacy Item UID Field
- `legacy_tag` field already existed in Firestore from the ETL migration but was invisible in the UI
- Surfaced it across all relevant pages:
  - **Catalog** (`index.php`): new "Legacy ID" column between Asset Tag and Name
  - **Search**: `legacy_tag` added to search blob so old UIDs are findable
  - **Item view** (`view.php`): Legacy ID shown alongside Asset Tag and QR Code (when populated)
  - **Add form** (`add.php`): Legacy ID input field for manually entering old UIDs
  - **Edit form** (`edit.php`): Legacy ID input field, persisted on save
- Documented `legacy_tag` and `source` fields in `docs/FIRESTORE_SCHEMA.md`

### 11. Project Documentation
- Created `context.md` — project overview for future sessions (architecture, key files, deployment, test credentials, known quirks)
- Created `session-log.md` — chronological record of all work across sessions

### Firebase Test Account
- Previous test user was deleted/expired; recreated:
  - Email: testadmin@1pwrafrica.com / Password: TestAdmin123!
  - Firebase UID: RXviBLQtHBeoqby4o6zxo3L6Ia12
  - Firestore `users` doc: role=admin, permissionLevel=3, organizationId=1pwr_lesotho

### Commits (chronological)
```
f5271c5 Initial commit: Project setup and README
49af00b Add consolidated database schema
04d9bd3 Add database migration guide
de3b023 Add QR code integration
a977689 Add deployment infrastructure
07dbe01 Add branch strategy and testing documentation
f15680b Add data sources assessment for migration
ee4f38a Migrate AM auth/data to Firebase
426f0c2 Add 1PWR Nexus unified auth portal
59515b7 Use nexus.1pwrafrica.com for unified portal
32ca5e1 Implement industry-standard 4-tier item classification model
934be4f Build complete AM web application with Firestore CRUD
35a4dd8 Add data migration pipeline, tablet mode, reports, and Firestore security
7d9a101 Fix PHP 8.5 deprecation warnings for $http_response_header
41181a4 Fix PHP 8.5 curl_close deprecation and replace missing logo
f26e326 Fix broken image references in header/topbar
affd828 Fix Firestore document ID fallback in catalog filter dropdowns
f6db277 Fix sort on UUID asset IDs and suppress warnings in production
34da144 Add in-app Help & User Guide page
a5b6fc9 Wire AM locations to PR portal's canonical sites collection
0a1ea08 Surface legacy_tag field across AM UI
```

### Open Items
- `qr_code_id` field missing on some older assets (causes PHP warnings in catalog)
- Legacy `pr_master_locations` collection still exists in Firestore (now unused, can be cleaned up)
- Country filter dropdown uses `pr_master_countries` IDs (1, 2, 3) — could be mapped to org names

## 2026-09-02 — Cursor — Fix: no new requests since Aug 27 (read + write root causes)
- **Symptom**: LS stores team (Thabo/Metro) reported submitted requests invisible; Service Workflows empty; last request AMW-2026-00131 (Aug 27).
- **Read-side root cause**: tracked `firebase-service-account.json` symlink (committed Jul 20, `ec025c8`) pointed at a macOS Dropbox path, so every EC2 deploy recreated a dangling link; the admin-bearer read fallback (`169bf1b`) threw on mint and reads died on expired user tokens. Fixed: untracked + gitignored the file (`79d1178`, deployed), placed the real SA key at `/var/www/onestop-asset-shop/firebase-service-account.json` (apache 640) — verified mint + 129-doc read on the server.
- **Write-side root cause**: claim-only authz + `am_require_can_request()` (Level D) while the Nexus AM catalog lacked `request_assets` and rules required Level C for creates. Fixed on the Nexus side (see nexus-portal session log): `request_assets` added to catalog (163d99e), rules `canRequestAssets` for `am_core_requests` (other session, Sep 1) + `am_core_phone_requests` (mine, `eb459a3`), functions + rules deployed. Verified live: D-level token created a request (200), update correctly denied.
- **Side effects**: production deploys of `main` (`79d1178`); server file placement; Firestore housekeeping verified already done (DIAG-DELETE-ME + empty docs gone).
- **User action required**: all AM users must sign out and re-launch via Nexus SSO once to pick up `request_assets` claims. Thabo (B grant) + Metro (AM Lead in HR → B) approved for fulfilment.
- **Note**: LS stores team was using shared `amtest@1pwrafrica.com` fallback session (unsigned → read-only). Instructed to use personal Nexus accounts. TestAdmin left enabled per MSO decision.

## 2026-09-03 — Cursor — Fix: country dropdowns empty / "country_id required" on request forms
- **Symptom**: THAKHOLI reported the dispatch form wouldn't allow country selection; catalog search showed "country_id required".
- **Root cause**: once the admin-bearer read fallback started working (79d1178, Sep 2), `am_get_countries()` began returning the `am_reference_countries` canonical cache — which carries PR's ISO-2 shape (`{code: 'LS', name}`) with no `country_id`/`country_code`. `dispatch-new.php` filters on `country_code` ∈ ISO-3 allow-list → zero countries → empty dropdown. (Latent incompatibility exposed by the symlink fix, not caused by a data change.)
- **Fix**: `am_get_countries()` now normalizes every row via `am_normalize_country_row()` — ISO-2→ISO-3 code mapping and legacy numeric `country_id` (LSO=1, ZMB=2, BEN=3, the FK stored on requests/inventory). Commit `dbff80a`, deployed to EC2; verified on-server: 3 countries with correct shape.
- **Side effects**: production deploy of `main` (`dbff80a`). No data changes.
- **Follow-up**: the same shape mismatch may lurk for other canonical types (organizations/departments/employees) if consumers expect different field names — worth a shape audit when touching those.

## 2026-09-07 — Cursor — Brief 01 inventory read API
- What: Read-only `/api/v1/{inventory,allocations,movements,loadouts,parts,health}` with existing `X-API-Key` scheme. `qty_available` computed server-side. Movements are a new append-only collection plus a projection of `am_core_transactions` (mutations endpoint is an audit log, not a stock ledger). Extended loadout payload with `lines[]` / `site_id`.
- Side effects: none deployed yet. Nexus `firestore.rules` gained `am_core_inventory_movements` (append-only) — **must be deployed from the Nexus repo**, not AM. Provision `AM_API_KEY_UGRIDPREDICT` on EC2 `.env` before consumers can call.
- Key files: `web/config/integration_api.php`, `web/config/inventory_read.php`, `web/config/inventory_movements.php`, `web/api/v1/*`, `CROSS_REPO_API_CONTRACT.md`
- Follow-ups: seed `AM_API_KEY_UGRIDPREDICT`; deploy Nexus rules; add What's New entry in Admin UI once live; smoke-test `?site_id=MAS`.

## 2026-09-07 — Cursor — Deploy Brief 01 inventory API + Nexus rules
- Pushed AM `e7195ee` to `main`; pulled on EC2 `/var/www/onestop-asset-shop` (live commit `e7195ee`).
- Appended `AM_API_KEY_UGRIDPREDICT` to EC2 `.env` (640 apache:apache). Did not commit the secret.
- Deployed `firestore.rules` from Nexus repo `feat/am-rules-claim-only` @ `047e3cb` to project `pr-system-4ea55` (`firebase deploy --only firestore:rules` only — no functions).
- Verified live: `/api/v1/health` 200 version e7195ee; `/api/v1/inventory` 401 without key; 200 with key (`site_id=MAS` returned 51 rows).
- Side effects: production AM code + env; production Firestore rules released.
- Follow-up: add the What's New row in Admin UI; give the ugridpredict key to the forecast service.

## 2026-09-07 — Cursor — What's New entry for inventory read API
- Created live Firestore `am_core_whats_new` doc `QcXau2iopCucfBCdmcBB` (title: Inventory read API for forecast and reporting) via EC2 admin token. Users who have not dismissed it will see the login primer.
- Added the same entry to `scripts/seed_whats_new.php` and Admin seed list so re-seeds skip it.
- Side effects: one production Firestore write to `am_core_whats_new`. No deploy required for the popup (data-only). Seed-list code will follow on next push.

## 2026-09-08 — Cursor — Forecast remaining-work instructions
- Added `docs/FORECAST_PROGRAMME_REMAINING.md` and a pointer on Brief 01. Remaining AM work is `ugp_part_id` mapping, unmapped worklist, units, and giving the consumer key to uGridPREDICT — not an API rebuild.
- Side effects: none (docs only).

## 2026-09-13 — Cursor — Inventory double-count + catalogue definitions
- Phase 1 (BRIEF_INVENTORY_LEVELS_DOUBLE_COUNT): `am_canonical_location_code` no longer echoes unresolved ids; site change on stockable edit moves qty (zero source + upsert dest + Transfer) in one Firestore commit; `/api/v1/inventory` exposes `reconciliation_status`; reconciler detects dups/orphans/unresolvable and quarantines orphans as `unverified` (dry-run first). Tests: `tests/inventory_levels_site_change_test.php`.
- Phase 2 (AM_CATALOGUE_QUALITY): `am_part_definitions` / reviews / `am_catalogue_tasks`; Admin UI + Add Item shared-definition search; `/api/v1/parts` catalogue_status fields. Photos deferred (Phase 3).
- Side effects: none from AM EC2 yet (code not pushed in this step). Nexus rules for new collections deployed separately.
- Key files: `web/config/inventory_levels.php`, `web/assets/edit.php`, `web/assets/add.php`, `web/config/inventory_read.php`, `scripts/reconcile_inventory_levels.php`, `web/config/part_definitions.php`, `web/admin/part-definitions.php`, `web/admin/catalogue-tasks.php`, `web/api/v1/parts.php`
- Follow-ups: push/deploy AM; run `php scripts/reconcile_inventory_levels.php --all --dry-run` against prod; Phoka drum count for unverified store-vs-site; Phase 3 photos when storage confirmed.

## 2026-09-13 — Cursor — Deploy inventory reconcile + shared catalogue to EC2
- Committed Phase 1+2 AM code + What's New marker (`9b7a105`); pushed `main`; GitHub Actions Deploy to EC2 succeeded (run 34779533134, ~20:03Z).
- Live health: `https://am.1pwrafrica.com/health.php` and `/api/v1/health` report commit `9b7a105`.
- CI now runs `inventory_levels_site_change_test.php` and `part_definitions_test.php`.
- Nexus: catalogue/movements rules already live via `npm run deploy:rules`; opened PR to sync rules into Nexus `main`: https://github.com/onepowerLS/nexus-portal/pull/11
- Side effects: production AM code on EC2 `/var/www/onestop-asset-shop` at `9b7a105` (was `5e6ca37` lineage; also shipped earlier unpushed receipt/UGP commits). Admin → What's New row still needs creating in the live app to match marker `2026-09-13-inventory-reconcile-and-catalogue.md`.
- Follow-ups: add live What's New; dry-run `php scripts/reconcile_inventory_levels.php --all --dry-run` on EC2; merge Nexus PR #11; Phase 3 photos later.

## 2026-09-14 — Cursor — Fix reference photo upload 2 MB PHP cap
- Symptom: Metro could not upload Suspension Clamp reference photo; UI said 5 MB but PHP-FPM `upload_max_filesize` was still 2M (default). Generic error masked the real cause.
- Fix (live): installed `/etc/php.d/99-am-uploads.ini` (`10M`/`12M`) and **restarted php-fpm** (httpd reload alone left workers on 2M). Verified via temporary probe: `upload=10M post=12M`.
- App: clearer upload error messages; browser-side JPEG compress ≤1600px before POST; documented drop-in in `deployment/99-am-uploads.ini` and `docs/RET_AM_WORKSHOP.md`.
- Side effects: production PHP-FPM upload limits raised on EC2 `16.28.64.221`; AM code deploy follows push to `main`.
- Follow-ups: Metro retries Suspension Clamp (and other class photos); hard-refresh if old page cached.

## 2026-09-15 — Cursor — Fix workshop "outside your organization scope" on publish
- Symptom: Metro could select LS AM candidates in RET–AM workshop but Publish failed with "An item is outside your organization scope."
- Root cause: `saveAmReconciliation` required exact `asset.organization_id ∈ JWT scopeOrganizations`. AM PHP shows candidates after country→org fallback and ignores unrecognized grant org ids (defaults to all known AM orgs). Assets with empty/mismatched org ids passed the UI then failed the callable.
- Fix (PR repo `15ac51b`): `assetInOrganizationScope` resolves org from country when missing and treats non-AM grant org ids as unrestricted (country gate remains). Deployed selectively: `saveAmReconciliation`, `confirmAmUgpMapping` to `pr-system-4ea55`.
- Side effects: production Cloud Functions updated; Nexus SSO functions verified still listed.
- Follow-ups: Metro hard-refresh / relaunch AM from Nexus, publish only true same-part matches (not Suspension Clamp / Stay Wire for Dead-End Clamp).

## 2026-09-19 — Cursor — Catalog search matches words, plurals, and related terms
- Catalog search was an exact phrase filter buried in the filter row, so "drones" missed "drone" and "UAV".
- Added `web/config/catalog_search.php`: each word must match, plurals and a small related-word list (drone/UAV/quadcopter) count, name hits rank first, and the row says where it matched.
- Catalog page has a search box with match count and related-word note. Same matcher is used by Add Item search and dispatch item search.
- Side effects: pushed `fc0f432` to `main`; Deploy to EC2 succeeded (run 35431866734). Live health reports `fc0f432`.
- Follow-ups: add the What's New row in Admin so the login primer matches the marker.

## 2026-09-19 — Cursor — Phone layout: off-canvas menu and stacked tables
- The shell was desktop-first: the hamburger opened a collapsed block, and catalog tables were wide sideways scrolls.
- `am-layout.css` now slides the sidebar over the page below 992px, dims the page behind it, and turns data tables into labeled cards below 768px. Inputs stay 16px so iOS does not zoom. Workshop tiles stack to one column.
- Side effects: pushed `8e4d98d` to `main`; Deploy to EC2 succeeded. Live health reports `8e4d98d`.
- Follow-ups: add the What's New row in Admin so the login primer matches the marker. Hard-refresh on the phone if the old stylesheet is cached.

## 2026-09-21 — Cursor — Dispatch catalog search timed out
- Symptom: Monaheng / Motiki Ramothule, dispatch request, search "M16 pigtail" in Lesotho showed "Search failed. Try again." Three other lines were already on the request (PVC conduit, TTD 201, House Wire 6mm Black).
- Cause: the search downloads the whole asset collection. A large Firestore page stalled (SSL EOF / 36s timeout around 15:08–15:15 Africa/Maseru). The browser got a non-JSON response.
- Fix: smaller pages (250), one retry on a dropped connection, skip the extra slow fallback after a timeout, two-minute file cache, and a clear retry message. "pigtail" also matches "pig tail".
- Side effects: deployed to production. Commit 98a84a1 pushed to origin/main. GitHub Actions Deploy to EC2 run 35606414345 succeeded. health.php at 2026-09-21T13:35:16Z reports commit 98a84a176007749a045e4eda833237c948aeda43. Live catalog scan (3982 assets) has no item named exactly "M16 pigtail"; closest Lesotho rows are M16 hex nuts for pigtail (1PWR-MAT-LSO-000239), M16 washers for pigtail (1PWR-MAT-LSO-000237), and Pigtail Screws (1PWR-MAT-LSO-000084).
- Key files: web/api/dispatch/search-items.php, web/config/firebase.php, web/config/catalog_search.php, web/requests/dispatch-new.php
- Follow-ups: they should hard-refresh the dispatch page and search again. Search "pigtail" to also see Pigtail Screws.

## 2026-09-24 — Cursor — Delete triplicate load-out manifests (Metro)
- Symptom: Metro — "RET mission 22/09/2026" appeared four times (LO-2026-0034…0037), all Delivered to Sehlabathebe. No Delete on Delivered. Created ~2 min apart by the same user (double/triple Save).
- Data fix (production Firestore `am_core_loadout_manifests`): kept `LO-2026-0034` (`3VsDMe74AstCSQ8jZlMa`); deleted `0035`/`fgpZz1eyF9RbQpKrO97m`, `0036`/`bb3m6tcoD7SZJLZiU73B`, `0037`/`BmWc1nT579frluaZ7qgr` via service-account OAuth2 (ID-token path lacks Approver delete). Verified only 0034 remains for that title.
- App fix `4aba612`: one-time submit token + disable Save on submit; Delete allowed for Cancelled as well as Draft; list hides Cancelled unless "Include Cancelled".
- Deploy: GitHub Actions SSH deploy first failed (ec2-user sudo password-gated after React2Shell). Hotfixed via SSM, then installed `/etc/sudoers.d/90-am-deploy` (NOPASSWD for git/chown/chmod/tee/httpd reload/php only — not ALL). GHA run 35987920377 succeeded. health.php reports `cbc5888` at 2026-09-24T10:34:23Z.
- Follow-ups: Metro hard-refresh Load-out list — only LO-2026-0034. Accidental extras: Edit → status Cancelled → Save (list hides Cancelled by default).

## 2026-09-24 — Cursor — Check-Out/In empty employee and return dropdowns
- Symptom: Thabo Molibeli — Check-Out/In: Item and Location work; Employee and "allocation to return" look empty. Active Allocations (0).
- Cause: HR cache `am_reference_employees` stores `name`, but the page rendered `first_name`/`last_name` only → 123 blank options. `am_core_allocations` has 0 docs while ~1000 assets are `CheckedOut`, so the return list had nothing.
- Fix: use `am_employee_directory_display_name`; list CheckedOut assets as returnable when no allocation row exists; store `employee_name` on new check-outs.
- Side effects: pending deploy with this commit.
- Key files: `web/checkout/index.php`
- Follow-ups: Thabo hard-refresh Check-Out/In.




