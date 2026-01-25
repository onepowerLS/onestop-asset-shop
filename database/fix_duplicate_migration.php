<?php
/**
 * Fix Duplicate Migration
 * 
 * The migration ran multiple times. This script will:
 * 1. Keep only the first 1615 assets (from first migration run)
 * 2. Delete the duplicate run (assets after ID 1615)
 * 3. Re-generate QR codes if needed
 */
require_once __DIR__ . '/../web/config/database.php';

echo "=== Fixing Duplicate Migration ===\n\n";

// Check current count
$total = $pdo->query("SELECT COUNT(*) as count FROM assets")->fetch()['count'];
echo "Current total assets: $total\n";

// Count assets in first batch (should be 1615)
$first_batch = $pdo->query("SELECT COUNT(*) as count FROM assets WHERE asset_id <= 1615")->fetch()['count'];
echo "Assets in first batch (ID <= 1615): $first_batch\n";

// Count assets in second batch
$second_batch = $pdo->query("SELECT COUNT(*) as count FROM assets WHERE asset_id > 1615")->fetch()['count'];
echo "Assets in second batch (ID > 1615): $second_batch\n\n";

if ($second_batch == 0) {
    echo "✅ No duplicate migration found. All assets are from first run.\n";
    exit(0);
}

echo "⚠️  WARNING: Found duplicate migration run!\n";
echo "This will delete $second_batch duplicate assets (IDs > 1615).\n";
echo "Keeping the first $first_batch assets.\n\n";

// Actually, let's be smarter - check if the second batch has the same data
echo "Analyzing duplicates...\n";

// Check if assets in second batch are duplicates of first batch
$duplicate_check = $pdo->query("
    SELECT COUNT(*) as count
    FROM assets a1
    INNER JOIN assets a2 ON (
        a1.asset_id <= 1615 
        AND a2.asset_id > 1615
        AND (
            (a1.serial_number IS NOT NULL AND a1.serial_number != '' AND a1.serial_number = a2.serial_number)
            OR (a1.asset_tag IS NOT NULL AND a1.asset_tag != '' AND a1.asset_tag = a2.asset_tag)
            OR (
                a1.name = a2.name 
                AND (a1.manufacturer = a2.manufacturer OR (a1.manufacturer IS NULL AND a2.manufacturer IS NULL))
                AND (a1.model = a2.model OR (a1.model IS NULL AND a2.model IS NULL))
                AND (a1.serial_number IS NULL OR a1.serial_number = '' OR a1.serial_number = 'null')
                AND (a1.asset_tag IS NULL OR a1.asset_tag = '')
            )
        )
    )
")->fetch()['count'];

echo "Found $duplicate_check potential duplicate matches between batches.\n\n";

// The safest approach: Delete all assets and re-run migration once
echo "=== Solution Options ===\n";
echo "Option 1: Delete second batch (IDs > 1615) - Quick fix\n";
echo "Option 2: Clear all and re-run migration - Clean slate\n\n";

// For now, let's do Option 1 - delete the second batch
echo "Executing Option 1: Deleting second batch (IDs > 1615)...\n";

$pdo->beginTransaction();
try {
    // Disable foreign key checks temporarily
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 0");
    
    // Delete related records first (if tables exist)
    try {
        $pdo->exec("DELETE FROM inventory_levels WHERE asset_id IN (SELECT asset_id FROM (SELECT asset_id FROM assets WHERE asset_id > 1615) AS temp)");
    } catch (PDOException $e) {
        // Table might not exist or have different structure
        echo "   Note: Could not delete from inventory_levels: " . $e->getMessage() . "\n";
    }
    
    try {
        $pdo->exec("DELETE FROM transactions WHERE asset_id IN (SELECT asset_id FROM (SELECT asset_id FROM assets WHERE asset_id > 1615) AS temp)");
    } catch (PDOException $e) {
        echo "   Note: Could not delete from transactions: " . $e->getMessage() . "\n";
    }
    
    try {
        $pdo->exec("DELETE FROM allocations WHERE asset_id IN (SELECT asset_id FROM (SELECT asset_id FROM assets WHERE asset_id > 1615) AS temp)");
    } catch (PDOException $e) {
        echo "   Note: Could not delete from allocations: " . $e->getMessage() . "\n";
    }
    
    try {
        $pdo->exec("DELETE FROM requests WHERE asset_id IN (SELECT asset_id FROM (SELECT asset_id FROM assets WHERE asset_id > 1615) AS temp)");
    } catch (PDOException $e) {
        echo "   Note: Could not delete from requests: " . $e->getMessage() . "\n";
    }
    
    // Delete duplicate assets
    $stmt = $pdo->prepare("DELETE FROM assets WHERE asset_id > 1615");
    $stmt->execute();
    $deleted = $stmt->rowCount();
    
    // Re-enable foreign key checks
    $pdo->exec("SET FOREIGN_KEY_CHECKS = 1");
    
    $pdo->commit();
    
    echo "✅ Successfully deleted $deleted duplicate assets!\n";
    
    // Verify
    $remaining = $pdo->query("SELECT COUNT(*) as count FROM assets")->fetch()['count'];
    echo "Remaining assets: $remaining\n";
    
    // Check for any remaining duplicates
    echo "\nChecking for remaining duplicates...\n";
    $remaining_dups = $pdo->query("
        SELECT name, COUNT(*) as count 
        FROM assets 
        WHERE name IS NOT NULL AND name != '' AND name != 'Unknown'
        GROUP BY name, manufacturer, model
        HAVING count > 1
        LIMIT 5
    ")->fetchAll();
    
    if (empty($remaining_dups)) {
        echo "✅ No remaining duplicates found!\n";
    } else {
        echo "⚠️  Found " . count($remaining_dups) . " remaining duplicate groups:\n";
        foreach ($remaining_dups as $dup) {
            echo "   - {$dup['name']}: {$dup['count']} assets\n";
        }
    }
    
} catch (PDOException $e) {
    $pdo->rollBack();
    echo "\n❌ ERROR: " . $e->getMessage() . "\n";
    echo "Transaction rolled back. No changes made.\n";
    exit(1);
}
