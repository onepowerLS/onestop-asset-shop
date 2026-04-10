<?php
/**
 * Create a local user for AM login
 * Run from command line: php create-local-user.php
 * Use the SAME username and password you use on am.1pwrafrica.com
 */
$baseDir = dirname(__DIR__, 2);
$envFile = $baseDir . '/.env';

if (!file_exists($envFile)) {
    echo "ERROR: .env file not found. Copy .env.example to .env and configure DB_HOST, DB_NAME, DB_USER, DB_PASS.\n";
    exit(1);
}

$env = parse_ini_file($envFile);
$db_host = $env['DB_HOST'] ?? 'localhost';
$db_name = $env['DB_NAME'] ?? 'onestop_asset_shop';
$db_user = $env['DB_USER'] ?? 'root';
$db_pass = $env['DB_PASS'] ?? '';

try {
    $pdo = new PDO(
        "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
        $db_user,
        $db_pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    echo "ERROR: Cannot connect to database: " . $e->getMessage() . "\n";
    echo "Make sure MySQL is running, the database exists, and .env has correct DB_HOST, DB_NAME, DB_USER, DB_PASS.\n";
    exit(1);
}

// Windows compatibility: readline may not exist
if (!function_exists('readline')) {
    function readline($prompt) {
        echo $prompt;
        return trim(fgets(STDIN));
    }
}

echo "=== Create local AM user (use same credentials as am.1pwrafrica.com) ===\n\n";
echo "(Password will be visible as you type - run this in a private terminal)\n\n";

$username = readline("Enter your username: ");
$password = readline("Enter your password: ");
$email = readline("Enter your email (or press Enter for username@local.dev): ");

$username = trim($username);
$password = trim($password);
$email = trim($email) ?: ($username . '@local.dev');

if (empty($username) || empty($password)) {
    echo "ERROR: Username and password are required.\n";
    exit(1);
}

// Check if user exists
$stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ?");
$stmt->execute([$username]);
if ($stmt->fetch()) {
    echo "User '$username' already exists. Updating password...\n";
    $stmt = $pdo->prepare("UPDATE users SET password_hash = ?, email = ? WHERE username = ?");
    $stmt->execute([password_hash($password, PASSWORD_DEFAULT), $email, $username]);
    echo "Password updated. You can now log in at http://localhost:8000/login.php\n";
} else {
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare("INSERT INTO users (username, email, password_hash, role, active) VALUES (?, ?, ?, 'Admin', 1)");
    $stmt->execute([$username, $email, $hash]);
    echo "User created. You can now log in at http://localhost:8000/login.php\n";
}
