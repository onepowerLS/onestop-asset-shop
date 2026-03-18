# HR Portal SSO Integration

## Installation Steps

### 1. Install JWT Package

```bash
composer require firebase/php-jwt
```

### 2. Add Environment Variables

Add to `.env`:

```env
FIREBASE_PROJECT_ID=pr-system-4ea55
```

### 3. Copy Middleware

Copy `FirebaseSSO.php` to `app/Http/Middleware/FirebaseSSO.php`

### 4. Register Middleware

Edit `app/Http/Kernel.php`:

```php
protected $middlewareGroups = [
    'web' => [
        // ... existing middleware
        \App\Http\Middleware\FirebaseSSO::class,
    ],
];
```

### 5. Run Migration

Create and run the migration to add `firebase_uid` column:

```bash
php artisan make:migration add_firebase_uid_to_users_table
# Copy content from add_firebase_uid_migration.php
php artisan migrate
```

### 6. Update User Model (Optional)

Add to `app/Models/User.php`:

```php
protected $fillable = [
    // ... existing fields
    'firebase_uid',
    'last_login_at',
];

protected $casts = [
    // ... existing casts
    'last_login_at' => 'datetime',
];
```

## Testing

1. Log into Nexus at hub.1pwrafrica.com
2. Click on "HR Portal" tile
3. You should be redirected to nexus.1pwrafrica.com and logged in automatically
4. Check Laravel logs for SSO debug messages

## Troubleshooting

### "User not found in HR Portal"
- Ensure the user exists in the HR Portal MySQL database
- Email addresses must match exactly

### "Firebase token validation failed"
- Check that FIREBASE_PROJECT_ID is correct
- Ensure server time is synchronized (NTP)

### "Token expired"
- SSO tokens are valid for 5 minutes
- Ensure there's no significant clock drift
