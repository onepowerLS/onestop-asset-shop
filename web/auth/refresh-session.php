<?php
/**
 * Updates PHP session Firebase ID token from the client SDK (fresh token) or server refresh token.
 * Firestore reads on the server use this token; it expires ~1h without refresh.
 *
 * Verification uses Google's tokeninfo with POST (form body) so long JWTs are not broken by
 * GET URL length limits. If tokeninfo fails, we fall back to exchanging firebase_refresh_token.
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

$expectedUid = (string)$_SESSION['user_id'];

/**
 * Mint ID token from session refresh token and validate claims match this user.
 */
$try_apply_session_from_exchange = static function () use ($expectedUid): bool {
    $rt = trim((string)($_SESSION['firebase_refresh_token'] ?? ''));
    if ($rt === '') {
        return false;
    }
    $res = am_firebase_exchange_refresh_token($rt);
    if (empty($res['ok']) || empty($res['id_token'])) {
        return false;
    }
    $newToken = (string)$res['id_token'];
    $payload = am_firebase_decode_id_token_payload($newToken);
    if ($payload === null || !am_firebase_id_token_payload_matches_session($payload, $expectedUid)) {
        return false;
    }
    $_SESSION['firebase_id_token'] = $newToken;
    if (!empty($res['refresh_token'])) {
        $_SESSION['firebase_refresh_token'] = (string)$res['refresh_token'];
    }
    return true;
};

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = [];
}
$idToken = trim((string)($input['id_token'] ?? ''));

if ($idToken !== '') {
    $data = am_verify_google_id_token($idToken);
    if ($data !== null) {
        $uid = (string)($data['user_id'] ?? $data['sub'] ?? '');
        if ($uid === '' || $uid !== $expectedUid) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'User mismatch']);
            exit;
        }
        $_SESSION['firebase_id_token'] = $idToken;
        echo json_encode(['ok' => true]);
        exit;
    }
    // RCA: tokeninfo often fails from app servers while the JWT from getIdToken() is still valid.
    // Refresh-token exchange is often unavailable because the web SDK does not expose refreshToken to JS.
    if (am_accept_firebase_id_token_for_php_session($idToken, $expectedUid)) {
        $_SESSION['firebase_id_token'] = $idToken;
        echo json_encode(['ok' => true]);
        exit;
    }
    if ($try_apply_session_from_exchange()) {
        echo json_encode(['ok' => true]);
        exit;
    }
    http_response_code(400);
    echo json_encode([
        'ok'    => false,
        'error' => 'Token verification failed',
        'hint'  => 'tokeninfo_failed_claims_mismatch_or_expired_and_no_valid_refresh_token_in_session',
    ]);
    exit;
}

if ($try_apply_session_from_exchange()) {
    echo json_encode(['ok' => true]);
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Missing id_token']);
exit;
