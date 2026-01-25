# Quick Start: Data Migration

## Quick Migration Steps

### 1. Upload SQL Dump to Server

```bash
# From your local machine
scp -i 1pwrAM.pem C:\Users\it\Downloads\npower5_asset_management.sql ec2-user@16.28.64.221:/tmp/
```

### 2. Update Migration Script Path

Edit the migration script to point to the SQL file:

```bash
# SSH into server
ssh -i 1pwrAM.pem ec2-user@16.28.64.221

# Edit the script
cd /var/www/onestop-asset-shop/database
nano migrate_from_sql_dump.php

# Change this line:
$sql_dump_file = __DIR__ . '/../../../../Downloads/npower5_asset_management.sql';
# To:
$sql_dump_file = '/tmp/npower5_asset_management.sql';
```

### 3. Run Migration

```bash
cd /var/www/onestop-asset-shop
php database/migrate_from_sql_dump.php
```

### 4. Check Results

```bash
# View migration log
cat database/migration_log.txt

# Check database
mysql -u asset_user -p onestop_asset_shop
```

Then run:
```sql
SELECT COUNT(*) FROM assets;
SELECT c.country_name, COUNT(*) FROM assets a JOIN countries c ON a.country_id = c.country_id GROUP BY c.country_name;
```

---

## For Google Sheets CSV Imports

1. Export each Google Sheet to CSV
2. Upload to server:
   ```bash
   scp -i 1pwrAM.pem *.csv ec2-user@16.28.64.221:/var/www/onestop-asset-shop/database/csv_imports/
   ```
3. Run CSV import:
   ```bash
   php database/migrate_data.php
   ```

---

## What Gets Imported

✅ **Assets** from old database
✅ **Locations** (auto-created from location strings)
✅ **Categories** (if present)
✅ **QR Codes** (auto-generated)
✅ **Inventory Levels** (initialized)

❌ **Duplicates** are automatically skipped based on:
- Serial numbers
- Asset tags
- Name + Manufacturer + Model

---

## Need Help?

See `MIGRATION_INSTRUCTIONS.md` for detailed guide.
