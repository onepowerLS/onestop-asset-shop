<?php
/**
 * Shared bootstrap for /api/v1/* read endpoints.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/firestore.php';
require_once __DIR__ . '/../../config/canonical_sync.php';
require_once __DIR__ . '/../../config/integration_api.php';
require_once __DIR__ . '/../../config/inventory_read.php';
require_once __DIR__ . '/../../config/loadout_manifests.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

/**
 * @param list<array<string, mixed>> $rows
 * @return array<string, array<string, mixed>>
 */
function am_v1_index_by_id(array $rows, string $primary = 'id', string $alt = ''): array {
    $out = [];
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $id = (string)($row[$primary] ?? '');
        if ($id === '' && $alt !== '') {
            $id = (string)($row[$alt] ?? '');
        }
        if ($id !== '') {
            $out[$id] = $row;
        }
        $doc = (string)($row['id'] ?? '');
        if ($doc !== '' && !isset($out[$doc])) {
            $out[$doc] = $row;
        }
    }
    return $out;
}

/**
 * @return array{assets: list<array<string,mixed>>, assetById: array<string,array<string,mixed>>, locByAnyKey: array<string,array<string,mixed>>, categoryById: array<string,array<string,mixed>>}
 */
function am_v1_load_masters(string $token): array {
    $assets = am_firestore_get_collection('am_core_assets', 4000, $token);
    $sites = am_get_pr_sites();
    $categories = am_firestore_get_collection('pr_master_categories', 1000, $token);
    $assetById = am_v1_index_by_id($assets, 'asset_id', 'id');
    $locByAnyKey = am_build_location_index($sites);
    $categoryById = am_v1_index_by_id($categories, 'category_id', 'id');
    foreach ($categories as $c) {
        $code = (string)($c['category_code'] ?? '');
        if ($code !== '' && !isset($categoryById[$code])) {
            $categoryById[$code] = $c;
        }
    }
    return [
        'assets' => $assets,
        'assetById' => $assetById,
        'locByAnyKey' => $locByAnyKey,
        'categoryById' => $categoryById,
    ];
}
