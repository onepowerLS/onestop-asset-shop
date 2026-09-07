<?php
/**
 * GET /api/v1/health — unauthenticated liveness for monitoring.
 */
require_once __DIR__ . '/../../config/version.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

$version = function_exists('am_app_version') ? am_app_version() : [];
$db = 'ok';
try {
    if (file_exists(__DIR__ . '/../../config/database.php')) {
        require_once __DIR__ . '/../../config/database.php';
        if (isset($pdo) && $pdo instanceof PDO) {
            $pdo->query('SELECT 1');
        }
    }
} catch (Throwable $e) {
    $db = 'degraded';
}

echo json_encode([
    'status' => 'ok',
    'db' => $db,
    'version' => (string)($version['short'] ?? $version['commit'] ?? ''),
    'server_time' => gmdate('c'),
], JSON_UNESCAPED_SLASHES);
