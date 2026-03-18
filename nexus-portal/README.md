# 1PWR Nexus Portal

Unified authentication and lobby portal for all 1PWR Africa tools and systems.

## Overview

Nexus provides a single sign-on (SSO) entry point for 1PWR employees to access all company tools:

- **HR Portal** - Human Resources & Employee Management
- **PR System** - Purchase Requests & Procurement
- **Job Cards** - Production Job Card Management
- **Asset Management** - Asset Tracking & Inventory
- **O&M Portal** - Operations & Maintenance
- **uGridPlan** - Grid Planning & Design

## Features

- Single Firebase Authentication for all systems
- Unified user profile management (`nexus_users` collection)
- Role-based access control per system
- SSO token flow for seamless navigation
- Admin panel for user management

## Tech Stack

- **Frontend**: React 18 + TypeScript + Vite
- **Styling**: Tailwind CSS
- **Auth**: Firebase Authentication
- **Database**: Cloud Firestore
- **Hosting**: Firebase Hosting

## Quick Start

### Prerequisites

- Node.js 18+
- npm or yarn
- Firebase CLI (`npm install -g firebase-tools`)

### Development

```bash
# Install dependencies
cd nexus-portal
npm install

# Start development server
npm run dev

# Open http://localhost:3000
```

### Build & Deploy

```bash
# Build for production
npm run build

# Deploy to Firebase
firebase login
firebase deploy --only hosting
```

## Project Structure

```
nexus-portal/
├── src/
│   ├── components/       # Reusable UI components
│   ├── config/          # Firebase configuration
│   ├── contexts/        # React contexts (Auth)
│   ├── lib/             # Utility functions (SSO)
│   ├── pages/           # Page components
│   └── types/           # TypeScript types
├── scripts/             # User reconciliation scripts
├── integrations/        # SSO integration code for other systems
│   ├── hr-portal/       # Laravel middleware
│   └── om-portal/       # Express routes
├── docs/                # Documentation
└── public/              # Static assets
```

## Configuration

Copy `.env.example` to `.env` and update values:

```env
VITE_FIREBASE_API_KEY=your_api_key
VITE_FIREBASE_AUTH_DOMAIN=pr-system-4ea55.firebaseapp.com
VITE_FIREBASE_PROJECT_ID=pr-system-4ea55
```

## User Reconciliation

Before deploying Nexus, reconcile users from existing systems:

```bash
cd scripts

# Export PR System users (requires Firebase service account)
export GOOGLE_APPLICATION_CREDENTIALS=/path/to/service-account.json
npm install
npm run export:pr

# Export HR Portal users (run SQL on HR database)
# See export-hr-users.sql

# Reconcile and merge
npm run reconcile

# Review reconciliation-report.md for conflicts

# Import to Firestore (dry run first)
npm run import:dry-run
npm run import:live
```

## SSO Integration

See [docs/SSO_INTEGRATION.md](docs/SSO_INTEGRATION.md) for detailed integration instructions for each system.

### Quick Reference

| System | Integration Type | Files |
|--------|-----------------|-------|
| PR System | React + Firebase (native) | Already uses same Firebase project |
| Job Cards | HTML + Firebase (native) | Already uses same Firebase project |
| HR Portal | Laravel Middleware | `integrations/hr-portal/` |
| O&M Portal | Express + Firebase Admin | `integrations/om-portal/` |
| Asset Mgmt | PHP + Firebase | Already integrated |

## Firestore Schema

See [SCHEMA.md](SCHEMA.md) for the `nexus_users` collection schema.

## Security

- Firestore rules in `firestore.rules`
- SSO tokens expire after 5 minutes
- Firebase ID tokens validated server-side
- Role-based access enforced at both Nexus and individual system level

## Deployment Domains

| Domain | Purpose |
|--------|---------|
| hub.1pwrafrica.com | Nexus Portal (production) |
| nexus.1pwrafrica.com | HR Portal (current) |
| pr.1pwrafrica.com | PR System |
| prod.1pwrafrica.com | Job Cards |
| om.1pwrafrica.com | O&M Portal |

## License

Proprietary - OnePower Africa
