<?php
/**
 * Fix Categories Phase 3: Update to descriptive category codes
 * 
 * New schema:
 * - TOOL-xxx: Tools (hand tools, power tools, measuring, etc.)
 * - EQUIP-xxx: Equipment (test, engineering, electrical, etc.)
 * - PLANT-xxx: Plant & Machinery (vehicles, heavy equipment, production)
 * - FURN-xxx: Furniture
 * - APPLNC-xxx: Appliances (electrical, kitchen, heating)
 * - STRUCT-xxx: Structures (housing, storage, facilities)
 * - COMM-xxx: Communications & IT
 * - SAFETY-xxx: Safety & PPE
 * - OTHER-xxx: Miscellaneous
 */

require_once __DIR__ . '/../web/config/database.php';

echo "=== Category Code Schema Update (Phase 3) ===\n\n";

// New category type mapping with descriptive codes
// Format: old_category_id => [new_type, new_code_prefix, new_category_name (optional rename)]
$category_updates = [
    // TOOL - Hand tools, power tools, general tools
    38 => ['Tools', 'TOOL', 'Hand Tools'],
    21 => ['Tools', 'TOOL', 'Power Tools'],
    43 => ['Tools', 'TOOL', 'General Tools'],
    10 => ['Tools', 'TOOL', 'Machine Tools'],
    48 => ['Tools', 'TOOL', 'Lifting Tools'],
    52 => ['Tools', 'TOOL', 'Production Tools'],
    62 => ['Tools', 'TOOL', 'Welding Tools'],
    71 => ['Tools', 'TOOL', 'Measuring Tools'],
    
    // EQUIP - Test, engineering, electrical equipment
    3 => ['Equipment', 'EQUIP', 'Test Equipment'],
    70 => ['Equipment', 'EQUIP', 'Engineering Equipment'],
    73 => ['Equipment', 'EQUIP', 'Electrical Equipment'],
    75 => ['Equipment', 'EQUIP', 'Weather Station Equipment'],
    
    // SAFETY - Safety equipment and PPE
    54 => ['Safety', 'SAFETY', 'Safety Equipment'],
    
    // PLANT - Plant, machinery, vehicles, heavy equipment
    55 => ['Plant', 'PLANT', 'Plant and Machinery'],
    9 => ['Plant', 'PLANT', 'Vehicle Equipment'],
    58 => ['Plant', 'PLANT', 'Machine Equipment'],
    77 => ['Plant', 'PLANT', 'Construction Equipment'],
    56 => ['Plant', 'PLANT', 'Production Equipment'],
    16 => ['Plant', 'PLANT', 'Powerhouse Equipment'],
    76 => ['Plant', 'PLANT', 'M&E Equipment'],
    51 => ['Plant', 'PLANT', 'O&M Equipment'],
    34 => ['Plant', 'PLANT', 'Cleaning Equipment'],
    
    // FURN - Furniture
    41 => ['Furniture', 'FURN', 'Furniture'],
    
    // APPLNC - Appliances
    61 => ['Appliances', 'APPLNC', 'Electrical Appliances'],
    23 => ['Appliances', 'APPLNC', 'Kitchen Equipment'],
    66 => ['Appliances', 'APPLNC', 'Heating Equipment'],
    
    // STRUCT - Structures, facilities, storage
    12 => ['Structures', 'STRUCT', 'Housing'],
    33 => ['Structures', 'STRUCT', 'Storage Equipment'],
    24 => ['Structures', 'STRUCT', 'Facilities Equipment'],
    
    // COMM - Communications and IT
    4 => ['Communications', 'COMM', 'Communications Equipment'],
    27 => ['Communications', 'COMM', 'IT Equipment'],
    50 => ['Communications', 'COMM', 'Office Equipment'],
    
    // OTHER
    25 => ['Other', 'OTHER', 'Other'],
];

// Get current categories
$stmt = $pdo->query("SELECT category_id, category_code, category_name, category_type FROM categories ORDER BY category_id");
$current = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Current categories:\n";
foreach ($current as $cat) {
    echo "  [{$cat['category_id']}] {$cat['category_code']} - {$cat['category_name']} ({$cat['category_type']})\n";
}
echo "\n";

echo "Proposed updates:\n";
foreach ($category_updates as $id => $config) {
    list($new_type, $prefix, $name) = $config;
    $new_code = $prefix . '-' . str_pad($id, 3, '0', STR_PAD_LEFT);
    echo "  [$id] $name => $new_code ($new_type)\n";
}
echo "\n";

echo "Proceed with updates? (yes/no): ";
$confirm = trim(fgets(STDIN));

if (strtolower($confirm) !== 'yes') {
    echo "Aborted.\n";
    exit(0);
}

// First, update the category_type enum to include new types
echo "\nUpdating category_type enum...\n";
try {
    $pdo->exec("ALTER TABLE categories MODIFY COLUMN category_type ENUM('RET','FAC','O&M','General','Meters','ReadyBoards','Tools','Equipment','Plant','Furniture','Appliances','Structures','Communications','Safety','Other') NOT NULL");
    echo "✅ Enum updated\n";
} catch (PDOException $e) {
    echo "⚠️ Enum update failed (may already be correct): " . $e->getMessage() . "\n";
}

// Apply updates
$updated = 0;
$errors = [];

foreach ($category_updates as $id => $config) {
    list($new_type, $prefix, $name) = $config;
    $new_code = $prefix . '-' . str_pad($id, 3, '0', STR_PAD_LEFT);
    
    try {
        $stmt = $pdo->prepare("
            UPDATE categories 
            SET category_type = ?, category_code = ?, category_name = ?
            WHERE category_id = ?
        ");
        $stmt->execute([$new_type, $new_code, $name, $id]);
        
        if ($stmt->rowCount() > 0) {
            echo "  Updated: [$id] $name => $new_code ($new_type)\n";
            $updated++;
        } else {
            echo "  Skipped: [$id] not found\n";
        }
    } catch (PDOException $e) {
        $errors[] = "[$id] " . $e->getMessage();
    }
}

echo "\n=== Results ===\n";
echo "Updated: $updated categories\n";
echo "Errors: " . count($errors) . "\n";

if (!empty($errors)) {
    echo "\nErrors:\n";
    foreach ($errors as $err) {
        echo "  - $err\n";
    }
}

// Show final state
echo "\nFinal categories by type:\n";
$stmt = $pdo->query("SELECT category_type, COUNT(*) as count FROM categories GROUP BY category_type ORDER BY category_type");
while ($row = $stmt->fetch()) {
    echo "  {$row['category_type']}: {$row['count']}\n";
}

echo "\nAll categories:\n";
$stmt = $pdo->query("SELECT category_id, category_code, category_name, category_type FROM categories ORDER BY category_type, category_name");
while ($row = $stmt->fetch()) {
    echo "  [{$row['category_id']}] {$row['category_code']} - {$row['category_name']} ({$row['category_type']})\n";
}

echo "\nDone!\n";
