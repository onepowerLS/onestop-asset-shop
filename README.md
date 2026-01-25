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

- **Backend**: PHP 8.5 (Custom)
- **Frontend**: Volt Dashboard (Bootstrap 5)
- **Database**: MariaDB 10.5
- **Web Server**: Apache 2.4
- **Hosting**: AWS EC2 (Amazon Linux 2023)
- **SSL**: Let's Encrypt (Certbot)
- **Version Control**: Git (GitHub: `onepowerLS/onestop-asset-shop`)

## Current Status

**Last Updated:** January 25, 2026  
**Phase:** Data Migration & Quality Enhancement

### ✅ Completed
- ✅ AWS EC2 hosting configured (Amazon Linux 2023)
- ✅ Application deployed and accessible at https://am.1pwrafrica.com
- ✅ Database schema implemented (MariaDB)
- ✅ **1,609 assets imported** from SQL dump
- ✅ All application pages functional (view, add, edit assets)
- ✅ User authentication and admin access
- ✅ QR code generation system
- ✅ Multi-country support (Lesotho, Zambia, Benin)
- ✅ Bug fixes (404 errors, jQuery issues, path duplication)

### ⏳ Current Step: Data Quality Enhancement
- ⏳ **Import from Access Database** - Waiting for .accdb file or CSV export
- ⏳ **Import from Google Sheets** - Ready (CSV or API)
- ⏳ Complete data population (manufacturer, model, prices, etc.)

**See [PROJECT_STATUS.md](PROJECT_STATUS.md) for detailed status.**  

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

### Access the Application
- **URL**: https://am.1pwrafrica.com
- **Admin Login**: 
  - Username: `mso`
  - Password: `Welcome123!` (⚠️ Change after first login)

### Documentation
- **[PROJECT_STATUS.md](PROJECT_STATUS.md)** - Complete project status and current step
- **[TESTING_GUIDE.md](TESTING_GUIDE.md)** - Testing procedures
- **[database/MIGRATION_INSTRUCTIONS.md](database/MIGRATION_INSTRUCTIONS.md)** - Data migration guide
- **[database/ACCESS_DATABASE_IMPORT.md](database/ACCESS_DATABASE_IMPORT.md)** - Access database import
- **[database/GOOGLE_SHEETS_API_SETUP.md](database/GOOGLE_SHEETS_API_SETUP.md)** - Google Sheets setup

### Quick Links
- **GitHub Repository**: https://github.com/onepowerLS/onestop-asset-shop
- **Deployment**: Auto-deploy via GitHub Actions
- **Server**: AWS EC2 (16.28.64.221)

## License

Proprietary - OnePower Africa
