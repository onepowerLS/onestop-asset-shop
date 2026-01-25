# How to Find Which Google Account Owns Your Sheets

## Method 1: Check Sheet Sharing (Easiest)

1. **Open any Google Sheet** from your list (e.g., RET Material Items Database)

2. **Click the "Share" button** (top right, usually blue)

3. **Look at the list of people** - the **owner** will be shown with:
   - An "Owner" label next to their name/email
   - Or they'll be listed first with full access

4. **Note the email address** - that's the account that owns the sheet

---

## Method 2: Check Sheet URL

1. **Open a Google Sheet**

2. **Look at the URL** - sometimes it shows the owner in the path or you can see it in the browser

3. **Check the top of the sheet** - the owner's name/email might be displayed

---

## Method 3: Check Your Google Accounts

Think about which Google accounts you/your organization might use:

- **Personal Gmail account?** (e.g., yourname@gmail.com)
- **Work/Organization account?** (e.g., yourname@1pwrafrica.com or yourname@onepowerafrica.com)
- **Shared organization account?** (e.g., assets@1pwrafrica.com)

---

## Method 4: Try Opening with Different Accounts

1. **Open a Google Sheet** in an incognito/private window

2. **Try signing in with different Google accounts** you think might own it

3. **The account that can access it** is likely the owner (or has been granted access)

---

## What to Do Once You Know

Once you identify the owner account:

1. **Sign in to Google Cloud Console** with that account
2. **Create the project and service account** (I'll guide you)
3. **Share the sheets** with the service account email

**OR** if you can't use the owner account:

- **Option A**: Ask the owner to create the service account and share credentials
- **Option B**: Have the owner share the sheets with your account, then you can set it up
- **Option C**: Export sheets manually to CSV (we can still import them)

---

## Quick Check

**Try this now:**
1. Open one of your Google Sheets
2. Click "Share"
3. Tell me what email address shows as "Owner" or has full access

Then we can proceed with the setup using that account!
