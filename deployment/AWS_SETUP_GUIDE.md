# AWS EC2 Setup Guide - Step by Step

## Prerequisites

- AWS Account with appropriate permissions
- Access to AWS Console
- Domain name ready (assets.1pwrafrica.com) or use EC2 public IP

## Step 1: Launch EC2 Instance

### 1.1 Navigate to EC2

1. Log into AWS Console: https://console.aws.amazon.com
2. Search for "EC2" in the top search bar
3. Click "EC2" service

### 1.2 Launch Instance

1. Click **"Launch Instance"** button (orange button, top right)
2. You'll see the "Launch an instance" wizard

### 1.3 Configure Instance

**Name and tags:**
- Name: `onestop-asset-shop-production`

**Application and OS Images (Amazon Machine Image):**
- Choose: **Amazon Linux 2023 AMI** (recommended)
  - OR **Ubuntu Server 22.04 LTS** (also works)
- Architecture: **64-bit (x86)**

**Instance type:**
- Select: **t3.medium** (2 vCPU, 4 GB RAM)
  - Minimum: t3.small (2 vCPU, 2 GB RAM)
  - For production with expected load: t3.medium or larger

**Key pair (login):**
- **Create new key pair** (if you don't have one)
  - Key pair name: `onestop-asset-shop-key`
  - Key pair type: **RSA**
  - Private key file format: **.pem** (for OpenSSH)
  - Click **"Create key pair"**
  - **IMPORTANT**: Download the `.pem` file and save it securely
  - You'll need this to SSH into the instance

**Network settings:**
- VPC: Default VPC (or your existing VPC)
- Subnet: Any public subnet
- Auto-assign Public IP: **Enable**
- Firewall (security groups): **Create security group**
  - Security group name: `onestop-asset-shop-sg`
  - Description: `Security group for OneStop Asset Shop`
  - **Inbound rules** (click "Add security group rule" for each):
    
    | Type | Protocol | Port Range | Source | Description |
    |------|----------|------------|--------|-------------|
    | SSH | TCP | 22 | My IP | SSH access |
    | HTTP | TCP | 80 | 0.0.0.0/0 | Web traffic |
    | HTTPS | TCP | 443 | 0.0.0.0/0 | Secure web traffic |
  
  - **Outbound rules**: Leave default (All traffic)

**Configure storage:**
- Volume type: **gp3** (General Purpose SSD)
- Size: **20 GiB** (minimum, increase if needed)
- Delete on termination: **Unchecked** (keep data if instance terminates)

**Advanced details** (optional):
- IAM instance profile: Create/select role with EC2 permissions (for future use)
- User data: Leave blank (we'll set up manually)

### 1.4 Launch

1. Review all settings
2. Click **"Launch instance"**
3. Wait for instance to be in "Running" state (green checkmark)

## Step 2: Get Instance Information

### 2.1 Find Your Instance

1. In EC2 Dashboard, click **"Instances"** (left sidebar)
2. Find your instance: `onestop-asset-shop-production`
3. Click on the instance to see details

### 2.2 Note Important Details

Write down these values (you'll need them):

- **Instance ID**: `i-xxxxxxxxxxxxxxxxx` (e.g., `i-0123456789abcdef0`)
- **Public IPv4 address**: `x.x.x.x` (e.g., `54.123.45.67`)
- **Public IPv4 DNS**: `ec2-x-x-x-x.compute-1.amazonaws.com`
- **Key pair name**: `onestop-asset-shop-key`

### 2.3 (Optional) Allocate Elastic IP

For a static IP address (recommended for production):

1. In EC2 Dashboard, click **"Elastic IPs"** (left sidebar)
2. Click **"Allocate Elastic IP address"**
3. Click **"Allocate"**
4. Select the Elastic IP, click **"Actions"** → **"Associate Elastic IP address"**
5. Select your instance
6. Click **"Associate"**

**Note the Elastic IP** - Use this instead of the public IP if you allocated one.

## Step 3: Configure Domain (Optional but Recommended)

### 3.1 Point Domain to EC2

If you have `assets.1pwrafrica.com`:

1. Go to your domain registrar (where you manage 1pwrafrica.com)
2. Add/Edit DNS A record:
   - **Name**: `assets` (or `@` for root domain)
   - **Type**: `A`
   - **Value**: Your EC2 Public IP (or Elastic IP)
   - **TTL**: 300 (or default)

3. Wait for DNS propagation (5-60 minutes)

**Verify DNS:**
```bash
# In terminal/command prompt
nslookup assets.1pwrafrica.com
# Should return your EC2 IP
```

## Step 4: Initial Server Setup

### 4.1 Connect to Instance

**On Windows (PowerShell):**

```powershell
# Navigate to where you saved the .pem file
cd C:\Users\it\Downloads  # or wherever you saved it

# Set correct permissions (if needed)
icacls onestop-asset-shop-key.pem /inheritance:r
icacls onestop-asset-shop-key.pem /grant:r "$($env:USERNAME):(R)"

# Connect (replace with your IP)
ssh -i onestop-asset-shop-key.pem ec2-user@YOUR_EC2_IP
```

**On Mac/Linux:**

```bash
# Set permissions
chmod 400 onestop-asset-shop-key.pem

# Connect
ssh -i onestop-asset-shop-key.pem ec2-user@YOUR_EC2_IP
```

**Note**: For Ubuntu instances, use `ubuntu` instead of `ec2-user`

### 4.2 Update System

Once connected, run:

```bash
# Amazon Linux
sudo yum update -y

# Ubuntu
sudo apt-get update && sudo apt-get upgrade -y
```

### 4.3 Install Required Software

**For Amazon Linux:**

```bash
# Install Apache, PHP, MySQL, Git
sudo yum install -y httpd mariadb-server php php-mysqlnd php-mbstring php-json php-curl git

# Start services
sudo systemctl start httpd
sudo systemctl enable httpd
sudo systemctl start mariadb
sudo systemctl enable mariadb
```

**For Ubuntu:**

```bash
# Install Apache, PHP, MySQL, Git
sudo apt-get install -y apache2 mysql-server php libapache2-mod-php php-mysql php-mbstring php-json php-curl git

# Start services
sudo systemctl start apache2
sudo systemctl enable apache2
sudo systemctl start mysql
sudo systemctl enable mysql
```

### 4.4 Set Up Application Directory

```bash
# Create directory
sudo mkdir -p /var/www/onestop-asset-shop
sudo chown -R $USER:$USER /var/www/onestop-asset-shop

# Navigate to directory
cd /var/www/onestop-asset-shop
```

### 4.5 Clone Repository

```bash
# Clone the repository
git clone https://github.com/onepowerLS/onestop-asset-shop.git .

# Or if you need to authenticate:
# git clone https://YOUR_GITHUB_TOKEN@github.com/onepowerLS/onestop-asset-shop.git .
```

### 4.6 Set Up Environment File

```bash
# Copy example environment file
cp .env.example .env

# Edit environment file
nano .env
# Or use: vi .env
```

**Update these values in `.env`:**

```env
DB_HOST=localhost
DB_NAME=onestop_asset_shop
DB_USER=onestop_user
DB_PASS=CREATE_A_STRONG_PASSWORD_HERE

APP_ENV=production
APP_DEBUG=false
APP_URL=https://assets.1pwrafrica.com
```

**Save and exit** (Ctrl+X, then Y, then Enter for nano)

### 4.7 Set Up Database

```bash
# Secure MySQL installation (set root password)
sudo mysql_secure_installation
# Follow prompts:
# - Set root password: YES (choose strong password)
# - Remove anonymous users: YES
# - Disallow root login remotely: YES
# - Remove test database: YES
# - Reload privilege tables: YES

# Create database and user
sudo mysql -u root -p
```

**In MySQL prompt, run:**

```sql
CREATE DATABASE onestop_asset_shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

CREATE USER 'onestop_user'@'localhost' IDENTIFIED BY 'YOUR_DB_PASSWORD_FROM_ENV';

GRANT ALL PRIVILEGES ON onestop_asset_shop.* TO 'onestop_user'@'localhost';

FLUSH PRIVILEGES;

EXIT;
```

**Import database schema:**

```bash
# Import the consolidated schema
mysql -u onestop_user -p onestop_asset_shop < database/schema-consolidated.sql

# Verify tables were created
mysql -u onestop_user -p onestop_asset_shop -e "SHOW TABLES;"
```

### 4.8 Configure Apache

**For Amazon Linux:**

```bash
# Create virtual host
sudo nano /etc/httpd/conf.d/onestop-asset-shop.conf
```

**For Ubuntu:**

```bash
# Create virtual host
sudo nano /etc/apache2/sites-available/onestop-asset-shop.conf
```

**Add this configuration:**

```apache
<VirtualHost *:80>
    ServerName assets.1pwrafrica.com
    ServerAlias www.assets.1pwrafrica.com
    
    DocumentRoot /var/www/onestop-asset-shop/web
    
    <Directory /var/www/onestop-asset-shop/web>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog ${APACHE_LOG_DIR}/onestop-asset-shop-error.log
    CustomLog ${APACHE_LOG_DIR}/onestop-asset-shop-access.log combined
</VirtualHost>
```

**Enable site and modules (Ubuntu only):**

```bash
# Enable site
sudo a2ensite onestop-asset-shop.conf

# Enable required modules
sudo a2enmod rewrite
sudo a2enmod php

# Disable default site (optional)
sudo a2dissite 000-default.conf
```

**Set permissions:**

```bash
# Set ownership
sudo chown -R www-data:www-data /var/www/onestop-asset-shop/web

# Set permissions
sudo chmod -R 755 /var/www/onestop-asset-shop

# Make sure .htaccess works
sudo chmod 644 /var/www/onestop-asset-shop/web/.htaccess
```

**Restart Apache:**

```bash
# Amazon Linux
sudo systemctl restart httpd

# Ubuntu
sudo systemctl restart apache2
```

### 4.9 Set Up SSL (Let's Encrypt)

```bash
# Install Certbot
# Amazon Linux
sudo yum install -y certbot python3-certbot-apache

# Ubuntu
sudo apt-get install -y certbot python3-certbot-apache

# Get SSL certificate
sudo certbot --apache -d assets.1pwrafrica.com

# Follow prompts:
# - Email address: your-email@1pwrafrica.com
# - Agree to terms: Y
# - Share email: N (or Y)
# - Redirect HTTP to HTTPS: 2 (recommended)
```

**Auto-renewal is set up automatically**, but test it:

```bash
sudo certbot renew --dry-run
```

## Step 5: Configure GitHub Actions

### 5.1 Create AWS IAM User for GitHub Actions

1. Go to AWS Console → **IAM** → **Users**
2. Click **"Create user"**
3. User name: `github-actions-onestop`
4. Click **"Next"**
5. **Permissions**: Attach policies directly
   - Click **"Create policy"**
   - Use JSON tab, paste:

```json
{
  "Version": "2012-10-17",
  "Statement": [
    {
      "Effect": "Allow",
      "Action": [
        "ec2:DescribeInstances",
        "ec2:SendSSHPublicKey"
      ],
      "Resource": "*"
    },
    {
      "Effect": "Allow",
      "Action": [
        "s3:PutObject",
        "s3:GetObject"
      ],
      "Resource": "arn:aws:s3:::onestop-asset-deploy/*"
    }
  ]
}
```

6. Name: `GitHubActionsDeployPolicy`
7. Click **"Create policy"**
8. Go back to user creation, refresh, select the policy
9. Click **"Next"** → **"Create user"**
10. **IMPORTANT**: Click on the user → **"Security credentials"** tab
11. Click **"Create access key"**
12. Choose: **"Application running outside AWS"**
13. **Save both**:
    - Access key ID
    - Secret access key (shown only once!)

### 5.2 Create S3 Bucket for Deployment (Optional)

If using S3 for deployment package:

1. Go to AWS Console → **S3**
2. Click **"Create bucket"**
3. Bucket name: `onestop-asset-deploy`
4. Region: Same as EC2 instance
5. Click **"Create bucket"**

### 5.3 Add GitHub Secrets

1. Go to: `https://github.com/onepowerLS/onestop-asset-shop/settings/secrets/actions`
2. Click **"New repository secret"** for each:

   **Secret 1: `AWS_ACCESS_KEY_ID`**
   - Value: Access key ID from IAM user

   **Secret 2: `AWS_SECRET_ACCESS_KEY`**
   - Value: Secret access key from IAM user

   **Secret 3: `AWS_EC2_INSTANCE_ID`**
   - Value: Your EC2 instance ID (e.g., `i-0123456789abcdef0`)

   **Secret 4: `EC2_HOST`**
   - Value: Your EC2 public IP or domain (e.g., `assets.1pwrafrica.com` or `54.123.45.67`)

   **Secret 5: `EC2_SSH_KEY`**
   - Value: Contents of your `.pem` file
     - Open `onestop-asset-shop-key.pem` in a text editor
     - Copy entire contents (including `-----BEGIN RSA PRIVATE KEY-----` and `-----END RSA PRIVATE KEY-----`)
     - Paste into secret value

### 5.4 Set Up SSH Key on EC2 for GitHub Actions

```bash
# SSH into your EC2 instance
ssh -i onestop-asset-shop-key.pem ec2-user@YOUR_EC2_IP

# Create .ssh directory for www-data (or apache user)
sudo mkdir -p /var/www/.ssh
sudo chown www-data:www-data /var/www/.ssh
sudo chmod 700 /var/www/.ssh

# Add GitHub Actions public key to authorized_keys
# (You'll generate this or use the one from GitHub Actions)
sudo nano /var/www/.ssh/authorized_keys
# Paste the public key from GitHub Actions
sudo chmod 600 /var/www/.ssh/authorized_keys
sudo chown www-data:www-data /var/www/.ssh/authorized_keys
```

**Alternative**: Configure Git to use HTTPS with token instead of SSH.

## Step 6: Test Deployment

### 6.1 Test Manual Deployment First

```bash
# SSH into EC2
ssh -i onestop-asset-shop-key.pem ec2-user@YOUR_EC2_IP

# Navigate to app
cd /var/www/onestop-asset-shop

# Pull latest
git pull origin main

# Verify files
ls -la web/

# Test health endpoint
curl http://localhost/health.php
```

### 6.2 Test GitHub Actions

1. Make a small change to `develop` branch
2. Push to GitHub
3. Go to: `https://github.com/onepowerLS/onestop-asset-shop/actions`
4. Watch the workflow run
5. Check for any errors

### 6.3 Test Production Deployment

1. Merge `develop` to `main`:
   ```bash
   git checkout main
   git merge develop
   git push origin main
   ```

2. Watch GitHub Actions deploy
3. Verify: `https://assets.1pwrafrica.com/health.php`
4. Should return: `{"status":"healthy",...}`

## Step 7: Verify Everything Works

### 7.1 Test Web Application

1. Open browser: `https://assets.1pwrafrica.com`
2. Should see login page
3. Test login (create a test user first in database)

### 7.2 Test Database

```bash
# SSH into EC2
mysql -u onestop_user -p onestop_asset_shop

# Run test queries
SELECT COUNT(*) FROM countries;
SELECT COUNT(*) FROM assets;
SHOW TABLES;
```

### 7.3 Monitor Logs

```bash
# Apache error log
sudo tail -f /var/log/httpd/error_log  # Amazon Linux
sudo tail -f /var/log/apache2/error.log  # Ubuntu

# Application logs (if configured)
tail -f /var/www/onestop-asset-shop/logs/app.log
```

## Troubleshooting

### Can't SSH into instance

- Check security group allows SSH from your IP
- Verify you're using correct key file
- Check instance is in "Running" state

### Website not loading

- Check Apache is running: `sudo systemctl status httpd`
- Check security group allows HTTP/HTTPS
- Verify DNS is pointing to correct IP
- Check Apache error logs

### Database connection errors

- Verify MySQL is running: `sudo systemctl status mariadb`
- Check `.env` file has correct credentials
- Test connection: `mysql -u onestop_user -p`

### GitHub Actions deployment fails

- Verify all secrets are set correctly
- Check EC2 security group allows SSH from GitHub Actions IPs
- Verify SSH key is correct in secrets
- Check GitHub Actions logs for specific errors

## Next Steps

Once everything is set up:

1. ✅ Import existing data (follow `database/MIGRATION_GUIDE.md`)
2. ✅ Create initial admin user
3. ✅ Test all functionality
4. ✅ Set up monitoring and backups
5. ✅ Document any custom configurations

## Support

If you encounter issues:

1. Check logs (Apache, MySQL, application)
2. Review GitHub Actions workflow logs
3. Verify all configuration values
4. Check AWS EC2 instance status and metrics

---

**You're all set!** Your OneStop Asset Shop is now ready for automated deployments. 🚀
