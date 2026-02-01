<?php
/**
 * One-time vehicle import trigger
 * Access: https://am.1pwrafrica.com/api/vehicles/import.php?key=import2026
 * DELETE THIS FILE AFTER USE
 */

// Simple security key
if (($_GET['key'] ?? '') !== 'import2026') {
    http_response_code(403);
    die('Forbidden');
}

header('Content-Type: text/plain');

// Change to database directory
chdir(__DIR__ . '/../../../database');

// Check if CSV exists
if (!file_exists('vehicles_unique.csv')) {
    die("Error: vehicles_unique.csv not found in database directory\n");
}

// Run the import script
echo "Running vehicle import...\n\n";
include 'update_vehicles_from_csv.php';
