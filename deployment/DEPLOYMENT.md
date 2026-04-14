# Deployment Guide

## Overview

OneStop Asset Shop uses **GitHub Actions** for CI/CD and deploys to **AWS EC2**. The application uses **Firebase/Firestore** as the primary data store and **Firebase Authentication** for user login.

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
   - Point `assets.1pwrafrica.com` to EC2 instance IP
   - Or use Elastic IP for static IP address

### GitHub Secrets Configuration

The workflow in `.github/workflows/deploy.yml` uses:

1. **`EC2_HOST`** — Public hostname or IP of the EC2 instance (e.g. `16.28.64.221` or `assets.1pwrafrica.com`).
2. **`EC2_SSH_KEY`** — **Private** PEM contents for `ec2-user` (the full key, including `-----BEGIN` / `END` lines).

Optional / older docs may mention AWS keys for other automation; **this repo’s deploy job only needs the two above** for `appleboy/ssh-action`.

### Access and credentials the operator needs (checklist)

| What | Why you need it |
|------|-----------------|
| **GitHub** — push to `main`, or `workflow_dispatch` on “Deploy to EC2” | Triggers CI deploy; merge your branch first. |
| **`EC2_HOST` + `EC2_SSH_KEY` secrets** (or manual SSH) | GitHub Actions runs `git reset --hard origin/main` on the server. |
| **SSH as `ec2-user`** (e.g. EC2 Instance Connect + same key, or `.pem`) | Manual pulls, Apache edits, `certbot`, `chown` for `apache`. |
| **DNS** — A/AAAA for `it.1pwrafrica.com` → same host as `am.1pwrafrica.com` | Points the IT helpdesk hostname at the app (same `DocumentRoot`). |
| **Let’s Encrypt** — expand cert to include `it.1pwrafrica.com` | Browsers require HTTPS SAN to match; see `deployment/onestop-asset-shop-ssl.conf`. |
| **Firebase CLI** — `firebase login` and project **`pr-system-4ea55`** | Deploy **`firestore.rules`** after schema/rule changes (shared with PR portal — coordinate). |
| **Firestore** | Rules deploy; no separate “DB password” for Firestore (uses CLI token). |
| **`.env` on EC2** (not in git) | `FIREBASE_WEB_API_KEY`, `FIREBASE_PROJECT_ID`, etc.; already required for AM. |

**I cannot complete deployment from this environment** without your GitHub/AWS/Firebase access. You (or CI with secrets) must: merge to `main`, ensure secrets are set, run workflow, then **deploy Firestore rules** separately if they changed.

### IT subdomain (`it.1pwrafrica.com`)

The PHP app is path-based (`web/it/`); no second deploy is required. On the **same** EC2 host:

1. Add **`it.1pwrafrica.com`** DNS (A record) to the same IP as Asset Management.
2. Install the Apache snippets under `deployment/` (SSL + HTTP) so `ServerAlias` includes `it.1pwrafrica.com` (see `onestop-asset-shop-ssl.conf`).
3. Expand TLS: e.g. `sudo certbot certonly --apache -d am.1pwrafrica.com -d it.1pwrafrica.com` (adjust domains to match what you already use).
4. `sudo systemctl reload httpd`

HTTP config uses mod_rewrite to send each host to **HTTPS on the same host** (so `http://it.…` becomes `https://it.…`).

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
   cp .env.example .env
   nano .env
   ```

   Required `.env` values:
   ```
   FIREBASE_WEB_API_KEY=AIzaSy...your-key
   FIREBASE_PROJECT_ID=pr-system-4ea55
   ALLOW_INSECURE_SSL_LOCAL=false
   
   # MySQL only needed for legacy username lookup during auth
   DB_HOST=localhost
   DB_NAME=onestop_asset_shop
   DB_USER=onestop_user
   DB_PASS=your-password
   ```

4. **Set up SSL (Let's Encrypt)**
   ```bash
   sudo certbot --apache -d assets.1pwrafrica.com
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
ssh ec2-user@assets.1pwrafrica.com

# Navigate to app directory
cd /var/www/onestop-asset-shop

# Pull latest changes
git pull origin main

# Verify .env has correct Firebase credentials
cat .env | grep FIREBASE

# Restart web server
sudo systemctl restart httpd  # Amazon Linux
# or
sudo systemctl restart apache2  # Ubuntu

# Verify health
curl http://localhost/health.php
```

No database migration is needed for Firestore changes -- collection schemas are implicit. MySQL migrations are only needed if the legacy `users` table for username lookup changes.

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
ssh ec2-user@assets.1pwrafrica.com

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
