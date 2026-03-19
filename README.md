# OneStop Asset Shop

**Consolidated Asset Management System for OnePower Africa**

A unified, modern asset management platform replacing fragmented Google Sheets and legacy WordPress systems. Designed for operations across **Lesotho, Zambia, and Benin**.

## Item Classification Model

All physical items are classified into four tiers aligned with IAS 16 / IAS 2 accounting standards:

| Class | Treatment | Description |
|---|---|---|
| **Fixed Asset** | Capitalized, depreciated (IAS 16 PP&E) | Vehicles, heavy equipment, IT, installed infrastructure |
| **Material** | Expensed to project on issuance (IAS 2) | Construction/installation inputs: wire, poles, panels |
| **Consumable** | Expensed immediately on use | Operational supplies: PPE, office, maintenance |
| **Inventory** | Carried as current asset until deployed (IAS 2) | Meters, ready boards, spare parts, kits |

See [`docs/SOP-ITEM-CLASSIFICATION.md`](docs/SOP-ITEM-CLASSIFICATION.md) for the full classification SOP with decision tree and edge cases.

## Documentation

| Document | Description |
|---|---|
| [`docs/USER_GUIDE.md`](docs/USER_GUIDE.md) | End-user guide for all application features |
| [`docs/FIRESTORE_SCHEMA.md`](docs/FIRESTORE_SCHEMA.md) | Collection schemas, field reference, PHP API usage |
| [`docs/SOP-ITEM-CLASSIFICATION.md`](docs/SOP-ITEM-CLASSIFICATION.md) | Item classification SOP with decision tree |
| [`database/MIGRATION_GUIDE.md`](database/MIGRATION_GUIDE.md) | Data migration procedures and legacy mapping |
| [`deployment/DEPLOYMENT.md`](deployment/DEPLOYMENT.md) | AWS EC2 deployment and CI/CD |
| [`TESTING.md`](TESTING.md) | Testing checklists and UAT scenarios |
| [`qr/README.md`](qr/README.md) | QR code format, generation API, scanning |

## Features

- **4-Tier Item Classification**: Industry-standard asset/material/consumable/inventory model with 22 seed categories
- **QR Code Integration**: Print labels (Brother PT-P710BT) and scan items (Symcode 2D Scanner)
- **Tablet-Optimized**: Mobile-first interface for field operations (stock ingestion, check-in/out, stock taking)
- **Multi-Country Support**: Unified tracking across Lesotho, Zambia, and Benin
- **Lifecycle Tracking**: Items transition between classes (e.g., Material -> Fixed Asset on commissioning)
- **Depreciation Support**: Per-category useful life and depreciation method for Fixed Assets
- **Reorder Alerts**: Configurable reorder points for Consumables and Inventory
- **Consolidated Database**: Single source of truth replacing 15+ Google Sheets
- **Auto-Deployment**: CI/CD pipeline to AWS EC2

## Technology Stack

- **Backend**: PHP with Firestore (Firebase) data layer
- **Frontend**: Volt Dashboard (Bootstrap 5)
- **Database**: Firestore (primary), MySQL (schema reference)
- **Auth**: Firebase Authentication (SSO via Nexus portal)
- **Hosting**: AWS EC2 / Firebase Hosting
- **Version Control**: Git (GitHub: `onepowerLS/onestop-asset-shop`)

## Current Status

- [x] Source code extracted from InMotion hosting
- [x] Database schema analyzed and modernized
- [x] Google Sheets inventory mapped (15+ sources)
- [x] Dropbox data sources assessed (see [`database/DATA_SOURCES_ASSESSMENT.md`](database/DATA_SOURCES_ASSESSMENT.md))
- [x] 4-tier item classification model implemented (IAS 16/IAS 2)
- [x] 22 seed categories defined across all classes
- [x] Classification SOP with decision tree created
- [x] Firestore write/update/delete layer (`web/config/firestore.php`)
- [x] Asset CRUD: add, view, edit with item_class-driven forms
- [x] Admin pages: categories, locations, employees
- [x] Stock levels dashboard with reorder alerts
- [x] Transaction history with filtering
- [x] Check-out/check-in workflow
- [x] Request management with approval flow
- [x] QR code generation via Firestore (API + batch admin)
- [x] Data migration ETL (Python) -- 2,874 items from 9 sources, classified and deduplicated
- [x] Legacy category_type → item_class mapping (built into ETL)
- [x] Firestore security rules (role-based via permissionLevel, deployed)
- [x] Tablet mode (scan-centric check-out/in, stock count, quick lookup)
- [x] Reports & export (6 report types, CSV and PDF)
- [x] Admin migration page (batch import from ETL JSON to Firestore)
- [ ] Initial data load (run ETL → import via admin page)
- [ ] UAT walkthrough on staging server

## Project Structure

```
onestop-asset-shop/
├── database/
│   ├── schema-consolidated.sql   # Canonical schema with item_class model
│   ├── MIGRATION_GUIDE.md        # Data migration procedures
│   └── migrations/               # Step-by-step migration scripts
├── docs/
│   └── SOP-ITEM-CLASSIFICATION.md  # Classification SOP & decision tree
├── web/
│   ├── config/                   # App, Firebase, Firestore config
│   ├── includes/                 # Header, sidebar, footer templates
│   ├── assets/                   # Item listing, add, edit, view
│   ├── inventory/                # Stock level tracking
│   ├── requests/                 # Material/item requests
│   ├── checkout/                 # Check-out/in workflows
│   ├── admin/                    # Categories, locations, employees, QR, migration
│   ├── tablet/                   # Tablet-optimized scan-centric UI
│   ├── reports/                  # Report generation and CSV/PDF export
│   └── api/                      # QR generation and other APIs
├── migration/
│   ├── etl.py                    # Python ETL: Excel sources → classified JSON
│   └── output/                   # ETL output (JSON files for import)
├── qr/                           # QR generation & scanning utilities
├── firestore.rules               # Firestore security rules
└── deployment/                   # AWS deployment configs
```

## Getting Started

1. Clone the repo: `git clone https://github.com/onepowerLS/onestop-asset-shop.git`
2. Copy `web/config/firebase.php.example` to `web/config/firebase.php` and add credentials
3. Serve via Apache/Nginx pointing document root to `web/`
4. Log in via Firebase Authentication

## License

Proprietary - OnePower Africa
