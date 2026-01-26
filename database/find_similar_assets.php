<?php
/**
 * Find Similar Assets (Potential Duplicates)
 * 
 * Finds assets that are likely the same but have different data completeness
 * - Same name (normalized) but different manufacturer/model
 * - Same name but one has more fields filled
 * - Similar names (fuzzy matching)
 */

require_once __DIR__ . '/../web/config/database.php';
require_once __DIR__ . '/migration_utils.php';

migration_log("=== Finding Similar Assets (Potential Duplicates) ===");

/**
 * Normalize asset name for comparison
 */
function normalize_name($name) {
    $name = strtolower(trim($name));
    $name = preg_replace('/\s+/', ' ', $name); // Multiple spaces to single
    $name = preg_replace('/[^a-z0-9\s]/', '', $name); // Remove special chars
    return $name;
}

/**
 * Calculate similarity between two asset names
 */
function name_similarity($name1, $name2) {
    $n1 = normalize_name($name1);
    $n2 = normalize_name($name2);
    
    if ($n1 === $n2) {
        return 100;
    }
    
    // Levenshtein distance
    $distance = levenshtein($n1, $n2);
    $max_len = max(strlen($n1), strlen($n2));
    if ($max_len == 0) return 0;
    
    return (1 - ($distance / $max_len)) * 100;
}

// Find assets with same normalized name but different data
migration_log("\nFinding assets with same name but different completeness...");

$stmt = $pdo->query("
    SELECT 
        asset_id, name, manufacturer, model, serial_number, asset_tag,
        description, purchase_price, current_value,
        CASE 
            WHEN description IS NOT NULL AND description != '' THEN 1 ELSE 0 END +
            CASE WHEN serial_number IS NOT NULL AND serial_number != '' THEN 1 ELSE 0 END +
            CASE WHEN manufacturer IS NOT NULL AND manufacturer != '' THEN 1 ELSE 0 END +
            CASE WHEN model IS NOT NULL AND model != '' THEN 1 ELSE 0 END +
            CASE WHEN purchase_price IS NOT NULL AND purchase_price > 0 THEN 1 ELSE 0 END +
            CASE WHEN current_value IS NOT NULL AND current_value > 0 THEN 1 ELSE 0 END +
            CASE WHEN asset_tag IS NOT NULL AND asset_tag != '' THEN 1 ELSE 0 END
        as completeness_score
    FROM assets
    WHERE name IS NOT NULL AND name != ''
    ORDER BY name, completeness_score DESC
");

$assets = $stmt->fetchAll();

// Group by normalized name
$groups = [];
foreach ($assets as $asset) {
    $normalized = normalize_name($asset['name']);
    if (!isset($groups[$normalized])) {
        $groups[$normalized] = [];
    }
    $groups[$normalized][] = $asset;
}

// Find groups with multiple assets (potential duplicates)
$potential_duplicates = [];
foreach ($groups as $normalized_name => $group) {
    if (count($group) > 1) {
        // Check if they're truly similar (not just same name with different data)
        $has_different_data = false;
        foreach ($group as $i => $asset1) {
            foreach ($group as $j => $asset2) {
                if ($i >= $j) continue;
                
                // If one has manufacturer/model and other doesn't, might be same asset
                if (empty($asset1['manufacturer']) && !empty($asset2['manufacturer'])) {
                    $has_different_data = true;
                }
                if (empty($asset1['model']) && !empty($asset2['model'])) {
                    $has_different_data = true;
                }
                if (empty($asset1['serial_number']) && !empty($asset2['serial_number'])) {
                    $has_different_data = true;
                }
                if (empty($asset1['description']) && !empty($asset2['description'])) {
                    $has_different_data = true;
                }
            }
        }
        
        if ($has_different_data) {
            $potential_duplicates[] = [
                'normalized_name' => $normalized_name,
                'assets' => $group,
                'count' => count($group)
            ];
        }
    }
}

migration_log("\nFound " . count($potential_duplicates) . " groups of potentially duplicate assets");

// Display potential duplicates
$total_potential = 0;
foreach ($potential_duplicates as $group) {
    if ($group['count'] > 1) {
        migration_log("\n" . str_repeat("=", 60));
        migration_log("Potential Duplicates: '{$group['normalized_name']}' ({$group['count']} assets)");
        
        foreach ($group['assets'] as $asset) {
            $details = [];
            if ($asset['manufacturer']) $details[] = "Mfr: {$asset['manufacturer']}";
            if ($asset['model']) $details[] = "Model: {$asset['model']}";
            if ($asset['serial_number']) $details[] = "SN: {$asset['serial_number']}";
            if ($asset['asset_tag']) $details[] = "Tag: {$asset['asset_tag']}";
            
            $details_str = $details ? " (" . implode(", ", $details) . ")" : " (no additional data)";
            migration_log("  - ID: {$asset['asset_id']}, Name: '{$asset['name']}', Score: {$asset['completeness_score']}{$details_str}");
        }
        
        $total_potential += ($group['count'] - 1); // Number of duplicates (keep 1)
    }
}

migration_log("\n" . str_repeat("=", 60));
migration_log("Summary:");
migration_log("  Groups with potential duplicates: " . count($potential_duplicates));
migration_log("  Total potential duplicate assets: " . $total_potential);
migration_log("\nThese assets have the same name but different data completeness.");
migration_log("They may represent the same physical asset with incomplete records.");
migration_log("\nRun merge_and_deduplicate.php to merge these automatically.");
