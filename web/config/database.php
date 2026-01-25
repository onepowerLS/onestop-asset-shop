<?php
/**
 * Database Configuration
 * 
 * Environment-based configuration for database connection
 */

// Load environment variables if .env file exists
$envPath = __DIR__ . '/../../.env';
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
    $db_host = $env['DB_HOST'] ?? 'localhost';
    $db_name = $env['DB_NAME'] ?? 'onestop_asset_shop';
    $db_user = $env['DB_USER'] ?? 'root';
    $db_pass = $env['DB_PASS'] ?? '';
} else {
    // Default configuration (override with .env in production)
    $db_host = getenv('DB_HOST') ?: 'localhost';
    $db_name = getenv('DB_NAME') ?: 'onestop_asset_shop';
    $db_user = getenv('DB_USER') ?: 'root';
    $db_pass = getenv('DB_PASS') ?: '';
}

// Create PDO connection
try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false
        ]
    );
} catch (PDOException $e) {
    // Log error securely (don't expose credentials)
    error_log("Database connection failed: " . $e->getMessage());
    die("Database connection failed. Please contact the administrator.");
}
