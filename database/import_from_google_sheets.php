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
        // Get sheet data
        $range = $sheet_name . '!A1:Z1000'; // Adjust range as needed
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
                'category_id' => null
            ];
            
            // Get or create category
            if (!empty($data['category'])) {
                $category_id = get_or_create_category($pdo, $data['category'], $category_type);
                $asset['category_id'] = $category_id;
            }
            
            // Import asset
            import_asset_from_old_db($pdo, $asset);
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
foreach ($google_sheets_config['spreadsheet_ids'] as $sheet_name => $spreadsheet_id) {
    migration_log("Processing: $sheet_name");
    import_from_google_sheet($spreadsheet_id, $sheet_name);
}

migration_log("=== Google Sheets Import Complete ===");
