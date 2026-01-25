# Quick Start: Google Sheets API Setup

## 🚀 Fast Track (5 Steps)

### Step 1: Create Google Cloud Project & Service Account (5 minutes)

1. Go to: https://console.cloud.google.com/
2. **Create Project**: Click project dropdown → "New Project" → Name: `1PWR Asset Management`
3. **Enable API**: APIs & Services → Library → Search "Google Sheets API" → Enable
4. **Create Service Account**: 
   - APIs & Services → Credentials → "+ CREATE CREDENTIALS" → Service Account
   - Name: `asset-migration-service`
   - Click through (skip optional steps)
5. **Download Key**: 
   - Click on the service account email
   - Keys tab → "ADD KEY" → "Create new key" → JSON → Download
   - Save as: `google-credentials.json`

### Step 2: Share Google Sheets (2 minutes)

For **each** Google Sheet:
1. Open the sheet
2. Click **"Share"** button
3. Paste the service account email (from JSON file, looks like: `xxx@xxx.iam.gserviceaccount.com`)
4. Set permission: **"Viewer"**
5. Click **"Share"**

**Sheets to share:**
- RET Material Items Database
- FAC Material Items Database
- O&M Material Database
- Meters_Meter Enclosures_Ready Boards Database
- General Materials and Items Database
- Engineering Tool List
- Powerhouse parts list_Lichaba
- (Any others from your list)

### Step 3: Get Sheet IDs (1 minute)

For each Google Sheet, get the ID from the URL:

**URL Format:**
```
https://docs.google.com/spreadsheets/d/{SHEET_ID}/edit
```

**Example:**
- URL: `https://docs.google.com/spreadsheets/d/1ABC123XYZ456DEF789/edit`
- Sheet ID: `1ABC123XYZ456DEF789`

Write them down:
- RET Materials: `_____________`
- FAC Items: `_____________`
- O&M Database: `_____________`
- Meters: `_____________`
- etc.

### Step 4: Upload Credentials & Configure (2 minutes)

**Upload credentials:**
```bash
scp -i 1pwrAM.pem google-credentials.json ec2-user@16.28.64.221:/var/www/onestop-asset-shop/database/
```

**Configure Sheet IDs:**

**Option A: Manual Edit**
```bash
ssh -i 1pwrAM.pem ec2-user@16.28.64.221
cd /var/www/onestop-asset-shop/database
nano import_from_google_sheets.php
```

Find this section and add your Sheet IDs:
```php
'spreadsheet_ids' => [
    'RET_Materials' => 'YOUR_SHEET_ID_HERE',
    'FAC_Items' => 'YOUR_SHEET_ID_HERE',
    'OM_Database' => 'YOUR_SHEET_ID_HERE',
    // ... add more
]
```

**Option B: Use Helper Script** (if you have local PHP)
```bash
php database/configure_sheets.php
# Enter: SheetName|SheetID (one per line)
```

### Step 5: Install & Run (1 minute)

```bash
ssh -i 1pwrAM.pem ec2-user@16.28.64.221
cd /var/www/onestop-asset-shop

# Install Google API Client
composer require google/apiclient

# Run import
php database/import_from_google_sheets.php
```

## ✅ Done!

The script will:
- ✅ Connect to all your Google Sheets
- ✅ Import all assets
- ✅ Skip duplicates (already imported from SQL dump)
- ✅ Create categories and locations automatically
- ✅ Generate QR codes for new assets

## 📊 Check Results

```bash
mysql -u asset_user -pChangeThisPassword123! onestop_asset_shop -e "SELECT COUNT(*) as total FROM assets;"
```

## 🆘 Troubleshooting

**"Permission denied"**
- Make sure you shared each sheet with the service account email
- Check the email matches exactly (copy-paste from JSON)

**"API not enabled"**
- Go back to Step 1 and verify Google Sheets API is enabled

**"Sheet not found"**
- Double-check the Sheet ID in the URL
- Make sure the sheet is shared with the service account

**"Composer not found"**
- Install Composer: `curl -sS https://getcomposer.org/installer | php`
- Or use: `php composer.phar require google/apiclient`

## 📝 Need Help?

See detailed guide: `GOOGLE_SHEETS_API_SETUP.md`
