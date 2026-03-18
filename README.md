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
- [ ] Data migration from identified sources
- [ ] Legacy category_type -> item_class migration
- [ ] QR code integration
- [ ] Tablet UI development

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
│   ├── admin/                    # Categories, locations, employees
│   └── api/                      # QR generation and other APIs
├── qr/                           # QR generation & scanning utilities
└── deployment/                   # AWS deployment configs
```

## Getting Started

1. Clone the repo: `git clone https://github.com/onepowerLS/onestop-asset-shop.git`
2. Copy `web/config/firebase.php.example` to `web/config/firebase.php` and add credentials
3. Serve via Apache/Nginx pointing document root to `web/`
4. Log in via Firebase Authentication

## License

Proprietary - OnePower Africa
