<?php
/**
 * Vehicles API Endpoint
 * 
 * Provides vehicle data for external systems (PR system)
 * AM is the single source of truth for vehicle data
 * 
 * GET /api/vehicles/ - List all active vehicles
 * GET /api/vehicles/?id=X - Get specific vehicle
 * POST /api/vehicles/sync.php - Sync vehicles from PR (one-time migration)
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
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

// Handle specific vehicle request
if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("
        SELECT 
            a.asset_id,
            a.name,
            a.serial_number as vin_number,
            a.manufacturer as make,
            a.model,
            a.asset_tag as registration_number,
            a.purchase_date,
            a.status,
            a.condition_status,
            a.notes,
            c.country_code,
            l.location_name
        FROM assets a
        LEFT JOIN countries c ON a.country_id = c.country_id
        LEFT JOIN locations l ON a.location_id = l.location_id
        WHERE a.asset_id = ? AND a.category_id = ?
    ");
    $stmt->execute([$_GET['id'], $vehicleCategoryId]);
    $vehicle = $stmt->fetch();
    
    if ($vehicle) {
        echo json_encode(['success' => true, 'vehicle' => $vehicle]);
    } else {
        http_response_code(404);
        echo json_encode(['error' => 'Vehicle not found']);
    }
    exit;
}

// List all active vehicles
$stmt = $pdo->prepare("
    SELECT 
        a.asset_id as id,
        a.name as code,
        a.name,
        a.serial_number as vinNumber,
        a.manufacturer as make,
        a.model,
        a.asset_tag as registrationNumber,
        a.status,
        CASE WHEN a.status = 'Available' THEN 1 ELSE 0 END as isActive,
        c.country_code as organization,
        l.location_name as location
    FROM assets a
    LEFT JOIN countries c ON a.country_id = c.country_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    WHERE a.category_id = ?
    ORDER BY a.name
");
$stmt->execute([$vehicleCategoryId]);
$vehicles = $stmt->fetchAll();

echo json_encode([
    'success' => true,
    'count' => count($vehicles),
    'vehicles' => $vehicles,
    'source' => 'AM',
    'timestamp' => date('c')
]);
