# Migration Tools - Ready to Use ✅

## Installed Tools

All migration tools are now installed and ready on the EC2 server:

✅ **PHP 8.5** - Installed and working
✅ **MySQL/MariaDB Client** - Installed and working  
✅ **Git** - Installed and working
✅ **PHP Extensions** - xml, mbstring, curl (all installed)
✅ **Unzip** - Installed for file handling

## Migration Scripts

All scripts are deployed and ready:

1. **`migrate_from_sql_dump.php`** - Imports from SQL dump file
2. **`migrate_data.php`** - Imports from CSV files (Google Sheets)
3. **`run_migration.php`** - Main orchestrator (runs both)
4. **`migration_utils.php`** - Shared utility functions

## Quick Start

### Run Complete Migration

```bash
# SSH into server
ssh -i 1pwrAM.pem ec2-user@16.28.64.221

# Run migration
cd /var/www/onestop-asset-shop
php database/run_migration.php
```

This will:
1. ✅ Import from SQL dump (`/tmp/npower5_asset_management.sql`)
2. ✅ Import from CSV files in `database/csv_imports/`
3. ✅ Generate QR codes for all assets
4. ✅ Initialize inventory levels
5. ✅ Show summary statistics

### Import Google Sheets

1. **Export each Google Sheet to CSV**
2. **Upload to server**:
   ```bash
   scp -i 1pwrAM.pem *.csv ec2-user@16.28.64.221:/var/www/onestop-asset-shop/database/csv_imports/
   ```
3. **Run migration**:
   ```bash
   php database/run_migration.php
   ```

## Current Status

- **SQL Dump**: ✅ Uploaded to `/tmp/npower5_asset_management.sql`
- **CSV Directory**: ✅ Created at `database/csv_imports/`
- **Migration Scripts**: ✅ Deployed and tested
- **Duplicate Detection**: ✅ Working (skips duplicates automatically)

## What Gets Imported

✅ Assets (with duplicate detection)
✅ Locations (auto-created from location strings)
✅ Categories (auto-created if needed)
✅ QR Codes (auto-generated: `1PWR-{COUNTRY}-{ID}`)
✅ Inventory Levels (initialized automatically)

## Duplicate Detection

The system automatically skips duplicates based on:
- **Serial Number** (exact match)
- **Asset Tag** (exact match)  
- **Name + Manufacturer + Model** (exact match)

## Logs

All migration activity is logged to:
- `database/migration_log.txt` - Detailed log file

## Verification

After migration, verify:

```bash
# Check total assets
mysql -u asset_user -p onestop_asset_shop -e "SELECT COUNT(*) FROM assets;"

# Check by country
mysql -u asset_user -p onestop_asset_shop -e "
SELECT c.country_name, COUNT(*) as count
FROM assets a
JOIN countries c ON a.country_id = c.country_id
GROUP BY c.country_name;
"

# Check for duplicates (should return 0)
mysql -u asset_user -p onestop_asset_shop -e "
SELECT serial_number, COUNT(*) as count
FROM assets
WHERE serial_number IS NOT NULL AND serial_number != ''
GROUP BY serial_number
HAVING count > 1;
"
```

## Next Steps

1. **Export Google Sheets to CSV** (if not done)
2. **Upload CSV files** to `database/csv_imports/`
3. **Run migration**: `php database/run_migration.php`
4. **Verify results** using SQL queries above
5. **Review migration log** for any issues

---

**All tools are ready!** Just export your Google Sheets to CSV and upload them, then run the migration script.
