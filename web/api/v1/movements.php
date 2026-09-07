<?php
/**
 * GET /api/v1/movements — append-only stock movement history.
 *
 * Reads am_core_inventory_movements and projects am_core_transactions
 * (the existing workflow ledger) so a full month of history is available
 * before new movement writes accumulate.
 *
 * Query: site_id, part_id, movement_type, occurred_since, cursor, limit
 */
require_once __DIR__ . '/_bootstrap.php';

$auth = am_integration_api_require();
$masters = am_v1_load_masters($auth['token']);
$ledger = am_firestore_get_collection(AM_INVENTORY_MOVEMENTS_COLLECTION, 4000, $auth['token']);
$transactions = am_firestore_get_collection('am_core_transactions', 4000, $auth['token']);

$items = am_inventory_read_build_movements(
    $ledger,
    $transactions,
    $masters['assetById'],
    $masters['locByAnyKey'],
    trim((string)($_GET['site_id'] ?? '')),
    trim((string)($_GET['part_id'] ?? '')),
    trim((string)($_GET['movement_type'] ?? '')),
    trim((string)($_GET['occurred_since'] ?? ''))
);

am_integration_api_emit_page($items);
