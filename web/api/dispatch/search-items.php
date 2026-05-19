<?php
/**
 * Inventory search API for dispatch request line-item builder.
 * GET ?country_id=X&q=searchterm
 * Returns JSON array of matching items in that country (same country resolution as the catalog).
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/firestore.php';
require_once __DIR__ . '/../../config/country_scope.php';
require_login();

header('Content-Type: application/json');

$countryId = trim($_GET['country_id'] ?? '');
$qRaw = trim((string)($_GET['q'] ?? ''));
$q = $qRaw === '' ? '' : (function_exists('mb_strtolower') ? mb_strtolower($qRaw, 'UTF-8') : strtolower($qRaw));
if ($q !== '') {
    $q = preg_replace('/\s+/u', ' ', $q);
}

if ($countryId === '') {
    echo json_encode(['ok' => false, 'error' => 'country_id required', 'items' => []]);
    exit;
}

$countries = am_firestore_get_collection('pr_master_countries', 500);
if (!am_user_may_access_country_id($countryId, $countries)) {
    echo json_encode(['ok' => false, 'error' => 'Country not in your scope', 'items' => []]);
    exit;
}

$assets = am_firestore_get_collection('am_core_assets', 10000);
$locations = am_get_pr_sites();
$inventoryLevels = am_firestore_get_collection('am_core_inventory_levels', 4000);
$categories = am_firestore_get_collection('pr_master_categories', 1000);

$locationById = [];
foreach ($locations as $l) {
    $lid = (string)($l['location_id'] ?? $l['id'] ?? '');
    if ($lid !== '') {
        $locationById[$lid] = $l;
    }
}

$categoryById = [];
foreach ($categories as $c) {
    $cid = (string)($c['category_id'] ?? $c['id'] ?? '');
    if ($cid !== '') {
        $categoryById[$cid] = $c;
    }
}

// Build stock index: asset_id => total qoh in any location
$stockByAsset = [];
foreach ($inventoryLevels as $inv) {
    $aid = (string)($inv['asset_id'] ?? '');
    if ($aid === '') {
        continue;
    }
    $qoh = (int)($inv['quantity_on_hand'] ?? 0);
    if ($qoh <= 0) {
        continue;
    }
    $stockByAsset[$aid] = ($stockByAsset[$aid] ?? 0) + $qoh;
}

/**
 * Match dispatch country filter to catalog behaviour: use master country_id when set,
 * otherwise infer from country_code / tags / location (same helpers as listings).
 */
function am_dispatch_search_resolve_asset_country_id(array $asset, array $countries, array $locationById): string {
    $cid = am_resolve_asset_country_id($asset, $countries);
    if ($cid !== '') {
        return $cid;
    }
    $code = am_asset_effective_org_country_code($asset, $countries, $locationById);
    if ($code === '') {
        return '';
    }
    foreach ($countries as $c) {
        $cc = strtoupper(trim((string)($c['country_code'] ?? '')));
        if ($cc !== '' && $cc === $code) {
            return (string)($c['country_id'] ?? $c['id'] ?? '');
        }
    }
    return '';
}

$lower = static function (string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
};

$results = [];

foreach ($assets as $asset) {
    $ic = (string)($asset['item_class'] ?? '');
    if (!in_array($ic, ['FixedAsset', 'Material', 'Consumable', 'Inventory'], true)) {
        continue;
    }

    if (!am_asset_passes_country_scope($asset, $countries, $locationById)) {
        continue;
    }

    $assetCountryId = am_dispatch_search_resolve_asset_country_id($asset, $countries, $locationById);
    if ($assetCountryId !== $countryId) {
        continue;
    }

    $status = (string)($asset['status'] ?? '');
    if (in_array($status, ['WrittenOff', 'Retired', 'Consumed'], true)) {
        continue;
    }

    if ($q !== '') {
        $catId = (string)($asset['category_id'] ?? '');
        $cat = $categoryById[$catId] ?? [];
        $locId = (string)($asset['location_id'] ?? '');
        $loc = $locationById[$locId] ?? [];
        $blobParts = [
            (string)($asset['name'] ?? ''),
            (string)($asset['asset_tag'] ?? ''),
            (string)($asset['legacy_tag'] ?? ''),
            (string)($asset['description'] ?? ''),
            (string)($asset['manufacturer'] ?? ''),
            (string)($asset['model'] ?? ''),
            (string)($asset['notes'] ?? ''),
            (string)($asset['ugp_part_id'] ?? ''),
            (string)($cat['category_name'] ?? ''),
            (string)($loc['location_name'] ?? ''),
            (string)($loc['location_code'] ?? ''),
        ];
        $blob = preg_replace('/\s+/u', ' ', implode(' ', $blobParts));
        $blob = $lower($blob);
        if (!str_contains($blob, $q)) {
            continue;
        }
    }

    $aid = (string)($asset['asset_id'] ?? $asset['id'] ?? '');
    $locId = (string)($asset['location_id'] ?? '');
    $loc = $locationById[$locId] ?? [];
    $catId = (string)($asset['category_id'] ?? '');
    $cat = $categoryById[$catId] ?? [];

    $results[] = [
        'asset_id' => $aid,
        'name' => (string)($asset['name'] ?? ''),
        'asset_tag' => (string)($asset['asset_tag'] ?? ''),
        'legacy_tag' => (string)($asset['legacy_tag'] ?? ''),
        'item_class' => $ic,
        'category_name' => (string)($cat['category_name'] ?? ''),
        'unit_of_measure' => (string)($asset['unit_of_measure'] ?? 'EA'),
        'location_name' => (string)($loc['location_name'] ?? ''),
        'status' => (string)($asset['status'] ?? ''),
        'quantity_on_hand' => $stockByAsset[$aid] ?? 0,
    ];

    if (count($results) >= 100) {
        break;
    }
}

echo json_encode(['ok' => true, 'items' => $results], JSON_UNESCAPED_SLASHES);
