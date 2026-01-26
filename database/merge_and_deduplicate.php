<?php
/**
 * Merge and Deduplicate Assets
 * 
 * This script identifies duplicate assets and merges data from duplicates
 * into the best record before removing duplicates.
 * 
 * Strategy:
 * 1. Find duplicate groups (same name+manufacturer+model, or same serial/tag)
 * 2. Merge data from all duplicates into the best record (most complete)
 * 3. Transfer any related records (transactions, allocations, etc.) to the kept record
 * 4. Remove duplicate records
 */

require_once __DIR__ . '/../web/config/database.php';
require_once __DIR__ . '/migration_utils.php';

migration_log("=== Merge and Deduplicate Assets ===");

// Statistics
$stats = [
    'duplicate_groups' => 0,
    'assets_merged' => 0,
    'assets_removed' => 0,
    'fields_merged' => 0,
    'errors' => []
];

/**
 * Merge data from source asset into target asset
 * Only merges non-empty fields from source into empty fields in target
 */
function merge_asset_data($target, $source) {
    $merged = $target;
    $fields_merged = 0;
    
    // Fields to merge (only if target is empty and source has value)
    $fields_to_merge = [
        'description', 'serial_number', 'manufacturer', 'model',
        'purchase_date', 'purchase_price', 'current_value', 'warranty_expiry',
        'asset_tag', 'notes', 'condition_status'
    ];
    
    foreach ($fields_to_merge as $field) {
        $target_value = $target[$field] ?? null;
        $source_value = $source[$field] ?? null;
        
        // If target is empty and source has value, merge it
        if (empty($target_value) && !empty($source_value)) {
            $merged[$field] = $source_value;
            $fields_merged++;
        }
        // If both have values but different, append to notes
        elseif (!empty($target_value) && !empty($source_value) && $target_value != $source_value && $field != 'notes') {
            if (empty($merged['notes'])) {
                $merged['notes'] = "Merged data: $field was '$source_value' (kept: '$target_value')";
            } else {
                $merged['notes'] .= "\nMerged: $field was '$source_value' (kept: '$target_value')";
            }
        }
    }
    
    // Merge notes
    if (!empty($source['notes']) && !empty($target['notes']) && $source['notes'] != $target['notes']) {
        $merged['notes'] = trim($target['notes'] . "\n\n--- Merged from duplicate ---\n" . $source['notes']);
    } elseif (!empty($source['notes']) && empty($target['notes'])) {
        $merged['notes'] = $source['notes'];
        $fields_merged++;
    }
    
    return ['asset' => $merged, 'fields_merged' => $fields_merged];
}

/**
 * Update asset with merged data
 */
function update_asset($pdo, $asset_id, $merged_data) {
    $stmt = $pdo->prepare("
        UPDATE assets SET
            description = ?,
            serial_number = ?,
            manufacturer = ?,
            model = ?,
            purchase_date = ?,
            purchase_price = ?,
            current_value = ?,
            warranty_expiry = ?,
            asset_tag = ?,
            notes = ?,
            condition_status = ?,
            updated_at = NOW()
        WHERE asset_id = ?
    ");
    
    $stmt->execute([
        $merged_data['description'] ?? null,
        $merged_data['serial_number'] ?? null,
        $merged_data['manufacturer'] ?? null,
        $merged_data['model'] ?? null,
        $merged_data['purchase_date'] ?? null,
        $merged_data['purchase_price'] ?? null,
        $merged_data['current_value'] ?? null,
        $merged_data['warranty_expiry'] ?? null,
        $merged_data['asset_tag'] ?? null,
        $merged_data['notes'] ?? null,
        $merged_data['condition_status'] ?? null,
        $asset_id
    ]);
}

/**
 * Transfer related records from source asset to target asset
 */
function transfer_related_records($pdo, $source_id, $target_id) {
    $transferred = 0;
    
    // Transfer transactions
    $stmt = $pdo->prepare("UPDATE transactions SET asset_id = ? WHERE asset_id = ?");
    $stmt->execute([$target_id, $source_id]);
    $transferred += $stmt->rowCount();
    
    // Transfer allocations
    $stmt = $pdo->prepare("UPDATE allocations SET asset_id = ? WHERE asset_id = ?");
    $stmt->execute([$target_id, $source_id]);
    $transferred += $stmt->rowCount();
    
    // Transfer request items
    $stmt = $pdo->prepare("UPDATE request_items SET asset_id = ? WHERE asset_id = ?");
    $stmt->execute([$target_id, $source_id]);
    $transferred += $stmt->rowCount();
    
    // Transfer inventory levels (handle unique constraint on asset_id + location_id)
    // First, get all inventory levels for source asset
    $stmt = $pdo->prepare("SELECT * FROM inventory_levels WHERE asset_id = ?");
    $stmt->execute([$source_id]);
    $source_levels = $stmt->fetchAll();
    
    foreach ($source_levels as $level) {
        // Check if target already has inventory level for this location
        $check = $pdo->prepare("SELECT inventory_id FROM inventory_levels WHERE asset_id = ? AND location_id = ?");
        $check->execute([$target_id, $level['location_id']]);
        $existing = $check->fetch();
        
        if ($existing) {
            // Update existing: sum quantities
            $update = $pdo->prepare("
                UPDATE inventory_levels SET
                    quantity_on_hand = quantity_on_hand + ?,
                    quantity_allocated = quantity_allocated + ?
                WHERE inventory_id = ?
            ");
            $update->execute([
                $level['quantity_on_hand'],
                $level['quantity_allocated'],
                $existing['inventory_id']
            ]);
        } else {
            // Insert new record with target asset_id
            $insert = $pdo->prepare("
                INSERT INTO inventory_levels (asset_id, location_id, country_id, quantity_on_hand, quantity_allocated)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insert->execute([
                $target_id,
                $level['location_id'],
                $level['country_id'],
                $level['quantity_on_hand'],
                $level['quantity_allocated']
            ]);
        }
    }
    
    // Delete source inventory levels
    $stmt = $pdo->prepare("DELETE FROM inventory_levels WHERE asset_id = ?");
    $stmt->execute([$source_id]);
    
    return $transferred;
}

/**
 * Find duplicate groups (same name+manufacturer+model, or same serial/tag)
 */
function find_duplicate_groups($pdo) {
    $groups = [];
    
    // Group 1: Same name + manufacturer + model (exact match)
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
            'asset_ids' => $asset_ids
        ];
    }
    
    // Group 2: Same name + manufacturer (no model)
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
        if (count($asset_ids) >= 2) {
            $groups[] = [
                'type' => 'name_mfr',
                'key' => $row['name'] . ' | ' . $row['manufacturer'],
                'asset_ids' => $asset_ids
            ];
        }
    }
    
    // Group 3: Same serial number
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
            'asset_ids' => $asset_ids
        ];
    }
    
    // Group 4: Same asset tag
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
            'asset_ids' => $asset_ids
        ];
    }
    
    // Group 5: Same normalized name (different data completeness)
    migration_log("Finding duplicates by normalized name (same name, different data)...");
    
    // Get all assets with same normalized name
    $stmt = $pdo->query("
        SELECT 
            asset_id, name, manufacturer, model, serial_number, asset_tag,
            LOWER(TRIM(REGEXP_REPLACE(name, '[^a-zA-Z0-9 ]', ''))) as normalized_name
        FROM assets
        WHERE name IS NOT NULL AND name != ''
        ORDER BY normalized_name, asset_id
    ");
    
    $assets_by_name = [];
    while ($row = $stmt->fetch()) {
        $normalized = preg_replace('/\s+/', ' ', strtolower(trim(preg_replace('/[^a-zA-Z0-9\s]/', '', $row['name']))));
        if (!isset($assets_by_name[$normalized])) {
            $assets_by_name[$normalized] = [];
        }
        $assets_by_name[$normalized][] = $row['asset_id'];
    }
    
    foreach ($assets_by_name as $normalized => $asset_ids) {
        if (count($asset_ids) > 1) {
            // Check if they have different data completeness
            $has_different_data = false;
            $asset_data = [];
            foreach ($asset_ids as $id) {
                $stmt = $pdo->prepare("SELECT manufacturer, model, serial_number, asset_tag, description FROM assets WHERE asset_id = ?");
                $stmt->execute([$id]);
                $asset_data[$id] = $stmt->fetch();
            }
            
            // Compare data completeness
            foreach ($asset_data as $id1 => $data1) {
                foreach ($asset_data as $id2 => $data2) {
                    if ($id1 >= $id2) continue;
                    
                    // If one has data the other doesn't, they might be duplicates
                    if ((empty($data1['manufacturer']) && !empty($data2['manufacturer'])) ||
                        (!empty($data1['manufacturer']) && empty($data2['manufacturer'])) ||
                        (empty($data1['model']) && !empty($data2['model'])) ||
                        (!empty($data1['model']) && empty($data2['model'])) ||
                        (empty($data1['serial_number']) && !empty($data2['serial_number'])) ||
                        (!empty($data1['serial_number']) && empty($data2['serial_number']))) {
                        $has_different_data = true;
                        break 2;
                    }
                }
            }
            
            if ($has_different_data) {
                $groups[] = [
                    'type' => 'normalized_name',
                    'key' => $normalized,
                    'asset_ids' => $asset_ids
                ];
            }
        }
    }
    
    return $groups;
}

/**
 * Select best asset to keep (most complete)
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
 * Calculate completeness score
 */
function get_completeness_score($asset) {
    $score = 0;
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

// Find duplicate groups
$duplicate_groups = find_duplicate_groups($pdo);
$stats['duplicate_groups'] = count($duplicate_groups);

migration_log("\nFound " . count($duplicate_groups) . " duplicate groups");

// Process each group
$processed_assets = [];
foreach ($duplicate_groups as $group) {
    migration_log("\nProcessing: {$group['type']} - {$group['key']} (" . count($group['asset_ids']) . " assets)");
    
    // Skip if already processed
    $already_processed = false;
    foreach ($group['asset_ids'] as $id) {
        if (isset($processed_assets[$id])) {
            $already_processed = true;
            break;
        }
    }
    if ($already_processed) {
        migration_log("  ⚠️  Skipped (already processed)");
        continue;
    }
    
    // Select best asset to keep
    $best = select_best_asset($pdo, $group['asset_ids']);
    if (!$best['id']) {
        continue;
    }
    
    $keep_id = $best['id'];
    $merged_asset = $best['asset'];
    $total_fields_merged = 0;
    
    migration_log("  ✅ Keeping asset_id: {$keep_id} (score: " . get_completeness_score($merged_asset) . ")");
    
    // Merge data from all other assets
    foreach ($group['asset_ids'] as $asset_id) {
        if ($asset_id == $keep_id) {
            continue;
        }
        
        $stmt = $pdo->prepare("SELECT * FROM assets WHERE asset_id = ?");
        $stmt->execute([$asset_id]);
        $source_asset = $stmt->fetch();
        
        if ($source_asset) {
            // Merge data
            $result = merge_asset_data($merged_asset, $source_asset);
            $merged_asset = $result['asset'];
            $total_fields_merged += $result['fields_merged'];
            
            // Transfer related records
            $transferred = transfer_related_records($pdo, $asset_id, $keep_id);
            
            migration_log("    Merged asset_id: {$asset_id} ({$result['fields_merged']} fields, {$transferred} related records)");
        }
    }
    
    // Update the kept asset with merged data
    if ($total_fields_merged > 0) {
        update_asset($pdo, $keep_id, $merged_asset);
        $stats['fields_merged'] += $total_fields_merged;
        migration_log("    Updated kept asset with {$total_fields_merged} merged fields");
    }
    
    // Mark as processed
    foreach ($group['asset_ids'] as $id) {
        $processed_assets[$id] = true;
    }
    
    // Remove duplicates (except the one we're keeping)
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    foreach ($group['asset_ids'] as $asset_id) {
        if ($asset_id != $keep_id) {
            try {
                // Delete from related tables (already transferred)
                $pdo->exec("DELETE FROM inventory_levels WHERE asset_id = $asset_id");
                
                // Delete the asset
                $stmt = $pdo->prepare("DELETE FROM assets WHERE asset_id = ?");
                $stmt->execute([$asset_id]);
                
                $stats['assets_removed']++;
            } catch (Exception $e) {
                $stats['errors'][] = "Error removing asset_id $asset_id: " . $e->getMessage();
            }
        }
    }
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    $stats['assets_merged']++;
}

// Summary
migration_log("\n=== Merge and Deduplication Complete ===");
migration_log("Duplicate groups processed: " . $stats['duplicate_groups']);
migration_log("Assets merged: " . $stats['assets_merged']);
migration_log("Assets removed: " . $stats['assets_removed']);
migration_log("Fields merged: " . $stats['fields_merged']);
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
