<?php
/**
 * GET /api/v1/parts — AM part master as AM holds it.
 *
 * Query: unmapped=true (no ugp_part_id), category, cursor, limit
 */
require_once __DIR__ . '/_bootstrap.php';

$auth = am_integration_api_require();
$masters = am_v1_load_masters($auth['token']);
$unmapped = in_array(strtolower(trim((string)($_GET['unmapped'] ?? ''))), ['1', 'true', 'yes'], true);

$items = am_inventory_read_build_parts($masters['assets'], $masters['categoryById'], $unmapped);
$catFilter = trim((string)($_GET['category'] ?? ''));
if ($catFilter !== '') {
    $items = array_values(array_filter($items, static function ($p) use ($catFilter) {
        return strcasecmp((string)$p['category'], $catFilter) === 0;
    }));
}

am_integration_api_emit_page($items);
