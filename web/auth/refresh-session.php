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
require_once __DIR__ . '/../config/country_scope.php';

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
 * Re-parse the signed Nexus privilege from a freshly validated ID token and
 * reconcile the session authorization snapshot with it. Previously the claim
 * was parsed only at login, so role changes in Nexus required a full
 * re-login; Firestore reads/writes already use the fresh token, so the UI
 * must follow the same claim or it would drift from what Firestore allows.
 *
 * A token carrying no AM claim (or one whose claim lost view_assets) means
 * the privilege was revoked or the session is a non-SSO fallback: downgrade
 * to read-only rather than keep serving stale grants.
 */
$apply_privilege_from_token = static function (string $idToken): void {
    $privilege = am_nexus_privilege_from_verified_id_token($idToken);
    if ($privilege === null) {
        if (($_SESSION['auth_source'] ?? '') === 'nexus_sso') {
            // SSO session whose refreshed token lost the claim: fail closed.
            $_SESSION['privilege_system'] = '';
            $_SESSION['privilege_level'] = '';
            $_SESSION['privilege_actions'] = [];
            $_SESSION['privilege_version'] = '';
            $_SESSION['privilege_scope_countries'] = [];
            $_SESSION['privilege_scope_organizations'] = [];
            $_SESSION['privilege_role_crud_owners'] = [];
            $_SESSION['role'] = 'Viewer';
        }
        return;
    }
    if (!in_array('view_assets', (array)($privilege['actions'] ?? []), true)) {
        // AM access explicitly removed; drop the signed grant entirely.
        $_SESSION['privilege_system'] = '';
        $_SESSION['privilege_level'] = '';
        $_SESSION['privilege_actions'] = [];
        $_SESSION['privilege_version'] = '';
        $_SESSION['role'] = 'Viewer';
        return;
    }
    $_SESSION['privilege_system'] = $privilege['system'] ?? '';
    $_SESSION['privilege_level'] = $privilege['level'] ?? '';
    $_SESSION['privilege_actions'] = $privilege['actions'] ?? [];
    $_SESSION['privilege_version'] = $privilege['version'] ?? '';
    $_SESSION['privilege_scope_countries'] = $privilege['scope_countries'] ?? [];
    $_SESSION['privilege_scope_organizations'] = $privilege['scope_organizations'] ?? [];
    $_SESSION['privilege_role_crud_owners'] = $privilege['role_crud_owners'] ?? [];
    $_SESSION['role'] = am_map_nexus_privilege_level_to_role((string)($privilege['level'] ?? ''));
    $_SESSION['auth_source'] = 'nexus_sso';

    $allow = (array)($privilege['scope_countries'] ?? []);
    $_SESSION['am_country_allow'] = am_apply_default_country_allow_if_empty($allow);
    $orgAccess = (array)($privilege['scope_organizations'] ?? []);
    if ($orgAccess === []) {
        $orgAccess = am_org_ids_from_country_codes($_SESSION['am_country_allow']);
    }
    $_SESSION['am_org_allow'] = am_apply_default_org_allow_if_empty($orgAccess);
};

/**
 * Mint ID token from session refresh token and validate claims match this user.
 */
$try_apply_session_from_exchange = static function () use ($expectedUid, $apply_privilege_from_token): bool {
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
    $apply_privilege_from_token($newToken);
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
        $apply_privilege_from_token($idToken);
        echo json_encode(['ok' => true]);
        exit;
    }
    // RCA: tokeninfo often fails from app servers while the JWT from getIdToken() is still valid.
    // Refresh-token exchange is often unavailable because the web SDK does not expose refreshToken to JS.
    if (am_accept_firebase_id_token_for_php_session($idToken, $expectedUid)) {
        $_SESSION['firebase_id_token'] = $idToken;
        $apply_privilege_from_token($idToken);
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
