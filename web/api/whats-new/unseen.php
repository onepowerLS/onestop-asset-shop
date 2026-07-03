<?php
/**
 * GET /api/whats-new/unseen.php
 * Returns active What's New entries the current user has not dismissed.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/firestore.php';
require_once __DIR__ . '/../../config/authz.php';
require_once __DIR__ . '/../../config/whats_new.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    echo json_encode(['ok' => false, 'error' => 'auth required', 'entries' => []]);
    exit;
}

$userId = (string)($_SESSION['user_id'] ?? $_SESSION['firebase_uid'] ?? '');
if ($userId === '') {
    echo json_encode(['ok' => false, 'error' => 'no user id', 'entries' => []]);
    exit;
}

$entries = am_whats_new_unseen_for($userId);

$colors = am_whats_new_category_colors();
$labels = am_whats_new_category_labels();

$out = [];
foreach ($entries as $e) {
    $eid = (string)($e['id'] ?? $e['entry_id'] ?? '');
    if ($eid === '') continue;
    $cat = (string)($e['category'] ?? 'feature');
    $out[] = [
        'id' => $eid,
        'title' => (string)($e['title'] ?? ''),
        'summary' => (string)($e['summary'] ?? ''),
        'details' => (string)($e['details'] ?? ''),
        'category' => $cat,
        'category_label' => $labels[$cat] ?? 'Update',
        'category_color' => $colors[$cat] ?? 'secondary',
        'icon' => (string)($e['icon'] ?? 'fa-star'),
        'deep_link' => (string)($e['deep_link'] ?? ''),
        'released_at' => (string)($e['released_at'] ?? ''),
    ];
}

echo json_encode(['ok' => true, 'entries' => $out], JSON_UNESCAPED_SLASHES);
