# Google Sheets API Setup - Step-by-Step Guide

## Overview

This guide will help you set up automated access to Google Sheets so we can import all your asset data directly without manual CSV exports.

---

## Step 1: Create Google Cloud Project

1. **Go to Google Cloud Console**
   - Visit: https://console.cloud.google.com/
   - Sign in with your Google account (the one that owns the Google Sheets)

2. **Create a New Project**
   - Click the project dropdown at the top
   - Click **"New Project"**
   - Project name: `1PWR Asset Management` (or any name)
   - Click **"Create"**
   - Wait for project creation (may take a few seconds)

3. **Select the Project**
   - Make sure your new project is selected in the dropdown

---

## Step 2: Enable Google Sheets API

1. **Navigate to APIs & Services**
   - In the left menu, go to **"APIs & Services"** → **"Library"**

2. **Search for Google Sheets API**
   - In the search box, type: `Google Sheets API`
   - Click on **"Google Sheets API"** from the results

3. **Enable the API**
   - Click the **"Enable"** button
   - Wait for it to enable (usually instant)

---

## Step 3: Create Service Account

1. **Go to Credentials**
   - In the left menu, go to **"APIs & Services"** → **"Credentials"**

2. **Create Service Account**
   - Click **"+ CREATE CREDENTIALS"** at the top
   - Select **"Service account"**

3. **Fill in Service Account Details**
   - **Service account name**: `asset-migration-service`
   - **Service account ID**: (auto-generated, you can change it)
   - **Description**: `Service account for 1PWR asset management data migration`
   - Click **"CREATE AND CONTINUE"**

4. **Grant Access (Optional)**
   - You can skip this step for now
   - Click **"CONTINUE"**

5. **Grant Users Access (Optional)**
   - You can skip this step
   - Click **"DONE"**

---

## Step 4: Create and Download Service Account Key

1. **Find Your Service Account**
   - In the Credentials page, under **"Service Accounts"**, find `asset-migration-service@[project-id].iam.gserviceaccount.com`
   - Click on the service account email

2. **Create Key**
   - Go to the **"Keys"** tab
   - Click **"ADD KEY"** → **"Create new key"**
   - Select **"JSON"** format
   - Click **"CREATE"**
   - A JSON file will download automatically

3. **Save the JSON File**
   - Save it as: `google-credentials.json`
   - **IMPORTANT**: Keep this file secure - it provides access to your Google Sheets
   - Note the email address shown (e.g., `asset-migration-service@your-project.iam.gserviceaccount.com`)

---

## Step 5: Share Google Sheets with Service Account

For **each Google Sheet** you want to import:

1. **Open the Google Sheet**
   - Go to the sheet URL from your PDF list

2. **Click "Share" Button**
   - Top right corner of the sheet

3. **Add Service Account Email**
   - In the "Add people and groups" field, paste the service account email:
     - Example: `asset-migration-service@your-project.iam.gserviceaccount.com`
   - **Permission**: Select **"Viewer"** (read-only is enough)
   - **Uncheck** "Notify people" (optional)
   - Click **"Share"**

4. **Repeat for All Sheets**
   - Do this for each sheet:
     - RET Material Items Database
     - FAC Material Items Database
     - O&M Material Database
     - Meters_Meter Enclosures_Ready Boards Database
     - General Materials and Items Database
     - Engineering Tool List
     - Powerhouse parts list_Lichaba
     - Any others from your list

---

## Step 6: Get Google Sheet IDs

For each Google Sheet, you need to extract the Sheet ID from the URL:

**Example URL:**
```
https://docs.google.com/spreadsheets/d/1ABC123XYZ456DEF789/edit#gid=0
```

**Sheet ID is:** `1ABC123XYZ456DEF789` (the long string between `/d/` and `/edit`)

**How to find:**
1. Open each Google Sheet
2. Look at the URL in your browser
3. Copy the Sheet ID (the long alphanumeric string)
4. Write them down with the sheet name

**Example:**
- RET Materials: `1ABC123XYZ456DEF789`
- FAC Items: `1DEF456UVW789GHI012`
- O&M Database: `1GHI789JKL012MNO345`
- etc.

---

## Step 7: Upload Credentials to Server

1. **Upload JSON File**
   ```bash
   scp -i 1pwrAM.pem google-credentials.json ec2-user@16.28.64.221:/var/www/onestop-asset-shop/database/
   ```

2. **Set Permissions**
   ```bash
   ssh -i 1pwrAM.pem ec2-user@16.28.64.221
   cd /var/www/onestop-asset-shop/database
   chmod 600 google-credentials.json
   ```

---

## Step 8: Configure Sheet IDs in Script

I'll help you update the import script with your Sheet IDs. Just provide me the Sheet IDs and I'll configure it.

---

## Step 9: Install Dependencies & Run

Once everything is configured, I'll run:

```bash
cd /var/www/onestop-asset-shop
composer require google/apiclient
php database/import_from_google_sheets.php
```

---

## Troubleshooting

### "Permission denied" when accessing sheets
- Make sure you shared each sheet with the service account email
- Check that the email matches exactly (copy-paste it)

### "API not enabled"
- Go back to Step 2 and verify Google Sheets API is enabled

### "Invalid credentials"
- Make sure the JSON file is uploaded correctly
- Check file permissions (should be 600)

### "Sheet not found"
- Verify the Sheet ID is correct in the URL
- Make sure the sheet is shared with the service account

---

## Security Notes

- **Keep credentials.json secure** - don't commit it to Git
- The service account only has "Viewer" access (read-only)
- You can revoke access anytime by removing the service account from shared sheets

---

## Next Steps

Once you complete Steps 1-6, let me know:
1. ✅ Service account created
2. ✅ JSON credentials downloaded
3. ✅ Sheets shared with service account
4. 📋 Sheet IDs (I'll configure the script)

Then I'll complete the setup and run the import!
