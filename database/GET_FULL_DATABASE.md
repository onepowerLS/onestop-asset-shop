# Getting the Full Database (1600 Records)

## Issue

The current SQL dump (`npower5_asset_management.sql`) only contains **7 INSERT statements**, which is a sample, not the full 1600 records from the production database.

## Solution Options

### Option 1: Export Fresh Database Dump (Recommended)

**From cPanel:**
1. Log into cPanel for `1pwrafrica.com`
2. Go to **phpMyAdmin**
3. Select database: `npower5_asset_management` (or similar)
4. Click **Export** tab
5. Select **Custom** export method
6. Choose **SQL** format
7. Select table: `assets` (or all tables)
8. Click **Go** to download
9. Upload to server:
   ```bash
   scp -i 1pwrAM.pem npower5_asset_management_FULL.sql ec2-user@16.28.64.221:/tmp/
   ```

**From Command Line (if you have SSH access to old server):**
```bash
mysqldump -u username -p npower5_asset_management > npower5_asset_management_FULL.sql
```

### Option 2: Connect to Live Database Directly

If the old database is still accessible, we can import directly:

1. **Get database credentials** from cPanel or old server
2. **Update `.env` file** on EC2:
   ```bash
   ssh -i 1pwrAM.pem ec2-user@16.28.64.221
   cd /var/www/onestop-asset-shop
   nano .env
   ```
   
   Add:
   ```
   OLD_DB_HOST=old-server-hostname-or-ip
   OLD_DB_NAME=npower5_asset_management
   OLD_DB_USER=old_database_user
   OLD_DB_PASS=old_database_password
   ```

3. **Run direct import script**:
   ```bash
   php database/export_from_live_db.php
   ```

### Option 3: Access via WordPress Database

If the old system is WordPress-based and still running:

1. **Get database credentials** from `wp-config.php`
2. **Use Option 2** above to connect directly

## Verify Record Count

After getting the full database, verify:

```bash
# Check SQL dump
grep -c "INSERT INTO.*assets" npower5_asset_management_FULL.sql

# Or check live database
mysql -u old_user -p old_database -e "SELECT COUNT(*) FROM assets;"
```

## Google Sheets Access

### Option A: Manual Export (Easiest)

1. Open each Google Sheet
2. **File → Download → CSV**
3. Upload to server:
   ```bash
   scp -i 1pwrAM.pem *.csv ec2-user@16.28.64.221:/var/www/onestop-asset-shop/database/csv_imports/
   ```

### Option B: Google Sheets API (Automated)

I can create a script that accesses Google Sheets directly, but you'll need to:

1. **Set up Google Cloud Project**
2. **Enable Google Sheets API**
3. **Create Service Account**
4. **Download credentials JSON**
5. **Share Google Sheets** with service account email

Then run:
```bash
php database/import_from_google_sheets.php
```

## Next Steps

**Immediate Action Needed:**
1. ✅ Get fresh database dump with all 1600 records
2. ⏳ Export Google Sheets to CSV (or set up API access)
3. ✅ Run migration script

**Which option do you prefer?**
- **A**: Get fresh SQL dump from cPanel/phpMyAdmin (easiest)
- **B**: Connect directly to live database (if accessible)
- **C**: I'll set up Google Sheets API access (more automated)

Let me know and I'll guide you through the chosen option!
