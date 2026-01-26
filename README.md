# OneStop Asset Shop

**Consolidated Asset Management System for OnePower Africa**

A unified, modern asset management platform replacing fragmented Google Sheets and legacy WordPress systems. Designed for operations across **Lesotho, Zambia, and Benin**.

## Features

- 🏷️ **QR Code Integration**: Print labels (Brother PT-P710BT) and scan assets (Symcode 2D Scanner)
- 📱 **Tablet-Optimized**: Mobile-first interface for field operations (stock ingestion, check-in/out, stock taking)
- 🌍 **Multi-Country Support**: Unified inventory tracking across Lesotho, Zambia, and Benin
- 📊 **Consolidated Database**: Single source of truth replacing 15+ Google Sheets
- 🔄 **Auto-Deployment**: CI/CD pipeline to AWS EC2

## Technology Stack

- **Backend**: Laravel (PHP) or Django (Python) - TBD
- **Frontend**: Volt Dashboard (Bootstrap 5) - Refactored
- **Database**: PostgreSQL/MySQL
- **Hosting**: AWS EC2
- **Version Control**: Git (GitHub: `onepowerLS/onestop-asset-shop`)

## Current Status

✅ Source code extracted from InMotion hosting  
✅ Database schema analyzed (`npower5_asset_management.sql`)  
✅ Google Sheets inventory mapped (15+ sources)  
✅ Dropbox data sources assessed (see [`database/DATA_SOURCES_ASSESSMENT.md`](database/DATA_SOURCES_ASSESSMENT.md))  
🔄 Database consolidation in progress  
⏳ Data migration from identified sources pending  
⏳ QR code integration pending  
⏳ Tablet UI development pending  

## Project Structure

```
onestop-asset-shop/
├── database/
│   ├── migrations/          # Database schema migrations
│   └── seeds/              # Initial data seeding
├── backend/                # API/Backend logic
├── frontend/               # Volt Dashboard refactor
├── qr/                     # QR generation & scanning
└── deployment/              # AWS deployment configs
```

## Getting Started

*Coming soon - setup instructions will be added as development progresses.*

## License

Proprietary - OnePower Africa
