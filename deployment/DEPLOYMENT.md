# Deployment Guide

## Overview

OneStop Asset Shop uses **GitHub Actions** for CI/CD and deploys to **AWS EC2**.

## Branch Strategy

- **`main`** - Production branch (auto-deploys to EC2)
- **`develop`** - Development branch (for testing and integration)
- **Feature branches** - `feature/feature-name` (merged into `develop`)

### Workflow

```
Feature Branch → develop → main (production)
```

## Prerequisites

### AWS Setup

1. **Launch EC2 Instance**
   - Instance type: `t3.small` or larger (recommended: `t3.medium`)
   - OS: Amazon Linux 2 or Ubuntu 22.04 LTS
   - Security Group: Allow HTTP (80), HTTPS (443), SSH (22)
   - Storage: 20GB minimum

2. **Configure Security Group**
   ```
   Inbound Rules:
   - HTTP (80) from 0.0.0.0/0
   - HTTPS (443) from 0.0.0.0/0
   - SSH (22) from your IP only
   ```

3. **Set up Domain**
   - Point `am.1pwrafrica.com` to EC2 instance IP
   - Or use Elastic IP for static IP address

### GitHub Secrets Configuration

Add these secrets to your GitHub repository (`Settings > Secrets and variables > Actions`):

1. **`AWS_ACCESS_KEY_ID`** - AWS IAM user access key
2. **`AWS_SECRET_ACCESS_KEY`** - AWS IAM user secret key
3. **`AWS_EC2_INSTANCE_ID`** - Your EC2 instance ID (e.g., `i-0123456789abcdef0`)
4. **`EC2_HOST`** - EC2 instance public IP or domain
5. **`EC2_SSH_KEY`** - Private SSH key for EC2 access

### AWS IAM User Setup

Create an IAM user with these permissions:

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

## Initial Server Setup

1. **SSH into EC2 instance**
   ```bash
   ssh -i your-key.pem ec2-user@your-ec2-ip
   ```

2. **Run setup script**
   ```bash
   curl -o setup.sh https://raw.githubusercontent.com/onepowerLS/onestop-asset-shop/main/deployment/aws-setup.sh
   chmod +x setup.sh
   ./setup.sh
   ```

3. **Configure environment**
   ```bash
   cd /var/www/onestop-asset-shop
   nano .env
   # Update database credentials and other settings
   ```

4. **Set up SSL (Let's Encrypt)**
   ```bash
   sudo certbot --apache -d am.1pwrafrica.com
   ```

## Deployment Process

### Automatic Deployment (via GitHub Actions)

1. **Push to `main` branch** → Automatically deploys to production
2. **GitHub Actions workflow**:
   - Runs tests
   - Creates deployment package
   - Deploys to EC2 via SSH
   - Runs health check

### Manual Deployment

```bash
# SSH into server
ssh ec2-user@am.1pwrafrica.com

# Navigate to app directory
cd /var/www/onestop-asset-shop

# Pull latest changes
git pull origin main

# Run database migrations (if any)
mysql -u onestop_user -p onestop_asset_shop < database/migrations/new-migration.sql

# Restart web server
sudo systemctl restart httpd  # Amazon Linux
# or
sudo systemctl restart apache2  # Ubuntu
```

## Testing Before Production

### Pre-Deployment Checklist

- [ ] All tests pass (`develop` branch)
- [ ] Database migrations tested
- [ ] Environment variables configured
- [ ] SSL certificate installed
- [ ] Backup of current production database
- [ ] Code review completed

### Testing Commands

```bash
# Run syntax checks
find . -name "*.php" -exec php -l {} \;

# Test database connectivity
php -r "require 'web/config/database.php'; echo 'Connected!';"

# Check file permissions
ls -la /var/www/onestop-asset-shop/web
```

## Rollback Procedure

If deployment fails:

```bash
# SSH into server
ssh ec2-user@am.1pwrafrica.com

# Navigate to app directory
cd /var/www/onestop-asset-shop

# Rollback to previous commit
git log --oneline  # Find previous commit hash
git checkout <previous-commit-hash>

# Restart web server
sudo systemctl restart httpd
```

## Monitoring

### Health Check Endpoint

Create `web/health.php`:

```php
<?php
header('Content-Type: application/json');
try {
    require_once __DIR__ . '/config/database.php';
    $pdo->query('SELECT 1');
    echo json_encode(['status' => 'healthy', 'timestamp' => date('c')]);
} catch (Exception $e) {
    http_response_code(503);
    echo json_encode(['status' => 'unhealthy', 'error' => $e->getMessage()]);
}
```

### Logs

- **Apache logs**: `/var/log/httpd/` (Amazon Linux) or `/var/log/apache2/` (Ubuntu)
- **Application logs**: Check PHP error log location
- **GitHub Actions logs**: Available in repository `Actions` tab

## Security Best Practices

1. **Never commit `.env` file** - Use GitHub Secrets
2. **Use strong database passwords**
3. **Enable firewall** (only allow necessary ports)
4. **Keep system updated**: `sudo yum update` or `sudo apt update && sudo apt upgrade`
5. **Regular backups** of database
6. **Monitor access logs** for suspicious activity

## Troubleshooting

### Deployment fails

1. Check GitHub Actions logs
2. Verify SSH key is correct
3. Check EC2 security group allows SSH from GitHub Actions IPs
4. Verify file permissions on server

### Application not loading

1. Check Apache/HTTPD status: `sudo systemctl status httpd`
2. Check error logs: `sudo tail -f /var/log/httpd/error_log`
3. Verify `.env` file exists and has correct values
4. Check database connectivity

### Database connection errors

1. Verify MySQL/MariaDB is running: `sudo systemctl status mariadb`
2. Check database credentials in `.env`
3. Verify database exists: `mysql -u root -e "SHOW DATABASES;"`
