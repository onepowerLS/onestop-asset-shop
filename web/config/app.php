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

// Error reporting (disable in production)
$app_env = getenv('APP_ENV') ?: 'development';
if ($app_env === 'production') {
    error_reporting(0);
    ini_set('display_errors', 0);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
}

// Helper function to get base URL
function base_url($path = '') {
    $protocol = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'];
    $base = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');
    return $protocol . '://' . $host . $base . '/' . ltrim($path, '/');
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
