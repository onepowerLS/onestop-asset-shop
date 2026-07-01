<?php
/**
 * Generic catalog search API for the Add Item flow and similar "look before you add" UX.
 * GET ?q=searchterm&country_id=X&item_class=Y (country_id and item_class optional)
 * Returns JSON array of matching items visible to the user.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/firestore.php';
require_once __DIR__ . '/../../config/country_scope.php';
require_once __DIR__ . '/../../config/inventory_levels.php';
require_login();

header('Content-Type: application/json');

$qRaw = trim((string)($_GET['q'] ?? ''));
$q = $qRaw === '' ? '' : (function_exists('mb_strtolower') ? mb_strtolower($qRaw, 'UTF-8') : strtolower($qRaw));
if ($q !== '') {
    $q = preg_replace('/\s+/u', ' ', $q);
}
$countryId = trim((string)($_GET['country_id'] ?? ''));
$classFilter = trim((string)($_GET['item_class'] ?? ''));
$limit = max(1, min(100, (int)($_GET['limit'] ?? 50)));

if ($q === '') {
    echo json_encode(['ok' => true, 'items' => [], 'q' => '']);
    exit;
}

$countries = am_firestore_get_collection('pr_master_countries', 500);
if ($countryId !== '' && !am_user_may_access_country_id($countryId, $countries)) {
    echo json_encode(['ok' => false, 'error' => 'Country not in your scope', 'items' => []]);
    exit;
}

$assets = am_firestore_get_collection('am_core_assets', 10000);
$locations = am_get_pr_sites();
$inventoryLevels = am_firestore_get_collection('am_core_inventory_levels', 5000);
$categories = am_firestore_get_collection('pr_master_categories', 1000);

$locationById = am_build_location_index($locations);
$categoryById = [];
foreach ($categories as $c) {
    $cid = (string)($c['category_id'] ?? $c['id'] ?? '');
    if ($cid !== '') {
        $categoryById[$cid] = $c;
    }
}

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

$lower = static function (string $s): string {
    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
};

$normalizedKey = static function (string $s) use ($lower): string {
    $s = $lower($s);
    $s = preg_replace('/[^a-z0-9]+/i', ' ', $s);
    return preg_replace('/\s+/u', ' ', trim($s));
};

$qKey = $normalizedKey($qRaw);

$results = [];

foreach ($assets as $asset) {
    $ic = (string)($asset['item_class'] ?? '');
    if (!in_array($ic, ['FixedAsset', 'Material', 'Consumable', 'Inventory'], true)) {
        continue;
    }
    if ($classFilter !== '' && $ic !== $classFilter) {
        continue;
    }

    if (!am_asset_passes_country_scope($asset, $countries, $locationById)) {
        continue;
    }

    if ($countryId !== '') {
        $assetCountryId = am_resolve_asset_country_id($asset, $countries);
        if ($assetCountryId !== '' && $assetCountryId !== $countryId) {
            continue;
        }
    }

    $status = (string)($asset['status'] ?? '');
    if (in_array($status, ['WrittenOff', 'Retired', 'Consumed'], true)) {
        continue;
    }

    $catId = (string)($asset['category_id'] ?? '');
    $cat = $categoryById[$catId] ?? [];
    $locId = (string)($asset['location_id'] ?? '');
    $loc = $locationById[$locId] ?? [];

    $blobParts = [
        (string)($asset['name'] ?? ''),
        (string)($asset['asset_tag'] ?? ''),
        (string)($asset['legacy_tag'] ?? ''),
        (string)($asset['qr_code_id'] ?? ''),
        (string)($asset['description'] ?? ''),
        (string)($asset['manufacturer'] ?? ''),
        (string)($asset['model'] ?? ''),
        (string)($asset['notes'] ?? ''),
        (string)($asset['ugp_part_id'] ?? ''),
        (string)($cat['category_name'] ?? ''),
        (string)($cat['category_code'] ?? ''),
        (string)($loc['location_name'] ?? ''),
        (string)($loc['location_code'] ?? ''),
    ];
    $blob = preg_replace('/\s+/u', ' ', implode(' ', $blobParts));
    $blob = $lower($blob);
    if (!str_contains($blob, $q)) {
        continue;
    }

    $nameKey = $normalizedKey((string)($asset['name'] ?? ''));
    $isStrongMatch = $qKey !== '' && $nameKey !== '' && (
        $nameKey === $qKey
        || str_starts_with($nameKey, $qKey)
        || str_contains($nameKey, ' ' . $qKey)
        || str_contains($nameKey, $qKey . ' ')
    );

    $aid = (string)($asset['asset_id'] ?? $asset['id'] ?? '');
    $results[] = [
        'asset_id' => $aid,
        'name' => (string)($asset['name'] ?? ''),
        'asset_tag' => (string)($asset['asset_tag'] ?? ''),
        'legacy_tag' => (string)($asset['legacy_tag'] ?? ''),
        'item_class' => $ic,
        'category_name' => (string)($cat['category_name'] ?? ''),
        'location_name' => (string)($loc['location_name'] ?? ''),
        'country_code' => (string)($loc['country_code'] ?? ''),
        'unit_of_measure' => (string)($asset['unit_of_measure'] ?? 'EA'),
        'status' => $status,
        'quantity' => (int)($asset['quantity'] ?? 0),
        'quantity_on_hand' => (int)(($stockByAsset[$aid]['qoh'] ?? 0)),
        'quantity_allocated' => (int)(($stockByAsset[$aid]['alloc'] ?? 0)),
        'strong_match' => $isStrongMatch,
        'view_url' => base_url('assets/view.php?id=' . urlencode($aid)),
        'edit_url' => base_url('assets/edit.php?id=' . urlencode($aid)),
    ];

    if (count($results) >= $limit) {
        break;
    }
}

usort($results, function ($a, $b) {
    if ($a['strong_match'] !== $b['strong_match']) {
        return $b['strong_match'] ? 1 : -1;
    }
    return strcasecmp($a['name'], $b['name']);
});

echo json_encode(['ok' => true, 'items' => $results, 'q' => $qRaw], JSON_UNESCAPED_SLASHES);
