<?php
/**
 * UGP → AM inventory alignment (server-to-server).
 *
 * POST /api/ugp/parts-sync.php
 * Headers: X-API-Key: <UGP_PARTS_SYNC_API_KEY>
 * Body JSON:
 * {
 *   "country_id": "<pr_master_countries id>",
 *   "parts": [
 *     { "ugp_part_id": "...", "name": "...", "description": "...", "quantity": 0, "unit_of_measure": "EA" }
 *   ],
 *   "link_on_normalized_name": true,
 *   "dry_run": false
 * }
 *
 * Or use Authorization: Bearer <Firebase ID token> (Manager+) instead of API key.
 */
require_once __DIR__ . '/../../config/firebase.php';
require_once __DIR__ . '/../../config/firestore.php';
require_once __DIR__ . '/../../config/ugp_parts.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-API-Key');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

function am_ugp_expected_api_key(): string {
    return (string)(getenv('UGP_PARTS_SYNC_API_KEY') ?: 'ugp-parts-sync-dev-2026');
}

function am_ugp_resolve_sync_token(): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $h, $m)) {
        return trim($m[1]);
    }
    $key = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    if ($key !== '' && hash_equals(am_ugp_expected_api_key(), $key)) {
        $admin = trim((string)am_env('FIREBASE_ADMIN_BEARER_TOKEN', ''));
        if ($admin !== '') {
            return $admin;
        }
    }
    return '';
}

$raw = (string)file_get_contents('php://input');
$input = json_decode($raw, true);
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON']);
    exit;
}

$token = am_ugp_resolve_sync_token();
if ($token === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authenticate with Authorization: Bearer <Firebase ID token> or X-API-Key + FIREBASE_ADMIN_BEARER_TOKEN on server.',
    ]);
    exit;
}

$countryId = trim((string)($input['country_id'] ?? ''));
$parts = $input['parts'] ?? null;
if ($countryId === '' || !is_array($parts)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'country_id and parts[] are required']);
    exit;
}

$countries = am_firestore_get_collection('pr_master_countries', 500, $token);
$categories = am_firestore_get_collection('pr_master_categories', 1000, $token);
$allAssets = am_firestore_get_collection('am_core_assets', 2000, $token);

$linkName = (bool)($input['link_on_normalized_name'] ?? true);
$dryRun = (bool)($input['dry_run'] ?? false);

$results = [];
$stats = ['updated' => 0, 'linked' => 0, 'created' => 0, 'ambiguous' => 0, 'errors' => 0];

foreach ($parts as $part) {
    if (!is_array($part)) {
        continue;
    }
    $part['country_id'] = $countryId;
    $ctx = [
        'countries' => $countries,
        'categories' => $categories,
        'all_assets' => $allAssets,
        'id_token_override' => $token,
        'link_on_normalized_name' => $linkName,
        'dry_run' => $dryRun,
        'created_by' => 'ugp-sync-api',
    ];
    $r = am_ugp_sync_single_part($part, $ctx);
    $results[] = $r;
    $act = (string)($r['action'] ?? '');
    if ($r['ok'] ?? false) {
        if ($act === 'updated') {
            $stats['updated']++;
        } elseif ($act === 'linked') {
            $stats['linked']++;
        } elseif ($act === 'created') {
            $stats['created']++;
        }
        if ($act === 'created' && !empty($r['asset_id'])) {
            $allAssets[] = [
                'id' => $r['asset_id'],
                'asset_id' => $r['asset_id'],
                'asset_tag' => $r['asset_tag'] ?? '',
                'item_class' => 'Inventory',
            ];
        }
    } else {
        if ($act === 'ambiguous') {
            $stats['ambiguous']++;
        } else {
            $stats['errors']++;
        }
    }
}

echo json_encode([
    'success' => true,
    'stats' => $stats,
    'results' => $results,
    'timestamp' => date('c'),
], JSON_UNESCAPED_SLASHES);
