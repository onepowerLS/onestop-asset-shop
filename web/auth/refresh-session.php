<?php
/**
 * Updates PHP session Firebase ID token from the client SDK (fresh token).
 * Firestore reads on the server use this token; it expires ~1h without refresh.
 *
 * Verifies id_token via Google's tokeninfo using POST (GET + query string can
 * exceed URL limits for long JWTs and return 400).
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

$data = am_verify_google_id_token($idToken);
if ($data === null) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'Token verification failed']);
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

/**
 * @return array<string,mixed>|null
 */
function am_verify_google_id_token(string $idToken): ?array {
    $url = 'https://oauth2.googleapis.com/tokeninfo';
    $body = http_build_query(['id_token' => $idToken], '', '&', PHP_QUERY_RFC3986);

    $raw = am_refresh_session_http_post($url, $body);
    if ($raw === null || $raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    if (isset($data['error_description']) || isset($data['error'])) {
        return null;
    }

    return $data;
}

function am_refresh_session_http_post(string $url, string $body): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => $body,
        ]);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        return is_string($resp) ? $resp : null;
    }

    $ctx = stream_context_create([
        'http' => [
            'method' => 'POST',
            'header' => "Content-Type: application/x-www-form-urlencoded\r\n",
            'content' => $body,
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ]);
    $resp = @file_get_contents($url, false, $ctx);
    return $resp === false ? null : $resp;
}
