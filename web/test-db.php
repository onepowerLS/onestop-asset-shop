<?php
$envPath = __DIR__ . '/../.env';
echo "ENV Path: $envPath\n";
echo "File exists: " . (file_exists($envPath) ? 'YES' : 'NO') . "\n";
if (file_exists($envPath)) {
    $env = parse_ini_file($envPath);
    echo "DB_HOST: " . ($env['DB_HOST'] ?? 'NOT SET') . "\n";
    echo "DB_NAME: " . ($env['DB_NAME'] ?? 'NOT SET') . "\n";
    echo "DB_USER: " . ($env['DB_USER'] ?? 'NOT SET') . "\n";
    echo "DB_PASS: " . (isset($env['DB_PASS']) ? 'SET' : 'NOT SET') . "\n";
    
    try {
        $pdo = new PDO(
            "mysql:host={$env['DB_HOST']};dbname={$env['DB_NAME']};charset=utf8mb4",
            $env['DB_USER'],
            $env['DB_PASS']
        );
        echo "Connection: SUCCESS\n";
    } catch (PDOException $e) {
        echo "Connection: FAILED - " . $e->getMessage() . "\n";
    }
}
