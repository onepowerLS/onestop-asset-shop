<?php
/**
 * Create Initial Admin User
 * Run this once, then delete the file
 */
require_once __DIR__ . '/config/database.php';

$username = 'mso';
$email = 'mso@1pwrafrica.com';
$password = 'Welcome123!'; // User should change this on first login
$role = 'Admin';

try {
    // Check if user already exists
    $stmt = $pdo->prepare("SELECT user_id FROM users WHERE username = ? OR email = ?");
    $stmt->execute([$username, $email]);
    if ($stmt->fetch()) {
        echo "User already exists!\n";
        exit(1);
    }
    
    // Generate password hash
    $password_hash = password_hash($password, PASSWORD_DEFAULT);
    
    // Insert user
    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password_hash, role, active, created_at)
        VALUES (?, ?, ?, ?, 1, NOW())
    ");
    $stmt->execute([$username, $email, $password_hash, $role]);
    
    $user_id = $pdo->lastInsertId();
    echo "Admin user created successfully!\n";
    echo "User ID: $user_id\n";
    echo "Username: $username\n";
    echo "Email: $email\n";
    echo "Password: $password\n";
    echo "Role: $role\n";
    echo "\n⚠️  IMPORTANT: Change the password after first login!\n";
    
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
    exit(1);
}
