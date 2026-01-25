<?php
/**
 * Check for Duplicate Assets
 */
require_once __DIR__ . '/../web/config/database.php';

echo "=== Duplicate Asset Analysis ===\n\n";

// Total assets
$total = $pdo->query("SELECT COUNT(*) as count FROM assets")->fetch()['count'];
echo "Total Assets: $total\n\n";

// Check for duplicate serial numbers
echo "1. Duplicate Serial Numbers:\n";
$duplicates = $pdo->query("
    SELECT serial_number, COUNT(*) as count 
    FROM assets 
    WHERE serial_number IS NOT NULL 
    AND serial_number != '' 
    AND serial_number != 'null'
    GROUP BY serial_number 
    HAVING count > 1
    ORDER BY count DESC
    LIMIT 20
")->fetchAll();

if (empty($duplicates)) {
    echo "   ✅ No duplicate serial numbers found\n";
} else {
    echo "   ⚠️  Found " . count($duplicates) . " serial numbers with duplicates:\n";
    foreach ($duplicates as $dup) {
        echo "      - {$dup['serial_number']}: {$dup['count']} assets\n";
    }
}
echo "\n";

// Check for duplicate asset tags
echo "2. Duplicate Asset Tags:\n";
$duplicates = $pdo->query("
    SELECT asset_tag, COUNT(*) as count 
    FROM assets 
    WHERE asset_tag IS NOT NULL 
    AND asset_tag != ''
    GROUP BY asset_tag 
    HAVING count > 1
    ORDER BY count DESC
    LIMIT 20
")->fetchAll();

if (empty($duplicates)) {
    echo "   ✅ No duplicate asset tags found\n";
} else {
    echo "   ⚠️  Found " . count($duplicates) . " asset tags with duplicates:\n";
    foreach ($duplicates as $dup) {
        echo "      - {$dup['asset_tag']}: {$dup['count']} assets\n";
    }
}
echo "\n";

// Check for duplicate name+manufacturer+model
echo "3. Duplicate Name + Manufacturer + Model:\n";
$duplicates = $pdo->query("
    SELECT name, manufacturer, model, COUNT(*) as count 
    FROM assets 
    WHERE name IS NOT NULL 
    AND name != ''
    AND name != 'Unknown'
    GROUP BY name, manufacturer, model 
    HAVING count > 1
    ORDER BY count DESC
    LIMIT 20
")->fetchAll();

if (empty($duplicates)) {
    echo "   ✅ No duplicate name+manufacturer+model combinations found\n";
} else {
    echo "   ⚠️  Found " . count($duplicates) . " duplicate combinations:\n";
    foreach ($duplicates as $dup) {
        $name = $dup['name'];
        $mfr = $dup['manufacturer'] ?: 'NULL';
        $model = $dup['model'] ?: 'NULL';
        echo "      - $name / $mfr / $model: {$dup['count']} assets\n";
    }
}
echo "\n";

// Check for exact duplicates (all key fields match)
echo "4. Exact Duplicates (Serial + Tag + Name + Mfr + Model):\n";
$exact_dups = $pdo->query("
    SELECT 
        serial_number, asset_tag, name, manufacturer, model,
        COUNT(*) as count,
        GROUP_CONCAT(asset_id ORDER BY asset_id) as asset_ids
    FROM assets
    WHERE (serial_number IS NOT NULL AND serial_number != '' AND serial_number != 'null')
       OR (asset_tag IS NOT NULL AND asset_tag != '')
       OR (name IS NOT NULL AND name != '' AND name != 'Unknown')
    GROUP BY serial_number, asset_tag, name, manufacturer, model
    HAVING count > 1
    ORDER BY count DESC
    LIMIT 20
")->fetchAll();

if (empty($exact_dups)) {
    echo "   ✅ No exact duplicates found\n";
} else {
    echo "   ⚠️  Found " . count($exact_dups) . " exact duplicate groups:\n";
    foreach ($exact_dups as $dup) {
        echo "      - {$dup['name']} (Serial: {$dup['serial_number']}, Tag: {$dup['asset_tag']}): {$dup['count']} assets (IDs: {$dup['asset_ids']})\n";
    }
}
echo "\n";

// Check migration runs
echo "5. Migration History:\n";
$runs = $pdo->query("
    SELECT 
        DATE(created_at) as date,
        COUNT(*) as count,
        MIN(created_at) as first,
        MAX(created_at) as last
    FROM assets
    GROUP BY DATE(created_at)
    ORDER BY date
")->fetchAll();

foreach ($runs as $run) {
    echo "   - {$run['date']}: {$run['count']} assets (from {$run['first']} to {$run['last']})\n";
}
echo "\n";

// Summary
echo "=== Summary ===\n";
$unique_serials = $pdo->query("SELECT COUNT(DISTINCT serial_number) as count FROM assets WHERE serial_number IS NOT NULL AND serial_number != '' AND serial_number != 'null'")->fetch()['count'];
$unique_tags = $pdo->query("SELECT COUNT(DISTINCT asset_tag) as count FROM assets WHERE asset_tag IS NOT NULL AND asset_tag != ''")->fetch()['count'];
$unique_names = $pdo->query("SELECT COUNT(DISTINCT name) as count FROM assets WHERE name IS NOT NULL AND name != '' AND name != 'Unknown'")->fetch()['count'];

echo "Total Assets: $total\n";
echo "Unique Serial Numbers: $unique_serials\n";
echo "Unique Asset Tags: $unique_tags\n";
echo "Unique Names: $unique_names\n";
