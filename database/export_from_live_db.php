<?php
/**
 * Export from Live Database
 * 
 * Connects to the live/production database and exports all assets
 * Use this if the SQL dump doesn't have all 1600 records
 */

require_once __DIR__ . '/../web/config/database.php';

// Configuration for OLD database
$old_db_config = [
    'host' => getenv('OLD_DB_HOST') ?: 'localhost',
    'name' => getenv('OLD_DB_NAME') ?: 'npower5_asset_management',
    'user' => getenv('OLD_DB_USER') ?: 'root',
    'pass' => getenv('OLD_DB_PASS') ?: ''
];

echo "=== Export from Live Database ===\n";
echo "This script will connect to the OLD database and export all assets.\n";
echo "Make sure the old database credentials are set in .env:\n";
echo "  OLD_DB_HOST=hostname\n";
echo "  OLD_DB_NAME=npower5_asset_management\n";
echo "  OLD_DB_USER=username\n";
echo "  OLD_DB_PASS=password\n\n";

// Try to connect to old database
try {
    $old_pdo = new PDO(
        "mysql:host={$old_db_config['host']};dbname={$old_db_config['name']};charset=utf8mb4",
        $old_db_config['user'],
        $old_db_config['pass'],
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
        ]
    );
    
    echo "✅ Connected to old database\n";
    
    // Count total assets
    $stmt = $old_pdo->query("SELECT COUNT(*) as total FROM assets");
    $count = $stmt->fetch()['total'];
    echo "Found $count assets in old database\n\n";
    
    if ($count == 0) {
        echo "No assets found. Check database connection.\n";
        exit(1);
    }
    
    // Now use the migration script to import directly
    require_once __DIR__ . '/migration_utils.php';
    require_once __DIR__ . '/migrate_data.php';
    
    echo "\nStarting import from live database...\n";
    
    $old_assets = $old_pdo->query("SELECT * FROM assets")->fetchAll();
    
    global $stats;
    $stats = [
        'assets_imported' => 0,
        'assets_skipped_duplicate' => 0,
        'categories_created' => 0,
        'locations_created' => 0,
        'errors' => []
    ];
    
    foreach ($old_assets as $old_asset) {
        import_asset_from_old_db($pdo, $old_asset);
    }
    
    echo "\n=== Import Complete ===\n";
    echo "Assets imported: {$stats['assets_imported']}\n";
    echo "Duplicates skipped: {$stats['assets_skipped_duplicate']}\n";
    echo "Locations created: {$stats['locations_created']}\n";
    
} catch (PDOException $e) {
    echo "❌ ERROR: Cannot connect to old database\n";
    echo "Error: " . $e->getMessage() . "\n\n";
    echo "Options:\n";
    echo "1. Set database credentials in .env file\n";
    echo "2. Or provide a fresh SQL dump with all 1600 records\n";
    echo "3. Or export from cPanel/phpMyAdmin\n";
    exit(1);
}
