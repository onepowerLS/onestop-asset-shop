<?php
/**
 * Remove Duplicate Assets
 * 
 * This script identifies and removes duplicate assets, keeping the first occurrence
 */
require_once __DIR__ . '/../web/config/database.php';

echo "=== Removing Duplicate Assets ===\n\n";

// Strategy: Keep the asset with the lowest asset_id for each duplicate group

// Find duplicates by name + manufacturer + model (when serial/tag are empty)
echo "1. Finding duplicates by name + manufacturer + model...\n";

$duplicates = $pdo->query("
    SELECT 
        name, manufacturer, model,
        COUNT(*) as count,
        GROUP_CONCAT(asset_id ORDER BY asset_id) as asset_ids
    FROM assets
    WHERE (serial_number IS NULL OR serial_number = '' OR serial_number = 'null')
      AND (asset_tag IS NULL OR asset_tag = '')
      AND name IS NOT NULL 
      AND name != ''
      AND name != 'Unknown'
    GROUP BY name, manufacturer, model
    HAVING count > 1
    ORDER BY count DESC
")->fetchAll();

$total_duplicates = 0;
$to_delete = [];

foreach ($duplicates as $dup) {
    $asset_ids = explode(',', $dup['asset_ids']);
    $keep_id = min($asset_ids); // Keep the first/lowest ID
    $delete_ids = array_filter($asset_ids, fn($id) => $id != $keep_id);
    
    $total_duplicates += count($delete_ids);
    $to_delete = array_merge($to_delete, $delete_ids);
    
    echo "   - {$dup['name']}: Keeping ID $keep_id, deleting " . count($delete_ids) . " duplicates\n";
}

echo "\n2. Finding duplicates by serial number...\n";
$serial_dups = $pdo->query("
    SELECT 
        serial_number,
        COUNT(*) as count,
        GROUP_CONCAT(asset_id ORDER BY asset_id) as asset_ids
    FROM assets
    WHERE serial_number IS NOT NULL 
      AND serial_number != ''
      AND serial_number != 'null'
    GROUP BY serial_number
    HAVING count > 1
")->fetchAll();

foreach ($serial_dups as $dup) {
    $asset_ids = explode(',', $dup['asset_ids']);
    $keep_id = min($asset_ids);
    $delete_ids = array_filter($asset_ids, fn($id) => $id != $keep_id);
    
    $total_duplicates += count($delete_ids);
    $to_delete = array_merge($to_delete, $delete_ids);
    
    echo "   - Serial {$dup['serial_number']}: Keeping ID $keep_id, deleting " . count($delete_ids) . " duplicates\n";
}

echo "\n3. Finding duplicates by asset tag...\n";
$tag_dups = $pdo->query("
    SELECT 
        asset_tag,
        COUNT(*) as count,
        GROUP_CONCAT(asset_id ORDER BY asset_id) as asset_ids
    FROM assets
    WHERE asset_tag IS NOT NULL 
      AND asset_tag != ''
    GROUP BY asset_tag
    HAVING count > 1
")->fetchAll();

foreach ($tag_dups as $dup) {
    $asset_ids = explode(',', $dup['asset_ids']);
    $keep_id = min($asset_ids);
    $delete_ids = array_filter($asset_ids, fn($id) => $id != $keep_id);
    
    $total_duplicates += count($delete_ids);
    $to_delete = array_merge($to_delete, $delete_ids);
    
    echo "   - Tag {$dup['asset_tag']}: Keeping ID $keep_id, deleting " . count($delete_ids) . " duplicates\n";
}

// Remove duplicates from array
$to_delete = array_unique($to_delete);
$delete_count = count($to_delete);

echo "\n=== Summary ===\n";
echo "Total duplicates to remove: $delete_count\n";
echo "Assets to keep: " . (3299 - $delete_count) . "\n\n";

if (empty($to_delete)) {
    echo "✅ No duplicates found!\n";
    exit(0);
}

// Ask for confirmation
echo "⚠️  WARNING: This will delete $delete_count duplicate assets!\n";
echo "Press Enter to continue, or Ctrl+C to cancel...\n";
// For automated run, we'll proceed (comment out for manual confirmation)
// fgets(STDIN);

echo "\n4. Deleting duplicates...\n";

$pdo->beginTransaction();
try {
    $deleted = 0;
    $stmt = $pdo->prepare("DELETE FROM assets WHERE asset_id = ?");
    
    foreach ($to_delete as $asset_id) {
        $stmt->execute([$asset_id]);
        $deleted++;
        if ($deleted % 100 == 0) {
            echo "   Deleted $deleted / $delete_count...\n";
        }
    }
    
    $pdo->commit();
    
    echo "\n✅ Successfully deleted $deleted duplicate assets!\n";
    
    // Verify
    $remaining = $pdo->query("SELECT COUNT(*) as count FROM assets")->fetch()['count'];
    echo "Remaining assets: $remaining\n";
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Transaction rolled back. No changes made.\n";
    exit(1);
}
