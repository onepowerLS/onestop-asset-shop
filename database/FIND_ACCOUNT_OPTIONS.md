# Finding the Right Google Account - Options

## Option 1: Try Common Account Patterns

Based on your organization (1PWR Africa), try these email patterns:

### Likely Account Patterns:
- `assets@1pwrafrica.com`
- `assetmanagement@1pwrafrica.com`
- `admin@1pwrafrica.com`
- `operations@1pwrafrica.com`
- `it@1pwrafrica.com`
- `info@1pwrafrica.com`
- `onepower@1pwrafrica.com` or `onepowerafrica@gmail.com`
- Your personal work email (if you have one)

### Steps:
1. Try accessing the folder with each account
2. The one that successfully opens the folder is the right one
3. Once you find it, use that account for Google Cloud setup

---

## Option 2: Check Your Email/Password Manager

1. **Check your password manager** (LastPass, 1Password, etc.)
   - Search for "google" or "drive"
   - Look for accounts with @1pwrafrica.com or related domains

2. **Check your email inbox**
   - Search for "Google Drive" or "shared folder" notifications
   - Look for emails about the asset management sheets
   - The "from" address might give you a clue

3. **Check company documentation**
   - IT documentation
   - Onboarding docs
   - Shared password lists (if secure)

---

## Option 3: Try Accessing with Your Current Account

1. **Sign in with your current Google account** (the one you're using now)
2. **Try to access the folder**: https://drive.google.com/drive/folders/152_3tRqi8Il_z_E7WfR-l1nonPv5JYib
3. **If it works**, great! Use that account
4. **If it doesn't**, you'll see an error that might give clues

---

## Option 4: CSV Export (No Account Needed!)

**Actually, we don't need to know the owner account if someone can export to CSV!**

### If ANYONE can access the sheets:
1. They can export each sheet to CSV (File → Download → CSV)
2. Upload CSVs to the server
3. We'll import them (no Google Cloud setup needed!)

### Steps for CSV Export:
1. Open each Google Sheet
2. **File → Download → Comma-separated values (.csv)**
3. Save with descriptive names:
   - `RET_Materials.csv`
   - `FAC_Items.csv`
   - `OM_Database.csv`
   - etc.
4. Upload to server:
   ```bash
   scp -i 1pwrAM.pem *.csv ec2-user@16.28.64.221:/var/www/onestop-asset-shop/database/csv_imports/
   ```
5. Run import:
   ```bash
   php database/migrate_data.php
   ```

**This is actually FASTER and doesn't require Google Cloud setup!**

---

## Recommendation

**For today (Sunday):**
- Try the common account patterns above
- If you find the password for any of them, try accessing the folder
- If you can access it, we can proceed with Google Cloud setup

**Alternative (if you can't find the account):**
- Wait until Monday when someone can export the sheets to CSV
- CSV import is simpler and doesn't require Google Cloud setup
- We already have the CSV import script ready!

---

## What to Try Right Now

1. **List the email accounts you have passwords for** (even if you're not sure)
2. **Try accessing the folder with each one**
3. **The one that works is the right account**

Or, if you prefer, we can just wait and do CSV export tomorrow - it's actually simpler!
