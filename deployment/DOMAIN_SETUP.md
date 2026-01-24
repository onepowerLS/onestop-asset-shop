# Domain Setup Guide: am.1pwrafrica.com

## Overview

This guide will help you configure `am.1pwrafrica.com` to point to your AWS EC2 instance hosting the OneStop Asset Shop.

## Option 1: Using AWS Route 53 (Recommended)

If you manage `1pwrafrica.com` through AWS Route 53:

### Step 1: Get Your EC2 Public IP

1. Go to AWS Console → EC2 → Instances
2. Select your instance
3. Note the **Public IPv4 address** (e.g., `54.123.45.67`)
4. (Optional) Allocate an **Elastic IP** for a static address

### Step 2: Create Route 53 Record

1. Go to AWS Console → **Route 53** → **Hosted zones**
2. Select the hosted zone for `1pwrafrica.com`
3. Click **"Create record"**
4. Configure:
   - **Record name**: `am` (creates `am.1pwrafrica.com`)
   - **Record type**: `A`
   - **Value**: Your EC2 Public IP (or Elastic IP)
   - **TTL**: `300` (5 minutes) or `60` (1 minute for testing)
   - **Routing policy**: Simple routing
5. Click **"Create records"**

### Step 3: Verify DNS Propagation

```bash
# In terminal/command prompt
nslookup am.1pwrafrica.com

# Should return your EC2 IP address
# If not, wait a few minutes for DNS propagation
```

## Option 2: Using External DNS Provider

If you manage `1pwrafrica.com` through another provider (GoDaddy, Namecheap, etc.):

### Step 1: Get Your EC2 Public IP

1. Go to AWS Console → EC2 → Instances
2. Select your instance
3. Note the **Public IPv4 address**
4. (Recommended) Allocate an **Elastic IP** for static address

### Step 2: Add DNS A Record

1. Log into your domain registrar/DNS provider
2. Navigate to DNS management for `1pwrafrica.com`
3. Find **DNS Records** or **DNS Settings**
4. Add a new **A Record**:
   - **Host/Name**: `am` (or `am.1pwrafrica.com` depending on provider)
   - **Type**: `A`
   - **Value/Points to**: Your EC2 Public IP (e.g., `54.123.45.67`)
   - **TTL**: `300` (or default)

### Step 3: Save and Wait

- Save the DNS record
- Wait 5-60 minutes for DNS propagation
- Verify with: `nslookup am.1pwrafrica.com`

## Option 3: Using Subdomain via CNAME (Alternative)

If you prefer using a CNAME record:

1. Create an **A Record** for the root domain pointing to EC2 IP (if not exists)
2. Create a **CNAME Record**:
   - **Name**: `am`
   - **Points to**: Your EC2 public DNS name (e.g., `ec2-54-123-45-67.compute-1.amazonaws.com`)

**Note**: CNAME is less reliable if EC2 instance IP changes. Use A record with Elastic IP instead.

## Verifying Domain Setup

### Test DNS Resolution

```bash
# Windows PowerShell
nslookup am.1pwrafrica.com

# Mac/Linux
dig am.1pwrafrica.com
# or
host am.1pwrafrica.com
```

**Expected output**: Should return your EC2 IP address

### Test HTTP Access

```bash
# Test HTTP (port 80)
curl http://am.1pwrafrica.com/health.php

# Test HTTPS (port 443) - after SSL setup
curl https://am.1pwrafrica.com/health.php
```

**Expected output**: `{"status":"healthy",...}`

### Test in Browser

1. Open browser
2. Navigate to: `http://am.1pwrafrica.com`
3. Should see login page or health check response

## Setting Up SSL Certificate

Once DNS is working, set up HTTPS:

### Using Let's Encrypt (Free SSL)

```bash
# SSH into your EC2 instance
ssh -i your-key.pem ec2-user@YOUR_EC2_IP

# Install Certbot (if not already installed)
# Amazon Linux
sudo yum install -y certbot python3-certbot-apache

# Ubuntu
sudo apt-get install -y certbot python3-certbot-apache

# Get SSL certificate
sudo certbot --apache -d am.1pwrafrica.com

# Follow prompts:
# - Email: your-email@1pwrafrica.com
# - Agree to terms: Y
# - Redirect HTTP to HTTPS: 2 (recommended)
```

### Verify SSL

```bash
# Test SSL certificate
curl -I https://am.1pwrafrica.com

# Should return 200 OK with valid SSL
```

### Auto-Renewal

Certbot sets up auto-renewal automatically. Test it:

```bash
sudo certbot renew --dry-run
```

## Updating Application Configuration

### Update .env File

On your EC2 instance:

```bash
# SSH into EC2
ssh -i your-key.pem ec2-user@YOUR_EC2_IP

# Edit .env file
cd /var/www/onestop-asset-shop
nano .env
```

Update:
```env
APP_URL=https://am.1pwrafrica.com
```

### Update Apache Virtual Host

```bash
# Edit Apache config
sudo nano /etc/httpd/conf.d/onestop-asset-shop.conf  # Amazon Linux
# or
sudo nano /etc/apache2/sites-available/onestop-asset-shop.conf  # Ubuntu
```

Ensure it has:
```apache
ServerName am.1pwrafrica.com
ServerAlias www.am.1pwrafrica.com
```

Restart Apache:
```bash
sudo systemctl restart httpd  # Amazon Linux
sudo systemctl restart apache2  # Ubuntu
```

## Troubleshooting DNS Issues

### DNS Not Resolving

1. **Check DNS propagation**:
   ```bash
   # Use online tool
   https://www.whatsmydns.net/#A/am.1pwrafrica.com
   ```

2. **Verify record exists**:
   - Check Route 53 console (if using AWS)
   - Check your DNS provider's control panel

3. **Clear DNS cache**:
   ```bash
   # Windows
   ipconfig /flushdns
   
   # Mac
   sudo dscacheutil -flushcache
   
   # Linux
   sudo systemd-resolve --flush-caches
   ```

### Domain Points to Wrong IP

1. Verify EC2 instance IP hasn't changed
2. Update DNS record with correct IP
3. Wait for propagation (5-60 minutes)

### SSL Certificate Issues

1. **Certificate not issued**:
   - Verify DNS is pointing to EC2
   - Check port 80 is open (required for Let's Encrypt validation)
   - Try: `sudo certbot --apache -d am.1pwrafrica.com --dry-run`

2. **Certificate expired**:
   - Renew: `sudo certbot renew`
   - Check auto-renewal: `sudo systemctl status certbot.timer`

## Security Considerations

### Firewall Rules

Ensure your EC2 Security Group allows:
- **Port 80 (HTTP)**: From `0.0.0.0/0` (for Let's Encrypt)
- **Port 443 (HTTPS)**: From `0.0.0.0/0` (for web traffic)
- **Port 22 (SSH)**: From your IP only (for security)

### Use Elastic IP

**Highly Recommended**: Allocate an Elastic IP to your EC2 instance to prevent IP changes:

1. Go to EC2 → Elastic IPs
2. Click "Allocate Elastic IP address"
3. Click "Allocate"
4. Select the Elastic IP → Actions → Associate Elastic IP address
5. Select your instance → Associate

**Benefits**:
- Static IP address (won't change if instance restarts)
- Easier DNS management
- No need to update DNS if instance is replaced

## Next Steps

Once `am.1pwrafrica.com` is configured:

1. ✅ Test domain resolution
2. ✅ Set up SSL certificate
3. ✅ Update application `.env` file
4. ✅ Test website loads correctly
5. ✅ Update GitHub Actions secrets with domain
6. ✅ Test automated deployment

## Quick Reference

- **Domain**: `am.1pwrafrica.com`
- **Application URL**: `https://am.1pwrafrica.com`
- **Health Check**: `https://am.1pwrafrica.com/health.php`
- **DNS Record Type**: A Record
- **SSL Provider**: Let's Encrypt (via Certbot)

---

**Need Help?** Check the main `AWS_SETUP_GUIDE.md` for complete EC2 setup instructions.
