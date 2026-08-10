<?php
/**
 * Firebase Login Handler
 *
 * Accepts a Firebase ID token from the client-side Firebase JS SDK,
 * verifies it, fetches the user profile from Firestore, and creates
 * a PHP session. This aligns AM auth with the PR portal — both use
 * the same Firebase JS SDK + same project.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firebase.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/locale.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => 'Method not allowed.']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$idToken = trim((string)($input['id_token'] ?? ''));
$uid     = trim((string)($input['uid'] ?? ''));
$email   = trim((string)($input['email'] ?? ''));
$refreshToken = trim((string)($input['refresh_token'] ?? ''));

// Legacy form-post support (non-JS fallback)
$identifier = trim((string)($input['identifier'] ?? ''));
$password   = (string)($input['password'] ?? '');

/** @param array<string, mixed> $profileData */
function am_start_authenticated_session(
    string $uid,
    string $email,
    string $idToken,
    string $refreshToken,
    array $profileData,
    string $displayName
): bool {
    $privilege = am_nexus_privilege_from_verified_id_token($idToken);
    if ($privilege && !in_array('view_assets', (array)($privilege['actions'] ?? []), true)) {
        return false;
    }
    $legacyRole = am_map_pr_role_to_am(
        (string)($profileData['role'] ?? ''),
        $profileData['permissionLevel'] ?? null
    );

    $_SESSION['user_id'] = $uid;
    $_SESSION['firebase_uid'] = $uid;
    $_SESSION['username'] = $displayName;
    $_SESSION['email'] = $email;
    $_SESSION['role'] = $privilege
        ? am_map_nexus_privilege_level_to_role((string)$privilege['level'])
        : $legacyRole;
    $_SESSION['employee_id'] = null;
    $_SESSION['auth_source'] = $privilege ? 'nexus_sso' : 'firebase';
    $_SESSION['firebase_id_token'] = $idToken;
    $_SESSION['firebase_refresh_token'] = $refreshToken;
    $_SESSION['permission_level'] = $profileData['permissionLevel'] ?? null;
    $_SESSION['department'] = (string)($profileData['department'] ?? '');
    $_SESSION['organization'] = (string)($profileData['organization'] ?? '');
    $_SESSION['organization_id'] = (string)($profileData['organizationId'] ?? '');
    $_SESSION['capabilities'] = is_array($profileData['capabilities'] ?? null)
        ? $profileData['capabilities']
        : [];

    $_SESSION['privilege_system'] = $privilege['system'] ?? '';
    $_SESSION['privilege_level'] = $privilege['level'] ?? '';
    $_SESSION['privilege_actions'] = $privilege['actions'] ?? [];
    $_SESSION['privilege_version'] = $privilege['version'] ?? '';
    $_SESSION['privilege_scope_countries'] = $privilege['scope_countries'] ?? [];
    $_SESSION['privilege_scope_organizations'] = $privilege['scope_organizations'] ?? [];
    $_SESSION['privilege_role_crud_owners'] = $privilege['role_crud_owners'] ?? [];

    $allow = $privilege
        ? ($privilege['scope_countries'] ?? [])
        : ($profileData['amCountryAccess'] ?? []);
    if (!is_array($allow)) {
        $allow = [];
    }
    $_SESSION['am_country_allow'] = am_apply_default_country_allow_if_empty($allow);

    $orgAccess = $privilege
        ? ($privilege['scope_organizations'] ?? [])
        : ($profileData['amOrgAccess'] ?? []);
    if (!is_array($orgAccess) || empty($orgAccess)) {
        $orgAccess = am_org_ids_from_country_codes($_SESSION['am_country_allow']);
    }
    $_SESSION['am_org_allow'] = am_apply_default_org_allow_if_empty($orgAccess);
    $_SESSION['am_country_filter'] = 'all';
    am_locale_bootstrap();
    return true;
}

if ($idToken !== '' && $uid !== '') {
    // Client-side Firebase auth — token already obtained by JS SDK
    $profile = am_fetch_pr_user_profile($idToken, $uid);
    if (!($profile['ok'] ?? false)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'No active Nexus or Asset Management profile is linked to this account. Contact IS&T.']);
        exit;
    }
    $profileData = ($profile['ok'] ?? false) ? ($profile['data'] ?? []) : [];

    if (isset($profileData['isActive']) && $profileData['isActive'] === false) {
        echo json_encode(['ok' => false, 'error' => 'Your account is inactive. Contact administrator.']);
        exit;
    }

    $firstName = (string)($profileData['firstName'] ?? '');
    $lastName  = (string)($profileData['lastName'] ?? '');
    $displayName = trim($firstName . ' ' . $lastName) ?: $email;

    if (!am_start_authenticated_session($uid, $email, $idToken, $refreshToken, $profileData, $displayName)) {
        http_response_code(403);
        echo json_encode(['ok' => false, 'error' => 'Your signed Nexus profile does not allow Asset Management access. Contact HR or IS&T.']);
        exit;
    }

    echo json_encode(['ok' => true, 'redirect' => '/index.php']);
    exit;
}

// Fallback: server-side auth for non-JS clients
if ($identifier !== '' && $password !== '') {
    $signInEmail = $identifier;
    if (strpos($identifier, '@') === false) {
        @include_once __DIR__ . '/../config/database.php';
        if (defined('DB_AVAILABLE') && DB_AVAILABLE && isset($pdo)) {
            try {
                $stmt = $pdo->prepare('SELECT email FROM users WHERE username = ? AND active = 1 LIMIT 1');
                $stmt->execute([$identifier]);
                $row = $stmt->fetch();
                $signInEmail = ($row && !empty($row['email'])) ? trim((string)$row['email']) : '';
            } catch (\Exception $e) {
                $signInEmail = '';
            }
        }
        if ($signInEmail === '') {
            $_SESSION['auth_error'] = 'Username not found. Try your email address.';
            header('Location: /login.php');
            exit;
        }
    }

    $signIn = am_firebase_sign_in($signInEmail, $password);
    if (!$signIn['ok']) {
        $_SESSION['auth_error'] = $signIn['message'] ?? 'Sign in failed.';
        header('Location: /login.php');
        exit;
    }

    $profile = am_fetch_pr_user_profile($signIn['id_token'] ?? '', $signIn['uid'] ?? '');
    if (!($profile['ok'] ?? false)) {
        $_SESSION['auth_error'] = 'No active Nexus or Asset Management profile is linked to this account. Contact IS&T.';
        header('Location: /login.php');
        exit;
    }
    $profileData = ($profile['ok'] ?? false) ? ($profile['data'] ?? []) : [];

    if (isset($profileData['isActive']) && $profileData['isActive'] === false) {
        $_SESSION['auth_error'] = 'Your account is inactive. Contact administrator.';
        header('Location: /login.php');
        exit;
    }

    $firstName = (string)($profileData['firstName'] ?? '');
    $lastName  = (string)($profileData['lastName'] ?? '');
    $displayName = trim($firstName . ' ' . $lastName) ?: (string)$identifier;

    if (!am_start_authenticated_session(
        (string)($signIn['uid'] ?? ''),
        (string)($signIn['email'] ?? $signInEmail),
        (string)($signIn['id_token'] ?? ''),
        (string)($signIn['refresh_token'] ?? ''),
        $profileData,
        $displayName
    )) {
        $_SESSION['auth_error'] = 'Your signed Nexus profile does not allow Asset Management access. Contact HR or IS&T.';
        header('Location: /login.php');
        exit;
    }

    header('Location: /index.php');
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Missing credentials.']);
