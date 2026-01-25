# Quick Setup Guide - EC2 Instance

## Your Instance Details
- **Instance ID**: `i-0dda937da2c9d0018`
- **Public IP**: `16.28.64.221`
- **Region**: af-south-1 (Cape Town)
- **Instance Type**: t3.small

## Step 1: Connect via SSH

### On Windows (PowerShell):

```powershell
# Navigate to where you saved the .pem file
cd C:\Users\it\Downloads  # or wherever you saved it

# Set permissions (if needed)
icacls onestop-asset-shop-key.pem /inheritance:r
icacls onestop-asset-shop-key.pem /grant:r "$($env:USERNAME):(R)"

# Connect to instance
ssh -i onestop-asset-shop-key.pem ec2-user@16.28.64.221
```

**Note**: If you get a permission error, the key file might have a different name. Check your Downloads folder for the `.pem` file.

### First Connection

When you connect for the first time, you'll see:
```
The authenticity of host '16.28.64.221' can't be established.
Are you sure you want to continue connecting (yes/no)?
```
Type: `yes` and press Enter.

You should then see:
```
[ec2-user@ip-172-31-2-97 ~]$
```

**You're now connected!** ✅

## Step 2: Update System

Once connected, run:

```bash
# Update all packages
sudo yum update -y
```

This may take 2-3 minutes.

## Step 3: Install Required Software

```bash
# Install Apache, PHP, MySQL, Git
sudo yum install -y httpd mariadb-server php php-mysqlnd php-mbstring php-json php-curl git

# Start and enable services
sudo systemctl start httpd
sudo systemctl enable httpd
sudo systemctl start mariadb
sudo systemctl enable mariadb
```

## Step 4: Create Application Directory

```bash
# Create directory
sudo mkdir -p /var/www/onestop-asset-shop
sudo chown -R ec2-user:ec2-user /var/www/onestop-asset-shop
cd /var/www/onestop-asset-shop
```

## Step 5: Clone Repository

```bash
# Clone the repository
git clone https://github.com/onepowerLS/onestop-asset-shop.git .

# Verify files
ls -la
```

## Step 6: Set Up Environment

```bash
# Copy environment template
cp .env.example .env

# Edit environment file
nano .env
```

**In the editor, update these values:**

```env
DB_HOST=localhost
DB_NAME=onestop_asset_shop
DB_USER=onestop_user
DB_PASS=CREATE_A_STRONG_PASSWORD_HERE

APP_ENV=production
APP_DEBUG=false
APP_URL=https://am.1pwrafrica.com
```

**To save in nano**: Press `Ctrl+X`, then `Y`, then `Enter`

## Step 7: Set Up Database

```bash
# Secure MySQL installation
sudo mysql_secure_installation
```

**Follow the prompts:**
- Set root password? **Y** (choose a strong password - write it down!)
- Remove anonymous users? **Y**
- Disallow root login remotely? **Y**
- Remove test database? **Y**
- Reload privilege tables? **Y**

**Create database and user:**

```bash
# Login to MySQL (use the root password you just set)
sudo mysql -u root -p
```

**In MySQL, run these commands** (replace `YOUR_PASSWORD` with the password from your `.env` file):

```sql
CREATE DATABASE onestop_asset_shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'onestop_user'@'localhost' IDENTIFIED BY 'YOUR_PASSWORD_FROM_ENV';

GRANT ALL PRIVILEGES ON onestop_asset_shop.* TO 'onestop_user'@'localhost';

FLUSH PRIVILEGES;

EXIT;
```

**Import database schema:**

```bash
# Import the schema
mysql -u onestop_user -p onestop_asset_shop < database/schema-consolidated.sql

# Verify tables were created
mysql -u onestop_user -p onestop_asset_shop -e "SHOW TABLES;"
```

You should see tables like: `assets`, `countries`, `locations`, `transactions`, etc.

## Step 8: Configure Apache

```bash
# Create virtual host configuration
sudo nano /etc/httpd/conf.d/onestop-asset-shop.conf
```

**Add this configuration:**

```apache
<VirtualHost *:80>
    ServerName am.1pwrafrica.com
    ServerAlias www.am.1pwrafrica.com
    
    DocumentRoot /var/www/onestop-asset-shop/web
    
    <Directory /var/www/onestop-asset-shop/web>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog /var/log/httpd/onestop-asset-shop-error.log
    CustomLog /var/log/httpd/onestop-asset-shop-access.log combined
</VirtualHost>
```

**Save and exit** (Ctrl+X, Y, Enter)

**Set permissions:**

```bash
# Set ownership
sudo chown -R apache:apache /var/www/onestop-asset-shop/web

# Set permissions
sudo chmod -R 755 /var/www/onestop-asset-shop

# Restart Apache
sudo systemctl restart httpd
```

## Step 9: Test Basic Setup

```bash
# Test Apache is running
sudo systemctl status httpd

# Test MySQL is running
sudo systemctl status mariadb

# Test web server (should show something)
curl http://localhost/health.php
```

## Step 10: Set Up Domain DNS

**Before setting up SSL, point your domain to this IP:**

1. Go to your DNS provider (where you manage `1pwrafrica.com`)
2. Add/Edit A record:
   - **Name**: `am`
   - **Type**: `A`
   - **Value**: `16.28.64.221`
   - **TTL**: `300`

3. Wait 5-60 minutes for DNS propagation

4. Test DNS:
   ```bash
   nslookup am.1pwrafrica.com
   # Should return: 16.28.64.221
   ```

## Step 11: Set Up SSL Certificate

**Once DNS is working:**

```bash
# Install Certbot
sudo yum install -y certbot python3-certbot-apache

# Get SSL certificate
sudo certbot --apache -d am.1pwrafrica.com
```

**Follow prompts:**
- Email: your-email@1pwrafrica.com
- Agree to terms: **Y**
- Share email: **N** (or Y)
- Redirect HTTP to HTTPS: **2** (recommended)

## Step 12: Verify Everything Works

```bash
# Test health endpoint
curl https://am.1pwrafrica.com/health.php

# Should return: {"status":"healthy",...}
```

## Next Steps

1. ✅ Test website: `https://am.1pwrafrica.com`
2. ✅ Create initial admin user in database
3. ✅ Configure GitHub Actions secrets for auto-deployment
4. ✅ Test automated deployment

---

**Need help?** Share any error messages you encounter and I'll help troubleshoot!
