<?php
/**
 * Import from Microsoft Access Database (.accdb) on Linux
 * 
 * Uses mdbtools to export Access database to CSV, then imports
 * Requires: mdbtools installed (sudo dnf install mdbtools)
 */

require_once __DIR__ . '/../web/config/database.php';
require_once __DIR__ . '/migration_utils.php';

// Configuration
$access_db_path = $_SERVER['argv'][1] ?? '/tmp/access_import.accdb';
$table_name = $_SERVER['argv'][2] ?? null;

if (!file_exists($access_db_path)) {
    die("ERROR: Access database file not found: $access_db_path\n");
}

migration_log("=== Importing from Access Database (Linux) ===");
migration_log("Database: $access_db_path");

// Check if mdbtools is available
$mdb_tables = shell_exec("which mdb-tables");
if (empty($mdb_tables)) {
    die("ERROR: mdbtools not installed. Run: sudo dnf install mdbtools\n");
}

// List tables
migration_log("\nListing tables in Access database:");
$tables_output = shell_exec("mdb-tables '$access_db_path' 2>&1");
$tables = array_filter(array_map('trim', explode("\n", $tables_output)));
migration_log("Found tables: " . implode(', ', $tables));

if (empty($tables)) {
    die("ERROR: No tables found in Access database\n");
}

// If table name not specified, use first table or look for 'assets' table
if (empty($table_name)) {
    if (in_array('assets', $tables)) {
        $table_name = 'assets';
    } else {
        $table_name = $tables[0];
    }
    migration_log("Using table: $table_name");
}

if (!in_array($table_name, $tables)) {
    die("ERROR: Table '$table_name' not found. Available: " . implode(', ', $tables) . "\n");
}

// Export table to CSV
$csv_file = '/tmp/access_export_' . $table_name . '.csv';
migration_log("\nExporting table '$table_name' to CSV...");
$export_cmd = "mdb-export '$access_db_path' '$table_name' > '$csv_file' 2>&1";
$export_output = shell_exec($export_cmd);

if (!file_exists($csv_file) || filesize($csv_file) == 0) {
    die("ERROR: Failed to export table. Output: $export_output\n");
}

$row_count = count(file($csv_file)) - 1; // Subtract header
migration_log("Exported $row_count rows to: $csv_file");

// Show first few lines to understand structure
migration_log("\nFirst 3 lines of exported CSV:");
$lines = file($csv_file);
for ($i = 0; $i < min(3, count($lines)); $i++) {
    migration_log("  " . trim($lines[$i]));
}

// Statistics
$stats = [
    'assets_imported' => 0,
    'assets_updated' => 0,
    'assets_skipped' => 0,
    'errors' => []
];

// Read CSV and import
migration_log("\n=== Starting Import ===");
$handle = fopen($csv_file, 'r');
if (!$handle) {
    die("ERROR: Cannot open CSV file: $csv_file\n");
}

// Read header
$headers = fgetcsv($handle);
if (!$headers) {
    die("ERROR: Empty CSV file\n");
}

// Normalize headers
$headers = array_map(function($h) {
    return strtolower(trim($h));
}, $headers);

migration_log("CSV columns: " . implode(', ', $headers));

$row_num = 0;
while (($row = fgetcsv($handle)) !== false) {
    $row_num++;
    if ($row_num % 100 == 0) {
        migration_log("Processing row $row_num...");
    }
    
    // Map row to associative array
    $access_row = array_combine($headers, $row);
    
    // Map Access fields to our schema
    $asset = [
        'name' => $access_row['name'] ?? $access_row['assetname'] ?? $access_row['item'] ?? '',
        'description' => $access_row['description'] ?? $access_row['item description'] ?? null,
        'serial_number' => $access_row['serial_number'] ?? $access_row['serialnumber'] ?? $access_row['serial'] ?? null,
        'Manufacturer' => $access_row['manufacturer'] ?? null,
        'Model' => $access_row['model'] ?? null,
        'purchase_date' => $access_row['purchase_date'] ?? $access_row['purchasedate'] ?? null,
        'PurchasePrice' => $access_row['purchase_price'] ?? $access_row['purchaseprice'] ?? $access_row['price'] ?? null,
        'CurrentValue' => $access_row['current_value'] ?? $access_row['currentvalue'] ?? $access_row['value'] ?? null,
        'warranty_expiry' => $access_row['warranty_expiry'] ?? $access_row['warrantyexpiry'] ?? null,
        'location' => $access_row['location'] ?? $access_row['site'] ?? null,
        'status' => $access_row['status'] ?? 'available',
        'ConditionStatus' => $access_row['condition'] ?? $access_row['condition_status'] ?? 'good',
        'NewTagNumber' => $access_row['tag'] ?? $access_row['tag_number'] ?? $access_row['asset_tag'] ?? null,
        'OldTagNumber' => $access_row['old_tag'] ?? $access_row['old_tag_number'] ?? null,
        'Quantity' => !empty($access_row['quantity']) ? intval($access_row['quantity']) : 1,
        'Comments' => $access_row['notes'] ?? $access_row['comments'] ?? null,
    ];
    
    // Skip if no name
    if (empty($asset['name'])) {
        $stats['assets_skipped']++;
        continue;
    }
    
    // Check for duplicates
    $serial = $asset['serial_number'] ?? null;
    $tag = $asset['NewTagNumber'] ?? $asset['OldTagNumber'] ?? null;
    $name = $asset['name'];
    
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
    
    // Try name + manufacturer + model match
    if (!$existing && !empty($asset['Manufacturer']) && !empty($asset['Model'])) {
        $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE name = ? AND manufacturer = ? AND model = ? LIMIT 1");
        $stmt->execute([$name, $asset['Manufacturer'], $asset['Model']]);
        $existing = $stmt->fetch();
    }
    
    if ($existing) {
        // Update existing asset
        $asset_id = $existing['asset_id'];
        
        $location_str = $asset['location'] ?? '';
        $country_code = detect_country_from_location($location_str);
        
        $stmt = $pdo->prepare("SELECT country_id FROM countries WHERE country_code = ?");
        $stmt->execute([$country_code]);
        $country = $stmt->fetch();
        if (!$country) {
            $stats['errors'][] = "Country not found: $country_code for asset: $name";
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
        
        // Update asset
        $stmt = $pdo->prepare("
            UPDATE assets SET
                name = ?, description = ?, serial_number = ?, manufacturer = ?, model = ?,
                purchase_date = ?, purchase_price = ?, current_value = ?, warranty_expiry = ?,
                condition_status = ?, status = ?, location_id = ?, country_id = ?,
                asset_tag = ?, quantity = ?, notes = ?, updated_at = NOW()
            WHERE asset_id = ?
        ");
        
        try {
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
                $asset['Quantity'],
                !empty($notes) ? $notes : null,
                $asset_id
            ]);
            
            $stats['assets_updated']++;
            if ($row_num % 50 == 0) {
                migration_log("UPDATED: $name (ID: $asset_id)");
            }
        } catch (PDOException $e) {
            $stats['errors'][] = "Error updating asset $name: " . $e->getMessage();
        }
        
    } else {
        // Import as new asset
        try {
            import_asset_from_old_db($pdo, $asset);
            $stats['assets_imported']++;
            if ($row_num % 50 == 0) {
                migration_log("IMPORTED: $name");
            }
        } catch (Exception $e) {
            $stats['errors'][] = "Error importing asset $name: " . $e->getMessage();
        }
    }
}

fclose($handle);

// Summary
migration_log("\n=== Import Complete ===");
migration_log("Assets imported (new): " . $stats['assets_imported']);
migration_log("Assets updated (existing): " . $stats['assets_updated']);
migration_log("Assets skipped: " . $stats['assets_skipped']);
migration_log("Errors: " . count($stats['errors']));

if (!empty($stats['errors'])) {
    migration_log("\nFirst 10 errors:");
    foreach (array_slice($stats['errors'], 0, 10) as $error) {
        migration_log("  - $error");
    }
    if (count($stats['errors']) > 10) {
        migration_log("  ... and " . (count($stats['errors']) - 10) . " more errors");
    }
}

migration_log("\nCheck migration_log.txt for full details");
