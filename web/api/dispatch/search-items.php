<?php
/**
 * Inventory search API for dispatch request line-item builder.
 * GET ?country_id=X&q=searchterm
 * Returns JSON array of matching stockable items.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/firestore.php';
require_once __DIR__ . '/../../config/country_scope.php';
require_login();

header('Content-Type: application/json');

$countryId = trim($_GET['country_id'] ?? '');
$q = strtolower(trim($_GET['q'] ?? ''));

if ($countryId === '') {
    echo json_encode(['ok' => false, 'error' => 'country_id required', 'items' => []]);
    exit;
}

if (!am_user_may_access_country_id($countryId, am_firestore_get_collection('pr_master_countries', 500))) {
    echo json_encode(['ok' => false, 'error' => 'Country not in your scope', 'items' => []]);
    exit;
}

$assets = am_firestore_get_collection('am_core_assets', 3000);
$countries = am_firestore_get_collection('pr_master_countries', 500);
$locations = am_get_pr_sites();

$locationById = [];
foreach ($locations as $l) {
    $lid = (string)($l['location_id'] ?? $l['id'] ?? '');
    if ($lid !== '') $locationById[$lid] = $l;
}

$stockableClasses = am_inventory_stockable_classes(); // ['Material', 'Consumable', 'Inventory']
$results = [];

foreach ($assets as $asset) {
    $ic = (string)($asset['item_class'] ?? '');
    if (!in_array($ic, $stockableClasses, true)) continue;

    if (!am_asset_passes_country_scope($asset, $countries, $locationById)) continue;

    $assetCountryId = am_resolve_asset_country_id($asset, $countries);
    if ($assetCountryId !== $countryId) continue;

    $status = (string)($asset['status'] ?? '');
    if (in_array($status, ['WrittenOff', 'Retired', 'Consumed'], true)) continue;

    if ($q !== '') {
        $blob = strtolower(implode(' ', [
            (string)($asset['name'] ?? ''),
            (string)($asset['asset_tag'] ?? ''),
            (string)($asset['legacy_tag'] ?? ''),
            (string)($asset['description'] ?? ''),
        ]));
        if (!str_contains($blob, $q)) continue;
    }

    $results[] = [
        'asset_id'      => (string)($asset['asset_id'] ?? $asset['id'] ?? ''),
        'name'          => (string)($asset['name'] ?? ''),
        'asset_tag'     => (string)($asset['asset_tag'] ?? ''),
        'legacy_tag'    => (string)($asset['legacy_tag'] ?? ''),
        'item_class'    => $ic,
        'category_name' => (string)($asset['category_name'] ?? ''),
        'unit_of_measure'=> (string)($asset['unit_of_measure'] ?? 'EA'),
        'location_name' => (string)($asset['location_name'] ?? ''),
        'status'        => (string)($asset['status'] ?? ''),
    ];

    if (count($results) >= 100) break;
}

echo json_encode(['ok' => true, 'items' => $results], JSON_UNESCAPED_SLASHES);
