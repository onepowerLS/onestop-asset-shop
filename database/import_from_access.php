<?php
/**
 * Import from Microsoft Access Database (.accdb)
 * 
 * This script can import data directly from an Access database file
 * Requires: ODBC drivers for Access (usually pre-installed on Windows)
 */

require_once __DIR__ . '/../web/config/database.php';
require_once __DIR__ . '/migration_utils.php';

// Configuration
$access_db_path = $_SERVER['argv'][1] ?? getenv('ACCESS_DB_PATH') ?: '';
$access_table = $_SERVER['argv'][2] ?? getenv('ACCESS_TABLE') ?: 'assets';

if (empty($access_db_path)) {
    echo "Usage: php import_from_access.php <path_to_database.accdb> [table_name]\n";
    echo "Example: php import_from_access.php C:/path/to/database.accdb assets\n\n";
    echo "Or set environment variables:\n";
    echo "  ACCESS_DB_PATH=C:/path/to/database.accdb\n";
    echo "  ACCESS_TABLE=assets\n";
    exit(1);
}

if (!file_exists($access_db_path)) {
    die("ERROR: Access database file not found: $access_db_path\n");
}

migration_log("=== Importing from Access Database ===");
migration_log("Database: $access_db_path");
migration_log("Table: $access_table");

// Statistics
$stats = [
    'assets_imported' => 0,
    'assets_skipped_duplicate' => 0,
    'assets_updated' => 0,
    'locations_created' => 0,
    'errors' => []
];

/**
 * Connect to Access database using ODBC
 */
function connect_to_access($db_path) {
    // Windows ODBC connection string for Access
    // Note: This works on Windows. For Linux, you'd need unixODBC and mdbtools
    $dsn = "Driver={Microsoft Access Driver (*.mdb, *.accdb)};Dbq=" . $db_path . ";";
    
    try {
        $pdo = new PDO("odbc:" . $dsn);
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        return $pdo;
    } catch (PDOException $e) {
        migration_log("ERROR: Cannot connect to Access database: " . $e->getMessage());
        migration_log("Make sure Microsoft Access Database Engine is installed");
        migration_log("Download: https://www.microsoft.com/en-us/download/details.aspx?id=54920");
        return null;
    }
}

/**
 * Map Access database fields to new schema
 */
function map_access_asset($access_row) {
    // Map field names (Access may have different names)
    $asset = [
        'name' => $access_row['name'] ?? $access_row['Name'] ?? $access_row['AssetName'] ?? '',
        'description' => $access_row['description'] ?? $access_row['Description'] ?? null,
        'serial_number' => $access_row['serial_number'] ?? $access_row['SerialNumber'] ?? $access_row['Serial_Number'] ?? null,
        'Manufacturer' => $access_row['Manufacturer'] ?? $access_row['manufacturer'] ?? null,
        'Model' => $access_row['Model'] ?? $access_row['model'] ?? null,
        'purchase_date' => $access_row['purchase_date'] ?? $access_row['PurchaseDate'] ?? $access_row['Purchase_Date'] ?? null,
        'PurchasePrice' => $access_row['PurchasePrice'] ?? $access_row['purchase_price'] ?? $access_row['Purchase_Price'] ?? null,
        'CurrentValue' => $access_row['CurrentValue'] ?? $access_row['current_value'] ?? $access_row['Current_Value'] ?? null,
        'warranty_expiry' => $access_row['warranty_expiry'] ?? $access_row['WarrantyExpiry'] ?? $access_row['Warranty_Expiry'] ?? null,
        'location' => $access_row['location'] ?? $access_row['Location'] ?? null,
        'status' => $access_row['status'] ?? $access_row['Status'] ?? null,
        'ConditionStatus' => $access_row['ConditionStatus'] ?? $access_row['condition_status'] ?? $access_row['Condition_Status'] ?? null,
        'NewTagNumber' => $access_row['NewTagNumber'] ?? $access_row['new_tag_number'] ?? $access_row['TagNumber'] ?? $access_row['Tag_Number'] ?? null,
        'OldTagNumber' => $access_row['OldTagNumber'] ?? $access_row['old_tag_number'] ?? null,
        'Quantity' => $access_row['Quantity'] ?? $access_row['quantity'] ?? 1,
        'Comments' => $access_row['Comments'] ?? $access_row['comments'] ?? $access_row['Notes'] ?? $access_row['notes'] ?? null,
        'AssignedTo' => $access_row['AssignedTo'] ?? $access_row['assigned_to'] ?? null,
        'Owner' => $access_row['Owner'] ?? $access_row['owner'] ?? null,
        'category_id' => null
    ];
    
    return $asset;
}

// Connect to Access database
$access_pdo = connect_to_access($access_db_path);
if (!$access_pdo) {
    exit(1);
}

// List available tables
migration_log("\nAvailable tables in Access database:");
try {
    $tables = $access_pdo->query("SELECT Name FROM MSysObjects WHERE Type=1 AND Flags=0")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($tables as $table) {
        migration_log("  - $table");
    }
} catch (PDOException $e) {
    migration_log("Could not list tables (may need admin permissions): " . $e->getMessage());
}

// Get data from Access database
migration_log("\nReading data from table: $access_table");
try {
    // Try to get all columns first to see what's available
    $stmt = $access_pdo->query("SELECT TOP 1 * FROM [$access_table]");
    $sample = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($sample) {
        migration_log("Found columns: " . implode(', ', array_keys($sample)));
    }
    
    // Get all records
    $stmt = $access_pdo->query("SELECT * FROM [$access_table]");
    $access_assets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    migration_log("Found " . count($access_assets) . " records in Access database");
    
    // Process each asset
    foreach ($access_assets as $access_row) {
        $asset = map_access_asset($access_row);
        
        // Check for duplicates
        $serial = $asset['serial_number'] ?? null;
        $tag = $asset['NewTagNumber'] ?? $asset['OldTagNumber'] ?? null;
        $name = $asset['name'] ?? '';
        
        if (empty($name)) {
            migration_log("SKIPPED: Empty asset name");
            continue;
        }
        
        // Check if asset already exists
        $existing = null;
        if (!empty($serial)) {
            $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE serial_number = ?");
            $stmt->execute([$serial]);
            $existing = $stmt->fetch();
        }
        
        if (!$existing && !empty($tag)) {
            $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE asset_tag = ?");
            $stmt->execute([$tag]);
            $existing = $stmt->fetch();
        }
        
        if ($existing) {
            // Update existing asset with Access data (which may be more complete)
            $asset_id = $existing['asset_id'];
            
            // Detect country
            $location_str = $asset['location'] ?? '';
            $country_code = detect_country_from_location($location_str);
            
            $stmt = $pdo->prepare("SELECT country_id FROM countries WHERE country_code = ?");
            $stmt->execute([$country_code]);
            $country = $stmt->fetch();
            if (!$country) {
                $stats['errors'][] = "Country not found: $country_code";
                continue;
            }
            $country_id = $country['country_id'];
            
            $location_id = get_or_create_location($pdo, $location_str, $country_code);
            $status = map_old_status($asset['status'] ?? 'available');
            $condition = map_old_condition($asset['ConditionStatus'] ?? 'good');
            
            // Build notes
            $notes = $asset['Comments'] ?? '';
            if (!empty($asset['OldTagNumber']) && $asset['OldTagNumber'] != $tag) {
                $notes .= "\nOld Tag: " . $asset['OldTagNumber'];
            }
            if (!empty($asset['AssignedTo'])) {
                $notes .= "\nAssigned To: " . $asset['AssignedTo'];
            }
            
            // Update asset with Access data
            $stmt = $pdo->prepare("
                UPDATE assets SET
                    name = ?, description = ?, serial_number = ?, manufacturer = ?, model = ?,
                    purchase_date = ?, purchase_price = ?, current_value = ?, warranty_expiry = ?,
                    condition_status = ?, status = ?, location_id = ?, country_id = ?,
                    asset_tag = ?, quantity = ?, notes = ?, updated_at = NOW()
                WHERE asset_id = ?
            ");
            
            $stmt->execute([
                $name,
                $asset['description'] ?? null,
                $serial,
                $asset['Manufacturer'] ?? null,
                $asset['Model'] ?? null,
                !empty($asset['purchase_date']) ? $asset['purchase_date'] : null,
                !empty($asset['PurchasePrice']) ? floatval($asset['PurchasePrice']) : null,
                !empty($asset['CurrentValue']) ? floatval($asset['CurrentValue']) : null,
                !empty($asset['warranty_expiry']) ? $asset['warranty_expiry'] : null,
                $condition,
                $status,
                $location_id,
                $country_id,
                $tag,
                !empty($asset['Quantity']) ? intval($asset['Quantity']) : 1,
                !empty($notes) ? $notes : null,
                $asset_id
            ]);
            
            $stats['assets_updated']++;
            migration_log("UPDATED: $name (ID: $asset_id) with Access data");
            
        } else {
            // Import as new asset (using existing import logic)
            import_asset_from_old_db($pdo, $asset);
            $stats['assets_imported']++;
        }
    }
    
} catch (PDOException $e) {
    migration_log("ERROR reading from Access database: " . $e->getMessage());
    $stats['errors'][] = $e->getMessage();
}

// Summary
migration_log("\n=== Import Complete ===");
migration_log("Assets imported (new): " . $stats['assets_imported']);
migration_log("Assets updated (existing): " . $stats['assets_updated']);
migration_log("Assets skipped (duplicates): " . $stats['assets_skipped_duplicate']);
migration_log("Locations created: " . $stats['locations_created']);
migration_log("Errors: " . count($stats['errors']));

if (!empty($stats['errors'])) {
    migration_log("\nError details:");
    foreach ($stats['errors'] as $error) {
        migration_log("  - $error");
    }
}
