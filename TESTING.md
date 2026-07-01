# Testing Guide

## Testing Strategy

OneStop Asset Shop uses a multi-level testing approach:

1. **PHP Syntax Validation** - All `.php` files must parse without errors
2. **Firestore Connectivity** - Firebase Auth + Firestore REST API must be reachable
3. **Manual Testing** - User workflows across all 16 pages
4. **Production Smoke Test** - Post-deployment verification

## Automated Tests

### Running Tests Locally

```bash
# PHP syntax check (all files)
find web/ -name "*.php" -exec php -l {} \;

# Health check (requires running web server)
curl http://localhost/health.php
```

### CI/CD Tests (GitHub Actions)

Tests run automatically on push to `develop` or `main`:
- PHP syntax validation across all files
- Health check endpoint verification

## Manual Testing Checklist

### Pre-Deployment Testing

#### Authentication & Authorization
- [ ] Login with Firebase email/password
- [ ] Login with username (MySQL lookup -> Firebase)
- [ ] Login with invalid credentials shows friendly error
- [ ] Logout clears session and redirects to login
- [ ] Session `firebase_id_token` is present after login
- [ ] Admin-only pages (categories, locations, employees, QR labels) blocked for non-Admin
- [ ] Role mapping: PR Admin -> AM Admin, PR Approver -> AM Manager, PR Requester -> AM Viewer

#### Item Catalog (web/assets/)
- [ ] **Listing** -- all items load from `am_core_assets`
- [ ] Filter by item_class (FixedAsset, Material, Consumable, Inventory)
- [ ] Filter by category, country, status
- [ ] Search by name, serial number, QR code, asset tag
- [ ] Class column shows correct color-coded badge
- [ ] Sidebar Catalog menu links filter correctly
- [ ] **Add** -- classification radio buttons toggle field sections
  - [ ] FixedAsset shows serial, manufacturer, model, purchase price, salvage, warranty
  - [ ] Material/Consumable/Inventory shows quantity, unit of measure, unit cost
  - [ ] Category dropdown filters by selected item_class
  - [ ] Asset tag auto-generated on save (1PWR-{CLASS}-{COUNTRY}-{PADDED})
  - [ ] Saved item appears in listing
- [ ] **View** -- detail page loads by doc ID and by QR code
  - [ ] All fields displayed correctly
  - [ ] Allocation history shows active allocations
  - [ ] Transaction history shows item-specific transactions
  - [ ] Breadcrumb navigation works
- [ ] **Edit** -- form pre-populated with existing data
  - [ ] Classification change updates visible fields
  - [ ] Status dropdown includes all 9 statuses
  - [ ] Save updates Firestore document
  - [ ] Flash message confirms update

#### Dashboard (web/index.php)
- [ ] Total item count correct
- [ ] Item class breakdown cards show correct counts
- [ ] Class cards link to filtered catalog view
- [ ] Assets by Country table accurate
- [ ] Assets by Status table accurate
- [ ] Recent Transactions list shows latest 10

#### Admin Pages (web/admin/)
- [ ] **Categories** -- grouped by item_class with correct counts
  - [ ] Create new category with all fields
  - [ ] Edit existing category
  - [ ] Delete category
  - [ ] Depreciation fields (useful life, method) saved correctly
  - [ ] Reorder toggle works
- [ ] **Locations** -- grouped by country
  - [ ] Create with code, name, type, country, parent
  - [ ] Edit and delete
  - [ ] Hierarchical parent selection dropdown
- [ ] **Employees** -- directory loads
  - [ ] Search by name/email/phone
  - [ ] Filter by country
  - [ ] DataTables pagination and sorting

#### Stock Levels (web/inventory/)
- [ ] Displays Material, Consumable, Inventory items
- [ ] On-hand, allocated, available columns correct
- [ ] Reorder alert banner when items at/below threshold
- [ ] Low-stock filter checkbox
- [ ] Filter by item_class and country

#### Transactions (web/transactions/)
- [ ] Full history loads sorted by date descending
- [ ] Filter by transaction type
- [ ] Search by asset name/tag/notes
- [ ] Color-coded type badges

#### Check-Out/In (web/checkout/)
- [ ] Check-out form: select item, employee, expected return, notes
  - [ ] Creates allocation record in `am_core_allocations`
  - [ ] Creates transaction record in `am_core_transactions`
  - [ ] Updates asset status to `CheckedOut`
- [ ] Check-in form: select active allocation, return location
  - [ ] Updates allocation status to `Returned`
  - [ ] Creates CheckIn transaction
  - [ ] Restores asset status to `Available`
- [ ] Active allocations table accurate

#### Requests (web/requests/)
- [ ] Status summary cards show correct counts
- [ ] New request form: item_class, department, country, priority, description
  - [ ] Request number auto-generated (REQ-YYYY-NNNN)
  - [ ] Status set to `Submitted`
- [ ] Admin approve/reject buttons work
- [ ] Fulfilled status records fulfilled_date

#### Inventory dispatch (`dispatch-new.php`, `api/dispatch/search-items.php`)
- [ ] **Request country** drives catalog search: hint under search box shows the same country label as the dropdown
- [ ] Changing **Request country** updates the hint and search API `country_id` (destination sites still filter correctly)
- [ ] Search returns items that match main catalog country rules (including assets where country is inferred from location when `country_id` is empty)
- [ ] Search finds items by manufacturer, model, notes, category name, location name/code (not only name/tag/description)
- [ ] Known LSO (or other) warehouse item appears when Request country matches that country

#### QR Labels (web/admin/qr-labels.php)
- [ ] Coverage stats (assigned, pending, percentage)
- [ ] Items without QR listed with Generate button
- [ ] Single QR generation via API works
- [ ] Batch generation selects all and generates
- [ ] QR code format: `1PWR-{COUNTRY}-{CLASS}-{PADDED}`
- [ ] QR preview images render

#### QR Scanner
- [ ] Hidden input captures HID scanner output
- [ ] Scanning redirects to `assets/view.php?qr=` with correct code
- [ ] Asset found by QR code displays correctly

#### Multi-Country
- [ ] All filters respect country selection
- [ ] Items created with correct country_id
- [ ] Location dropdown shows locations for all countries

### Browser Testing

Test on:
- [ ] Chrome (latest)
- [ ] Firefox (latest)
- [ ] Safari (latest)
- [ ] Edge (latest)
- [ ] Mobile browsers (iOS Safari, Chrome Mobile)

### Device Testing

- [ ] Desktop (1920x1080)
- [ ] Laptop (1366x768)
- [ ] Tablet (768x1024) - for future tablet features
- [ ] Mobile (375x667)

## Performance Considerations

All data lives in Firestore via REST API. Every page-load fetches entire collections client-side. Expected performance characteristics:

- **< 500 items** -- fast, no issues
- **500-2000 items** -- noticeable latency on listing pages
- **> 2000 items** -- pagination or server-side filtering via Firestore structured queries needed

### Quick Smoke Test

```bash
# Health check
curl https://assets.1pwrafrica.com/health.php

# QR generation (requires auth session -- test via browser)
# /api/qr/generate.php?asset_id=DOC_ID&country_code=LSO
```

## Security Testing

### Checklist

- [ ] All Firestore calls include valid `firebase_id_token` in Authorization header
- [ ] `firebase_id_token` refreshed/validated on each request
- [ ] XSS prevention: all user input HTML-escaped with `htmlspecialchars()`
- [ ] CSRF: forms use POST + session validation
- [ ] No hardcoded Firebase API keys beyond the public web API key (which is safe to expose)
- [ ] `.env` file excluded from git (check `.gitignore`)
- [ ] Admin pages check `$_SESSION['am_role'] === 'Admin'` before rendering
- [ ] No raw Firestore error details exposed to end users

## User Acceptance Testing (UAT)

### End-to-End Scenarios

**Scenario 1: Register a Fixed Asset**
1. Navigate to Catalog > Add New Item
2. Select classification: Fixed Asset
3. Fill serial number, manufacturer, model, purchase price, warranty
4. Save -- verify asset_tag generated
5. Navigate to Admin > QR Labels
6. Find the new item, generate QR code
7. Verify QR code image renders
8. Click asset link -- verify view page loads

**Scenario 2: Register Consumables (Bulk)**
1. Add New Item, select Consumable
2. Enter name, quantity = 100, UOM = EA, unit cost
3. Save
4. Navigate to Stock Levels -- verify item appears with qty 100

**Scenario 3: Check Out and Return**
1. Navigate to Check-Out/In
2. Select an Available item and an employee
3. Set expected return date, submit
4. Verify item status changes to `CheckedOut`
5. Verify allocation appears in Active Allocations
6. Select the allocation, set return location, check in
7. Verify item status reverts to `Available`
8. Verify Transaction History shows both CheckOut and CheckIn

**Scenario 4: Submit and Fulfill a Request**
1. Navigate to Requests > New Request
2. Select item_class = Material, department = O&M, priority = High
3. Submit
4. As Admin, approve the request
5. Fulfill the request
6. Verify fulfilled_date is set

**Scenario 5: Multi-Country Operation**
1. Switch country filter to Zambia on dashboard
2. Add item in Zambia
3. Switch to Lesotho -- item should not appear
4. Switch to All Countries -- item appears

## Production Readiness Checklist

Before deploying to production:

- [ ] `php -l` passes on all PHP files
- [ ] `.env` configured with correct Firebase project ID and API key
- [ ] Firebase Auth: email/password sign-in enabled in Firebase Console
- [ ] Firestore: `pr_master_countries` seeded with LSO, ZMB, BEN
- [ ] Firestore: `pr_master_categories` seeded (see `database/schema-consolidated.sql` for seed data)
- [ ] Firestore: at least one user in `users` collection with Admin role
- [ ] SSL certificate installed and valid
- [ ] Apache DocumentRoot points to `/var/www/onestop-asset-shop/web`
- [ ] Apache `AllowOverride All` for URL rewriting
- [ ] Health check returns `{"status":"healthy"}`
- [ ] Manual walkthrough of all 5 UAT scenarios above
- [ ] Rollback plan: `git checkout <previous-tag>` documented

## Test Data Seeding

Minimum data required to exercise all features:

| Collection | Minimum Records | Key Fields |
|---|---|---|
| `pr_master_countries` | 3 | LSO, ZMB, BEN |
| `pr_master_locations` | 6 | 2 per country |
| `pr_master_categories` | 8 | 2 per item_class |
| `am_core_assets` | 12 | 3 per item_class |
| `users` | 3 | 1 Admin, 1 Manager, 1 Viewer |

## Bug Reporting

When reporting bugs, include:

1. **Page URL and action** -- which page, what button/form
2. **Expected vs actual result**
3. **Browser console errors** (F12 > Console)
4. **PHP error log** -- `sudo tail -50 /var/log/apache2/error.log`
5. **Network tab** -- screenshot of failed Firestore API call if applicable
6. **User role** -- Admin, Manager, or Viewer
