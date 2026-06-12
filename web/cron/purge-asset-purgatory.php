<?php
/**
 * Delete expired am_core_asset_purgatory documents (purge_after <= now).
 * Schedule daily, e.g. curl -s "https://am.1pwrafrica.com/cron/purge-asset-purgatory.php?secret=YOUR_CRON_SECRET"
 *
 * Requires: CRON_SECRET in environment (or .env), FIREBASE_ADMIN_BEARER_TOKEN for Firestore REST.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firebase.php';
require_once __DIR__ . '/../config/duplicate_assets.php';

header('Content-Type: application/json; charset=utf-8');

$secret = trim((string)(getenv('CRON_SECRET') ?: ''));
$req = trim((string)($_GET['secret'] ?? ''));
if ($secret === '' || $req === '' || !hash_equals($secret, $req)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Forbidden']);
    exit;
}

$tok = trim((string)(getenv('FIREBASE_ADMIN_BEARER_TOKEN') ?: ''));
if ($tok === '') {
    http_response_code(500);
    echo json_encode(['ok' => false, 'error' => 'FIREBASE_ADMIN_BEARER_TOKEN not set']);
    exit;
}

$r = am_duplicate_purge_expired_purgatory($tok);
http_response_code($r['ok'] ? 200 : 500);
echo json_encode($r);
