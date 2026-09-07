<?php
/**
 * GET /api/v1/allocations — committed stock not yet consumed.
 *
 * Query: site_id, status (reserved|issued|consumed|returned), cursor, limit
 */
require_once __DIR__ . '/_bootstrap.php';

$auth = am_integration_api_require();
$masters = am_v1_load_masters($auth['token']);
$allocations = am_firestore_get_collection('am_core_allocations', 4000, $auth['token']);
$requests = am_firestore_get_collection('am_core_requests', 2000, $auth['token']);

$items = am_inventory_read_build_allocations(
    $allocations,
    $requests,
    $masters['assetById'],
    $masters['locByAnyKey'],
    trim((string)($_GET['site_id'] ?? '')),
    trim((string)($_GET['status'] ?? ''))
);

am_integration_api_emit_page($items);
