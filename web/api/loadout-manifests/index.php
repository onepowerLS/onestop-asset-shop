<?php
/**
 * Load-out manifests API — for fm.1pwrafrica.com and other tools (same Firebase project).
 *
 * Auth (pick one):
 *   - Authorization: Bearer <Firebase ID token> (FM user signed in; respects Firestore rules)
 *   - GET/POST id_token=<Firebase ID token>
 *   - api_key + FIREBASE_ADMIN_BEARER_TOKEN in .env (server-to-server; rotate token periodically)
 *
 * GET  /api/loadout-manifests/index.php
 *      ?id=<manifest doc id>           — single document
 *      ?trip_id=<trip id>              — all manifests linked to that trip
 *      (no query)                      — list all (up to page size)
 *
 * POST /api/loadout-manifests/index.php  (JSON body)
 *      { "action": "link_trip", "manifest_id": "...", "trip_id": "...", "trip_label": "..." }
 *      { "action": "unlink_trip", "manifest_id": "..." }
 */

require_once __DIR__ . '/../../config/firebase.php';
require_once __DIR__ . '/../../config/firestore.php';
require_once __DIR__ . '/../../config/loadout_manifests.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

function am_loadout_api_expected_key(): string {
    return (string)(getenv('LOADOUT_MANIFEST_API_KEY') ?: 'am-loadout-manifest-dev-2026');
}

/**
 * @param string|null $rawPostBody Same request body as used for JSON decode (php://input is single-read).
 */
function am_loadout_api_resolve_token(?string $rawPostBody): string {
    $h = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/Bearer\s+(\S+)/i', $h, $m)) {
        return trim($m[1]);
    }
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        $q = trim((string)($_GET['id_token'] ?? ''));
        if ($q !== '') {
            return $q;
        }
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $rawPostBody !== null && $rawPostBody !== '') {
        $input = json_decode($rawPostBody, true);
        if (is_array($input) && !empty($input['id_token'])) {
            return trim((string)$input['id_token']);
        }
    }
    $apiKey = trim((string)($_GET['api_key'] ?? ''));
    if ($apiKey === '' && $_SERVER['REQUEST_METHOD'] === 'POST' && $rawPostBody !== null && $rawPostBody !== '') {
        $input = json_decode($rawPostBody, true);
        if (is_array($input)) {
            $apiKey = trim((string)($input['api_key'] ?? ''));
        }
    }
    if ($apiKey !== '' && hash_equals(am_loadout_api_expected_key(), $apiKey)) {
        $admin = trim((string)am_env('FIREBASE_ADMIN_BEARER_TOKEN', ''));
        if ($admin !== '') {
            return $admin;
        }
    }
    return '';
}

function am_loadout_manifest_json(array $doc): array {
    $doc['id'] = (string)($doc['id'] ?? '');
    return $doc;
}

$rawPost = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawPost = (string)file_get_contents('php://input');
}

$token = am_loadout_api_resolve_token($rawPost);
if ($token === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Missing or invalid authentication. Use Authorization: Bearer <Firebase ID token>, or id_token, or api_key with FIREBASE_ADMIN_BEARER_TOKEN configured.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $id = trim((string)($_GET['id'] ?? ''));
    $tripId = trim((string)($_GET['trip_id'] ?? ''));

    if ($id !== '') {
        $doc = am_firestore_get_document(AM_LOADOUT_COLLECTION, $id, $token);
        if (!$doc) {
            http_response_code(404);
            echo json_encode(['success' => false, 'error' => 'Manifest not found']);
            exit;
        }
        echo json_encode(['success' => true, 'manifest' => am_loadout_manifest_json($doc)], JSON_UNESCAPED_SLASHES);
        exit;
    }

    $all = am_firestore_get_collection(AM_LOADOUT_COLLECTION, 2000, $token);
    if ($tripId !== '') {
        $all = array_values(array_filter($all, fn($m) => (string)($m['trip_id'] ?? '') === $tripId));
    }
    usort($all, function ($a, $b) {
        return strcmp((string)($b['updated_at'] ?? $b['created_at'] ?? ''), (string)($a['updated_at'] ?? $a['created_at'] ?? ''));
    });
    echo json_encode([
        'success' => true,
        'count' => count($all),
        'manifests' => array_map('am_loadout_manifest_json', $all),
        'source' => 'am.1pwrafrica.com',
        'timestamp' => date('c'),
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $input = json_decode($rawPost ?? '', true);
    if (!is_array($input)) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'Invalid JSON body']);
        exit;
    }

    $action = trim((string)($input['action'] ?? ''));
    $manifestId = trim((string)($input['manifest_id'] ?? ''));

    if ($manifestId === '') {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => 'manifest_id is required']);
        exit;
    }

    if ($action === 'link_trip') {
        $tripId = trim((string)($input['trip_id'] ?? ''));
        if ($tripId === '') {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => 'trip_id is required for link_trip']);
            exit;
        }
        $tripLabel = trim((string)($input['trip_label'] ?? ''));
        $patch = [
            'trip_id' => $tripId,
            'trip_label' => $tripLabel,
            'updated_at' => date('c'),
            'linked_from_fm' => true,
        ];
        $result = am_firestore_update_document(AM_LOADOUT_COLLECTION, $manifestId, $patch, $token);
        if (!$result['ok']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Update failed']);
            exit;
        }
        $doc = am_firestore_get_document(AM_LOADOUT_COLLECTION, $manifestId, $token);
        echo json_encode(['success' => true, 'manifest' => $doc ? am_loadout_manifest_json($doc) : null], JSON_UNESCAPED_SLASHES);
        exit;
    }

    if ($action === 'unlink_trip') {
        $patch = [
            'trip_id' => '',
            'trip_label' => '',
            'linked_from_fm' => false,
            'updated_at' => date('c'),
        ];
        $result = am_firestore_update_document(AM_LOADOUT_COLLECTION, $manifestId, $patch, $token);
        if (!$result['ok']) {
            http_response_code(400);
            echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Update failed']);
            exit;
        }
        $doc = am_firestore_get_document(AM_LOADOUT_COLLECTION, $manifestId, $token);
        echo json_encode(['success' => true, 'manifest' => $doc ? am_loadout_manifest_json($doc) : null], JSON_UNESCAPED_SLASHES);
        exit;
    }

    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Unknown action. Use link_trip or unlink_trip.']);
    exit;
}

http_response_code(405);
echo json_encode(['success' => false, 'error' => 'Method not allowed']);
