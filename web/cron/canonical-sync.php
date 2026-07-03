<?php
/**
 * Cron: refresh AM canonical data cache from upstream APIs (PR / HR / FM).
 *
 * Schedule every 15 minutes, e.g.:
 *   curl -s "https://am.1pwrafrica.com/cron/canonical-sync.php?secret=YOUR_CRON_SECRET"
 *
 * Requires: CRON_SECRET in environment (or .env), FIREBASE_ADMIN_BEARER_TOKEN,
 *   PR_CATALOG_API_KEY, HR_API_KEY_AM_PORTAL, FLEET_INTEGRATION_API_KEY.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firebase.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/canonical_sync.php';

header('Content-Type: application/json; charset=utf-8');

$secret = trim((string)(getenv('CRON_SECRET') ?: am_env('CRON_SECRET', '')));
$req = trim((string)($_GET['secret'] ?? ''));
if ($secret === '' || $req === '' || !hash_equals($secret, $req)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

if (am_canonical_admin_token() === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'FIREBASE_ADMIN_BEARER_TOKEN not set']);
    exit;
}

$mode = trim((string)($_GET['mode'] ?? 'full'));
if (!in_array($mode, ['full', 'incremental'], true)) {
    $mode = 'full';
}

$results = am_canonical_refresh_all($mode);
$allOk = true;
foreach ($results as $r) {
    if (!$r['ok']) {
        $allOk = false;
    }
}

http_response_code($allOk ? 200 : 500);
echo json_encode(['ok' => $allOk, 'mode' => $mode, 'results' => $results], JSON_UNESCAPED_SLASHES);
