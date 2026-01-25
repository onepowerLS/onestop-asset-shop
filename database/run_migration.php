<?php
/**
 * Migration Runner - Main Entry Point
 * 
 * This script orchestrates the complete migration process:
 * 1. Import from SQL dump
 * 2. Import from CSV files (Google Sheets)
 * 3. Generate QR codes
 * 4. Initialize inventory
 */

require_once __DIR__ . '/../web/config/database.php';
require_once __DIR__ . '/migration_utils.php';

$csv_directory = __DIR__ . '/csv_imports';

// Clear previous log
$log_file = __DIR__ . '/migration_log.txt';
if (file_exists($log_file)) {
    file_put_contents($log_file, '');
}

migration_log("=== Starting Complete Migration Process ===");
migration_log("");

// Step 1: Import from SQL dump
migration_log("STEP 1: Importing from SQL dump...");
migration_log("----------------------------------------");
$sql_script = __DIR__ . '/migrate_from_sql_dump.php';
if (file_exists($sql_script)) {
    // Execute SQL migration in separate process to avoid function conflicts
    $output = shell_exec("cd " . escapeshellarg(__DIR__) . " && php " . escapeshellarg($sql_script) . " 2>&1");
    echo $output;
} else {
    migration_log("WARNING: SQL migration script not found: $sql_script");
}
migration_log("");

// Step 2: Import from CSV files
migration_log("STEP 2: Importing from CSV files (Google Sheets)...");
migration_log("----------------------------------------");
$csv_script = __DIR__ . '/migrate_data.php';
if (file_exists($csv_script) && is_dir($csv_directory)) {
    $csv_files = glob($csv_directory . '/*.csv');
    if (empty($csv_files)) {
        migration_log("No CSV files found in: $csv_directory");
        migration_log("To import Google Sheets:");
        migration_log("  1. Export each Google Sheet to CSV");
        migration_log("  2. Upload CSV files to: $csv_directory");
        migration_log("  3. Re-run this script");
    } else {
        migration_log("Found " . count($csv_files) . " CSV file(s) to import");
        // Execute CSV migration in separate process
        $output = shell_exec("cd " . escapeshellarg(__DIR__) . " && php " . escapeshellarg($csv_script) . " 2>&1");
        echo $output;
    }
} else {
    migration_log("WARNING: CSV migration script or directory not found");
}
migration_log("");

// Step 3: Final verification
migration_log("STEP 3: Verifying migration...");
migration_log("----------------------------------------");
try {
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM assets");
    $total = $stmt->fetch()['total'];
    migration_log("Total assets in database: $total");
    
    $stmt = $pdo->query("
        SELECT c.country_name, COUNT(*) as count
        FROM assets a
        JOIN countries c ON a.country_id = c.country_id
        GROUP BY c.country_name
    ");
    $by_country = $stmt->fetchAll();
    foreach ($by_country as $row) {
        migration_log("  - {$row['country_name']}: {$row['count']} assets");
    }
    
    $stmt = $pdo->query("SELECT COUNT(*) as total FROM locations");
    $locations = $stmt->fetch()['total'];
    migration_log("Total locations: $locations");
    
    $stmt = $pdo->query("
        SELECT 
            COUNT(*) as total,
            COUNT(qr_code_id) as with_qr,
            COUNT(*) - COUNT(qr_code_id) as missing_qr
        FROM assets
    ");
    $qr_stats = $stmt->fetch();
    migration_log("QR Codes: {$qr_stats['with_qr']}/{$qr_stats['total']} generated");
    if ($qr_stats['missing_qr'] > 0) {
        migration_log("WARNING: {$qr_stats['missing_qr']} assets missing QR codes");
    }
    
} catch (PDOException $e) {
    migration_log("ERROR during verification: " . $e->getMessage());
}

migration_log("");
migration_log("=== Migration Process Complete ===");
migration_log("Check migration_log.txt for detailed log");
migration_log("");
echo "\n✅ Migration complete! Check the log above for details.\n";
