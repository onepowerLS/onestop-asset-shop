<?php
/**
 * Health Check Endpoint
 * 
 * Used by deployment scripts and monitoring tools
 */
header('Content-Type: application/json');

$health = [
    'status' => 'healthy',
    'timestamp' => date('c'),
    'checks' => []
];

// Check database connectivity
try {
    require_once __DIR__ . '/config/database.php';
    $pdo->query('SELECT 1');
    $health['checks']['database'] = 'ok';
} catch (Exception $e) {
    $health['status'] = 'unhealthy';
    $health['checks']['database'] = 'failed: ' . $e->getMessage();
    http_response_code(503);
}

// Check file system
if (is_writable(__DIR__)) {
    $health['checks']['filesystem'] = 'ok';
} else {
    $health['status'] = 'degraded';
    $health['checks']['filesystem'] = 'read-only';
}

// Check PHP version
$health['checks']['php_version'] = PHP_VERSION;

echo json_encode($health, JSON_PRETTY_PRINT);
