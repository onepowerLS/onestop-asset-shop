<?php
/**
 * GET /api/v1/loadouts — forecast-shaped view of am_core_loadout_manifests.
 *
 * Extends the existing FM endpoint; does not replace it.
 * Query: site_id, status, cursor, limit
 */
require_once __DIR__ . '/_bootstrap.php';

$auth = am_integration_api_require();
$masters = am_v1_load_masters($auth['token']);
$manifests = am_firestore_get_collection(AM_LOADOUT_COLLECTION, 2000, $auth['token']);

$siteFilter = trim((string)($_GET['site_id'] ?? ''));
$statusFilter = trim((string)($_GET['status'] ?? ''));
$items = [];
foreach ($manifests as $m) {
    if (!is_array($m)) {
        continue;
    }
    $shaped = am_inventory_read_build_loadout($m, $masters['assetById']);
    if ($siteFilter !== '') {
        $dest = (string)($shaped['site_id'] ?? '');
        $loc = $masters['locByAnyKey'][$dest] ?? [];
        if (!am_inventory_read_site_matches($siteFilter, $dest, $loc)) {
            continue;
        }
    }
    if ($statusFilter !== '' && strcasecmp((string)$shaped['status'], $statusFilter) !== 0) {
        continue;
    }
    $items[] = $shaped;
}

usort($items, static function ($a, $b) {
    return strcmp((string)$b['dispatched_at'], (string)$a['dispatched_at']);
});

am_integration_api_emit_page($items);
