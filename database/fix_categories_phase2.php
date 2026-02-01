<?php
/**
 * Fix Categories Phase 2: Merge duplicates and map remaining General categories
 * 
 * This script:
 * 1. Merges duplicate/misspelled categories into canonical names
 * 2. Maps remaining General categories to proper types
 * 3. Updates all asset references to point to canonical categories
 */

require_once __DIR__ . '/../web/config/database.php';

echo "=== Category Cleanup Phase 2 ===\n\n";

// Define canonical categories and their mappings
// Format: 'Canonical Name' => ['type', ['variant1', 'variant2', ...]]
$canonical_categories = [
    // FAC (Facilities) - Buildings, furniture, office, infrastructure
    'IT Equipment' => ['FAC', ['IT Equipment', 'IT equpment', 'IT Eqquipment', 'IT equipment0']],
    'Office Equipment' => ['FAC', ['Office Equipment', 'office equipmrnt']],
    'Furniture' => ['FAC', ['Furniture']],
    'Kitchen Equipment' => ['FAC', ['Kitchen Equipment']],
    'Facilities Equipment' => ['FAC', ['Facilities Equipment']],
    'Communications Equipment' => ['FAC', ['Communications Equipment']],
    'Storage Equipment' => ['FAC', ['Storage', 'storage equipment']],
    'Housing' => ['FAC', ['Housing']],
    'Heating Equipment' => ['FAC', ['heating equipment', 'Heating Equipment']],
    'Electrical Appliances' => ['FAC', ['Electrical Appliance/ Equipment', 'Electrical Appliance']],
    
    // O&M (Operations & Maintenance) - Plant, vehicles, machinery, production
    'Plant and Machinery' => ['O&M', ['Plant And Vehicles', 'Plant And Equipment', 'plant and machinery', 'Plant And Machinery', 'plant and equpment', 'Machinery And Equipment']],
    'Vehicle Equipment' => ['O&M', ['vehicle equipment', 'Vehicle And Equipment', 'Vehicle additional storage Equipment']],
    'Production Equipment' => ['O&M', ['Production Equipment', 'Hq Production Area, Welding Area']],
    'Powerhouse Equipment' => ['O&M', ['Powerhouse Equipment']],
    'O&M Equipment' => ['O&M', ['OnM Equipment', 'O&M Equipment']],
    'Construction Equipment' => ['O&M', ['Construction Equipment']],
    'Machine Equipment' => ['O&M', ['Machine Equipment']],
    'M&E Equipment' => ['O&M', ['M.E Equipment']],
    
    // Tools - Testing, measuring, safety, engineering
    'Test Equipment' => ['Tools', ['Test Equipment', 'test equipmrnt', 'test quipment', 'tes equipment', 'test eqipment', 'test equpment', 'Testing Equipment', 'Fleet Test Equipment']],
    'Measuring Tools' => ['Tools', ['Measuring Tools', 'Measuring Instrument']],
    'Lifting Tools' => ['Tools', ['Lifting Tools', 'lifting tools', 'lifting equipment']],
    'Hand Tools' => ['Tools', ['Hand Tools', 'hand tool']],
    'Power Tools' => ['Tools', ['Power Tools', 'power tool', 'power rool']],
    'Machine Tools' => ['Tools', ['Machine Tools']],
    'Production Tools' => ['Tools', ['Production Tools', 'Hq Production, Welding Area On Welding Table']],
    'Welding Tools' => ['Tools', ['welding tool', 'Welding PPE']],
    'Safety Equipment' => ['Tools', ['Safety', 'EHS Equipment']],
    'Engineering Equipment' => ['Tools', ['EE Equipment', 'EE', 'Engineering Equipment']],
    'Electrical Equipment' => ['Tools', ['Electrical Equipment']],
    'Weather Station Equipment' => ['Tools', ['Weather Station Equipment']],
    
    // Other - Miscellaneous that don't fit elsewhere
    'Other' => ['Other', ['5CG8231LDP']], // Looks like a serial number, not a category
];

// Get all current categories
$stmt = $pdo->query("SELECT category_id, category_code, category_name, category_type FROM categories ORDER BY category_name");
$all_categories = $stmt->fetchAll();

echo "Current categories: " . count($all_categories) . "\n\n";

// Build mapping: old_category_id => canonical_category_id
$category_mapping = [];
$canonical_ids = [];
$categories_to_create = [];
$categories_to_delete = [];

// First pass: identify which canonical categories need to be created vs already exist
foreach ($canonical_categories as $canonical_name => $config) {
    list($type, $variants) = $config;
    
    $found_canonical = null;
    $variant_ids = [];
    
    foreach ($all_categories as $cat) {
        $cat_name_lower = strtolower(trim($cat['category_name']));
        foreach ($variants as $variant) {
            if ($cat_name_lower === strtolower(trim($variant))) {
                $variant_ids[] = $cat['category_id'];
                // Prefer exact match to canonical name
                if (strtolower($cat['category_name']) === strtolower($canonical_name)) {
                    $found_canonical = $cat['category_id'];
                }
            }
        }
    }
    
    if (empty($variant_ids)) {
        continue; // No matching categories found
    }
    
    // If canonical doesn't exist, use the first variant as the base
    if (!$found_canonical) {
        $found_canonical = $variant_ids[0];
    }
    
    $canonical_ids[$canonical_name] = [
        'id' => $found_canonical,
        'type' => $type,
        'merge_from' => array_diff($variant_ids, [$found_canonical])
    ];
}

echo "Canonical categories to establish:\n";
foreach ($canonical_ids as $name => $info) {
    $merge_count = count($info['merge_from']);
    echo "  - $name ({$info['type']}): ID {$info['id']}";
    if ($merge_count > 0) {
        echo " + merge $merge_count duplicates";
    }
    echo "\n";
}
echo "\n";

// Confirm before proceeding
echo "This will:\n";
echo "  1. Rename categories to canonical names\n";
echo "  2. Update category types (General -> proper type)\n";
echo "  3. Merge duplicate categories (update asset references)\n";
echo "  4. Delete merged duplicate categories\n\n";
echo "Proceed? (yes/no): ";
$confirm = trim(fgets(STDIN));

if (strtolower($confirm) !== 'yes') {
    echo "Aborted.\n";
    exit(0);
}

$stats = [
    'renamed' => 0,
    'type_updated' => 0,
    'assets_remapped' => 0,
    'categories_deleted' => 0,
    'errors' => []
];

// Code prefixes for each type
$code_prefixes = [
    'RET' => 'RET',
    'FAC' => 'FAC',
    'O&M' => 'O&M',
    'Meters' => 'MET',
    'ReadyBoards' => 'RB',
    'Tools' => 'TOOL',
    'Other' => 'OTH',
    'General' => 'GEN'
];

try {
    $pdo->beginTransaction();
    
    foreach ($canonical_ids as $canonical_name => $info) {
        $canonical_id = $info['id'];
        $type = $info['type'];
        $merge_from = $info['merge_from'];
        
        // 1. Rename and update type for canonical category
        $prefix = $code_prefixes[$type] ?? 'GEN';
        $new_code = $prefix . '-' . str_pad($canonical_id, 3, '0', STR_PAD_LEFT);
        
        $stmt = $pdo->prepare("
            UPDATE categories 
            SET category_name = ?, category_type = ?, category_code = ?
            WHERE category_id = ?
        ");
        $stmt->execute([$canonical_name, $type, $new_code, $canonical_id]);
        $stats['renamed']++;
        $stats['type_updated']++;
        echo "  Updated: $canonical_name ($type, $new_code)\n";
        
        // 2. Merge duplicates: update asset references
        foreach ($merge_from as $old_id) {
            // Get old category name for logging
            $stmt = $pdo->prepare("SELECT category_name FROM categories WHERE category_id = ?");
            $stmt->execute([$old_id]);
            $old_cat = $stmt->fetch();
            $old_name = $old_cat ? $old_cat['category_name'] : "ID:$old_id";
            
            // Update assets to point to canonical category
            $stmt = $pdo->prepare("UPDATE assets SET category_id = ? WHERE category_id = ?");
            $stmt->execute([$canonical_id, $old_id]);
            $affected = $stmt->rowCount();
            $stats['assets_remapped'] += $affected;
            
            // Delete the duplicate category
            $stmt = $pdo->prepare("DELETE FROM categories WHERE category_id = ?");
            $stmt->execute([$old_id]);
            $stats['categories_deleted']++;
            
            echo "    Merged: '$old_name' (ID:$old_id) -> '$canonical_name' (ID:$canonical_id), $affected assets remapped\n";
        }
    }
    
    $pdo->commit();
    echo "\n✅ Transaction committed successfully\n";
    
} catch (Exception $e) {
    $pdo->rollBack();
    echo "\n❌ Error: " . $e->getMessage() . "\n";
    echo "Transaction rolled back.\n";
    exit(1);
}

echo "\n=== Results ===\n";
echo "Categories renamed: {$stats['renamed']}\n";
echo "Types updated: {$stats['type_updated']}\n";
echo "Assets remapped: {$stats['assets_remapped']}\n";
echo "Duplicate categories deleted: {$stats['categories_deleted']}\n";

// Show final state
echo "\nFinal categories by type:\n";
$stmt = $pdo->query("SELECT category_type, COUNT(*) as count FROM categories GROUP BY category_type ORDER BY count DESC");
while ($row = $stmt->fetch()) {
    echo "  {$row['category_type']}: {$row['count']}\n";
}

echo "\nAll categories:\n";
$stmt = $pdo->query("SELECT category_id, category_code, category_name, category_type FROM categories ORDER BY category_type, category_name");
while ($row = $stmt->fetch()) {
    echo "  [{$row['category_id']}] {$row['category_code']} - {$row['category_name']} ({$row['category_type']})\n";
}

echo "\nDone!\n";
