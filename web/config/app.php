<?php
/**
 * Application Configuration
 */

// Application settings
define('APP_NAME', 'OneStop Asset Shop');
define('APP_VERSION', '1.0.0');
define('BASE_URL', '/');

// Session configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Timezone
date_default_timezone_set('Africa/Maseru'); // Default to Lesotho, can be changed per user

// Error reporting: suppress deprecation/notices, log everything
$envFile = __DIR__ . '/../../.env';
$isProduction = true;
if (file_exists($envFile)) {
    $parsed = @parse_ini_file($envFile);
    if (is_array($parsed) && ($parsed['APP_DEBUG'] ?? '') === 'true') {
        $isProduction = false;
    }
}
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);
ini_set('display_errors', $isProduction ? '0' : '1');
ini_set('log_errors', '1');

// Helper function to get base URL
function base_url($path = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        $host = 'localhost';
    }
    $base = trim(BASE_URL, '/');
    $prefix = $base === '' ? '' : '/' . $base;
    return $protocol . '://' . $host . $prefix . '/' . ltrim($path, '/');
}

// Helper function to redirect
function redirect($url) {
    header("Location: " . base_url($url));
    exit;
}

// Helper function to check if user is logged in
function is_logged_in() {
    return isset($_SESSION['user_id']);
}

// Helper function to require login
function require_login() {
    if (!is_logged_in()) {
        redirect('login.php');
    }
}
