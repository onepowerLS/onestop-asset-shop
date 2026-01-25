<?php
/**
 * Migration from SQL Dump File
 * 
 * Imports assets from npower5_asset_management.sql dump file
 * Prevents duplicates by checking serial numbers and asset tags
 */

require_once __DIR__ . '/../web/config/database.php';
require_once __DIR__ . '/migration_utils.php';

// Try multiple possible locations for SQL dump
$possible_paths = [
    '/tmp/npower5_asset_management.sql',
    __DIR__ . '/../../../../Downloads/npower5_asset_management.sql',
    __DIR__ . '/npower5_asset_management.sql'
];

$sql_dump_file = null;
foreach ($possible_paths as $path) {
    if (file_exists($path)) {
        $sql_dump_file = $path;
        break;
    }
}

if (!$sql_dump_file) {
    die("ERROR: SQL dump file not found. Tried:\n" . implode("\n", $possible_paths) . "\n");
}

$log_file = __DIR__ . '/migration_log.txt';

$stats = [
    'assets_imported' => 0,
    'assets_skipped_duplicate' => 0,
    'locations_created' => 0,
    'errors' => []
];

// Functions moved to migration_utils.php

/**
 * Parse SQL INSERT statement and extract values
 */
function parse_insert_statement($sql_line) {
    // Match INSERT INTO `assets` (...) VALUES (...)
    if (preg_match('/INSERT INTO `assets`\s*\(([^)]+)\)\s*VALUES\s*\(([^)]+)\)/i', $sql_line, $matches)) {
        $columns = array_map('trim', explode(',', str_replace('`', '', $matches[1])));
        $values_str = $matches[2];
        
        // Parse values (handle NULL, strings, numbers)
        $values = [];
        $current = '';
        $in_quotes = false;
        $quote_char = null;
        
        for ($i = 0; $i < strlen($values_str); $i++) {
            $char = $values_str[$i];
            
            if (($char === '"' || $char === "'") && ($i === 0 || $values_str[$i-1] !== '\\')) {
                if (!$in_quotes) {
                    $in_quotes = true;
                    $quote_char = $char;
                } elseif ($char === $quote_char) {
                    $in_quotes = false;
                    $quote_char = null;
                }
                $current .= $char;
            } elseif ($char === ',' && !$in_quotes) {
                $values[] = trim($current);
                $current = '';
            } else {
                $current .= $char;
            }
        }
        if (!empty($current)) {
            $values[] = trim($current);
        }
        
        // Clean values (remove quotes, handle NULL)
        $cleaned_values = [];
        foreach ($values as $val) {
            $val = trim($val);
            if (strtoupper($val) === 'NULL') {
                $cleaned_values[] = null;
            } else {
                $cleaned_values[] = trim($val, "'\"");
            }
        }
        
        return array_combine($columns, $cleaned_values);
    }
    return null;
}

// Main execution
migration_log("=== Starting Migration from SQL Dump ===");
migration_log("Reading SQL dump: $sql_dump_file");

if (!file_exists($sql_dump_file)) {
    die("ERROR: SQL dump file not found: $sql_dump_file\n");
}

$sql_content = file_get_contents($sql_dump_file);

// Extract all INSERT statements for assets (handle multi-line)
preg_match_all('/INSERT INTO `assets`[^;]*;/is', $sql_content, $matches);

migration_log("Found " . count($matches[0]) . " INSERT statements");

$imported_count = 0;
foreach ($matches[0] as $insert_stmt) {
    // Remove newlines and extra spaces
    $insert_stmt = preg_replace('/\s+/', ' ', trim($insert_stmt));
    
    // Parse the INSERT statement
    $asset_data = parse_insert_statement($insert_stmt);
    
    if (!$asset_data) {
        log_message("WARNING: Could not parse INSERT statement");
        continue;
    }
    
    // Map old field names to new structure
    $serial = $asset_data['serial_number'] ?? null;
    $tag = $asset_data['NewTagNumber'] ?? $asset_data['OldTagNumber'] ?? null;
    
    // Check for duplicates
    if (is_asset_duplicate($pdo, $serial, $tag)) {
        $stats['assets_skipped_duplicate']++;
        migration_log("SKIPPED (duplicate): " . ($asset_data['name'] ?? 'Unknown') . " (Serial: $serial, Tag: $tag)");
        continue;
    }
    
    // Detect country from location
    $location_str = $asset_data['location'] ?? '';
    $country_code = detect_country_from_location($location_str);
    
    // Get country_id
    $stmt = $pdo->prepare("SELECT country_id FROM countries WHERE country_code = ?");
    $stmt->execute([$country_code]);
    $country = $stmt->fetch();
    if (!$country) {
        $stats['errors'][] = "Country not found: $country_code";
        continue;
    }
    $country_id = $country['country_id'];
    
    // Get or create location
    $location_id = get_or_create_location($pdo, $location_str, $country_code);
    
    // Map status and condition
    $status = map_old_status($asset_data['status'] ?? 'available');
    $condition = map_old_condition($asset_data['ConditionStatus'] ?? 'good');
    
    // Build notes
    $notes = '';
    if (!empty($asset_data['Comments'])) {
        $notes .= "Comments: " . $asset_data['Comments'] . "\n";
    }
    if (!empty($asset_data['OldTagNumber']) && $asset_data['OldTagNumber'] != $tag) {
        $notes .= "Old Tag: " . $asset_data['OldTagNumber'] . "\n";
    }
    if (!empty($asset_data['AssignedTo'])) {
        $notes .= "Assigned To: " . $asset_data['AssignedTo'] . "\n";
    }
    
    // Insert asset
    try {
        $stmt = $pdo->prepare("
            INSERT INTO assets (
                name, description, serial_number, manufacturer, model,
                purchase_date, purchase_price, current_value, warranty_expiry,
                condition_status, status, location_id, country_id,
                asset_tag, quantity, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        
        $stmt->execute([
            $asset_data['name'] ?? '',
            $asset_data['description'] ?? null,
            $serial,
            $asset_data['Manufacturer'] ?? null,
            $asset_data['Model'] ?? null,
            !empty($asset_data['purchase_date']) && $asset_data['purchase_date'] !== 'NULL' ? $asset_data['purchase_date'] : null,
            !empty($asset_data['PurchasePrice']) && $asset_data['PurchasePrice'] !== 'NULL' ? $asset_data['PurchasePrice'] : null,
            !empty($asset_data['CurrentValue']) && $asset_data['CurrentValue'] !== 'NULL' ? $asset_data['CurrentValue'] : null,
            !empty($asset_data['warranty_expiry']) && $asset_data['warranty_expiry'] !== 'NULL' ? $asset_data['warranty_expiry'] : null,
            $condition,
            $status,
            $location_id,
            $country_id,
            $tag,
            !empty($asset_data['Quantity']) && $asset_data['Quantity'] !== 'NULL' ? intval($asset_data['Quantity']) : 1,
            !empty($notes) ? $notes : null,
        ]);
        
        $new_asset_id = $pdo->lastInsertId();
        $qr_code_id = generate_qr_code_id($country_code, $new_asset_id);
        
        $stmt = $pdo->prepare("UPDATE assets SET qr_code_id = ? WHERE asset_id = ?");
        $stmt->execute([$qr_code_id, $new_asset_id]);
        
        $stats['assets_imported']++;
        migration_log("IMPORTED: " . ($asset_data['name'] ?? 'Unknown') . " (ID: $new_asset_id, QR: $qr_code_id)");
        
    } catch (PDOException $e) {
        migration_log("ERROR importing asset: " . $e->getMessage());
        $stats['errors'][] = $e->getMessage();
    }
}

// Initialize inventory levels
migration_log("Initializing inventory levels...");
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
    migration_log("Inventory levels initialized");
} catch (PDOException $e) {
    migration_log("ERROR initializing inventory: " . $e->getMessage());
    $stats['errors'][] = $e->getMessage();
}

migration_log("=== Migration Complete ===");
migration_log("Assets imported: " . $stats['assets_imported']);
migration_log("Assets skipped (duplicates): " . $stats['assets_skipped_duplicate']);
migration_log("Locations created: " . $stats['locations_created']);
migration_log("Errors: " . count($stats['errors']));

echo "\n=== Summary ===\n";
echo "Assets imported: {$stats['assets_imported']}\n";
echo "Duplicates skipped: {$stats['assets_skipped_duplicate']}\n";
echo "Check migration_log.txt for details\n";
