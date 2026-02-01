<?php
/**
 * Migration: Add vehicle-specific fields and odometer readings table
 * 
 * Run this script to add:
 * - transmission_type (MT/AT)
 * - fuel_type (Petrol/Diesel)
 * - drive_type (2WD/4WD/6WD)
 * - odometer_readings table for tracking vehicle mileage
 */

require_once __DIR__ . '/../web/config/database.php';

echo "=== Vehicle Fields Migration ===\n\n";

// Check if columns already exist
$existingColumns = [];
$result = $pdo->query("SHOW COLUMNS FROM assets");
foreach ($result as $row) {
    $existingColumns[] = $row['Field'];
}

$columnsToAdd = [
    'vehicle_year' => "YEAR DEFAULT NULL COMMENT 'Model year for vehicles'",
    'engine_number' => "VARCHAR(100) DEFAULT NULL COMMENT 'Engine number for vehicles'",
    'transmission_type' => "ENUM('MT', 'AT') DEFAULT NULL COMMENT 'Manual or Automatic transmission'",
    'fuel_type' => "ENUM('Petrol', 'Diesel') DEFAULT NULL COMMENT 'Fuel type'",
    'drive_type' => "ENUM('2WD', '4WD', '6WD') DEFAULT NULL COMMENT 'Drive configuration'"
];

foreach ($columnsToAdd as $column => $definition) {
    if (in_array($column, $existingColumns)) {
        echo "Column '$column' already exists, skipping.\n";
    } else {
        try {
            $pdo->exec("ALTER TABLE assets ADD COLUMN $column $definition");
            echo "Added column '$column'.\n";
        } catch (PDOException $e) {
            echo "Error adding column '$column': " . $e->getMessage() . "\n";
        }
    }
}

// Create odometer_readings table
echo "\nCreating odometer_readings table...\n";

try {
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS odometer_readings (
            reading_id INT(11) NOT NULL AUTO_INCREMENT,
            asset_id INT(11) NOT NULL COMMENT 'Vehicle asset ID',
            reading_km INT(11) NOT NULL COMMENT 'Odometer reading in kilometers',
            reading_date DATE NOT NULL COMMENT 'Date of reading',
            notes VARCHAR(255) DEFAULT NULL COMMENT 'Optional notes (e.g., service, trip)',
            recorded_by INT(11) DEFAULT NULL COMMENT 'User who recorded the reading',
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (reading_id),
            FOREIGN KEY (asset_id) REFERENCES assets(asset_id) ON DELETE CASCADE,
            FOREIGN KEY (recorded_by) REFERENCES users(user_id) ON DELETE SET NULL,
            INDEX idx_asset_date (asset_id, reading_date DESC),
            INDEX idx_reading_date (reading_date)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    echo "Created odometer_readings table.\n";
} catch (PDOException $e) {
    if (strpos($e->getMessage(), 'already exists') !== false) {
        echo "Table odometer_readings already exists.\n";
    } else {
        echo "Error creating table: " . $e->getMessage() . "\n";
    }
}

// Verify the changes
echo "\n=== Verification ===\n";

$result = $pdo->query("SHOW COLUMNS FROM assets WHERE Field IN ('vehicle_year', 'engine_number', 'transmission_type', 'fuel_type', 'drive_type')");
$vehicleColumns = $result->fetchAll();
echo "Vehicle columns in assets table: " . count($vehicleColumns) . "\n";
foreach ($vehicleColumns as $col) {
    echo "  - {$col['Field']} ({$col['Type']})\n";
}

$result = $pdo->query("SHOW TABLES LIKE 'odometer_readings'");
if ($result->rowCount() > 0) {
    echo "\nodometer_readings table exists.\n";
    
    $result = $pdo->query("SHOW COLUMNS FROM odometer_readings");
    echo "Columns:\n";
    foreach ($result as $col) {
        echo "  - {$col['Field']} ({$col['Type']})\n";
    }
}

echo "\n=== Migration Complete ===\n";
