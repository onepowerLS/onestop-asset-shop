<?php
/**
 * Inventory search API for dispatch request line-item builder.
 * GET ?country_id=X&q=searchterm
 * Returns JSON array of matching items in that country (same country resolution as the catalog).
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/firestore.php';
require_once __DIR__ . '/../../config/country_scope.php';
require_once __DIR__ . '/../../config/catalog_search.php';
require_login();

header('Content-Type: application/json');
set_time_limit(90);

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

$countries = am_get_countries();
if (!am_user_may_access_country_id($countryId, $countries)) {
    echo json_encode(['ok' => false, 'error' => 'Country not in your scope', 'items' => []]);
    exit;
}

/**
 * Smaller pages stay under the Firestore timeout. A two-minute file cache
 * lets the next search succeed when the first download was interrupted.
 *
 * @return list<array<string, mixed>>
 */
function am_dispatch_search_collection(string $name, int $pageSize): array {
    $file = sys_get_temp_dir() . '/am_dispatch_' . preg_replace('/[^a-z0-9_]+/i', '_', $name) . '.json';
    if (is_file($file) && (time() - filemtime($file)) < 120) {
        $cached = json_decode((string)file_get_contents($file), true);
        if (is_array($cached) && $cached !== []) {
            return $cached;
        }
    }
    $token = function_exists('am_firestore_admin_bearer') ? am_firestore_admin_bearer() : '';
    $rows = am_firestore_get_collection($name, $pageSize, $token !== '' ? $token : null);
    if ($rows !== []) {
        $encoded = json_encode($rows);
        if (is_string($encoded) && $encoded !== '') {
            @file_put_contents($file, $encoded);
        }
    }
    return $rows;
}

$assets = am_dispatch_search_collection('am_core_assets', 250);
$locations = am_get_pr_sites();
$inventoryLevels = am_dispatch_search_collection('am_core_inventory_levels', 250);
$categories = am_dispatch_search_collection('pr_master_categories', 250);
if ($assets === []) {
    echo json_encode(['ok' => false, 'error' => 'The catalog could not be loaded. Wait a few seconds and search again.', 'items' => []]);
    exit;
}

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

// Build stock index: asset_id => totals across locations
$stockByAsset = [];
foreach ($inventoryLevels as $inv) {
    $aid = (string)($inv['asset_id'] ?? '');
    if ($aid === '') {
        continue;
    }
    $qoh = (int)($inv['quantity_on_hand'] ?? 0);
    $alloc = (int)($inv['quantity_allocated'] ?? 0);
    if (!isset($stockByAsset[$aid])) {
        $stockByAsset[$aid] = ['qoh' => 0, 'alloc' => 0];
    }
    $stockByAsset[$aid]['qoh'] += $qoh;
    $stockByAsset[$aid]['alloc'] += $alloc;
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
        $match = am_catalog_search_match(am_catalog_search_fields($asset, [
            'category_name' => (string)($cat['category_name'] ?? ''),
            'location_name' => (string)($loc['location_name'] ?? ''),
            'location_code' => (string)($loc['location_code'] ?? ''),
        ]), $qRaw);
        if ($match === null) {
            continue;
        }
        $searchScore = $match['score'];
    } else {
        $searchScore = 0;
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
        'quantity_on_hand' => (int)(($stockByAsset[$aid]['qoh'] ?? 0)),
        'quantity_allocated' => (int)(($stockByAsset[$aid]['alloc'] ?? 0)),
        'quantity_available' => max(
            0,
            (int)(($stockByAsset[$aid]['qoh'] ?? 0)) - (int)(($stockByAsset[$aid]['alloc'] ?? 0))
        ),
        'score' => $searchScore,
    ];
}

usort($results, function ($a, $b) {
    if (($a['score'] ?? 0) !== ($b['score'] ?? 0)) {
        return ($b['score'] ?? 0) <=> ($a['score'] ?? 0);
    }
    return strcasecmp($a['name'], $b['name']);
});
$results = array_slice($results, 0, 100);

echo json_encode(['ok' => true, 'items' => $results], JSON_UNESCAPED_SLASHES);
