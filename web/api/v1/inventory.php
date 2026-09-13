<?php
/**
 * GET /api/v1/inventory — current stock position by part and site.
 *
 * Query: site_id, store_id, part_id, category, updated_since, cursor, limit
 *
 * Each position includes reconciliation_status:
 *   ok | unverified | duplicate_location | sum_mismatch | unresolvable_location
 * Consumers must not treat non-ok rows as verified stock for lender/forecast use.
 */
require_once __DIR__ . '/_bootstrap.php';

$auth = am_integration_api_require();
$masters = am_v1_load_masters($auth['token']);
$levels = am_firestore_get_collection('am_core_inventory_levels', 4000, $auth['token']);

$items = am_inventory_read_build_positions(
    $levels,
    $masters['assetById'],
    $masters['locByAnyKey'],
    $masters['categoryById'],
    trim((string)($_GET['site_id'] ?? '')),
    trim((string)($_GET['store_id'] ?? '')),
    trim((string)($_GET['part_id'] ?? '')),
    trim((string)($_GET['category'] ?? '')),
    trim((string)($_GET['updated_since'] ?? ''))
);

am_integration_api_emit_page($items);
