<?php
/**
 * Quick Configuration Helper for Google Sheets
 * 
 * Use this script to easily configure your Google Sheet IDs
 * Run: php configure_sheets.php
 */

$config_file = __DIR__ . '/import_from_google_sheets.php';

echo "=== Google Sheets Configuration Helper ===\n\n";

echo "Enter your Google Sheet IDs (one per line).\n";
echo "Format: SheetName|SheetID\n";
echo "Example: RET_Materials|1ABC123XYZ456DEF789\n";
echo "Press Enter twice when done.\n\n";

$sheets = [];
$line_num = 1;

while (true) {
    echo "Sheet $line_num (Name|ID, or press Enter to finish): ";
    $input = trim(fgets(STDIN));
    
    if (empty($input)) {
        break;
    }
    
    if (strpos($input, '|') === false) {
        echo "⚠️  Invalid format. Use: Name|ID\n";
        continue;
    }
    
    list($name, $id) = explode('|', $input, 2);
    $sheets[trim($name)] = trim($id);
    $line_num++;
}

if (empty($sheets)) {
    echo "No sheets configured. Exiting.\n";
    exit(0);
}

echo "\n📋 Configured Sheets:\n";
foreach ($sheets as $name => $id) {
    echo "  - $name: $id\n";
}

echo "\nUpdating configuration file...\n";

// Read the current file
$content = file_get_contents($config_file);

// Find and replace the spreadsheet_ids array
$new_config = "    'spreadsheet_ids' => [\n";
foreach ($sheets as $name => $id) {
    $new_config .= "        '$name' => '$id',\n";
}
$new_config .= "    ]\n";

// Replace the old config
$pattern = "/'spreadsheet_ids'\s*=>\s*\[[^\]]*\]/s";
$content = preg_replace($pattern, $new_config, $content);

file_put_contents($config_file, $content);

echo "✅ Configuration updated!\n";
echo "\nNext steps:\n";
echo "1. Upload google-credentials.json to: database/\n";
echo "2. Share all Google Sheets with the service account email\n";
echo "3. Run: php database/import_from_google_sheets.php\n";
