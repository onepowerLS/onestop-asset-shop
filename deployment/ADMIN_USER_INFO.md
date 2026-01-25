# Admin User Credentials

## Initial Admin User

**⚠️ IMPORTANT: Change the password after first login!**

- **Username**: `mso`
- **Email**: `mso@1pwrafrica.com`
- **Password**: `Welcome123!`
- **Role**: `Admin`
- **User ID**: `1`

## First Login

1. Navigate to: `http://16.28.64.221/login.php` (or `https://am.1pwrafrica.com/login.php` once DNS/SSL is configured)
2. Login with:
   - Username: `mso`
   - Password: `Welcome123!`
3. **Immediately change the password** in user settings/profile

## Security Notes

- The default password is temporary and should be changed immediately
- Admin users have full access to:
  - All assets, locations, and inventory
  - User management
  - System configuration
  - All countries (Lesotho, Zambia, Benin)

## Creating Additional Users

After logging in as admin:
1. Navigate to Admin → Users (or similar menu)
2. Create new users with appropriate roles:
   - **Admin**: Full access
   - **Manager**: Can manage assets and inventory
   - **Operator**: Can perform check-in/out and transactions
   - **Viewer**: Read-only access

## Password Reset

If the password needs to be reset:

```bash
# SSH into server
ssh -i 1pwrAM.pem ec2-user@16.28.64.221

# Generate new password hash
php -r "echo password_hash('NewPassword123!', PASSWORD_DEFAULT) . PHP_EOL;"

# Update in database
mysql -u asset_user -p onestop_asset_shop
UPDATE users SET password_hash = 'NEW_HASH_HERE' WHERE username = 'mso';
```
