<?php
/**
 * Import/Update Vehicles from CSV
 * 
 * This script reads a CSV file exported from the PR system and imports
 * or updates vehicle records in the AM database.
 * 
 * Usage: php update_vehicles_from_csv.php [csv_file]
 * Default CSV: vehicles_unique.csv
 */

require_once __DIR__ . '/migration_utils.php';

// Get CSV filename from argument or use default
$csv_file = $argv[1] ?? 'vehicles_unique.csv';
$csv_path = __DIR__ . '/' . $csv_file;

if (!file_exists($csv_path)) {
    die("Error: CSV file not found: $csv_path\n");
}

echo "=== Import/Update Vehicles from CSV ===\n";
echo "CSV File: $csv_file\n\n";

// Read CSV
$handle = fopen($csv_path, 'r');
if (!$handle) {
    die("Error: Could not open CSV file\n");
}

// Read header row
$headers = fgetcsv($handle);
if (!$headers) {
    die("Error: Could not read CSV headers\n");
}

// Normalize headers
$headers = array_map('trim', $headers);
echo "CSV Headers: " . implode(', ', $headers) . "\n\n";

// Expected columns mapping
$column_map = [
    'Code' => 'code',
    'Registration Number' => 'registration_number',
    'Year' => 'year',
    'Make' => 'make',
    'Model' => 'model',
    'VIN Number' => 'vin_number',
    'Engine Number' => 'engine_number',
    'Organization' => 'organization',
    'Organization ID' => 'organization_id',
    'Active' => 'active'
];

// Find column indices
$col_indices = [];
foreach ($column_map as $csv_col => $field) {
    $index = array_search($csv_col, $headers);
    if ($index !== false) {
        $col_indices[$field] = $index;
    }
}

echo "Mapped columns: " . implode(', ', array_keys($col_indices)) . "\n\n";

// Get database connection
$pdo = get_db_connection();

// Get the Vehicles category ID
$stmt = $pdo->query("SELECT category_id FROM categories WHERE category_name = 'Vehicles' LIMIT 1");
$vehicleCategory = $stmt->fetch();
if (!$vehicleCategory) {
    die("Error: Vehicles category not found in database\n");
}
$vehicleCategoryId = $vehicleCategory['category_id'];
echo "Vehicles category ID: $vehicleCategoryId\n\n";

// Map organization IDs to country IDs
$orgToCountry = [];
$stmt = $pdo->query("SELECT country_id, country_code, country_name FROM countries");
while ($row = $stmt->fetch()) {
    // Map various org ID formats to country_id
    $code = strtolower($row['country_code']);
    $orgToCountry[$code] = $row['country_id'];
    $orgToCountry['1pwr_' . $code] = $row['country_id'];
    $orgToCountry['1pwr ' . strtolower($row['country_name'])] = $row['country_id'];
}
echo "Country mappings loaded: " . count($orgToCountry) . " entries\n\n";

// Process each row
$inserted = 0;
$updated = 0;
$skipped = 0;
$row_num = 1;

while (($row = fgetcsv($handle)) !== false) {
    $row_num++;
    
    // Get values from row
    $code = isset($col_indices['code']) ? trim($row[$col_indices['code']] ?? '') : '';
    $registration = isset($col_indices['registration_number']) ? trim($row[$col_indices['registration_number']] ?? '') : '';
    $year = isset($col_indices['year']) ? trim($row[$col_indices['year']] ?? '') : '';
    $make = isset($col_indices['make']) ? trim($row[$col_indices['make']] ?? '') : '';
    $model = isset($col_indices['model']) ? trim($row[$col_indices['model']] ?? '') : '';
    $vin = isset($col_indices['vin_number']) ? trim($row[$col_indices['vin_number']] ?? '') : '';
    $engine = isset($col_indices['engine_number']) ? trim($row[$col_indices['engine_number']] ?? '') : '';
    $orgName = isset($col_indices['organization']) ? trim($row[$col_indices['organization']] ?? '') : '';
    $orgId = isset($col_indices['organization_id']) ? trim($row[$col_indices['organization_id']] ?? '') : '';
    
    if (empty($code)) {
        echo "Row $row_num: Skipped (no code)\n";
        $skipped++;
        continue;
    }
    
    // Determine country_id from organization
    $countryId = null;
    $orgKey = strtolower($orgId ?: $orgName);
    if (isset($orgToCountry[$orgKey])) {
        $countryId = $orgToCountry[$orgKey];
    } else {
        // Default to LSO if not found
        $countryId = $orgToCountry['lso'] ?? 1;
    }
    
    echo "Row $row_num: Processing '$code' (org: $orgId)... ";
    
    // Find existing vehicle by name AND country
    $stmt = $pdo->prepare("
        SELECT asset_id, name, asset_tag, vehicle_year, manufacturer, model, serial_number, engine_number, country_id
        FROM assets 
        WHERE category_id = ? AND name = ? AND country_id = ?
        LIMIT 1
    ");
    $stmt->execute([$vehicleCategoryId, $code, $countryId]);
    $vehicle = $stmt->fetch();
    
    if (!$vehicle) {
        // Try without country filter (for legacy data)
        $stmt = $pdo->prepare("
            SELECT asset_id, name, asset_tag, vehicle_year, manufacturer, model, serial_number, engine_number, country_id
            FROM assets 
            WHERE category_id = ? AND name = ?
            LIMIT 1
        ");
        $stmt->execute([$vehicleCategoryId, $code]);
        $vehicle = $stmt->fetch();
    }
    
    if ($vehicle) {
        // UPDATE existing vehicle
        $updates = [];
        $params = [];
        
        if (!empty($registration) && $registration !== $vehicle['asset_tag']) {
            $updates[] = "asset_tag = ?";
            $params[] = $registration;
        }
        
        if (!empty($year) && is_numeric($year) && $year != $vehicle['vehicle_year']) {
            $updates[] = "vehicle_year = ?";
            $params[] = (int)$year;
        }
        
        if (!empty($make) && $make !== $vehicle['manufacturer']) {
            $updates[] = "manufacturer = ?";
            $params[] = $make;
        }
        
        if (!empty($model) && $model !== $vehicle['model']) {
            $updates[] = "model = ?";
            $params[] = $model;
        }
        
        if (!empty($vin) && $vin !== $vehicle['serial_number']) {
            $updates[] = "serial_number = ?";
            $params[] = $vin;
        }
        
        if (!empty($engine) && $engine !== $vehicle['engine_number']) {
            $updates[] = "engine_number = ?";
            $params[] = $engine;
        }
        
        // Update country if different
        if ($countryId && $countryId != $vehicle['country_id']) {
            $updates[] = "country_id = ?";
            $params[] = $countryId;
        }
        
        if (empty($updates)) {
            echo "No changes needed\n";
            $skipped++;
            continue;
        }
        
        $params[] = $vehicle['asset_id'];
        $sql = "UPDATE assets SET " . implode(', ', $updates) . " WHERE asset_id = ?";
        
        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            echo "UPDATED (" . count($updates) . " fields)\n";
            $updated++;
        } catch (PDOException $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    } else {
        // INSERT new vehicle
        $qrCode = '1PWR-' . strtoupper(substr($orgId ?: 'LSO', -3)) . '-' . str_pad(rand(100000, 999999), 6, '0', STR_PAD_LEFT);
        
        try {
            $stmt = $pdo->prepare("
                INSERT INTO assets (
                    name, asset_tag, category_id, country_id, 
                    manufacturer, model, serial_number, engine_number, vehicle_year,
                    status, condition_status, qr_code_id, notes, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'Available', 'Good', ?, 'Imported from PR System', NOW())
            ");
            $stmt->execute([
                $code,
                $registration ?: null,
                $vehicleCategoryId,
                $countryId,
                $make ?: null,
                $model ?: null,
                $vin ?: null,
                $engine ?: null,
                !empty($year) && is_numeric($year) ? (int)$year : null,
                $qrCode
            ]);
            echo "INSERTED (new vehicle)\n";
            $inserted++;
        } catch (PDOException $e) {
            echo "ERROR: " . $e->getMessage() . "\n";
        }
    }
}

fclose($handle);

echo "\n=== Summary ===\n";
echo "Inserted: $inserted\n";
echo "Updated: $updated\n";
echo "Skipped (no changes): $skipped\n";
echo "Total rows processed: " . ($row_num - 1) . "\n";
