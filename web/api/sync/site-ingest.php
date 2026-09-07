<?php
/**
 * PR → AM site fanout ingest endpoint.
 *
 * POST /api/sync/site-ingest.php
 * Auth: X-API-Key: <SITE_SYNC_FANOUT_API_KEY>
 *    or Authorization: Bearer <Firebase ID token>
 */
require_once __DIR__ . '/../../config/firebase.php';
require_once __DIR__ . '/../../config/firestore.php';

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

function am_site_sync_expected_key(): string {
    return trim((string)(getenv('SITE_SYNC_FANOUT_API_KEY') ?: ''));
}

function am_site_sync_resolve_token(): string {
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return trim((string)$m[1]);
    }

    $provided = trim((string)($_SERVER['HTTP_X_API_KEY'] ?? ''));
    $expected = am_site_sync_expected_key();
    if ($provided !== '' && $expected !== '' && hash_equals($expected, $provided)) {
        return trim((string)am_env('FIREBASE_ADMIN_BEARER_TOKEN', ''));
    }
    return '';
}

function am_site_sync_parse_json(): ?array {
    $raw = (string)file_get_contents('php://input');
    $payload = json_decode($raw, true);
    return is_array($payload) ? $payload : null;
}

function am_site_sync_validate(array $input): array {
    $site = is_array($input['site'] ?? null) ? $input['site'] : [];
    $errors = [];

    $organizationId = strtolower(trim((string)($site['organizationId'] ?? '')));
    $code = strtoupper(trim((string)($site['code'] ?? '')));
    $name = trim((string)($site['name'] ?? ''));
    $lat = isset($site['latitude']) ? (float)$site['latitude'] : null;
    $lng = isset($site['longitude']) ? (float)$site['longitude'] : null;
    $country = strtoupper(trim((string)($site['countryCode'] ?? '')));

    if ($organizationId === '') $errors[] = 'site.organizationId is required';
    if ($code === '') $errors[] = 'site.code is required';
    if ($name === '') $errors[] = 'site.name is required';
    if ($lat === null || $lat < -90 || $lat > 90) $errors[] = 'site.latitude must be between -90 and 90';
    if ($lng === null || $lng < -180 || $lng > 180) $errors[] = 'site.longitude must be between -180 and 180';
    if (($input['idempotencyKey'] ?? '') === '') $errors[] = 'idempotencyKey is required';

    return [
        'errors' => $errors,
        'normalized' => [
            'organizationId' => $organizationId,
            'countryCode' => $country ?: 'LSO',
            'code' => $code,
            'name' => $name,
            'active' => ($site['active'] ?? true) ? true : false,
            'latitude' => $lat,
            'longitude' => $lng,
            'externalIds' => is_array($site['externalIds'] ?? null) ? $site['externalIds'] : [],
            'source' => (string)($input['source'] ?? 'pr_admin'),
            'eventType' => (string)($input['eventType'] ?? 'site.updated'),
            'updatedAt' => (string)($input['updatedAt'] ?? date('c')),
            'idempotencyKey' => (string)$input['idempotencyKey'],
        ],
    ];
}

$token = am_site_sync_resolve_token();
if ($token === '') {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$input = am_site_sync_parse_json();
if (!is_array($input)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Invalid JSON payload']);
    exit;
}

$validated = am_site_sync_validate($input);
$errors = $validated['errors'];
if (!empty($errors)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'errors' => $errors]);
    exit;
}

$site = $validated['normalized'];
$eventId = (string)$site['idempotencyKey'];
$eventDoc = am_firestore_get_document('am_site_sync_events', $eventId, $token);
if (is_array($eventDoc) && !empty($eventDoc['id'])) {
    echo json_encode(['success' => true, 'idempotent' => true, 'eventId' => $eventId], JSON_UNESCAPED_SLASHES);
    exit;
}

$docId = strtolower($site['organizationId'] . '_' . strtolower($site['code']));
$existing = am_firestore_get_document('am_reference_sites', $docId, $token);

$payload = [
    'id' => $docId,
    'organizationId' => $site['organizationId'],
    'countryCode' => $site['countryCode'],
    'code' => $site['code'],
    'name' => $site['name'],
    'active' => $site['active'],
    'latitude' => $site['latitude'],
    'longitude' => $site['longitude'],
    'externalIds' => $site['externalIds'],
    'source' => $site['source'],
    'lastEventType' => $site['eventType'],
    'lastUpdatedAt' => $site['updatedAt'],
    'lastIdempotencyKey' => $site['idempotencyKey'],
    'updatedAt' => date('c'),
];

if (!is_array($existing) || empty($existing['id'])) {
    $payload['createdAt'] = date('c');
    $write = am_firestore_create_document('am_reference_sites', $payload, $docId, $token);
} else {
    $write = am_firestore_update_document('am_reference_sites', $docId, $payload, $token);
}

if (!($write['ok'] ?? false)) {
    http_response_code(502);
    echo json_encode(['success' => false, 'error' => (string)($write['error'] ?? 'Failed to upsert site')]);
    exit;
}

$eventWrite = am_firestore_create_document(
    'am_site_sync_events',
    [
        'id' => $eventId,
        'eventType' => $site['eventType'],
        'source' => $site['source'],
        'organizationId' => $site['organizationId'],
        'siteCode' => $site['code'],
        'siteId' => $docId,
        'processedAt' => date('c'),
    ],
    $eventId,
    $token
);

if (!($eventWrite['ok'] ?? false)) {
    // Non-fatal: upsert succeeded, event log can be retried safely.
    error_log('am site sync: failed to persist idempotency log ' . (string)($eventWrite['error'] ?? 'unknown'));
}

echo json_encode([
    'success' => true,
    'idempotent' => false,
    'eventId' => $eventId,
    'siteId' => $docId,
], JSON_UNESCAPED_SLASHES);
