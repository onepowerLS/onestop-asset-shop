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
 * Log message
 */
function log_message($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
    echo $log_entry;
}

/**
 * Connect to old database
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
        log_message("ERROR: Cannot connect to old database: " . $e->getMessage());
        return null;
    }
}

/**
 * Detect country from location string
 */
function detect_country($location) {
    $location_lower = strtolower($location ?? '');
    
    if (strpos($location_lower, 'zambia') !== false || strpos($location_lower, 'zmb') !== false) {
        return 'ZMB';
    }
    if (strpos($location_lower, 'benin') !== false || strpos($location_lower, 'ben') !== false) {
        return 'BEN';
    }
    // Default to Lesotho
    return 'LSO';
}

/**
 * Map old status to new status
 */
function map_status($old_status) {
    $status_map = [
        'available' => 'Available',
        'unallocated' => 'Available',
        'allocated' => 'Allocated',
        'checked out' => 'CheckedOut',
        'checkout' => 'CheckedOut',
        'missing' => 'Missing',
        'written off' => 'WrittenOff',
        'write-off' => 'WrittenOff',
        'retired' => 'Retired'
    ];
    
    $old_lower = strtolower($old_status ?? 'available');
    return $status_map[$old_lower] ?? 'Available';
}

/**
 * Map old condition to new condition
 */
function map_condition($old_condition) {
    $condition_map = [
        'new' => 'New',
        'good' => 'Good',
        'fair' => 'Fair',
        'poor' => 'Poor',
        'damaged' => 'Damaged',
        'retired' => 'Retired'
    ];
    
    $old_lower = strtolower($old_condition ?? 'good');
    return $condition_map[$old_lower] ?? 'Good';
}

/**
 * Check if asset is duplicate
 */
function is_duplicate($pdo, $serial_number, $asset_tag, $name, $manufacturer, $model) {
    // Check by serial number
    if (!empty($serial_number)) {
        $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE serial_number = ?");
        $stmt->execute([$serial_number]);
        if ($stmt->fetch()) {
            return true;
        }
    }
    
    // Check by asset tag
    if (!empty($asset_tag)) {
        $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE asset_tag = ?");
        $stmt->execute([$asset_tag]);
        if ($stmt->fetch()) {
            return true;
        }
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

/**
 * Get or create location
 */
function get_or_create_location($pdo, $location_name, $country_code) {
    if (empty($location_name)) {
        return null;
    }
    
    // Get country_id
    $stmt = $pdo->prepare("SELECT country_id FROM countries WHERE country_code = ?");
    $stmt->execute([$country_code]);
    $country = $stmt->fetch();
    if (!$country) {
        return null;
    }
    $country_id = $country['country_id'];
    
    // Check if location exists
    $stmt = $pdo->prepare("
        SELECT location_id FROM locations 
        WHERE location_name = ? AND country_id = ?
    ");
    $stmt->execute([$location_name, $country_id]);
    $location = $stmt->fetch();
    
    if ($location) {
        return $location['location_id'];
    }
    
    // Create new location
    $location_code = strtoupper($country_code) . '-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $location_name), 0, 3)) . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    
    $stmt = $pdo->prepare("
        INSERT INTO locations (country_id, location_code, location_name, location_type, active)
        VALUES (?, ?, ?, 'Site', 1)
    ");
    $stmt->execute([$country_id, $location_code, $location_name]);
    
    global $stats;
    $stats['locations_created']++;
    log_message("Created location: $location_name ($location_code)");
    
    return $pdo->lastInsertId();
}

/**
 * Get or create category
 */
function get_or_create_category($pdo, $category_name, $category_type = 'General') {
    if (empty($category_name)) {
        return null;
    }
    
    // Check if category exists
    $stmt = $pdo->prepare("
        SELECT category_id FROM categories 
        WHERE category_name = ? AND category_type = ?
    ");
    $stmt->execute([$category_name, $category_type]);
    $category = $stmt->fetch();
    
    if ($category) {
        return $category['category_id'];
    }
    
    // Create new category
    $category_code = strtoupper(substr($category_type, 0, 3)) . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    
    $stmt = $pdo->prepare("
        INSERT INTO categories (category_code, category_name, category_type, active)
        VALUES (?, ?, ?, 1)
    ");
    $stmt->execute([$category_code, $category_name, $category_type]);
    
    global $stats;
    $stats['categories_created']++;
    log_message("Created category: $category_name ($category_code)");
    
    return $pdo->lastInsertId();
}

/**
 * Generate QR code ID
 */
function generate_qr_code_id($country_code, $asset_id) {
    return '1PWR-' . strtoupper($country_code) . '-' . str_pad($asset_id, 6, '0', STR_PAD_LEFT);
}

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
    
    if (is_duplicate($pdo, $serial, $tag, $name, $manufacturer, $model)) {
        $stats['assets_skipped_duplicate']++;
        log_message("SKIPPED (duplicate): $name (Serial: $serial, Tag: $tag)");
        return false;
    }
    
    // Detect country
    $location_str = $old_asset['location'] ?? '';
    $country_code = detect_country($location_str);
    
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
    $status = map_status($old_asset['status'] ?? 'available');
    $condition = map_condition($old_asset['ConditionStatus'] ?? 'good');
    
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
    
    $stmt->execute([
        $name,
        $old_asset['description'] ?? null,
        $serial,
        $manufacturer,
        $model,
        $old_asset['purchase_date'] ?? null,
        $old_asset['PurchasePrice'] ?? null,
        $old_asset['CurrentValue'] ?? null,
        $old_asset['warranty_expiry'] ?? null,
        $condition,
        $status,
        $location_id,
        $country_id,
        $tag,
        $old_asset['Quantity'] ?? 1,
        !empty($notes) ? $notes : null,
    ]);
    
    $new_asset_id = $pdo->lastInsertId();
    
    // Generate QR code
    $qr_code_id = generate_qr_code_id($country_code, $new_asset_id);
    $stmt = $pdo->prepare("UPDATE assets SET qr_code_id = ? WHERE asset_id = ?");
    $stmt->execute([$qr_code_id, $new_asset_id]);
    
    $stats['assets_imported']++;
    log_message("IMPORTED: $name (ID: $new_asset_id, QR: $qr_code_id)");
    
    return true;
}

/**
 * Import from CSV file (Google Sheets export)
 */
function import_from_csv($pdo, $csv_file, $category_type = 'General') {
    if (!file_exists($csv_file)) {
        log_message("ERROR: CSV file not found: $csv_file");
        return;
    }
    
    $handle = fopen($csv_file, 'r');
    if (!$handle) {
        log_message("ERROR: Cannot open CSV file: $csv_file");
        return;
    }
    
    // Read header row
    $headers = fgetcsv($handle);
    if (!$headers) {
        log_message("ERROR: Empty CSV file: $csv_file");
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
        
        // Map CSV columns to asset fields
        $asset = [
            'name' => $data['name'] ?? $data['item name'] ?? $data['description'] ?? '',
            'description' => $data['description'] ?? $data['notes'] ?? null,
            'serial_number' => $data['serial number'] ?? $data['serial'] ?? null,
            'Manufacturer' => $data['manufacturer'] ?? $data['brand'] ?? null,
            'Model' => $data['model'] ?? null,
            'purchase_date' => $data['purchase date'] ?? $data['date purchased'] ?? null,
            'PurchasePrice' => $data['purchase price'] ?? $data['price'] ?? null,
            'CurrentValue' => $data['current value'] ?? $data['value'] ?? null,
            'location' => $data['location'] ?? $data['site'] ?? null,
            'status' => $data['status'] ?? 'available',
            'ConditionStatus' => $data['condition'] ?? $data['condition status'] ?? 'good',
            'NewTagNumber' => $data['tag number'] ?? $data['tag'] ?? $data['asset tag'] ?? null,
            'Quantity' => $data['quantity'] ?? $data['qty'] ?? 1,
            'Comments' => $data['comments'] ?? $data['notes'] ?? null,
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
    log_message("Completed CSV import: $csv_file ($row_num rows processed)");
}

// Main execution
log_message("=== Starting Data Migration ===");

// Step 1: Import from old database
log_message("Step 1: Importing from old database...");
$old_pdo = connect_old_db();
if ($old_pdo) {
    try {
        $stmt = $old_pdo->query("SELECT * FROM assets");
        $old_assets = $stmt->fetchAll();
        
        log_message("Found " . count($old_assets) . " assets in old database");
        
        foreach ($old_assets as $old_asset) {
            import_asset_from_old_db($pdo, $old_asset);
        }
    } catch (PDOException $e) {
        log_message("ERROR: " . $e->getMessage());
        $stats['errors'][] = $e->getMessage();
    }
} else {
    log_message("WARNING: Old database not accessible, skipping...");
}

// Step 2: Import from CSV files (Google Sheets)
log_message("Step 2: Importing from CSV files...");
if (is_dir($csv_directory)) {
    $csv_files = glob($csv_directory . '/*.csv');
    foreach ($csv_files as $csv_file) {
        $filename = basename($csv_file);
        log_message("Processing CSV: $filename");
        
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
    log_message("WARNING: CSV directory not found: $csv_directory");
    log_message("Create this directory and place CSV exports from Google Sheets there");
}

// Step 3: Initialize inventory levels
log_message("Step 3: Initializing inventory levels...");
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
    log_message("Inventory levels initialized");
} catch (PDOException $e) {
    log_message("ERROR initializing inventory: " . $e->getMessage());
    $stats['errors'][] = $e->getMessage();
}

// Summary
log_message("=== Migration Complete ===");
log_message("Assets imported: " . $stats['assets_imported']);
log_message("Assets skipped (duplicates): " . $stats['assets_skipped_duplicate']);
log_message("Categories created: " . $stats['categories_created']);
log_message("Locations created: " . $stats['locations_created']);
log_message("Errors: " . count($stats['errors']));

if (!empty($stats['errors'])) {
    log_message("Error details:");
    foreach ($stats['errors'] as $error) {
        log_message("  - $error");
    }
}

echo "\n=== Migration Summary ===\n";
echo "Check migration_log.txt for detailed log\n";
echo "Assets imported: {$stats['assets_imported']}\n";
echo "Duplicates skipped: {$stats['assets_skipped_duplicate']}\n";
