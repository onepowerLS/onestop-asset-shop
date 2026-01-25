<?php
/**
 * Migration Utility Functions
 * Shared functions for all migration scripts
 */

$migration_log_file = __DIR__ . '/migration_log.txt';

function migration_log($message) {
    global $migration_log_file;
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] $message\n";
    file_put_contents($migration_log_file, $log_entry, FILE_APPEND);
    echo $log_entry;
}

function detect_country_from_location($location) {
    $location_lower = strtolower($location ?? '');
    if (strpos($location_lower, 'zambia') !== false || strpos($location_lower, 'zmb') !== false) {
        return 'ZMB';
    }
    if (strpos($location_lower, 'benin') !== false || strpos($location_lower, 'ben') !== false) {
        return 'BEN';
    }
    return 'LSO';
}

function map_old_status($old_status) {
    $status_map = [
        'available' => 'Available',
        'unallocated' => 'Available',
        'allocated' => 'Allocated',
        'checked out' => 'CheckedOut',
        'checkout' => 'CheckedOut',
        'missing' => 'Missing',
        'written off' => 'WrittenOff',
        'write-off' => 'WrittenOff',
        'retired' => 'Retired'
    ];
    $old_lower = strtolower($old_status ?? 'available');
    return $status_map[$old_lower] ?? 'Available';
}

function map_old_condition($old_condition) {
    $condition_map = [
        'new' => 'New',
        'good' => 'Good',
        'fair' => 'Fair',
        'poor' => 'Poor',
        'damaged' => 'Damaged',
        'retired' => 'Retired'
    ];
    $old_lower = strtolower($old_condition ?? 'good');
    return $condition_map[$old_lower] ?? 'Good';
}

function is_asset_duplicate($pdo, $serial_number, $asset_tag) {
    if (!empty($serial_number)) {
        $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE serial_number = ?");
        $stmt->execute([$serial_number]);
        if ($stmt->fetch()) {
            return true;
        }
    }
    if (!empty($asset_tag)) {
        $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE asset_tag = ?");
        $stmt->execute([$asset_tag]);
        if ($stmt->fetch()) {
            return true;
        }
    }
    return false;
}

function get_or_create_location($pdo, $location_name, $country_code) {
    if (empty($location_name)) {
        return null;
    }
    
    $stmt = $pdo->prepare("SELECT country_id FROM countries WHERE country_code = ?");
    $stmt->execute([$country_code]);
    $country = $stmt->fetch();
    if (!$country) {
        return null;
    }
    $country_id = $country['country_id'];
    
    $stmt = $pdo->prepare("SELECT location_id FROM locations WHERE location_name = ? AND country_id = ?");
    $stmt->execute([$location_name, $country_id]);
    $location = $stmt->fetch();
    
    if ($location) {
        return $location['location_id'];
    }
    
    $location_code = strtoupper($country_code) . '-' . strtoupper(substr(preg_replace('/[^A-Z0-9]/', '', $location_name), 0, 3)) . '-' . str_pad(rand(1, 999), 3, '0', STR_PAD_LEFT);
    
    $stmt = $pdo->prepare("INSERT INTO locations (country_id, location_code, location_name, location_type, active) VALUES (?, ?, ?, 'Site', 1)");
    $stmt->execute([$country_id, $location_code, $location_name]);
    
    migration_log("Created location: $location_name ($location_code)");
    
    return $pdo->lastInsertId();
}

function generate_qr_code_id($country_code, $asset_id) {
    return '1PWR-' . strtoupper($country_code) . '-' . str_pad($asset_id, 6, '0', STR_PAD_LEFT);
}
