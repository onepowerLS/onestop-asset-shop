<?php
/**
 * Import Vehicles from PR System
 * 
 * One-time migration script to import vehicles from PR system CSV
 * After this, AM becomes the single source of truth
 */

require_once __DIR__ . '/../web/config/database.php';

echo "=== Import Vehicles from PR System ===\n\n";

// PR System vehicles (from Vehicle.csv)
$prVehicles = [
    ['code' => '36', 'registrationNumber' => 'RLL415J', 'isActive' => true],
    ['code' => 'Compressor', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Drill rig', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Hardbody 1', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Hardbody 2', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Hilux', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Jeep 1', 'registrationNumber' => 'A992 BCF', 'isActive' => true],
    ['code' => 'Jeep 2', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Jeep 3', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Mazda 1', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Pajero', 'registrationNumber' => 'RLZ052J', 'isActive' => true],
    ['code' => 'Raider', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Ranger 1', 'registrationNumber' => 'A 838 BLF', 'isActive' => true],
    ['code' => 'Ranger 2', 'registrationNumber' => 'A 374 BBV', 'isActive' => true],
    ['code' => 'Ranger 3', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Surf 1', 'registrationNumber' => 'RCY461J', 'isActive' => true],
    ['code' => 'Surf 2', 'registrationNumber' => 'RY019', 'isActive' => true],
    ['code' => 'Telehandler', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Tractors', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'Trailer', 'registrationNumber' => null, 'isActive' => true],
    ['code' => 'XTrail 1', 'registrationNumber' => 'RLK506J', 'isActive' => true],
    ['code' => 'Xtrail 2', 'registrationNumber' => null, 'isActive' => true],
];

// Map known makes from vehicle names
$makeMapping = [
    'Jeep' => ['make' => 'Jeep', 'model' => 'Wrangler'],
    'Pajero' => ['make' => 'Mitsubishi', 'model' => 'Pajero'],
    'Ranger' => ['make' => 'Ford', 'model' => 'Ranger'],
    'Surf' => ['make' => 'Toyota', 'model' => 'Surf'],
    'XTrail' => ['make' => 'Nissan', 'model' => 'X-Trail'],
    'Xtrail' => ['make' => 'Nissan', 'model' => 'X-Trail'],
    'Hilux' => ['make' => 'Toyota', 'model' => 'Hilux'],
    'Mazda' => ['make' => 'Mazda', 'model' => 'BT-50'],
    'Hardbody' => ['make' => 'Nissan', 'model' => 'Hardbody'],
    'Raider' => ['make' => 'Mitsubishi', 'model' => 'Raider'],
    'Telehandler' => ['make' => 'JCB', 'model' => 'Telehandler'],
    'Compressor' => ['make' => null, 'model' => 'Air Compressor'],
    'Drill rig' => ['make' => null, 'model' => 'Drill Rig'],
    'Tractors' => ['make' => null, 'model' => 'Tractor'],
    'Trailer' => ['make' => null, 'model' => 'Trailer'],
];

// Get vehicles category ID
$stmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_name = 'Vehicles'");
$stmt->execute();
$vehicleCategory = $stmt->fetch();

if (!$vehicleCategory) {
    echo "❌ Vehicles category not found. Please create it first.\n";
    exit(1);
}

$vehicleCategoryId = $vehicleCategory['category_id'];
echo "Using Vehicles category ID: $vehicleCategoryId\n\n";

// Get default country (Lesotho)
$stmt = $pdo->prepare("SELECT country_id FROM countries WHERE country_code = 'LSO'");
$stmt->execute();
$defaultCountry = $stmt->fetch();
$defaultCountryId = $defaultCountry ? $defaultCountry['country_id'] : 1;

echo "Vehicles to import:\n";
foreach ($prVehicles as $v) {
    $reg = $v['registrationNumber'] ?? 'N/A';
    echo "  - {$v['code']} (Reg: $reg)\n";
}
echo "\n";

echo "Proceed with import? (yes/no): ";
$confirm = trim(fgets(STDIN));

if (strtolower($confirm) !== 'yes') {
    echo "Aborted.\n";
    exit(0);
}

$stats = [
    'imported' => 0,
    'skipped' => 0,
    'errors' => []
];

$pdo->beginTransaction();

try {
    foreach ($prVehicles as $vehicle) {
        $code = $vehicle['code'];
        $registrationNumber = $vehicle['registrationNumber'];
        $isActive = $vehicle['isActive'] ? 'Available' : 'Retired';
        
        // Skip "Other" as it's not a real vehicle
        if ($code === 'Other') {
            $stats['skipped']++;
            continue;
        }
        
        // Determine make/model from name
        $make = null;
        $model = null;
        foreach ($makeMapping as $key => $mapping) {
            if (stripos($code, $key) !== false) {
                $make = $mapping['make'];
                $model = $mapping['model'];
                break;
            }
        }
        
        // Check if vehicle already exists
        $stmt = $pdo->prepare("
            SELECT asset_id FROM assets 
            WHERE category_id = ? AND name = ?
        ");
        $stmt->execute([$vehicleCategoryId, $code]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            echo "  Skipped (exists): $code\n";
            $stats['skipped']++;
            continue;
        }
        
        // Insert new vehicle
        $stmt = $pdo->prepare("
            INSERT INTO assets (
                name, asset_tag, manufacturer, model,
                category_id, country_id, status, condition_status,
                quantity, notes, created_at
            ) VALUES (?, ?, ?, ?, ?, ?, ?, 'Good', 1, 'Imported from PR System', NOW())
        ");
        $stmt->execute([
            $code,
            $registrationNumber,
            $make,
            $model,
            $vehicleCategoryId,
            $defaultCountryId,
            $isActive
        ]);
        
        $newId = $pdo->lastInsertId();
        
        // Generate QR code
        $qrCode = '1PWR-LSO-' . str_pad($newId, 6, '0', STR_PAD_LEFT);
        $stmt = $pdo->prepare("UPDATE assets SET qr_code_id = ? WHERE asset_id = ?");
        $stmt->execute([$qrCode, $newId]);
        
        echo "  Imported: $code (ID: $newId, QR: $qrCode)\n";
        $stats['imported']++;
    }
    
    $pdo->commit();
    echo "\n✅ Transaction committed\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Transaction rolled back.\n";
    exit(1);
}

echo "\n=== Results ===\n";
echo "Imported: {$stats['imported']}\n";
echo "Skipped: {$stats['skipped']}\n";
echo "Errors: " . count($stats['errors']) . "\n";

// Show all vehicles
echo "\nAll vehicles in AM:\n";
$stmt = $pdo->prepare("
    SELECT asset_id, name, asset_tag, manufacturer, model, qr_code_id, status
    FROM assets 
    WHERE category_id = ?
    ORDER BY name
");
$stmt->execute([$vehicleCategoryId]);
while ($row = $stmt->fetch()) {
    $reg = $row['asset_tag'] ?? 'N/A';
    $make = $row['manufacturer'] ?? 'Unknown';
    echo "  [{$row['asset_id']}] {$row['name']} - $make {$row['model']} (Reg: $reg, QR: {$row['qr_code_id']})\n";
}

echo "\nDone! AM is now the single source of truth for vehicles.\n";
echo "PR system should fetch from: https://am.1pwrafrica.com/api/vehicles/\n";
