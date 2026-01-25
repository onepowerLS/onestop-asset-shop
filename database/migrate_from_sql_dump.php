<?php
/**
 * Migration from SQL Dump File
 * 
 * Imports assets from npower5_asset_management.sql dump file
 * Prevents duplicates by checking serial numbers and asset tags
 */

require_once __DIR__ . '/../web/config/database.php';

$sql_dump_file = __DIR__ . '/../../../../Downloads/npower5_asset_management.sql';
$log_file = __DIR__ . '/migration_log.txt';

$stats = [
    'assets_imported' => 0,
    'assets_skipped_duplicate' => 0,
    'locations_created' => 0,
    'errors' => []
];

function log_message($message) {
    global $log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
    echo $log_entry;
}

function detect_country($location) {
    $location_lower = strtolower($location ?? '');
    if (strpos($location_lower, 'zambia') !== false || strpos($location_lower, 'zmb') !== false) {
        return 'ZMB';
    }
    if (strpos($location_lower, 'benin') !== false || strpos($location_lower, 'ben') !== false) {
        return 'BEN';
    }
    return 'LSO';
}

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

function is_duplicate($pdo, $serial_number, $asset_tag) {
    if (!empty($serial_number)) {
        $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE serial_number = ?");
        $stmt->execute([$serial_number]);
        if ($stmt->fetch()) {
            return true;
        }
    }
    if (!empty($asset_tag)) {
        $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE asset_tag = ?");
        $stmt->execute([$asset_tag]);
        if ($stmt->fetch()) {
            return true;
        }
    }
    return false;
}

function get_or_create_location($pdo, $location_name, $country_code) {
    if (empty($location_name)) {
        return null;
    }
    
    $stmt = $pdo->prepare("SELECT country_id FROM countries WHERE country_code = ?");
    $stmt->execute([$country_code]);
    $country = $stmt->fetch();
    if (!$country) {
        return null;
    }
    $country_id = $country['country_id'];
    
    $stmt = $pdo->prepare("SELECT location_id FROM locations WHERE location_name = ? AND country_id = ?");
    $stmt->execute([$location_name, $country_id]);
    $location = $stmt->fetch();
    
    if ($location) {
        return $location['location_id'];
    }
    
    $location_code = strtoupper($country_code) . '-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $location_name), 0, 3)) . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    
    $stmt = $pdo->prepare("INSERT INTO locations (country_id, location_code, location_name, location_type, active) VALUES (?, ?, ?, 'Site', 1)");
    $stmt->execute([$country_id, $location_code, $location_name]);
    
    global $stats;
    $stats['locations_created']++;
    log_message("Created location: $location_name ($location_code)");
    
    return $pdo->lastInsertId();
}

function generate_qr_code_id($country_code, $asset_id) {
    return '1PWR-' . strtoupper($country_code) . '-' . str_pad($asset_id, 6, '0', STR_PAD_LEFT);
}

// Parse SQL dump file
log_message("=== Starting Migration from SQL Dump ===");
log_message("Reading SQL dump: $sql_dump_file");

if (!file_exists($sql_dump_file)) {
    die("ERROR: SQL dump file not found: $sql_dump_file\n");
}

$sql_content = file_get_contents($sql_dump_file);

// Extract INSERT statements for assets table
preg_match_all("/INSERT INTO `assets`[^;]+;/i", $sql_content, $matches);

if (empty($matches[0])) {
    log_message("WARNING: No INSERT statements found in SQL dump");
    log_message("Attempting to create temporary database and import...");
    
    // Alternative: Create temp database and import
    try {
        $temp_pdo = new PDO("mysql:host=localhost;charset=utf8mb4", 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
        $temp_pdo->exec("CREATE DATABASE IF NOT EXISTS temp_migration");
        $temp_pdo->exec("USE temp_migration");
        
        // Import SQL dump
        $temp_pdo->exec($sql_content);
        
        // Read from temp database
        $stmt = $temp_pdo->query("SELECT * FROM assets");
        $old_assets = $stmt->fetchAll();
        
        log_message("Found " . count($old_assets) . " assets in SQL dump");
        
        foreach ($old_assets as $old_asset) {
            $serial = $old_asset['serial_number'] ?? null;
            $tag = $old_asset['NewTagNumber'] ?? $old_asset['OldTagNumber'] ?? null;
            
            if (is_duplicate($pdo, $serial, $tag)) {
                $stats['assets_skipped_duplicate']++;
                log_message("SKIPPED (duplicate): " . ($old_asset['name'] ?? 'Unknown') . " (Serial: $serial, Tag: $tag)");
                continue;
            }
            
            $location_str = $old_asset['location'] ?? '';
            $country_code = detect_country($location_str);
            
            $stmt = $pdo->prepare("SELECT country_id FROM countries WHERE country_code = ?");
            $stmt->execute([$country_code]);
            $country = $stmt->fetch();
            if (!$country) {
                $stats['errors'][] = "Country not found: $country_code";
                continue;
            }
            $country_id = $country['country_id'];
            
            $location_id = get_or_create_location($pdo, $location_str, $country_code);
            
            $status = map_status($old_asset['status'] ?? 'available');
            $condition = map_condition($old_asset['ConditionStatus'] ?? 'good');
            
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
            
            $stmt = $pdo->prepare("
                INSERT INTO assets (
                    name, description, serial_number, manufacturer, model,
                    purchase_date, purchase_price, current_value, warranty_expiry,
                    condition_status, status, location_id, country_id,
                    asset_tag, quantity, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
            ");
            
            $stmt->execute([
                $old_asset['name'] ?? '',
                $old_asset['description'] ?? null,
                $serial,
                $old_asset['Manufacturer'] ?? null,
                $old_asset['Model'] ?? null,
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
            $qr_code_id = generate_qr_code_id($country_code, $new_asset_id);
            
            $stmt = $pdo->prepare("UPDATE assets SET qr_code_id = ? WHERE asset_id = ?");
            $stmt->execute([$qr_code_id, $new_asset_id]);
            
            $stats['assets_imported']++;
            log_message("IMPORTED: " . ($old_asset['name'] ?? 'Unknown') . " (ID: $new_asset_id, QR: $qr_code_id)");
        }
        
        // Cleanup
        $temp_pdo->exec("DROP DATABASE temp_migration");
        
    } catch (PDOException $e) {
        log_message("ERROR: " . $e->getMessage());
        $stats['errors'][] = $e->getMessage();
    }
} else {
    log_message("Found " . count($matches[0]) . " INSERT statements");
    // Parse and process INSERT statements
    // (This would require more complex parsing)
}

// Initialize inventory
log_message("Initializing inventory levels...");
try {
    $stmt = $pdo->query("
        INSERT INTO inventory_levels (asset_id, location_id, country_id, quantity_on_hand, last_counted_at)
        SELECT 
            asset_id, location_id, country_id,
            COALESCE(quantity, 1) as quantity_on_hand,
            NOW() as last_counted_at
        FROM assets
        WHERE status IN ('Available', 'Allocated')
        AND location_id IS NOT NULL
        ON DUPLICATE KEY UPDATE quantity_on_hand = VALUES(quantity_on_hand)
    ");
    log_message("Inventory levels initialized");
} catch (PDOException $e) {
    log_message("ERROR: " . $e->getMessage());
}

log_message("=== Migration Complete ===");
log_message("Assets imported: " . $stats['assets_imported']);
log_message("Assets skipped (duplicates): " . $stats['assets_skipped_duplicate']);
log_message("Locations created: " . $stats['locations_created']);
log_message("Errors: " . count($stats['errors']));

echo "\n=== Summary ===\n";
echo "Assets imported: {$stats['assets_imported']}\n";
echo "Duplicates skipped: {$stats['assets_skipped_duplicate']}\n";
echo "Check migration_log.txt for details\n";
