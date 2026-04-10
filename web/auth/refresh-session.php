<?php
/**
 * Updates PHP session Firebase ID token from the client SDK (fresh token).
 * Firestore reads on the server use this token; it expires ~1h without refresh.
 */
require_once __DIR__ . '/../config/app.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
    exit;
}

if (!is_logged_in() || empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Not logged in']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$idToken = trim((string)($input['id_token'] ?? ''));
if ($idToken === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Missing id_token']);
    exit;
}

$url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
$raw = @file_get_contents($url);
if ($raw === false) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token verification failed']);
    exit;
}

$data = json_decode($raw, true);
if (!is_array($data)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Invalid tokeninfo']);
    exit;
}

$uid = (string)($data['user_id'] ?? $data['sub'] ?? '');
if ($uid === '' || $uid !== (string)$_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'User mismatch']);
    exit;
}

$_SESSION['firebase_id_token'] = $idToken;
echo json_encode(['ok' => true]);
