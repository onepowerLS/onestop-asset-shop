# Google Cloud Setup - Interactive Walkthrough

Follow these steps in order. I'll guide you through each one.

---

## Step 1: Access Google Cloud Console

1. **Open your web browser**
2. **Go to**: https://console.cloud.google.com/
3. **Sign in** with your Google account (the same one that owns your Google Sheets)

**What you should see:**
- If you've never used Google Cloud before, you'll see a welcome screen
- If you have projects, you'll see the dashboard

**✅ Tell me when you're signed in and I'll guide you to the next step.**

---

## Step 2: Create a New Project

1. **Look at the top of the page** - you'll see a project dropdown (it might say "Select a project" or show an existing project name)

2. **Click the project dropdown** (top left, next to "Google Cloud")

3. **Click "NEW PROJECT"** (or "Create Project" button)

4. **Fill in the project details:**
   - **Project name**: `1PWR Asset Management` (or any name you prefer)
   - **Organization**: Leave as default (or select if you have one)
   - **Location**: Leave as default

5. **Click "CREATE"** (or "Create Project")

6. **Wait a few seconds** - you'll see a notification when it's created

7. **Select your new project** from the dropdown (it should auto-select, but make sure it's selected)

**✅ Tell me when your project is created and selected.**

---

## Step 3: Enable Google Sheets API

1. **In the left sidebar**, look for "APIs & Services"
   - If you don't see it, click the "☰" (hamburger menu) icon at the top left

2. **Click "APIs & Services"** → **"Library"** (or just "Library" if you see it)

3. **In the search box at the top**, type: `Google Sheets API`

4. **Click on "Google Sheets API"** from the search results

5. **Click the blue "ENABLE" button** (top of the page)

6. **Wait a moment** - you'll see a loading indicator, then it will say "API enabled"

**✅ Tell me when the API is enabled.**

---

## Step 4: Create Service Account

1. **In the left sidebar**, go to **"APIs & Services"** → **"Credentials"**

2. **At the top of the page**, click **"+ CREATE CREDENTIALS"** (blue button)

3. **Select "Service account"** from the dropdown menu

4. **Fill in Service Account details:**
   - **Service account name**: `asset-migration-service` (or any name)
   - **Service account ID**: (auto-filled, you can leave it as is)
   - **Service account description**: `Service account for 1PWR asset management data migration`

5. **Click "CREATE AND CONTINUE"**

6. **Optional - Grant access**: You can skip this step
   - Click "CONTINUE" (or "SKIP" if available)

7. **Optional - Grant users access**: You can skip this too
   - Click "DONE"

**✅ Tell me when the service account is created.**

---

## Step 5: Create and Download Service Account Key

1. **You should now be on the Credentials page** - look for a section called "Service Accounts"

2. **Find your service account** in the list (it will show as an email like: `asset-migration-service@your-project-id.iam.gserviceaccount.com`)

3. **Click on the service account email/name** (this opens the service account details)

4. **Click on the "KEYS" tab** (at the top of the page)

5. **Click "ADD KEY"** → **"Create new key"**

6. **Select "JSON"** (it should be selected by default)

7. **Click "CREATE"**

8. **A JSON file will download automatically** - this is your credentials file!

9. **IMPORTANT**: 
   - **Note the email address** shown (e.g., `asset-migration-service@xxx.iam.gserviceaccount.com`)
   - **Save the downloaded JSON file** as `google-credentials.json`
   - **Keep this file secure** - it provides access to your Google Sheets

**✅ Tell me:**
- Did the JSON file download?
- What's the service account email address? (I'll need this for the next step)

---

## Step 6: Share Google Sheets with Service Account

Now we need to share each Google Sheet with the service account email.

**For EACH Google Sheet** (from your list):

1. **Open the Google Sheet** in your browser

2. **Click the "Share" button** (top right, usually blue)

3. **In the "Add people and groups" field**, paste the service account email address
   - This is the email from Step 5 (e.g., `asset-migration-service@xxx.iam.gserviceaccount.com`)

4. **Set the permission** to **"Viewer"** (read-only is enough)

5. **Uncheck "Notify people"** (optional, to avoid email notifications)

6. **Click "Share"** (or "Send" if that's what it says)

7. **Repeat for all your sheets:**
   - RET Material Items Database
   - FAC Material Items Database
   - O&M Material Database
   - Meters_Meter Enclosures_Ready Boards Database
   - General Materials and Items Database
   - Engineering Tool List
   - Powerhouse parts list_Lichaba
   - Any others from your list

**✅ Tell me when you've shared all the sheets.**

---

## Step 7: Get Google Sheet IDs

For each Google Sheet, we need the Sheet ID from the URL.

1. **Open each Google Sheet** in your browser

2. **Look at the URL** in your browser's address bar

3. **The URL will look like:**
   ```
   https://docs.google.com/spreadsheets/d/1ABC123XYZ456DEF789/edit#gid=0
   ```

4. **The Sheet ID is the long string** between `/d/` and `/edit`
   - In the example above: `1ABC123XYZ456DEF789`

5. **Write down each Sheet ID** with its name:
   - RET Materials: `_____________`
   - FAC Items: `_____________`
   - O&M Database: `_____________`
   - Meters: `_____________`
   - etc.

**✅ Share the Sheet IDs with me when you have them, and I'll help you configure the script.**

---

## Next Steps (After Setup)

Once you complete the above steps, I'll help you:
1. Upload the credentials file to the server
2. Configure the import script with your Sheet IDs
3. Install the Google API client library
4. Run the import

**Let's start with Step 1 - are you ready to begin?**
