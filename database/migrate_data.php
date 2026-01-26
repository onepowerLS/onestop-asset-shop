<?php
/**
 * Data Migration Script
 * 
 * Consolidates data from:
 * - Old npower5_asset_management database
 * - Google Sheets (CSV exports)
 * 
 * Prevents duplicates by checking:
 * - Serial number
 * - Asset tag
 * - Name + Manufacturer + Model combination
 */

require_once __DIR__ . '/../web/config/database.php';
require_once __DIR__ . '/migration_utils.php';

// Configuration
$old_db_host = getenv('OLD_DB_HOST') ?: 'localhost';
$old_db_name = getenv('OLD_DB_NAME') ?: 'npower5_asset_management';
$old_db_user = getenv('OLD_DB_USER') ?: 'root';
$old_db_pass = getenv('OLD_DB_PASS') ?: '';

$csv_directory = __DIR__ . '/csv_imports'; // Directory for Google Sheets CSV exports
$log_file = __DIR__ . '/migration_log.txt';

// Statistics
$stats = [
    'assets_imported' => 0,
    'assets_skipped_duplicate' => 0,
    'categories_created' => 0,
    'locations_created' => 0,
    'errors' => []
];

/**
 * Connect to old database (if needed)
 */
function connect_old_db() {
    global $old_db_host, $old_db_name, $old_db_user, $old_db_pass;
    
    try {
        $pdo = new PDO(
            "mysql:host={$old_db_host};dbname={$old_db_name};charset=utf8mb4",
            $old_db_user,
            $old_db_pass,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC
            ]
        );
        return $pdo;
    } catch (PDOException $e) {
        migration_log("ERROR: Cannot connect to old database: " . $e->getMessage());
        return null;
    }
}

/**
 * Check if asset is duplicate (extended version with name+manufacturer+model check)
 */
function is_duplicate_extended($pdo, $serial_number, $asset_tag, $name, $manufacturer, $model) {
    // Use base duplicate check
    if (is_asset_duplicate($pdo, $serial_number, $asset_tag)) {
        return true;
    }
    
    // Check by name + manufacturer + model (fuzzy match)
    if (!empty($name) && !empty($manufacturer) && !empty($model)) {
        $stmt = $pdo->prepare("
            SELECT asset_id FROM assets 
            WHERE name = ? AND manufacturer = ? AND model = ?
        ");
        $stmt->execute([$name, $manufacturer, $model]);
        if ($stmt->fetch()) {
            return true;
        }
    }
    
    return false;
}

// get_or_create_category() is now in migration_utils.php - no need to redeclare

/**
 * Import asset from old database
 */
function import_asset_from_old_db($pdo, $old_asset) {
    global $stats;
    
    // Check for duplicates
    $serial = $old_asset['serial_number'] ?? null;
    $tag = $old_asset['NewTagNumber'] ?? $old_asset['OldTagNumber'] ?? null;
    $name = $old_asset['name'] ?? '';
    $manufacturer = $old_asset['Manufacturer'] ?? '';
    $model = $old_asset['Model'] ?? '';
    
    if (is_duplicate_extended($pdo, $serial, $tag, $name, $manufacturer, $model)) {
        $stats['assets_skipped_duplicate']++;
        migration_log("SKIPPED (duplicate): $name (Serial: $serial, Tag: $tag)");
        return false;
    }
    
    // Detect country
    $location_str = $old_asset['location'] ?? '';
    $country_code = detect_country_from_location($location_str);
    
    // Get country_id
    $stmt = $pdo->prepare("SELECT country_id FROM countries WHERE country_code = ?");
    $stmt->execute([$country_code]);
    $country = $stmt->fetch();
    if (!$country) {
        $stats['errors'][] = "Country not found: $country_code";
        return false;
    }
    $country_id = $country['country_id'];
    
    // Get or create location
    $location_id = get_or_create_location($pdo, $location_str, $country_code);
    
    // Get or create category
    $category_id = null;
    if (!empty($old_asset['category_id'])) {
        // Try to get category from old system
        $category_id = $old_asset['category_id'];
    }
    
    // Map fields
    $status = map_old_status($old_asset['status'] ?? 'available');
    $condition = map_old_condition($old_asset['ConditionStatus'] ?? 'good');
    
    // Insert asset
    $stmt = $pdo->prepare("
        INSERT INTO assets (
            name, description, serial_number, manufacturer, model,
            purchase_date, purchase_price, current_value, warranty_expiry,
            condition_status, status, location_id, country_id,
            asset_tag, quantity, notes, created_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
    ");
    
    $notes = '';
    if (!empty($old_asset['Comments'])) {
        $notes .= "Comments: " . $old_asset['Comments'] . "\n";
    }
    if (!empty($old_asset['OldTagNumber']) && $old_asset['OldTagNumber'] != $tag) {
        $notes .= "Old Tag: " . $old_asset['OldTagNumber'] . "\n";
    }
    if (!empty($old_asset['AssignedTo'])) {
        $notes .= "Assigned To: " . $old_asset['AssignedTo'] . "\n";
    }
    
    // Format purchase date (handle Access date format: MM/DD/YY HH:MM:SS)
    $purchase_date = null;
    if (!empty($old_asset['purchase_date'])) {
        $date_str = trim($old_asset['purchase_date']);
        if ($date_str && $date_str !== '') {
            // Try to parse Access date format
            $date_obj = date_create_from_format('m/d/y H:i:s', $date_str);
            if (!$date_obj) {
                $date_obj = date_create_from_format('m/d/Y H:i:s', $date_str);
            }
            if (!$date_obj) {
                $date_obj = date_create($date_str);
            }
            if ($date_obj) {
                $purchase_date = $date_obj->format('Y-m-d');
            }
        }
    }
    
    $stmt->execute([
        $name,
        $old_asset['description'] ?? null,
        $serial,
        $manufacturer,
        $model,
        $purchase_date,
        $old_asset['PurchasePrice'] ?? null,
        $old_asset['CurrentValue'] ?? null,
        $old_asset['warranty_expiry'] ?? null,
        $condition,
        $status,
        $location_id,
        $country_id,
        !empty($tag) ? $tag : null,  // Set to NULL if empty to avoid duplicate constraint
        !empty($old_asset['Quantity']) && is_numeric($old_asset['Quantity']) ? intval($old_asset['Quantity']) : 1,
        !empty($notes) ? $notes : null,
    ]);
    
    $new_asset_id = $pdo->lastInsertId();
    
    // Generate QR code
    $qr_code_id = generate_qr_code_id($country_code, $new_asset_id);
    $stmt = $pdo->prepare("UPDATE assets SET qr_code_id = ? WHERE asset_id = ?");
    $stmt->execute([$qr_code_id, $new_asset_id]);
    
    $stats['assets_imported']++;
    migration_log("IMPORTED: $name (ID: $new_asset_id, QR: $qr_code_id)");
    
    return true;
}

/**
 * Import from CSV file (Google Sheets export)
 */
function import_from_csv($pdo, $csv_file, $category_type = 'General') {
    if (!file_exists($csv_file)) {
        migration_log("ERROR: CSV file not found: $csv_file");
        return;
    }
    
    $handle = fopen($csv_file, 'r');
    if (!$handle) {
        migration_log("ERROR: Cannot open CSV file: $csv_file");
        return;
    }
    
    // Read header row
    $headers = fgetcsv($handle);
    if (!$headers) {
        migration_log("ERROR: Empty CSV file: $csv_file");
        fclose($handle);
        return;
    }
    
    // Normalize headers (lowercase, remove spaces)
    $headers = array_map(function($h) {
        return strtolower(trim($h));
    }, $headers);
    
    $row_num = 1;
    while (($row = fgetcsv($handle)) !== false) {
        $row_num++;
        $data = array_combine($headers, $row);
        
        // Map CSV columns to asset fields (handle Access database field names)
        $asset = [
            'name' => $data['item'] ?? $data['name'] ?? $data['item name'] ?? $data['description'] ?? '',
            'description' => $data['description'] ?? $data['comments'] ?? $data['notes'] ?? null,
            'serial_number' => $data['serial_number'] ?? $data['serial number'] ?? $data['serial'] ?? null,
            'Manufacturer' => $data['manufacturer'] ?? $data['brand'] ?? null,
            'Model' => $data['model'] ?? null,
            'purchase_date' => $data['acquireddate'] ?? $data['purchase date'] ?? $data['date purchased'] ?? null,
            'PurchasePrice' => !empty($data['purchaseprice']) && floatval($data['purchaseprice']) > 0 ? $data['purchaseprice'] : (!empty($data['purchase price']) && floatval($data['purchase price']) > 0 ? $data['purchase price'] : (!empty($data['price']) && floatval($data['price']) > 0 ? $data['price'] : null)),
            'CurrentValue' => !empty($data['currentvalue']) && floatval($data['currentvalue']) > 0 ? $data['currentvalue'] : (!empty($data['current value']) && floatval($data['current value']) > 0 ? $data['current value'] : (!empty($data['value']) && floatval($data['value']) > 0 ? $data['value'] : null)),
            'location' => $data['location'] ?? $data['site'] ?? null,
            'status' => $data['availabilitystatus'] ?? $data['status'] ?? 'available',
            'ConditionStatus' => $data['conditionstatus'] ?? $data['condition'] ?? $data['condition status'] ?? 'good',
            'NewTagNumber' => $data['newtagnumber'] ?? $data['tag number'] ?? $data['tag'] ?? $data['asset tag'] ?? null,
            'OldTagNumber' => $data['oldtagnumber'] ?? $data['old tag'] ?? null,
            'Quantity' => !empty($data['quantity']) ? intval($data['quantity']) : 1,
            'Comments' => $data['comments'] ?? $data['notes'] ?? null,
            'AssignedTo' => $data['assignedto'] ?? $data['assigned to'] ?? null,
            'category_id' => null
        ];
        
        // Get or create category from CSV
        if (!empty($data['category'])) {
            $category_id = get_or_create_category($pdo, $data['category'], $category_type);
            $asset['category_id'] = $category_id;
        }
        
        import_asset_from_old_db($pdo, $asset);
    }
    
    fclose($handle);
    migration_log("Completed CSV import: $csv_file ($row_num rows processed)");
}

// Main execution
migration_log("=== Starting Data Migration ===");

// Step 1: Import from old database
migration_log("Step 1: Importing from old database...");
$old_pdo = connect_old_db();
if ($old_pdo) {
    try {
        $stmt = $old_pdo->query("SELECT * FROM assets");
        $old_assets = $stmt->fetchAll();
        
        migration_log("Found " . count($old_assets) . " assets in old database");
        
        foreach ($old_assets as $old_asset) {
            import_asset_from_old_db($pdo, $old_asset);
        }
    } catch (PDOException $e) {
        migration_log("ERROR: " . $e->getMessage());
        $stats['errors'][] = $e->getMessage();
    }
} else {
    migration_log("WARNING: Old database not accessible, skipping...");
}

// Step 2: Import from CSV files (Google Sheets)
migration_log("Step 2: Importing from CSV files...");
if (is_dir($csv_directory)) {
    $csv_files = glob($csv_directory . '/*.csv');
    foreach ($csv_files as $csv_file) {
        $filename = basename($csv_file);
        migration_log("Processing CSV: $filename");
        
        // Detect category type from filename
        $category_type = 'General';
        if (stripos($filename, 'ret') !== false) {
            $category_type = 'RET';
        } elseif (stripos($filename, 'fac') !== false) {
            $category_type = 'FAC';
        } elseif (stripos($filename, 'o&m') !== false || stripos($filename, 'om') !== false) {
            $category_type = 'O&M';
        } elseif (stripos($filename, 'meter') !== false) {
            $category_type = 'Meters';
        } elseif (stripos($filename, 'ready') !== false || stripos($filename, 'board') !== false) {
            $category_type = 'ReadyBoards';
        } elseif (stripos($filename, 'tool') !== false) {
            $category_type = 'Tools';
        }
        
        import_from_csv($pdo, $csv_file, $category_type);
    }
} else {
    migration_log("WARNING: CSV directory not found: $csv_directory");
    migration_log("Create this directory and place CSV exports from Google Sheets there");
}

// Step 3: Initialize inventory levels
migration_log("Step 3: Initializing inventory levels...");
try {
    $stmt = $pdo->query("
        INSERT INTO inventory_levels (asset_id, location_id, country_id, quantity_on_hand, last_counted_at)
        SELECT 
            asset_id,
            location_id,
            country_id,
            COALESCE(quantity, 1) as quantity_on_hand,
            NOW() as last_counted_at
        FROM assets
        WHERE status IN ('Available', 'Allocated')
        AND location_id IS NOT NULL
        ON DUPLICATE KEY UPDATE quantity_on_hand = VALUES(quantity_on_hand)
    ");
    migration_log("Inventory levels initialized");
} catch (PDOException $e) {
    migration_log("ERROR initializing inventory: " . $e->getMessage());
    $stats['errors'][] = $e->getMessage();
}

// Summary
migration_log("=== Migration Complete ===");
migration_log("Assets imported: " . $stats['assets_imported']);
migration_log("Assets skipped (duplicates): " . $stats['assets_skipped_duplicate']);
migration_log("Categories created: " . $stats['categories_created']);
migration_log("Locations created: " . $stats['locations_created']);
migration_log("Errors: " . count($stats['errors']));

if (!empty($stats['errors'])) {
    migration_log("Error details:");
    foreach ($stats['errors'] as $error) {
        migration_log("  - $error");
    }
}

echo "\n=== Migration Summary ===\n";
echo "Check migration_log.txt for detailed log\n";
echo "Assets imported: {$stats['assets_imported']}\n";
echo "Duplicates skipped: {$stats['assets_skipped_duplicate']}\n";
