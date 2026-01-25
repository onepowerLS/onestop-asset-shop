<?php
/**
 * Import from Google Sheets via API
 * 
 * This script can access Google Sheets directly using the Google Sheets API
 * Requires: Google API credentials (service account or OAuth)
 */

require_once __DIR__ . '/../web/config/database.php';
require_once __DIR__ . '/migration_utils.php';

// Google Sheets API Configuration
// You'll need to set up a Google Cloud Project and get credentials
$google_sheets_config = [
    'credentials_file' => __DIR__ . '/google-credentials.json', // Service account JSON
    'spreadsheet_ids' => [
        // Add your Google Sheet IDs here
        // 'RET_Materials' => '1ABC...XYZ',
        // 'FAC_Items' => '1DEF...UVW',
        // etc.
    ]
];

/**
 * Install Google API Client Library
 * Run: composer require google/apiclient
 */
function install_google_api() {
    $composer_file = __DIR__ . '/../composer.json';
    if (!file_exists($composer_file)) {
        // Create composer.json
        file_put_contents($composer_file, json_encode([
            'require' => [
                'google/apiclient' => '^2.0'
            ]
        ], JSON_PRETTY_PRINT));
    }
    
    echo "Installing Google API Client...\n";
    exec('cd ' . escapeshellarg(__DIR__ . '/..') . ' && composer install 2>&1', $output, $return);
    if ($return === 0) {
        echo "✅ Google API Client installed\n";
        return true;
    } else {
        echo "❌ Failed to install. Run manually: composer require google/apiclient\n";
        return false;
    }
}

/**
 * Import from Google Sheet
 */
// Statistics
$stats = [
    'assets_imported' => 0,
    'assets_skipped_duplicate' => 0,
    'categories_created' => 0,
    'locations_created' => 0,
    'errors' => []
];

function import_from_google_sheet($spreadsheet_id, $sheet_name, $category_type = 'General') {
    global $pdo, $stats;
    
    if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
        echo "Google API Client not installed. Installing...\n";
        if (!install_google_api()) {
            return false;
        }
    }
    
    require_once __DIR__ . '/../vendor/autoload.php';
    
    $client = new Google_Client();
    $client->setApplicationName('OneStop Asset Shop Migration');
    $client->setScopes(Google_Service_Sheets::SPREADSHEETS_READONLY);
    
    // Use service account credentials
    if (file_exists(__DIR__ . '/google-credentials.json')) {
        $client->setAuthConfig(__DIR__ . '/google-credentials.json');
    } else {
        echo "❌ Google credentials file not found: google-credentials.json\n";
        echo "Please download service account credentials from Google Cloud Console\n";
        return false;
    }
    
    $service = new Google_Service_Sheets($client);
    
    try {
        // First, get all sheet names to find the right one
        $spreadsheet = $service->spreadsheets->get($spreadsheet_id);
        $sheets = $spreadsheet->getSheets();
        
        // Use first sheet if sheet_name not found, or use sheet_name if it matches
        $target_sheet = null;
        foreach ($sheets as $sheet) {
            $sheet_title = $sheet->getProperties()->getTitle();
            if (strtolower($sheet_title) === strtolower($sheet_name) || empty($target_sheet)) {
                $target_sheet = $sheet_title;
            }
        }
        
        if (!$target_sheet) {
            $target_sheet = $sheets[0]->getProperties()->getTitle();
        }
        
        migration_log("Reading sheet: $target_sheet from spreadsheet: $spreadsheet_id");
        
        // Get sheet data - try to get all rows (up to 10,000)
        $range = $target_sheet . '!A1:Z10000';
        $response = $service->spreadsheets_values->get($spreadsheet_id, $range);
        $values = $response->getValues();
        
        if (empty($values)) {
            migration_log("No data found in sheet: $sheet_name");
            return false;
        }
        
        // First row is headers
        $headers = array_map('strtolower', array_map('trim', $values[0]));
        
        migration_log("Importing from Google Sheet: $sheet_name (" . (count($values) - 1) . " rows)");
        
        // Process each row
        for ($i = 1; $i < count($values); $i++) {
            $row = $values[$i];
            $data = [];
            
            // Map row to associative array
            for ($j = 0; $j < min(count($headers), count($row)); $j++) {
                $data[$headers[$j]] = $row[$j] ?? '';
            }
            
            // Map to asset structure
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
                'category_id' => null,
                'source' => "Google Sheet: $sheet_name"
            ];
            
            // Get or create category
            if (!empty($data['category'])) {
                $category_id = get_or_create_category($pdo, $data['category'], $category_type);
                $asset['category_id'] = $category_id;
            } elseif (!empty($category_type) && $category_type !== 'General') {
                // Use sheet name as category if no category column
                $category_id = get_or_create_category($pdo, $category_type, $category_type);
                $asset['category_id'] = $category_id;
            }
            
            // Import asset using the same logic as CSV import
            // Check for duplicates
            $serial = $asset['serial_number'] ?? null;
            $tag = $asset['NewTagNumber'] ?? $asset['OldTagNumber'] ?? null;
            $name = $asset['name'] ?? '';
            $manufacturer = $asset['Manufacturer'] ?? '';
            $model = $asset['Model'] ?? '';
            
            // Use extended duplicate check
            if (is_asset_duplicate($pdo, $serial, $tag)) {
                $stats['assets_skipped_duplicate']++;
                migration_log("SKIPPED (duplicate): $name (Serial: $serial, Tag: $tag)");
                continue;
            }
            
            // Check by name + manufacturer + model (fuzzy match)
            if (!empty($name) && !empty($manufacturer) && !empty($model)) {
                $stmt = $pdo->prepare("
                    SELECT asset_id FROM assets 
                    WHERE name = ? AND manufacturer = ? AND model = ?
                ");
                $stmt->execute([$name, $manufacturer, $model]);
                if ($stmt->fetch()) {
                    $stats['assets_skipped_duplicate']++;
                    migration_log("SKIPPED (duplicate): $name - $manufacturer $model");
                    continue;
                }
            }
            
            // Detect country
            $location_str = $asset['location'] ?? '';
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
            $status = map_old_status($asset['status'] ?? 'available');
            $condition = map_old_condition($asset['ConditionStatus'] ?? 'good');
            
            // Build notes
            $notes = '';
            if (!empty($asset['Comments'])) {
                $notes .= "Comments: " . $asset['Comments'] . "\n";
            }
            if (!empty($asset['OldTagNumber']) && $asset['OldTagNumber'] != $tag) {
                $notes .= "Old Tag: " . $asset['OldTagNumber'] . "\n";
            }
            if (!empty($asset['AssignedTo'])) {
                $notes .= "Assigned To: " . $asset['AssignedTo'] . "\n";
            }
            if (!empty($asset['source'])) {
                $notes .= "Source: " . $asset['source'] . "\n";
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
                    $name,
                    $asset['description'] ?? null,
                    $serial,
                    $manufacturer,
                    $model,
                    !empty($asset['purchase_date']) ? $asset['purchase_date'] : null,
                    !empty($asset['PurchasePrice']) ? $asset['PurchasePrice'] : null,
                    !empty($asset['CurrentValue']) ? $asset['CurrentValue'] : null,
                    !empty($asset['warranty_expiry']) ? $asset['warranty_expiry'] : null,
                    $condition,
                    $status,
                    $location_id,
                    $country_id,
                    $tag,
                    !empty($asset['Quantity']) ? intval($asset['Quantity']) : 1,
                    !empty($notes) ? $notes : null,
                ]);
                
                $new_asset_id = $pdo->lastInsertId();
                
                // Generate QR code
                $qr_code_id = generate_qr_code_id($country_code, $new_asset_id);
                $stmt = $pdo->prepare("UPDATE assets SET qr_code_id = ? WHERE asset_id = ?");
                $stmt->execute([$qr_code_id, $new_asset_id]);
                
                $stats['assets_imported']++;
                migration_log("IMPORTED: $name (ID: $new_asset_id, QR: $qr_code_id)");
                
            } catch (PDOException $e) {
                migration_log("ERROR importing asset: " . $e->getMessage());
                $stats['errors'][] = $e->getMessage();
            }
        }
        
        return true;
        
    } catch (Exception $e) {
        migration_log("ERROR importing from Google Sheet: " . $e->getMessage());
        return false;
    }
}

// Main execution
migration_log("=== Google Sheets Import ===");

if (!file_exists(__DIR__ . '/google-credentials.json')) {
    echo "❌ Google credentials not found\n";
    echo "\nTo use Google Sheets API:\n";
    echo "1. Go to https://console.cloud.google.com/\n";
    echo "2. Create a project (or use existing)\n";
    echo "3. Enable Google Sheets API\n";
    echo "4. Create Service Account\n";
    echo "5. Download JSON credentials\n";
    echo "6. Save as: database/google-credentials.json\n";
    echo "7. Share your Google Sheets with the service account email\n";
    echo "\nAlternatively, export sheets to CSV and use migrate_data.php\n";
    exit(1);
}

// Check if spreadsheet IDs are configured
if (empty($google_sheets_config['spreadsheet_ids'])) {
    echo "⚠️  No spreadsheet IDs configured\n";
    echo "Edit this file and add your Google Sheet IDs to \$google_sheets_config['spreadsheet_ids']\n";
    echo "\nTo find Sheet ID: Look at the URL\n";
    echo "https://docs.google.com/spreadsheets/d/{SHEET_ID}/edit\n";
    exit(1);
}

// Import from each configured sheet
foreach ($google_sheets_config['spreadsheet_ids'] as $sheet_name => $config) {
    $spreadsheet_id = is_array($config) ? $config['id'] : $config;
    $category_type = is_array($config) && isset($config['category']) ? $config['category'] : 'General';
    
    // Auto-detect category from sheet name if not specified
    if ($category_type === 'General') {
        if (stripos($sheet_name, 'ret') !== false) {
            $category_type = 'RET';
        } elseif (stripos($sheet_name, 'fac') !== false) {
            $category_type = 'FAC';
        } elseif (stripos($sheet_name, 'o&m') !== false || stripos($sheet_name, 'om') !== false) {
            $category_type = 'O&M';
        } elseif (stripos($sheet_name, 'meter') !== false) {
            $category_type = 'Meters';
        } elseif (stripos($sheet_name, 'ready') !== false || stripos($sheet_name, 'board') !== false) {
            $category_type = 'ReadyBoards';
        } elseif (stripos($sheet_name, 'tool') !== false) {
            $category_type = 'Tools';
        }
    }
    
    migration_log("Processing: $sheet_name (Category: $category_type)");
    import_from_google_sheet($spreadsheet_id, $sheet_name, $category_type);
}

// Summary
migration_log("=== Google Sheets Import Complete ===");
migration_log("Assets imported: " . $stats['assets_imported']);
migration_log("Assets skipped (duplicates): " . $stats['assets_skipped_duplicate']);
migration_log("Categories created: " . $stats['categories_created']);
migration_log("Locations created: " . $stats['locations_created']);
migration_log("Errors: " . count($stats['errors']));

echo "\n=== Google Sheets Import Summary ===\n";
echo "Assets imported: {$stats['assets_imported']}\n";
echo "Duplicates skipped: {$stats['assets_skipped_duplicate']}\n";
echo "Check migration_log.txt for details\n";
