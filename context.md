# Project Context — 1PWR Asset Management (AM)

## What This Is

**OneStop Asset Shop** — a consolidated asset management system for OnePower Africa, replacing fragmented Google Sheets and legacy WordPress trackers. Tracks physical items across **Lesotho, Zambia, and Benin** operations.

- **Repo:** `onepowerLS/onestop-asset-shop`
- **Branch:** `develop` (production)
- **Live URL:** https://am.1pwrafrica.com
- **Hosting:** AWS EC2 (af-south-1), Apache + PHP 8.5

## Architecture

| Layer | Technology |
|---|---|
| Backend | PHP (Laravel-like structure, no framework) |
| Frontend | HTML / Bootstrap 5 (Volt Dashboard) / jQuery / DataTables |
| Database | Firestore (Google Cloud) — project `pr-system-4ea55` |
| Auth | Firebase Authentication (email/password) |
| QR Codes | External API (`api.qrserver.com`) + Brother PT-P710BT printer |

### Firestore Collections

- **`am_core_assets`** — primary item catalog (owned by AM); includes `legacy_tag` field preserving original item UIDs from pre-migration systems
- **`am_core_allocations`** — check-out/in tracking
- **`am_core_transactions`** — immutable audit trail
- **`pr_master_categories`** — item categories (shared, from PR portal)
- **`pr_master_countries`** — country reference data (shared, from PR portal)
- **`sites`** + **`referenceData_sites`** — canonical location/site data (owned by PR portal, read live by AM)
- **`users`** — user profiles with roles/permissions (shared across all 1PWR tools)

### Shared Firebase Project

This project shares `pr-system-4ea55` with the PR System, Job Cards, and other 1PWR tools. **Never deploy Firestore rules or Firebase config without verifying it covers ALL dependent systems.**

## Item Classification (IAS 16 / IAS 2)

| Class | Treatment | Examples |
|---|---|---|
| Fixed Asset | Capitalized, depreciated | Vehicles, equipment, IT |
| Material | Expensed to project on issuance | Wire, poles, panels |
| Consumable | Expensed immediately on use | PPE, office supplies |
| Inventory | Carried as current asset until deployed | Meters, ready boards |

## Key Files

| Path | Purpose |
|---|---|
| `web/config/app.php` | App configuration, session handling, error reporting |
| `web/config/firebase.php` | Firebase auth, HTTP client functions |
| `web/config/firestore.php` | Firestore CRUD, `am_get_pr_sites()` for location sync |
| `web/login.php` | Login page (form posts to `/auth/firebase-login.php`) |
| `web/assets/index.php` | Main asset catalog with search/filters |
| `web/assets/add.php` | Add new item form |
| `web/assets/edit.php` | Edit existing item |
| `web/assets/view.php` | Single item detail view |
| `web/admin/locations.php` | Read-only location view (synced from PR portal) |
| `web/admin/categories.php` | Category management |
| `web/help.php` | In-app user guide |
| `web/includes/header.php` | HTML header + topbar |
| `web/includes/sidebar.php` | Left navigation sidebar |
| `firestore.rules` | Firestore security rules (covers AM + PR + all tools) |
| `migration/etl.py` | ETL script for Excel→Firestore data migration |
| `migration/import_to_firestore.py` | Batch import script (Firestore REST API) |

## Location Data Flow

Locations are **not** managed in the AM portal. They are read live from the PR portal's Firestore:

1. `am_get_pr_sites()` in `web/config/firestore.php` reads:
   - `sites` collection — Lesotho field sites (canonical)
   - `referenceData_sites` — Benin, Zambia, multi-org sites
2. Maps `organizationId` → country code (1pwr_lesotho→LSO, 1pwr_benin→BEN, 1pwr_zambia→ZMB)
3. Deduplicates by code+country, sorts by country then name
4. To add/rename/remove a site: update it in the **PR portal** at https://pr.1pwrafrica.com

## Deployment

```
# SSH into EC2 (key expires after 60s)
aws ec2-instance-connect send-ssh-public-key \
  --instance-id i-0dda937da2c9d0018 \
  --availability-zone af-south-1a \
  --instance-os-user ec2-user \
  --ssh-public-key file://~/.ssh/id_rsa.pub \
  --region af-south-1

ssh ec2-user@16.28.64.221

# On server — pull and fix permissions
cd /var/www/onestop-asset-shop
sudo chown -R ec2-user:ec2-user .
git fetch origin && git reset --hard origin/develop
sudo chown -R apache:apache .
```

## Test Account

- **Email:** testadmin@1pwrafrica.com
- **Password:** TestAdmin123!
- **Firebase UID:** RXviBLQtHBeoqby4o6zxo3L6Ia12
- **Role:** admin (permissionLevel 3)

## Legacy Data

Migrated items carry a `legacy_tag` field with their original UID from the pre-migration system (e.g. `AD-3`, `FIRE001`, `JKH002`). This field is:
- Displayed in the catalog table, item detail view, and edit/add forms
- Searchable via the catalog search box
- Documented in `docs/FIRESTORE_SCHEMA.md`
- Populated by the ETL migration (`migration/etl.py`); can also be entered manually on new items

The `source` field records migration origin (e.g. `AssetSpreadsheetDB`).

## Known Quirks

- PHP 8.5 deprecated `$http_response_header` and `curl_close()` — already fixed with fallbacks
- `.env` on server uses INI syntax (`;` for comments, quote values with special chars)
- PHP error log: `/var/log/php-fpm/www-error.log`
- `qr_code_id` field may be missing on older assets (causes PHP warnings in catalog)
- Firestore security rules require a `users/{uid}` document with `permissionLevel` for write access
- Legacy `pr_master_locations` collection still exists in Firestore (now unused — AM reads from `sites`/`referenceData_sites` instead)
