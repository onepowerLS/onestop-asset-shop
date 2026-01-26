<?php
/**
 * Deduplicate Assets
 * 
 * Identifies and removes duplicate assets, keeping the most complete record
 * Strategy:
 * 1. Keep asset with most non-null fields
 * 2. Prefer asset with serial number
 * 3. Prefer asset with asset tag
 * 4. Prefer asset with purchase price
 * 5. Keep oldest asset_id (first imported)
 */

require_once __DIR__ . '/../web/config/database.php';
require_once __DIR__ . '/migration_utils.php';

migration_log("=== Asset Deduplication ===");

// Statistics
$stats = [
    'duplicates_found' => 0,
    'duplicates_removed' => 0,
    'kept' => 0,
    'errors' => []
];

/**
 * Calculate completeness score for an asset
 */
function get_completeness_score($asset) {
    $score = 0;
    
    // Field weights
    if (!empty($asset['description'])) $score += 2;
    if (!empty($asset['serial_number'])) $score += 10;
    if (!empty($asset['asset_tag'])) $score += 8;
    if (!empty($asset['manufacturer'])) $score += 5;
    if (!empty($asset['model'])) $score += 5;
    if (!empty($asset['purchase_price']) && $asset['purchase_price'] > 0) $score += 5;
    if (!empty($asset['current_value']) && $asset['current_value'] > 0) $score += 3;
    if (!empty($asset['purchase_date'])) $score += 2;
    if (!empty($asset['warranty_expiry'])) $score += 2;
    if (!empty($asset['notes'])) $score += 1;
    
    return $score;
}

/**
 * Find duplicate groups
 */
function find_duplicate_groups($pdo) {
    $groups = [];
    
    // Group 1: Same serial number
    migration_log("Finding duplicates by serial number...");
    $stmt = $pdo->query("
        SELECT serial_number, GROUP_CONCAT(asset_id ORDER BY asset_id) as asset_ids, COUNT(*) as count
        FROM assets
        WHERE serial_number IS NOT NULL AND serial_number != ''
        GROUP BY serial_number
        HAVING count > 1
    ");
    
    while ($row = $stmt->fetch()) {
        $asset_ids = explode(',', $row['asset_ids']);
        $groups[] = [
            'type' => 'serial',
            'key' => $row['serial_number'],
            'asset_ids' => $asset_ids,
            'count' => count($asset_ids)
        ];
    }
    
    // Group 2: Same asset tag
    migration_log("Finding duplicates by asset tag...");
    $stmt = $pdo->query("
        SELECT asset_tag, GROUP_CONCAT(asset_id ORDER BY asset_id) as asset_ids, COUNT(*) as count
        FROM assets
        WHERE asset_tag IS NOT NULL AND asset_tag != ''
        GROUP BY asset_tag
        HAVING count > 1
    ");
    
    while ($row = $stmt->fetch()) {
        $asset_ids = explode(',', $row['asset_ids']);
        $groups[] = [
            'type' => 'tag',
            'key' => $row['asset_tag'],
            'asset_ids' => $asset_ids,
            'count' => count($asset_ids)
        ];
    }
    
    // Group 3: Same name + manufacturer + model (exact match)
    migration_log("Finding duplicates by name + manufacturer + model...");
    $stmt = $pdo->query("
        SELECT name, manufacturer, model, GROUP_CONCAT(asset_id ORDER BY asset_id) as asset_ids, COUNT(*) as count
        FROM assets
        WHERE name IS NOT NULL AND name != ''
          AND manufacturer IS NOT NULL AND manufacturer != ''
          AND model IS NOT NULL AND model != ''
        GROUP BY name, manufacturer, model
        HAVING count > 1
    ");
    
    while ($row = $stmt->fetch()) {
        $asset_ids = explode(',', $row['asset_ids']);
        $groups[] = [
            'type' => 'name_mfr_model',
            'key' => $row['name'] . ' | ' . $row['manufacturer'] . ' | ' . $row['model'],
            'asset_ids' => $asset_ids,
            'count' => count($asset_ids)
        ];
    }
    
    // Group 3b: Same name + manufacturer (model is NULL/empty) - likely duplicates
    migration_log("Finding duplicates by name + manufacturer (no model)...");
    $stmt = $pdo->query("
        SELECT name, manufacturer, GROUP_CONCAT(asset_id ORDER BY asset_id) as asset_ids, COUNT(*) as count
        FROM assets
        WHERE name IS NOT NULL AND name != ''
          AND manufacturer IS NOT NULL AND manufacturer != ''
          AND (model IS NULL OR model = '')
        GROUP BY name, manufacturer
        HAVING count > 1
    ");
    
    while ($row = $stmt->fetch()) {
        $asset_ids = explode(',', $row['asset_ids']);
        // Consider all duplicates (including pairs) for name+manufacturer matches
        $groups[] = [
            'type' => 'name_mfr',
            'key' => $row['name'] . ' | ' . $row['manufacturer'],
            'asset_ids' => $asset_ids,
            'count' => count($asset_ids)
        ];
    }
    
    // Group 4: Same name only (if no manufacturer/model, likely duplicates)
    migration_log("Finding duplicates by name only (no manufacturer/model)...");
    $stmt = $pdo->query("
        SELECT name, GROUP_CONCAT(asset_id ORDER BY asset_id) as asset_ids, COUNT(*) as count
        FROM assets
        WHERE name IS NOT NULL AND name != ''
          AND (manufacturer IS NULL OR manufacturer = '')
          AND (model IS NULL OR model = '')
        GROUP BY name
        HAVING count > 1
    ");
    
    while ($row = $stmt->fetch()) {
        $asset_ids = explode(',', $row['asset_ids']);
        // Consider all duplicates (including pairs) for name-only matches
        $groups[] = [
            'type' => 'name_only',
            'key' => $row['name'],
            'asset_ids' => $asset_ids,
            'count' => count($asset_ids)
        ];
    }
    
    return $groups;
}

/**
 * Select best asset to keep from a group
 */
function select_best_asset($pdo, $asset_ids) {
    $best_id = null;
    $best_score = -1;
    $best_asset = null;
    
    foreach ($asset_ids as $asset_id) {
        $stmt = $pdo->prepare("SELECT * FROM assets WHERE asset_id = ?");
        $stmt->execute([$asset_id]);
        $asset = $stmt->fetch();
        
        if ($asset) {
            $score = get_completeness_score($asset);
            
            // Tie-breaker: prefer older asset_id (first imported)
            if ($score > $best_score || ($score == $best_score && $asset_id < $best_id)) {
                $best_score = $score;
                $best_id = $asset_id;
                $best_asset = $asset;
            }
        }
    }
    
    return ['id' => $best_id, 'asset' => $best_asset];
}

/**
 * Remove duplicate asset
 */
function remove_duplicate($pdo, $asset_id, $reason) {
    try {
        // Disable foreign key checks temporarily
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
        
        // Delete from related tables first
        $pdo->exec("DELETE FROM inventory_levels WHERE asset_id = $asset_id");
        $pdo->exec("DELETE FROM transactions WHERE asset_id = $asset_id");
        $pdo->exec("DELETE FROM allocations WHERE asset_id = $asset_id");
        $pdo->exec("DELETE FROM request_items WHERE asset_id = $asset_id");
        
        // Delete the asset
        $stmt = $pdo->prepare("DELETE FROM assets WHERE asset_id = ?");
        $stmt->execute([$asset_id]);
        
        // Re-enable foreign key checks
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        
        return true;
    } catch (PDOException $e) {
        $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
        throw $e;
    }
}

// Find all duplicate groups
$duplicate_groups = find_duplicate_groups($pdo);
$stats['duplicates_found'] = count($duplicate_groups);

migration_log("\nFound " . count($duplicate_groups) . " duplicate groups");

// Process each group
$assets_to_remove = [];
$assets_to_keep = [];

foreach ($duplicate_groups as $group) {
    migration_log("\nProcessing group: {$group['type']} - {$group['key']} ({$group['count']} duplicates)");
    
    // Select best asset to keep
    $best = select_best_asset($pdo, $group['asset_ids']);
    
    if ($best['id']) {
        $assets_to_keep[] = $best['id'];
        $assets_to_remove = array_merge($assets_to_remove, array_diff($group['asset_ids'], [$best['id']]));
        
        migration_log("  ✅ Keeping asset_id: {$best['id']} (score: " . get_completeness_score($best['asset']) . ")");
        migration_log("  ❌ Removing: " . implode(', ', array_diff($group['asset_ids'], [$best['id']])));
    }
}

// Remove duplicates
migration_log("\n=== Removing Duplicates ===");
$assets_to_remove = array_unique($assets_to_remove);
migration_log("Total duplicates to remove: " . count($assets_to_remove));

$removed = 0;
foreach ($assets_to_remove as $asset_id) {
    try {
        remove_duplicate($pdo, $asset_id, "Duplicate");
        $removed++;
        
        if ($removed % 100 == 0) {
            migration_log("Removed $removed / " . count($assets_to_remove) . " duplicates...");
        }
    } catch (Exception $e) {
        $stats['errors'][] = "Error removing asset_id $asset_id: " . $e->getMessage();
        migration_log("ERROR removing asset_id $asset_id: " . $e->getMessage());
    }
}

$stats['duplicates_removed'] = $removed;
$stats['kept'] = count($assets_to_keep);

// Summary
migration_log("\n=== Deduplication Complete ===");
migration_log("Duplicate groups found: " . $stats['duplicates_found']);
migration_log("Duplicates removed: " . $stats['duplicates_removed']);
migration_log("Assets kept: " . $stats['kept']);
migration_log("Errors: " . count($stats['errors']));

// Final count
$stmt = $pdo->query("SELECT COUNT(*) as total FROM assets");
$final_count = $stmt->fetch()['total'];
migration_log("Final asset count: $final_count");

if (!empty($stats['errors'])) {
    migration_log("\nErrors encountered:");
    foreach (array_slice($stats['errors'], 0, 10) as $error) {
        migration_log("  - $error");
    }
}

migration_log("\nCheck migration_log.txt for full details");
