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

if ($idToken !== '' && $uid !== '') {
    // Client-side Firebase auth — token already obtained by JS SDK
    $profile = am_fetch_pr_user_profile($idToken, $uid);
    $profileData = ($profile['ok'] ?? false) ? ($profile['data'] ?? []) : [];

    if (isset($profileData['isActive']) && $profileData['isActive'] === false) {
        echo json_encode(['ok' => false, 'error' => 'Your account is inactive. Contact administrator.']);
        exit;
    }

    $firstName = (string)($profileData['firstName'] ?? '');
    $lastName  = (string)($profileData['lastName'] ?? '');
    $displayName = trim($firstName . ' ' . $lastName) ?: $email;

    $_SESSION['user_id']               = $uid;
    $_SESSION['firebase_uid']          = $uid;
    $_SESSION['username']              = $displayName;
    $_SESSION['email']                 = $email;
    $_SESSION['role']                  = am_map_pr_role_to_am(
        (string)($profileData['role'] ?? ''),
        $profileData['permissionLevel'] ?? null
    );
    $_SESSION['employee_id']           = null;
    $_SESSION['auth_source']           = 'firebase';
    $_SESSION['firebase_id_token']     = $idToken;
    $_SESSION['firebase_refresh_token'] = $refreshToken;
    $_SESSION['permission_level']      = $profileData['permissionLevel'] ?? null;
    $_SESSION['department']            = (string)($profileData['department'] ?? '');
    $_SESSION['organization']          = (string)($profileData['organization'] ?? '');
    $_SESSION['capabilities']          = is_array($profileData['capabilities'] ?? null) ? $profileData['capabilities'] : [];

    $allow = $profileData['amCountryAccess'] ?? [];
    if (!is_array($allow)) {
        $allow = [];
    }
    $_SESSION['am_country_allow'] = am_apply_default_country_allow_if_empty($allow);
    $_SESSION['am_country_filter'] = 'all';
    am_locale_bootstrap();

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
    $profileData = ($profile['ok'] ?? false) ? ($profile['data'] ?? []) : [];

    if (isset($profileData['isActive']) && $profileData['isActive'] === false) {
        $_SESSION['auth_error'] = 'Your account is inactive. Contact administrator.';
        header('Location: /login.php');
        exit;
    }

    $firstName = (string)($profileData['firstName'] ?? '');
    $lastName  = (string)($profileData['lastName'] ?? '');
    $displayName = trim($firstName . ' ' . $lastName) ?: (string)$identifier;

    $_SESSION['user_id']               = (string)($signIn['uid'] ?? '');
    $_SESSION['firebase_uid']          = (string)($signIn['uid'] ?? '');
    $_SESSION['username']              = $displayName;
    $_SESSION['email']                 = (string)($signIn['email'] ?? $signInEmail);
    $_SESSION['role']                  = am_map_pr_role_to_am(
        (string)($profileData['role'] ?? ''),
        $profileData['permissionLevel'] ?? null
    );
    $_SESSION['employee_id']           = null;
    $_SESSION['auth_source']           = 'firebase';
    $_SESSION['firebase_id_token']     = (string)($signIn['id_token'] ?? '');
    $_SESSION['firebase_refresh_token'] = (string)($signIn['refresh_token'] ?? '');
    $_SESSION['permission_level']      = $profileData['permissionLevel'] ?? null;
    $_SESSION['department']            = (string)($profileData['department'] ?? '');
    $_SESSION['organization']          = (string)($profileData['organization'] ?? '');
    $_SESSION['capabilities']          = is_array($profileData['capabilities'] ?? null) ? $profileData['capabilities'] : [];

    $allow = $profileData['amCountryAccess'] ?? [];
    if (!is_array($allow)) {
        $allow = [];
    }
    $_SESSION['am_country_allow'] = am_apply_default_country_allow_if_empty($allow);
    $_SESSION['am_country_filter'] = 'all';
    am_locale_bootstrap();

    header('Location: /index.php');
    exit;
}

http_response_code(400);
echo json_encode(['ok' => false, 'error' => 'Missing credentials.']);
