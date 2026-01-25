# Data Migration Instructions

## Overview

This guide will help you consolidate and import all records from:
- **Old Database**: `npower5_asset_management.sql`
- **Google Sheets**: RET Materials, FAC Items, O&M Database, Meters, Ready Boards, etc.

The migration scripts will **automatically detect and skip duplicates** based on:
- Serial numbers
- Asset tags
- Name + Manufacturer + Model combinations

---

## Prerequisites

1. **SQL Dump File**: `npower5_asset_management.sql` should be in your Downloads folder
2. **Google Sheets CSV Exports**: Export each Google Sheet to CSV format
3. **Database Access**: Ensure you can connect to the new database

---

## Step 1: Export Google Sheets to CSV

For each Google Sheet mentioned in the PDF:

1. Open the Google Sheet
2. Go to **File → Download → Comma-separated values (.csv)**
3. Save with a descriptive filename:
   - `RET_Materials.csv`
   - `FAC_Items.csv`
   - `OM_Database.csv`
   - `Meters.csv`
   - `Ready_Boards.csv`
   - etc.

4. Place all CSV files in: `database/csv_imports/` directory

---

## Step 2: Run Migration Script

### Option A: From Command Line (Recommended)

```bash
# SSH into your EC2 instance
ssh -i 1pwrAM.pem ec2-user@16.28.64.221

# Navigate to project directory
cd /var/www/onestop-asset-shop

# Run migration script
php database/migrate_from_sql_dump.php
```

### Option B: Via Web Interface

1. Upload the migration script to your server
2. Access via browser: `https://am.1pwrafrica.com/database/migrate_from_sql_dump.php`
3. Review the log output

### Option C: Import SQL Dump Directly

If the PHP script has issues, you can import the SQL dump directly:

```bash
# On EC2 server
mysql -u asset_user -p onestop_asset_shop < /path/to/npower5_asset_management.sql

# Then run a SQL migration script to map old schema to new schema
```

---

## Step 3: Import Google Sheets (CSV)

After importing from SQL dump, import CSV files:

```bash
# Create CSV imports directory
mkdir -p /var/www/onestop-asset-shop/database/csv_imports

# Upload CSV files (via SCP or SFTP)
scp -i 1pwrAM.pem *.csv ec2-user@16.28.64.221:/var/www/onestop-asset-shop/database/csv_imports/

# Run CSV import script
php database/migrate_data.php
```

---

## Step 4: Verify Migration

After migration, verify the data:

```sql
-- Check total assets imported
SELECT COUNT(*) as total_assets FROM assets;

-- Check assets by country
SELECT c.country_name, COUNT(*) as count
FROM assets a
JOIN countries c ON a.country_id = c.country_id
GROUP BY c.country_name;

-- Check for duplicates (should return 0)
SELECT serial_number, COUNT(*) as count
FROM assets
WHERE serial_number IS NOT NULL AND serial_number != ''
GROUP BY serial_number
HAVING count > 1;

-- Check QR codes generated
SELECT 
    COUNT(*) as total,
    COUNT(qr_code_id) as with_qr,
    COUNT(*) - COUNT(qr_code_id) as missing_qr
FROM assets;

-- Check inventory levels
SELECT COUNT(*) as inventory_records FROM inventory_levels;
```

---

## Duplicate Detection

The migration script checks for duplicates using:

1. **Serial Number**: If two assets have the same serial number, the second one is skipped
2. **Asset Tag**: If two assets have the same tag (NewTagNumber or OldTagNumber), the second one is skipped
3. **Name + Manufacturer + Model**: If all three match exactly, it's considered a duplicate

**Note**: If you want to import duplicates anyway (e.g., same item in different locations), you can modify the `is_duplicate()` function in the migration script.

---

## Troubleshooting

### Issue: "SQL dump file not found"

**Solution**: 
- Ensure `npower5_asset_management.sql` is in your Downloads folder
- Or update the path in `migrate_from_sql_dump.php`:
  ```php
  $sql_dump_file = '/path/to/your/npower5_asset_management.sql';
  ```

### Issue: "Cannot connect to database"

**Solution**:
- Check `.env` file has correct database credentials
- Verify database user has INSERT permissions
- Test connection: `mysql -u asset_user -p onestop_asset_shop`

### Issue: "Foreign key constraint fails"

**Solution**:
- Ensure countries are created first (should be in schema)
- Ensure locations exist before importing assets
- Check that category_id references exist

### Issue: "CSV import not working"

**Solution**:
- Verify CSV files are in UTF-8 encoding
- Check CSV has header row
- Ensure column names match expected format
- Review `migration_log.txt` for specific errors

---

## Manual Data Review

After migration, review the data:

1. **Check for missing data**:
   ```sql
   SELECT * FROM assets WHERE name IS NULL OR name = '';
   ```

2. **Check location mapping**:
   ```sql
   SELECT location, COUNT(*) 
   FROM (SELECT location FROM old_assets) AS old
   GROUP BY location;
   ```

3. **Review duplicate skips**:
   - Check `migration_log.txt` for "SKIPPED (duplicate)" entries
   - Verify these are actually duplicates or need manual review

---

## Post-Migration Tasks

1. **Generate QR Codes**: All assets should have QR codes, but verify:
   ```sql
   UPDATE assets 
   SET qr_code_id = CONCAT('1PWR-', 
       (SELECT country_code FROM countries WHERE country_id = assets.country_id),
       '-', LPAD(asset_id, 6, '0'))
   WHERE qr_code_id IS NULL;
   ```

2. **Initialize Inventory**: Should be done automatically, but verify:
   ```sql
   SELECT COUNT(*) FROM inventory_levels;
   ```

3. **Review and Clean**: 
   - Check for data quality issues
   - Update missing information
   - Merge duplicate locations if needed

---

## Rollback

If migration has issues:

1. **Backup current database**:
   ```bash
   mysqldump -u asset_user -p onestop_asset_shop > backup_before_migration.sql
   ```

2. **Restore if needed**:
   ```bash
   mysql -u asset_user -p onestop_asset_shop < backup_before_migration.sql
   ```

3. **Or start fresh**:
   ```sql
   TRUNCATE TABLE assets;
   TRUNCATE TABLE inventory_levels;
   -- Then re-run migration
   ```

---

## Next Steps

After successful migration:

1. ✅ Verify all assets imported
2. ✅ Check for duplicates (should be none)
3. ✅ Generate QR codes for physical assets
4. ✅ Print QR labels using Brother PT-P710BT
5. ✅ Test QR scanning functionality
6. ✅ Train users on new system

---

## Support

If you encounter issues:
1. Check `database/migration_log.txt` for detailed error messages
2. Review database error logs
3. Verify all prerequisites are met
4. Contact support if needed
