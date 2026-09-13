<?php
/**
 * GET /api/v1/parts — AM part master as AM holds it.
 *
 * Query: unmapped=true (no ugp_part_id), category, cursor, limit
 *
 * Each part includes catalogue stewardship fields:
 *   definition_id, classification, forecast_ready, catalogue_status, catalogue_reason
 * so forecast can separate incomplete catalogue capture from missing/incompatible mappings.
 */
require_once __DIR__ . '/_bootstrap.php';
require_once dirname(__DIR__, 2) . '/config/part_definitions.php';

$auth = am_integration_api_require();
$masters = am_v1_load_masters($auth['token']);
$unmapped = in_array(strtolower(trim((string)($_GET['unmapped'] ?? ''))), ['1', 'true', 'yes'], true);

$definitions = am_firestore_get_collection(AM_PART_DEFINITIONS_COLLECTION, 3000, $auth['token']);
$definitionById = [];
foreach ($definitions as $d) {
    $did = (string)($d['id'] ?? '');
    if ($did !== '') {
        $definitionById[$did] = $d;
    }
}

$tasks = am_firestore_get_collection(AM_CATALOGUE_TASKS_COLLECTION, 2000, $auth['token']);
$openTasksByAsset = [];
foreach ($tasks as $t) {
    if (!in_array((string)($t['status'] ?? ''), ['open', 'in_progress'], true)) {
        continue;
    }
    $aid = trim((string)($t['asset_id'] ?? ''));
    if ($aid === '') {
        continue;
    }
    if (!isset($openTasksByAsset[$aid])) {
        $openTasksByAsset[$aid] = [];
    }
    $openTasksByAsset[$aid][] = $t;
}

$items = am_inventory_read_build_parts(
    $masters['assets'],
    $masters['categoryById'],
    $unmapped,
    $definitionById,
    $openTasksByAsset
);
$catFilter = trim((string)($_GET['category'] ?? ''));
if ($catFilter !== '') {
    $items = array_values(array_filter($items, static function ($p) use ($catFilter) {
        return strcasecmp((string)$p['category'], $catFilter) === 0;
    }));
}

am_integration_api_emit_page($items);
