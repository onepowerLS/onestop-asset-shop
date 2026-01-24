#!/bin/bash
# AWS EC2 Setup Script for OneStop Asset Shop
# Run this script on a fresh EC2 instance (Amazon Linux 2 or Ubuntu)

set -e

echo "=========================================="
echo "OneStop Asset Shop - AWS EC2 Setup"
echo "=========================================="

# Detect OS
if [ -f /etc/os-release ]; then
    . /etc/os-release
    OS=$ID
else
    echo "Cannot detect OS"
    exit 1
fi

echo "Detected OS: $OS"

# Update system
if [ "$OS" = "amzn" ] || [ "$OS" = "rhel" ]; then
    sudo yum update -y
    sudo yum install -y httpd mariadb-server php php-mysqlnd php-mbstring php-json php-curl git
elif [ "$OS" = "ubuntu" ] || [ "$OS" = "debian" ]; then
    sudo apt-get update
    sudo apt-get install -y apache2 mysql-server php php-mysql php-mbstring php-json php-curl git
fi

# Create application directory
sudo mkdir -p /var/www/onestop-asset-shop
sudo chown -R $USER:$USER /var/www/onestop-asset-shop

# Clone repository (or you'll deploy via GitHub Actions)
cd /var/www/onestop-asset-shop
# git clone https://github.com/onepowerLS/onestop-asset-shop.git .

# Set up Apache virtual host
if [ "$OS" = "amzn" ] || [ "$OS" = "rhel" ]; then
    APACHE_CONF="/etc/httpd/conf.d/onestop-asset-shop.conf"
elif [ "$OS" = "ubuntu" ] || [ "$OS" = "debian" ]; then
    APACHE_CONF="/etc/apache2/sites-available/onestop-asset-shop.conf"
fi

sudo tee $APACHE_CONF > /dev/null <<EOF
<VirtualHost *:80>
    ServerName am.1pwrafrica.com
    ServerAlias www.am.1pwrafrica.com
    
    DocumentRoot /var/www/onestop-asset-shop/web
    
    <Directory /var/www/onestop-asset-shop/web>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>
    
    ErrorLog \${APACHE_LOG_DIR}/onestop-asset-shop-error.log
    CustomLog \${APACHE_LOG_DIR}/onestop-asset-shop-access.log combined
</VirtualHost>
EOF

# Enable site (Ubuntu/Debian)
if [ "$OS" = "ubuntu" ] || [ "$OS" = "debian" ]; then
    sudo a2ensite onestop-asset-shop.conf
    sudo a2enmod rewrite
    sudo a2enmod php
fi

# Set permissions
sudo chown -R www-data:www-data /var/www/onestop-asset-shop/web
sudo chmod -R 755 /var/www/onestop-asset-shop

# Create .env file template
cat > /var/www/onestop-asset-shop/.env <<EOF
# Database Configuration
DB_HOST=localhost
DB_NAME=onestop_asset_shop
DB_USER=onestop_user
DB_PASS=CHANGE_THIS_PASSWORD

# Application
APP_ENV=production
APP_DEBUG=false
APP_URL=https://assets.1pwrafrica.com
EOF

# Set up MySQL database
echo "Setting up MySQL database..."
sudo systemctl start mariadb || sudo systemctl start mysql
sudo systemctl enable mariadb || sudo systemctl enable mysql

# Create database and user (you'll need to set root password)
mysql -u root <<EOF || true
CREATE DATABASE IF NOT EXISTS onestop_asset_shop CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
CREATE USER IF NOT EXISTS 'onestop_user'@'localhost' IDENTIFIED BY 'CHANGE_THIS_PASSWORD';
GRANT ALL PRIVILEGES ON onestop_asset_shop.* TO 'onestop_user'@'localhost';
FLUSH PRIVILEGES;
EOF

# Import database schema
if [ -f /var/www/onestop-asset-shop/database/schema-consolidated.sql ]; then
    mysql -u root onestop_asset_shop < /var/www/onestop-asset-shop/database/schema-consolidated.sql
fi

# Restart Apache
if [ "$OS" = "amzn" ] || [ "$OS" = "rhel" ]; then
    sudo systemctl restart httpd
    sudo systemctl enable httpd
elif [ "$OS" = "ubuntu" ] || [ "$OS" = "debian" ]; then
    sudo systemctl restart apache2
    sudo systemctl enable apache2
fi

# Set up SSL (Let's Encrypt) - optional
echo "To set up SSL with Let's Encrypt, run:"
echo "sudo certbot --apache -d am.1pwrafrica.com"

echo "=========================================="
echo "Setup complete!"
echo "=========================================="
echo "Next steps:"
echo "1. Update .env file with production database credentials"
echo "2. Set up SSL certificate (Let's Encrypt)"
echo "3. Configure GitHub Actions secrets for auto-deployment"
echo "4. Test the application at http://$(curl -s ifconfig.me)"
