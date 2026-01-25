# EC2 Setup Complete! ✅

## What Was Done

1. **EC2 Instance Setup**
   - Instance launched and running at `16.28.64.221`
   - Security groups configured for SSH (22), HTTP (80), and HTTPS (443)

2. **Software Installation**
   - Apache HTTP Server (httpd) - ✅ Running
   - MariaDB 10.5 - ✅ Running
   - PHP 8.5 with required extensions - ✅ Installed
   - Git - ✅ Installed

3. **Application Deployment**
   - Repository cloned to `/var/www/onestop-asset-shop`
   - Database created: `onestop_asset_shop`
   - Database user created: `asset_user`
   - Database schema imported successfully
   - `.env` file configured with database credentials

4. **Apache Configuration**
   - Virtual host configured for `am.1pwrafrica.com`
   - Document root: `/var/www/onestop-asset-shop/web`
   - Health endpoint working: `http://16.28.64.221/health.php`

5. **Database Connection**
   - Fixed `.env` file parsing in `database.php`
   - Database connection verified and working

## Current Status

- ✅ Web server running
- ✅ Database running
- ✅ Application accessible at `http://16.28.64.221`
- ✅ Health check endpoint working
- ⚠️ SSL certificate not yet configured
- ⚠️ DNS not yet pointing to instance
- ⚠️ Initial admin user not yet created

## Next Steps

### 1. Configure DNS
Point `am.1pwrafrica.com` to the EC2 instance IP: `16.28.64.221`
- If using Route 53: Create A record
- If using external DNS: Update A record

### 2. Set Up SSL Certificate
```bash
# Install Certbot
sudo yum install -y certbot python3-certbot-apache

# Get certificate
sudo certbot --apache -d am.1pwrafrica.com -d www.am.1pwrafrica.com
```

### 3. Create Initial Admin User
```bash
# Connect to database
mysql -u asset_user -p onestop_asset_shop

# Create admin user (replace password hash with actual hash)
INSERT INTO users (username, password_hash, role, active, created_at) 
VALUES ('admin', '$2y$10$...', 'Admin', 1, NOW());
```

Or use the web interface once logged in (if you have a default user).

### 4. Secure Database Password
Change the default database password in `.env`:
```bash
# Generate a strong password
openssl rand -base64 32

# Update .env file
sudo nano /var/www/onestop-asset-shop/.env

# Update MySQL user password
mysql -u root -e "ALTER USER 'asset_user'@'localhost' IDENTIFIED BY 'NEW_STRONG_PASSWORD';"
```

### 5. Configure GitHub Actions for Auto-Deployment
- Add GitHub Secrets:
  - `AWS_EC2_INSTANCE_ID`
  - `AWS_ACCESS_KEY_ID`
  - `AWS_SECRET_ACCESS_KEY`
  - `EC2_HOST` (16.28.64.221)
  - `EC2_SSH_KEY` (contents of 1pwrAM.pem)

## Access Information

- **SSH**: `ssh -i 1pwrAM.pem ec2-user@16.28.64.221`
- **Web**: `http://16.28.64.221` (will redirect to login)
- **Health Check**: `http://16.28.64.221/health.php`
- **Database**: `mysql -u asset_user -p onestop_asset_shop`

## Important Notes

- Default database password is `ChangeThisPassword123!` - **CHANGE THIS IN PRODUCTION**
- The `.env` file contains sensitive credentials - ensure it's not committed to Git
- SELinux is enabled - if you encounter permission issues, check SELinux contexts
- The instance uses a dynamic IP - consider using an Elastic IP for production

## Troubleshooting

### Check Apache Status
```bash
sudo systemctl status httpd
sudo tail -f /var/log/httpd/error_log
```

### Check Database Status
```bash
sudo systemctl status mariadb
mysql -u asset_user -p onestop_asset_shop -e "SHOW TABLES;"
```

### Check Application Logs
```bash
sudo tail -f /var/log/httpd/onestop-asset-shop-error.log
sudo tail -f /var/log/httpd/onestop-asset-shop-access.log
```
