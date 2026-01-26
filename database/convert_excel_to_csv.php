<?php
/**
 * Convert Excel files to CSV
 * 
 * This script converts .xlsx files to CSV format for import
 * Requires: PhpSpreadsheet library (or manual conversion)
 */

$source_dir = $argv[1] ?? __DIR__ . '/excel_files';
$output_dir = $argv[2] ?? __DIR__ . '/csv_imports';

if (!is_dir($source_dir)) {
    die("ERROR: Source directory not found: $source_dir\n");
}

if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}

echo "=== Converting Excel Files to CSV ===\n";
echo "Source: $source_dir\n";
echo "Output: $output_dir\n\n";

// Check if PhpSpreadsheet is available
$use_phpspreadsheet = false;
if (file_exists(__DIR__ . '/../vendor/autoload.php')) {
    require_once __DIR__ . '/../vendor/autoload.php';
    if (class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
        $use_phpspreadsheet = true;
        echo "✅ PhpSpreadsheet library found - will convert directly\n\n";
    }
}

if (!$use_phpspreadsheet) {
    echo "⚠️  PhpSpreadsheet not found. Options:\n";
    echo "1. Install: composer require phpoffice/phpspreadsheet\n";
    echo "2. Or manually convert Excel files to CSV in Excel/LibreOffice\n";
    echo "3. Or use online converter\n\n";
    
    // List files that need conversion
    $excel_files = glob($source_dir . '/*.xlsx') + glob($source_dir . '/*.xls');
    echo "Files to convert:\n";
    foreach ($excel_files as $file) {
        echo "  - " . basename($file) . "\n";
    }
    exit(0);
}

// Convert using PhpSpreadsheet
$excel_files = glob($source_dir . '/*.xlsx') + glob($source_dir . '/*.xls');
$converted = 0;

foreach ($excel_files as $excel_file) {
    $filename = basename($excel_file, '.xlsx');
    $filename = basename($filename, '.xls');
    $csv_file = $output_dir . '/' . $filename . '.csv';
    
    try {
        echo "Converting: " . basename($excel_file) . " → " . basename($csv_file) . "\n";
        
        $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($excel_file);
        $worksheet = $spreadsheet->getActiveSheet();
        
        $csv = fopen($csv_file, 'w');
        
        foreach ($worksheet->getRowIterator() as $row) {
            $cellIterator = $row->getCellIterator();
            $cellIterator->setIterateOnlyExistingCells(false);
            
            $rowData = [];
            foreach ($cellIterator as $cell) {
                $rowData[] = $cell->getFormattedValue();
            }
            
            fputcsv($csv, $rowData);
        }
        
        fclose($csv);
        $converted++;
        echo "  ✅ Converted successfully\n";
        
    } catch (Exception $e) {
        echo "  ❌ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n=== Conversion Complete ===\n";
echo "Converted: $converted / " . count($excel_files) . " files\n";
echo "CSV files saved to: $output_dir\n";
