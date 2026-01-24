# Testing Guide

## Testing Strategy

OneStop Asset Shop uses a multi-level testing approach:

1. **Unit Tests** - Individual functions/components
2. **Integration Tests** - Database and API interactions
3. **Manual Testing** - User workflows and UI
4. **Production Testing** - Pre-deployment verification

## Automated Tests

### Running Tests Locally

```bash
# PHP syntax check
find . -name "*.php" -exec php -l {} \;

# Database connectivity test
php -r "require 'web/config/database.php'; echo 'Connected!';"

# Run GitHub Actions tests locally (using act)
act -j test
```

### CI/CD Tests (GitHub Actions)

Tests run automatically on:
- Push to `develop` or `main`
- Pull requests
- Manual workflow dispatch

**Test Suite Includes**:
- PHP syntax validation
- Database schema validation
- Security checks (basic)
- Connectivity tests

## Manual Testing Checklist

### Pre-Deployment Testing

#### Authentication & Authorization
- [ ] Login with valid credentials
- [ ] Login with invalid credentials (error handling)
- [ ] Logout functionality
- [ ] Session timeout
- [ ] Access control (admin vs user)

#### Asset Management
- [ ] View assets list
- [ ] Filter by country (Lesotho, Zambia, Benin)
- [ ] Filter by status
- [ ] Filter by category
- [ ] Search by name/serial/QR code
- [ ] Add new asset
- [ ] Edit existing asset
- [ ] View asset details
- [ ] Delete asset (if implemented)

#### QR Code Functionality
- [ ] Generate QR code for asset
- [ ] Print QR label (Brother PT-P710BT)
- [ ] Scan QR code with Symcode scanner
- [ ] QR code redirects to correct asset page
- [ ] QR code format validation

#### Check-In/Check-Out
- [ ] Check out asset to employee
- [ ] Check in asset
- [ ] View allocation history
- [ ] Bulk checkout (if implemented)

#### Requests
- [ ] Create new request (RET, FAC, O&M, etc.)
- [ ] View request list
- [ ] Filter requests by type
- [ ] Approve/reject requests (if implemented)
- [ ] Fulfill request

#### Inventory
- [ ] View inventory levels by country
- [ ] View inventory by location
- [ ] Stock taking workflow
- [ ] Stock ingestion workflow

#### Multi-Country Features
- [ ] Filter assets by Lesotho
- [ ] Filter assets by Zambia
- [ ] Filter assets by Benin
- [ ] Country-specific reporting
- [ ] Country-specific permissions

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

## Performance Testing

### Load Testing

```bash
# Using Apache Bench
ab -n 1000 -c 10 https://assets.1pwrafrica.com/

# Using curl for response time
time curl https://assets.1pwrafrica.com/health.php
```

### Database Performance

- [ ] Test with 1000+ assets
- [ ] Test with 100+ concurrent users
- [ ] Check query execution time
- [ ] Verify indexes are used

## Security Testing

### Checklist

- [ ] SQL injection prevention (use prepared statements)
- [ ] XSS prevention (escape output)
- [ ] CSRF protection (if forms implemented)
- [ ] Authentication bypass attempts
- [ ] File upload security (if implemented)
- [ ] Sensitive data exposure
- [ ] Password strength requirements
- [ ] Session security

### Tools

- OWASP ZAP (basic scan)
- Manual security review
- Check for hardcoded credentials

## Database Testing

### Schema Validation

```sql
-- Verify all tables exist
SHOW TABLES;

-- Check table structures
DESCRIBE assets;
DESCRIBE countries;
DESCRIBE transactions;

-- Verify foreign keys
SELECT * FROM information_schema.KEY_COLUMN_USAGE 
WHERE TABLE_SCHEMA = 'onestop_asset_shop' 
AND REFERENCED_TABLE_NAME IS NOT NULL;
```

### Data Integrity

- [ ] Foreign key constraints work
- [ ] Unique constraints enforced
- [ ] Required fields validated
- [ ] Data types correct

## API Testing

### Endpoints to Test

```bash
# Health check
curl https://assets.1pwrafrica.com/health.php

# QR generation
curl "https://assets.1pwrafrica.com/api/qr/generate.php?asset_id=1"

# Asset lookup by QR
curl "https://assets.1pwrafrica.com/api/assets/by-qr.php?qr_code=1PWR-LSO-000001"
```

## User Acceptance Testing (UAT)

### Test Scenarios

1. **New Asset Registration**
   - User adds new asset
   - Generates QR code
   - Prints label
   - Scans label to verify

2. **Asset Checkout**
   - User scans QR code
   - Selects employee
   - Completes checkout
   - Verifies in allocation list

3. **Stock Taking**
   - User starts stock take
   - Scans multiple assets
   - Completes stock take
   - Reviews variance report

4. **Multi-Country Workflow**
   - User filters by Lesotho
   - Adds asset in Zambia
   - Views Benin inventory
   - Generates country report

## Production Readiness Checklist

Before deploying to production:

- [ ] All automated tests pass
- [ ] Manual testing completed
- [ ] Performance acceptable
- [ ] Security review completed
- [ ] Database backup created
- [ ] Rollback plan documented
- [ ] Monitoring configured
- [ ] Error logging configured
- [ ] SSL certificate installed
- [ ] Environment variables set
- [ ] Documentation updated

## Bug Reporting

When reporting bugs, include:

1. **Description**: What happened vs what was expected
2. **Steps to Reproduce**: Detailed steps
3. **Environment**: Browser, OS, user role
4. **Screenshots**: If applicable
5. **Error Messages**: Full error text
6. **Logs**: Relevant log entries

## Test Data

### Sample Data for Testing

Create test data for:
- 3 countries (Lesotho, Zambia, Benin)
- 10+ locations per country
- 50+ assets across categories
- 5+ employees
- 20+ transactions
- Various asset statuses

### Test User Accounts

- Admin user (full access)
- Manager user (limited admin)
- Operator user (standard access)
- Viewer user (read-only)

## Continuous Improvement

- Review test coverage regularly
- Add tests for new features
- Update test data as needed
- Refine testing procedures
- Document edge cases
