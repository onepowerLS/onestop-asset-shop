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
