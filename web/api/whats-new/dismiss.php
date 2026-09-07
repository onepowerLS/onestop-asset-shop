<?php
/**
 * POST /api/whats-new/dismiss.php
 * Body: {"entry_ids": ["id1", "id2", ...]}
 * Marks those entries dismissed for the current user.
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/firestore.php';
require_once __DIR__ . '/../../config/authz.php';
require_once __DIR__ . '/../../config/whats_new.php';

header('Content-Type: application/json');

if (!is_logged_in()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'auth required']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'POST required']);
    exit;
}

$userId = (string)($_SESSION['user_id'] ?? $_SESSION['firebase_uid'] ?? '');
if ($userId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'no user id']);
    exit;
}

$body = json_decode(file_get_contents('php://input') ?: '[]', true);
if (!is_array($body)) {
    $body = [];
}
$entryIds = $body['entry_ids'] ?? [];
if (!is_array($entryIds)) {
    $entryIds = [];
}

$r = am_whats_new_dismiss_entries($userId, $entryIds);
echo json_encode($r, JSON_UNESCAPED_SLASHES);
