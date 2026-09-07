<?php
declare(strict_types=1);

$root = '/var/www/onestop-asset-shop';
require_once $root . '/web/config/app.php';
require_once $root . '/web/config/firestore.php';
require_once $root . '/web/config/firebase_admin_token.php';
require_once $root . '/web/config/whats_new.php';

$adminToken = am_firestore_admin_access_token();
if ($adminToken === '') {
    $adminToken = am_firebase_admin_token();
}
if ($adminToken === '') {
    fwrite(STDERR, "No admin token available.\n");
    exit(1);
}

$entry = [
    'title' => 'IT Equipment Requests',
    'summary' => 'IS&T equipment requests can now be forwarded directly to Asset Management.',
    'details' => '<p>When an IS&T staff member forwards an equipment ticket, AM creates a dedicated IT Equipment Request workflow.</p><p>AM managers can review, approve, reject, or fulfill the request, and the status syncs back to the IS&T ticket.</p>',
    'category' => 'feature',
    'icon' => 'fa-laptop',
    'released_at' => '2026-07-17T00:00:00Z',
    'deep_link' => '/requests/workflow-index.php?type=it_equipment_request',
    'active' => 1,
    'created_at' => gmdate('c'),
];

$existing = am_firestore_get_collection(AM_WHATS_NEW_COLLECTION, 500, $adminToken);
foreach ($existing as $e) {
    if (strtolower(trim((string)($e['title'] ?? ''))) === strtolower(trim($entry['title']))) {
        echo "SKIP: entry already exists\n";
        exit(0);
    }
}

$r = am_firestore_create_document(AM_WHATS_NEW_COLLECTION, $entry, null, $adminToken);
if ($r['ok']) {
    echo "OK: created entry " . ($r['id'] ?? '') . "\n";
    exit(0);
} else {
    fwrite(STDERR, "FAIL: " . ($r['error'] ?? 'Unknown') . "\n");
    exit(1);
}
