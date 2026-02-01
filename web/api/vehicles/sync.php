<?php
/**
 * Vehicle Sync Endpoint
 * 
 * One-time migration: Import vehicles from PR system into AM
 * After migration, AM becomes the single source of truth
 * 
 * POST /api/vehicles/sync.php
 * Body: { "vehicles": [...], "api_key": "..." }
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// Get request body
$input = json_decode(file_get_contents('php://input'), true);

if (!$input || !isset($input['vehicles'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid request body. Expected: { "vehicles": [...] }']);
    exit;
}

// Simple API key check (should be enhanced for production)
$expectedKey = getenv('VEHICLE_SYNC_API_KEY') ?: 'onestop-vehicle-sync-2026';
if (!isset($input['api_key']) || $input['api_key'] !== $expectedKey) {
    http_response_code(401);
    echo json_encode(['error' => 'Invalid API key']);
    exit;
}

// Get vehicles category ID
$stmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_name = 'Vehicles'");
$stmt->execute();
$vehicleCategory = $stmt->fetch();

if (!$vehicleCategory) {
    http_response_code(500);
    echo json_encode(['error' => 'Vehicles category not found']);
    exit;
}

$vehicleCategoryId = $vehicleCategory['category_id'];

// Get default country (Lesotho)
$stmt = $pdo->prepare("SELECT country_id FROM countries WHERE country_code = 'LSO'");
$stmt->execute();
$defaultCountry = $stmt->fetch();
$defaultCountryId = $defaultCountry ? $defaultCountry['country_id'] : 1;

$stats = [
    'imported' => 0,
    'updated' => 0,
    'skipped' => 0,
    'errors' => []
];

$pdo->beginTransaction();

try {
    foreach ($input['vehicles'] as $vehicle) {
        $code = $vehicle['code'] ?? $vehicle['name'] ?? null;
        $registrationNumber = $vehicle['registrationNumber'] ?? $vehicle['registration_number'] ?? null;
        $make = $vehicle['make'] ?? $vehicle['manufacturer'] ?? null;
        $model = $vehicle['model'] ?? null;
        $vinNumber = $vehicle['vinNumber'] ?? $vehicle['vin_number'] ?? null;
        $year = $vehicle['year'] ?? null;
        $isActive = isset($vehicle['isActive']) ? ($vehicle['isActive'] ? 'Available' : 'Retired') : 'Available';
        
        if (empty($code)) {
            $stats['errors'][] = "Vehicle missing code/name";
            continue;
        }
        
        // Check if vehicle already exists (by name or registration)
        $stmt = $pdo->prepare("
            SELECT asset_id FROM assets 
            WHERE category_id = ? AND (name = ? OR (asset_tag IS NOT NULL AND asset_tag = ?))
        ");
        $stmt->execute([$vehicleCategoryId, $code, $registrationNumber]);
        $existing = $stmt->fetch();
        
        if ($existing) {
            // Update existing vehicle
            $stmt = $pdo->prepare("
                UPDATE assets SET
                    asset_tag = COALESCE(?, asset_tag),
                    manufacturer = COALESCE(?, manufacturer),
                    model = COALESCE(?, model),
                    serial_number = COALESCE(?, serial_number),
                    status = ?,
                    updated_at = NOW()
                WHERE asset_id = ?
            ");
            $stmt->execute([
                $registrationNumber,
                $make,
                $model,
                $vinNumber,
                $isActive,
                $existing['asset_id']
            ]);
            $stats['updated']++;
        } else {
            // Insert new vehicle
            $stmt = $pdo->prepare("
                INSERT INTO assets (
                    name, asset_tag, manufacturer, model, serial_number,
                    category_id, country_id, status, condition_status,
                    quantity, created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'Good', 1, NOW())
            ");
            $stmt->execute([
                $code,
                $registrationNumber,
                $make,
                $model,
                $vinNumber,
                $vehicleCategoryId,
                $defaultCountryId,
                $isActive
            ]);
            
            $newId = $pdo->lastInsertId();
            
            // Generate QR code
            $qrCode = '1PWR-LSO-' . str_pad($newId, 6, '0', STR_PAD_LEFT);
            $stmt = $pdo->prepare("UPDATE assets SET qr_code_id = ? WHERE asset_id = ?");
            $stmt->execute([$qrCode, $newId]);
            
            $stats['imported']++;
        }
    }
    
    $pdo->commit();
    
    echo json_encode([
        'success' => true,
        'stats' => $stats,
        'message' => "Imported: {$stats['imported']}, Updated: {$stats['updated']}, Errors: " . count($stats['errors'])
    ]);
    
} catch (Exception $e) {
    $pdo->rollBack();
    http_response_code(500);
    echo json_encode([
        'error' => 'Sync failed: ' . $e->getMessage(),
        'stats' => $stats
    ]);
}
