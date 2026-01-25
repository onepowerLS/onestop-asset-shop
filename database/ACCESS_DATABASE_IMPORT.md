# Import from Microsoft Access Database (.accdb)

## Overview

This guide will help you import data directly from your Microsoft Access database file. The Access database likely has more complete data than the SQL dump.

## Prerequisites

### Option 1: Export to CSV (Easiest - Recommended)

**If you can open the Access database:**

1. Open the `.accdb` file in Microsoft Access
2. Open the `assets` table (or whatever table has your asset data)
3. **File → Export → Export to Text File**
4. Choose **CSV** format
5. Save as `assets_from_access.csv`
6. Upload to server and use the CSV import script

**This is the easiest method and doesn't require any special drivers.**

---

### Option 2: Direct Access Database Import (Windows Server)

**If your EC2 server is Windows-based**, you can import directly:

1. **Install Microsoft Access Database Engine**
   - Download: https://www.microsoft.com/en-us/download/details.aspx?id=54920
   - Install on the server

2. **Upload the .accdb file to the server:**
   ```bash
   scp -i 1pwrAM.pem database.accdb ec2-user@16.28.64.221:/tmp/
   ```

3. **Run the import script:**
   ```bash
   ssh -i 1pwrAM.pem ec2-user@16.28.64.221
   cd /var/www/onestop-asset-shop
   php database/import_from_access.php /tmp/database.accdb assets
   ```

**Note:** This only works on Windows servers. Linux servers would need additional setup.

---

### Option 3: Convert Access to SQL/CSV Locally

**On your Windows machine:**

1. **Open Access database**
2. **Export to CSV:**
   - Right-click on the `assets` table
   - Export → Text File
   - Choose CSV format
   - Save

3. **Or export to SQL:**
   - Use Access export wizard
   - Or use a tool like "Access to MySQL" converter

4. **Upload and import:**
   ```bash
   scp -i 1pwrAM.pem assets.csv ec2-user@16.28.64.221:/var/www/onestop-asset-shop/database/csv_imports/
   ssh -i 1pwrAM.pem ec2-user@16.28.64.221
   cd /var/www/onestop-asset-shop
   php database/migrate_data.php
   ```

---

## Recommended Approach

**For Linux EC2 server (which you have):**

1. **Export from Access to CSV on your Windows machine**
2. **Upload CSV to server**
3. **Run CSV import script**

This is the simplest and most reliable method.

---

## What Data Will Be Imported

The script will import/update:
- ✅ Asset names
- ✅ Descriptions
- ✅ Serial numbers
- ✅ Manufacturer (if available)
- ✅ Model (if available)
- ✅ Purchase dates
- ✅ Purchase prices
- ✅ Current values
- ✅ Asset tags
- ✅ Locations
- ✅ Status and condition
- ✅ All other available fields

**Existing assets will be updated** with data from Access (which may be more complete).

**New assets** will be imported as new records.

---

## Field Mapping

The script automatically maps common Access field names:
- `Name` or `AssetName` → `name`
- `SerialNumber` or `Serial_Number` → `serial_number`
- `Manufacturer` → `manufacturer`
- `Model` → `model`
- `PurchasePrice` or `Purchase_Price` → `purchase_price`
- `CurrentValue` or `Current_Value` → `current_value`
- `TagNumber` or `NewTagNumber` → `asset_tag`
- etc.

---

## Next Steps

**Option A: Export to CSV (Recommended)**
1. Open Access database
2. Export `assets` table to CSV
3. Upload to server
4. Run CSV import

**Option B: Share the .accdb file**
1. Upload the .accdb file somewhere accessible
2. I can help convert it or guide you through export

**Which method would you prefer?**
