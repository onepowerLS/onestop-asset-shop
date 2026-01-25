# cPanel DNS Configuration Guide

## Overview

This guide will help you configure DNS in cPanel to point `am.1pwrafrica.com` to your EC2 instance at `16.28.64.221`.

---

## Prerequisites

- Access to cPanel for `1pwrafrica.com`
- EC2 instance IP: `16.28.64.221`
- Domain: `am.1pwrafrica.com` (subdomain of `1pwrafrica.com`)

---

## Step-by-Step Instructions

### Step 1: Log into cPanel

1. Navigate to your cPanel login URL (usually `https://1pwrafrica.com:2083` or provided by your host)
2. Enter your cPanel username and password
3. Click "Log in"

### Step 2: Navigate to DNS Zone Editor

1. In the cPanel dashboard, look for the **"Domains"** section
2. Click on **"Zone Editor"** or **"DNS Zone Editor"**
   - If you don't see it, try searching for "DNS" in the search bar at the top
   - Alternative names: "Advanced DNS Zone Editor", "Simple DNS Zone Editor"

### Step 3: Select the Domain

1. You should see a dropdown or list of domains
2. Select **`1pwrafrica.com`** from the list
3. Click **"Manage"** or the domain name

### Step 4: Add A Record for `am` Subdomain

1. Look for a button that says:
   - **"Add Record"**
   - **"+ Add Record"**
   - **"Add A Record"**

2. Click the button to add a new A record

3. Fill in the form fields:
   - **Name**: `am`
     - ⚠️ **Important**: Enter only `am` (not `am.1pwrafrica.com`)
     - cPanel will automatically append the domain
   - **Type**: `A` (should be selected by default)
   - **Address**: `16.28.64.221`
   - **TTL**: `3600` (1 hour) or leave default
     - Lower TTL (300-600) for faster changes, higher (3600+) for stability

4. Click **"Add Record"** or **"Save"**

### Step 5: Add A Record for `www.am` Subdomain (Optional but Recommended)

1. Click **"Add Record"** again

2. Fill in the form:
   - **Name**: `www.am`
     - ⚠️ **Important**: Enter `www.am` (not `www.am.1pwrafrica.com`)
   - **Type**: `A`
   - **Address**: `16.28.64.221`
   - **TTL**: `3600` or default

3. Click **"Add Record"** or **"Save"**

### Step 6: Verify the Records

After adding, you should see entries like:

```
am.1pwrafrica.com.    3600    IN    A    16.28.64.221
www.am.1pwrafrica.com.    3600    IN    A    16.28.64.221
```

---

## Alternative Method: Using "Simple DNS Zone Editor"

If your cPanel uses "Simple DNS Zone Editor":

1. Navigate to **"Simple DNS Zone Editor"**
2. Select domain: **`1pwrafrica.com`**
3. In the **"Add an A Record"** section:
   - **Name**: `am`
   - **Address**: `16.28.64.221`
   - **TTL**: `3600`
4. Click **"Add A Record"**
5. Repeat for `www.am` if needed

---

## Verification

### Method 1: Check in cPanel
1. Go back to Zone Editor
2. Verify the records are listed correctly
3. Check that the IP address matches `16.28.64.221`

### Method 2: Command Line (After Propagation)
Wait 5-30 minutes, then test:

**Windows PowerShell:**
```powershell
nslookup am.1pwrafrica.com
```

**Expected output:**
```
Name:    am.1pwrafrica.com
Address: 16.28.64.221
```

**Linux/Mac:**
```bash
nslookup am.1pwrafrica.com
# or
dig am.1pwrafrica.com
```

### Method 3: Online Tools
- Visit: https://www.whatsmydns.net/
- Enter: `am.1pwrafrica.com`
- Check if it resolves to `16.28.64.221`

---

## Common Issues & Solutions

### Issue 1: "Record Already Exists"
**Solution:**
- Find the existing `am` A record
- Edit it to point to `16.28.64.221`
- Or delete and recreate it

### Issue 2: "Invalid Name Format"
**Solution:**
- Make sure you're entering only `am` (not `am.1pwrafrica.com`)
- Remove any trailing dots
- Check for typos

### Issue 3: DNS Not Propagating
**Solutions:**
- Wait longer (can take up to 48 hours, usually < 1 hour)
- Check TTL value (lower TTL = faster updates)
- Clear DNS cache on your computer:
  ```powershell
  # Windows
  ipconfig /flushdns
  ```
- Try different DNS servers (8.8.8.8, 1.1.1.1)

### Issue 4: Can't Find Zone Editor
**Solution:**
- Look in "Advanced" section
- Search for "DNS" in cPanel search bar
- Contact your hosting provider if it's not available
- Some hosts manage DNS externally (Route 53, Cloudflare, etc.)

### Issue 5: Subdomain Already Points Elsewhere
**Solution:**
- Check if `am` subdomain exists in cPanel "Subdomains"
- If it points to a different directory, that's OK - DNS can still point to EC2
- The DNS A record takes precedence

---

## After DNS is Configured

Once DNS is working (verified via `nslookup`):

1. **Test HTTP access:**
   ```bash
   curl -I http://am.1pwrafrica.com
   ```

2. **Set up SSL certificate** (see `SSL_SETUP.md`):
   ```bash
   ssh -i 1pwrAM.pem ec2-user@16.28.64.221
   sudo certbot --apache -d am.1pwrafrica.com -d www.am.1pwrafrica.com \
     --non-interactive --agree-tos --email mso@1pwrafrica.com --redirect
   ```

3. **Test HTTPS access:**
   ```bash
   curl -I https://am.1pwrafrica.com
   ```

---

## Screenshot Reference

If available in your cPanel, the DNS Zone Editor should look similar to:

```
┌─────────────────────────────────────────────────────────┐
│ Zone Editor - 1pwrafrica.com                           │
├─────────────────────────────────────────────────────────┤
│                                                         │
│  Add Record                                            │
│  ┌─────────────┬──────┬─────────────────┬──────┐     │
│  │ Name        │ Type │ Address          │ TTL  │     │
│  ├─────────────┼──────┼─────────────────┼──────┤     │
│  │ am          │ A    │ 16.28.64.221     │ 3600 │     │
│  └─────────────┴──────┴─────────────────┴──────┘     │
│                                                         │
│  [Add Record]                                          │
│                                                         │
│  Existing Records:                                      │
│  ┌─────────────────────┬──────┬──────────────┐         │
│  │ Name                │ Type │ Address      │         │
│  ├─────────────────────┼──────┼──────────────┤         │
│  │ @                   │ A    │ 192.0.2.1    │         │
│  │ www                 │ A    │ 192.0.2.1    │         │
│  │ am                  │ A    │ 16.28.64.221│ ← New   │
│  └─────────────────────┴──────┴──────────────┘         │
└─────────────────────────────────────────────────────────┘
```

---

## Quick Checklist

- [ ] Logged into cPanel
- [ ] Found "Zone Editor" or "DNS Zone Editor"
- [ ] Selected `1pwrafrica.com` domain
- [ ] Added A record: Name=`am`, Address=`16.28.64.221`
- [ ] (Optional) Added A record: Name=`www.am`, Address=`16.28.64.221`
- [ ] Verified records appear in the list
- [ ] Waited 5-30 minutes for propagation
- [ ] Tested with `nslookup am.1pwrafrica.com`
- [ ] Confirmed it resolves to `16.28.64.221`

---

## Need Help?

If you encounter issues:
1. Check the "Common Issues & Solutions" section above
2. Verify you have DNS management permissions in cPanel
3. Contact your hosting provider if DNS Zone Editor is not available
4. Check if DNS is managed externally (Route 53, Cloudflare, etc.)

---

## Next Steps

After DNS is configured and verified:
1. ✅ DNS working → Proceed to SSL setup (`SSL_SETUP.md`)
2. ✅ SSL configured → Access `https://am.1pwrafrica.com`
3. ✅ Login with admin credentials (see `ADMIN_USER_INFO.md`)
