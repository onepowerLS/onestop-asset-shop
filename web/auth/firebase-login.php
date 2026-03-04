<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firebase.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /login.php');
    exit;
}

$identifier = trim((string)($_POST['identifier'] ?? ''));
$password = (string)($_POST['password'] ?? '');

if ($identifier === '' || $password === '') {
    $_SESSION['auth_error'] = 'Please enter both username/email and password.';
    header('Location: /login.php');
    exit;
}

$email = $identifier;
if (strpos($identifier, '@') === false) {
    require_once __DIR__ . '/../config/database.php';
    if (!defined('DB_AVAILABLE') || !DB_AVAILABLE || !$pdo) {
        $_SESSION['auth_error'] = 'Username login requires database user mapping. Use your email or configure DB.';
        header('Location: /login.php');
        exit;
    }

    try {
        $stmt = $pdo->prepare('SELECT email FROM users WHERE username = ? AND active = 1 LIMIT 1');
        $stmt->execute([$identifier]);
        $row = $stmt->fetch();
        if (!$row || empty($row['email'])) {
            $_SESSION['auth_error'] = 'Username not found. Try your email address.';
            header('Location: /login.php');
            exit;
        }
        $email = trim((string)$row['email']);
    } catch (PDOException $e) {
        error_log('firebase-login username lookup error: ' . $e->getMessage());
        $_SESSION['auth_error'] = 'Unable to resolve username. Try email login.';
        header('Location: /login.php');
        exit;
    }
}

$signIn = am_firebase_sign_in($email, $password);
if (!$signIn['ok']) {
    $_SESSION['auth_error'] = $signIn['message'] ?? 'Sign in failed.';
    header('Location: /login.php');
    exit;
}

$profile = am_fetch_pr_user_profile($signIn['id_token'] ?? '', $signIn['uid'] ?? '');
$profileData = ($profile['ok'] ?? false) ? ($profile['data'] ?? []) : [];

if (isset($profileData['isActive']) && $profileData['isActive'] === false) {
    $_SESSION['auth_error'] = 'Your account is inactive. Please contact the administrator.';
    header('Location: /login.php');
    exit;
}

$firstName = (string)($profileData['firstName'] ?? '');
$lastName = (string)($profileData['lastName'] ?? '');
$displayName = trim($firstName . ' ' . $lastName);
if ($displayName === '') {
    $displayName = (string)$identifier;
}

// Big-bang cutover session payload: AM uses PR/Firebase as auth source.
$_SESSION['user_id'] = (string)($signIn['uid'] ?? '');
$_SESSION['username'] = $displayName;
$_SESSION['email'] = (string)($signIn['email'] ?? $email);
$_SESSION['role'] = am_map_pr_role_to_am(
    (string)($profileData['role'] ?? ''),
    $profileData['permissionLevel'] ?? null
);
$_SESSION['employee_id'] = null;
$_SESSION['auth_source'] = 'firebase';
$_SESSION['firebase_id_token'] = (string)($signIn['id_token'] ?? '');
$_SESSION['firebase_refresh_token'] = (string)($signIn['refresh_token'] ?? '');
$_SESSION['permission_level'] = $profileData['permissionLevel'] ?? null;
$_SESSION['department'] = (string)($profileData['department'] ?? '');
$_SESSION['organization'] = (string)($profileData['organization'] ?? '');

header('Location: /index.php');
exit;
