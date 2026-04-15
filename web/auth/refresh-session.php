<?php
/**
 * Updates PHP session Firebase ID token from the client SDK (fresh token).
 * Firestore reads on the server use this token; it expires ~1h without refresh.
 *
 * Verifies id_token via Google's tokeninfo endpoint (GET ?id_token=…). The legacy
 * oauth2 tokeninfo service does not reliably accept POST form bodies; that path
 * returned 4xx and broke session refresh in production.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firebase.php';

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
exit;

/**
 * @return array<string,mixed>|null
 */
function am_verify_google_id_token(string $idToken): ?array {
    if ($idToken === '') {
        return null;
    }

    $url = 'https://oauth2.googleapis.com/tokeninfo?id_token=' . rawurlencode($idToken);
    $raw = am_refresh_session_http_get($url);
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

function am_refresh_session_http_get(string $url): ?string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_HTTPGET => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
        ];
        if (am_allow_insecure_ssl_for_local()) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);
        $resp = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
        if ($resp === false || $code < 200 || $code >= 300) {
            return null;
        }
        return is_string($resp) ? $resp : null;
    }

    $ctx = [
        'http' => [
            'method' => 'GET',
            'timeout' => 15,
            'ignore_errors' => true,
        ],
    ];
    if (am_allow_insecure_ssl_for_local()) {
        $ctx['ssl'] = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];
    }
    $context = stream_context_create($ctx);
    $resp = @file_get_contents($url, false, $context);
    return $resp === false ? null : $resp;
}
