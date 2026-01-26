<?php
/**
 * Convert Excel files to CSV and import assets
 * 
 * This script:
 * 1. Converts .xlsx files to CSV
 * 2. Imports assets from the CSV files
 */

// Increase memory limit for large Excel files
ini_set('memory_limit', '512M');
set_time_limit(300); // 5 minutes

require_once __DIR__ . '/../web/config/database.php';
require_once __DIR__ . '/migration_utils.php';

// Check if PhpSpreadsheet is available
if (!file_exists(__DIR__ . '/../vendor/autoload.php')) {
    die("ERROR: PhpSpreadsheet not installed. Run: composer require phpoffice/phpspreadsheet\n");
}

require_once __DIR__ . '/../vendor/autoload.php';

use PhpOffice\PhpSpreadsheet\IOFactory;

$excel_dir = $argv[1] ?? '/tmp/excel_imports';
$csv_dir = __DIR__ . '/csv_imports';

if (!is_dir($excel_dir)) {
    die("ERROR: Excel directory not found: $excel_dir\n");
}

if (!is_dir($csv_dir)) {
    mkdir($csv_dir, 0755, true);
}

migration_log("=== Converting Excel Files to CSV ===");
migration_log("Excel directory: $excel_dir");
migration_log("CSV output: $csv_dir");

// Find all Excel files
$excel_files = array_merge(
    glob($excel_dir . '/*.xlsx'),
    glob($excel_dir . '/*.xls')
);

if (empty($excel_files)) {
    die("ERROR: No Excel files found in $excel_dir\n");
}

migration_log("Found " . count($excel_files) . " Excel files\n");

$converted = 0;
foreach ($excel_files as $excel_file) {
    $filename = basename($excel_file);
    $base_name = pathinfo($filename, PATHINFO_FILENAME);
    $csv_file = $csv_dir . '/' . $base_name . '.csv';
    
    try {
        migration_log("Converting: $filename → " . basename($csv_file));
        
        // Load Excel file
        $spreadsheet = IOFactory::load($excel_file);
        $worksheet = $spreadsheet->getActiveSheet();
        
        // Open CSV file for writing
        $csv_handle = fopen($csv_file, 'w');
        if (!$csv_handle) {
            throw new Exception("Cannot create CSV file: $csv_file");
        }
        
        // Write rows to CSV (optimized for large files)
        $row_count = 0;
        $highestRow = $worksheet->getHighestRow();
        $highestColumn = $worksheet->getHighestColumn();
        $highestColumnIndex = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($highestColumn);
        
        // Limit to first 10,000 rows to avoid memory issues
        $max_rows = min($highestRow, 10000);
        
        for ($row = 1; $row <= $max_rows; $row++) {
            $rowData = [];
            for ($col = 1; $col <= $highestColumnIndex; $col++) {
                $cellAddress = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::stringFromColumnIndex($col) . $row;
                $cell = $worksheet->getCell($cellAddress);
                $rowData[] = $cell->getFormattedValue();
            }
            
            fputcsv($csv_handle, $rowData);
            $row_count++;
        }
        
        if ($highestRow > 10000) {
            migration_log("  ⚠️  Limited to first 10,000 rows (file has $highestRow rows)");
        }
        
        fclose($csv_handle);
        $converted++;
        
        migration_log("  ✅ Converted ($row_count rows)");
        
    } catch (Exception $e) {
        migration_log("  ❌ Error: " . $e->getMessage());
    }
}

migration_log("\n=== Conversion Complete ===");
migration_log("Converted: $converted / " . count($excel_files) . " files");
migration_log("CSV files saved to: $csv_dir\n");

// Now import the CSV files
migration_log("=== Starting CSV Import ===");
require_once __DIR__ . '/migrate_data.php';

migration_log("\n=== Complete ===");
migration_log("Check migration_log.txt for details");
