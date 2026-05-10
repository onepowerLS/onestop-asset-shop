<?php
/**
 * Vehicles API Endpoint
 *
 * Provides vehicle data for external systems (PR system).
 * AM is the single source of truth for vehicle data.
 * Reads from Firestore am_core_assets (post-migration).
 *
 * GET /api/vehicles/ - List all vehicles
 * GET /api/vehicles/?id=X - Get specific vehicle
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/firestore.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$vehicleCategories = ['FA-VEH', 'FA-VEH-4X4', 'FA-VEH-TRUCK', 'FA-VEH-EQUIP'];

// Fetch all assets and filter to vehicles
$assets = am_firestore_get_collection('am_core_assets', 2000);
$countries = am_firestore_get_collection('pr_master_countries', 500);
$locations = am_get_pr_sites();

$countryById = [];
foreach ($countries as $c) {
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($cid !== '') $countryById[$cid] = $c;
}
$locationById = [];
foreach ($locations as $l) {
    $lid = (string)($l['location_id'] ?? $l['id'] ?? '');
    if ($lid !== '') $locationById[$lid] = $l;
}

$vehicles = [];
foreach ($assets as $a) {
    $cls = (string)($a['item_class'] ?? '');
    $cat = (string)($a['category_id'] ?? '');
    if ($cls !== 'FixedAsset' || !in_array($cat, $vehicleCategories, true)) continue;

    $cid = (string)($a['country_id'] ?? '');
    $lid = (string)($a['location_id'] ?? '');
    $country = $countryById[$cid] ?? [];
    $loc = $locationById[$lid] ?? [];

    $vehicles[] = [
        'id'                 => $a['asset_id'] ?? $a['id'] ?? '',
        'code'               => (string)($a['name'] ?? ''),
        'name'               => (string)($a['name'] ?? ''),
        'vinNumber'          => (string)($a['serial_number'] ?? '') ?: null,
        'make'               => (string)($a['manufacturer'] ?? '') ?: null,
        'model'              => (string)($a['model'] ?? '') ?: null,
        'registrationNumber' => (string)($a['legacy_tag'] ?? '') ?: null,
        'year'               => isset($a['vehicle_year']) ? (int)$a['vehicle_year'] : null,
        'engineNumber'       => (string)($a['engine_number'] ?? '') ?: null,
        'status'             => (string)($a['status'] ?? 'Available'),
        'isActive'           => (string)($a['status'] ?? '') === 'Available' ? 1 : 0,
        'organization'       => (string)($country['country_code'] ?? ''),
        'location'           => (string)($loc['location_name'] ?? '') ?: null,
        'vehicleType'        => (string)($a['vehicle_type'] ?? '') ?: null,
        'notes'              => (string)($a['notes'] ?? '') ?: null,
        'qrCode'             => (string)($a['qr_code_id'] ?? '') ?: null,
        'assetTag'           => (string)($a['asset_tag'] ?? ''),
    ];
}

// Handle specific vehicle request
if (isset($_GET['id'])) {
    $requestedId = trim($_GET['id']);
    foreach ($vehicles as $v) {
        if ((string)$v['id'] === $requestedId) {
            echo json_encode(['success' => true, 'vehicle' => $v]);
            exit;
        }
    }
    http_response_code(404);
    echo json_encode(['error' => 'Vehicle not found']);
    exit;
}

usort($vehicles, fn($a, $b) => strcmp($a['name'], $b['name']));

echo json_encode([
    'success'   => true,
    'count'     => count($vehicles),
    'vehicles'  => $vehicles,
    'source'    => 'AM (Firestore)',
    'timestamp' => date('c'),
]);
