# Google Sheets Access - Options

## Current Status

✅ **SQL Dump**: Found **1615 records** - All will be imported!

## Google Sheets Access Options

I **cannot directly access Google Sheets** without credentials, but here are your options:

### Option 1: Manual CSV Export (Easiest - Recommended)

**Steps:**
1. Open each Google Sheet from the list
2. **File → Download → Comma-separated values (.csv)**
3. Save with descriptive names
4. Upload to server:
   ```bash
   scp -i 1pwrAM.pem *.csv ec2-user@16.28.64.221:/var/www/onestop-asset-shop/database/csv_imports/
   ```
5. Run migration:
   ```bash
   php database/run_migration.php
   ```

**Sheets to Export:**
- RET Material Items Database
- FAC Material Items Database  
- O&M Material Database
- Meters_Meter Enclosures_Ready Boards Database
- General Materials and Items Database
- Engineering Tool List
- Powerhouse parts list_Lichaba
- (Any others from the PDF)

### Option 2: Google Sheets API (Automated)

I can set up automated access, but you'll need to:

1. **Create Google Cloud Project**
   - Go to: https://console.cloud.google.com/
   - Create new project (or use existing)

2. **Enable Google Sheets API**
   - APIs & Services → Enable APIs
   - Search for "Google Sheets API"
   - Click "Enable"

3. **Create Service Account**
   - APIs & Services → Credentials
   - Create Credentials → Service Account
   - Name it (e.g., "asset-migration")
   - Create and download JSON key

4. **Share Sheets with Service Account**
   - Open each Google Sheet
   - Click "Share" button
   - Add the service account email (from JSON file, looks like: `xxx@xxx.iam.gserviceaccount.com`)
   - Give "Viewer" access

5. **Upload Credentials**
   ```bash
   scp -i 1pwrAM.pem google-credentials.json ec2-user@16.28.64.221:/var/www/onestop-asset-shop/database/
   ```

6. **Configure Sheet IDs**
   - Edit `database/import_from_google_sheets.php`
   - Add your Google Sheet IDs to the `$google_sheets_config` array
   - Sheet ID is in the URL: `https://docs.google.com/spreadsheets/d/{SHEET_ID}/edit`

7. **Install Dependencies & Run**
   ```bash
   cd /var/www/onestop-asset-shop
   composer require google/apiclient
   php database/import_from_google_sheets.php
   ```

### Option 3: I Can Help You Export

If you want, I can guide you through:
- Setting up Google API access step-by-step
- Or help you export all sheets quickly

## Recommendation

**For now**: Use **Option 1 (Manual CSV Export)** - it's fastest and most reliable.

**Later**: Set up Google API for ongoing automation if needed.

## Next Steps

1. ✅ **SQL Dump**: 1615 records found - migration running
2. ⏳ **Google Sheets**: Export to CSV and upload
3. ✅ **Run migration**: `php database/run_migration.php`

The SQL dump has all 1600+ records, so once that's done, you'll have most of your data. The Google Sheets will add any additional items not in the database.
