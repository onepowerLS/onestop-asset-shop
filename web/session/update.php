<?php
/**
 * POST: update UI language or country scope filter (same-origin redirect).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/country_scope.php';
require_login();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit('Method not allowed');
}

$return = trim((string)($_POST['return_url'] ?? ''));
if ($return === '' || !str_starts_with($return, '/')) {
    $return = '/index.php';
}

$action = trim((string)($_POST['action'] ?? ''));

if ($action === 'set_lang') {
    $lang = strtolower(trim((string)($_POST['ui_lang'] ?? '')));
    if ($lang === 'fr' || $lang === 'en') {
        $_SESSION['ui_lang'] = $lang;
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off');
        setcookie('am_lang', $lang, [
            'expires' => time() + 365 * 86400,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

if ($action === 'set_country_filter') {
    $mode = trim((string)($_POST['country_filter'] ?? 'all'));
    if ($mode === 'all') {
        $_SESSION['am_country_filter'] = 'all';
    } elseif (in_array($mode, am_org_country_codes(), true)) {
        $allow = am_country_allow_codes();
        if (empty($allow) || in_array($mode, $allow, true)) {
            $_SESSION['am_country_filter'] = $mode;
        }
    }
}

header('Location: ' . $return, true, 302);
exit;
