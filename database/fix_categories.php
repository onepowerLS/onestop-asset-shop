<?php
/**
 * Fix Categories Script
 * 
 * Disaggregates categories from "General" to their proper types (RET, FAC, O&M, etc.)
 * Run this script on the server: php fix_categories.php
 */

require_once __DIR__ . '/../web/config/database.php';

echo "=== Category Fix Script ===\n\n";

// Show current state
echo "BEFORE - Categories by type:\n";
$stmt = $pdo->query("SELECT category_type, COUNT(*) as count FROM categories GROUP BY category_type ORDER BY category_type");
while ($row = $stmt->fetch()) {
    echo "  {$row['category_type']}: {$row['count']}\n";
}
echo "\n";

// Category mapping rules: pattern => type
$category_mappings = [
    // RET (Renewable Energy Technology)
    'RET' => [
        'solar', 'panel', 'inverter', 'battery', 'batteries', 'charge controller',
        'mppt', 'pv ', 'photovoltaic', 'module', 'dc cable', 'solar cable', 'mc4',
        'connector', 'combiner', 'junction box', 'mounting', 'racking', 'tracker',
        'string', 'array', 'bms', 'lithium', 'gel battery', 'agm'
    ],
    
    // FAC (Facilities)
    'FAC' => [
        'furniture', 'desk', 'chair', 'table', 'cabinet', 'shelf', 'shelving',
        'office', 'building', 'fixture', 'lighting', 'light', 'lamp', 'bulb',
        'air condition', 'hvac', 'fan', 'door', 'window', 'lock', 'security',
        'cctv', 'camera', 'alarm', 'fence', 'gate', 'container', 'shed'
    ],
    
    // O&M (Operations & Maintenance)
    'O&M' => [
        'spare', 'replacement', 'consumable', 'maintenance', 'repair', 'fuse',
        'breaker', 'circuit', 'switch', 'relay', 'contactor', 'terminal', 'lug',
        'bolt', 'nut', 'screw', 'washer', 'gasket', 'seal', 'lubricant', 'grease',
        'oil', 'filter', 'cleaning', 'wire', 'cable', 'conduit', 'gland', 'tape'
    ],
    
    // Meters
    'Meters' => [
        'meter', 'prepaid', 'smart meter', 'energy meter', 'kwh', 'metering',
        'current transformer', 'ct ratio', 'vending', 'token'
    ],
    
    // ReadyBoards
    'ReadyBoards' => [
        'ready board', 'readyboard', 'distribution board', 'db box', 'panel board',
        'electrical panel', 'load center', 'consumer unit', 'mcb', 'rcd', 'elcb'
    ],
    
    // Tools
    'Tools' => [
        'tool', 'drill', 'saw', 'hammer', 'screwdriver', 'wrench', 'plier',
        'cutter', 'crimper', 'stripper', 'multimeter', 'tester', 'clamp meter',
        'oscilloscope', 'analyzer', 'measuring', 'ladder', 'scaffold', 'harness',
        'safety', 'ppe', 'helmet', 'glove', 'boot', 'torque', 'spanner'
    ]
];

// Get all General categories
$stmt = $pdo->query("SELECT category_id, category_code, category_name FROM categories WHERE category_type = 'General'");
$general_categories = $stmt->fetchAll();

echo "Found " . count($general_categories) . " categories with type 'General'\n\n";

$updates = [];
$unchanged = [];

foreach ($general_categories as $cat) {
    $name_lower = strtolower($cat['category_name']);
    $new_type = null;
    
    // Check each type's patterns
    foreach ($category_mappings as $type => $patterns) {
        foreach ($patterns as $pattern) {
            if (strpos($name_lower, $pattern) !== false) {
                $new_type = $type;
                break 2;
            }
        }
    }
    
    if ($new_type) {
        $updates[] = [
            'id' => $cat['category_id'],
            'name' => $cat['category_name'],
            'old_code' => $cat['category_code'],
            'new_type' => $new_type
        ];
    } else {
        $unchanged[] = $cat;
    }
}

echo "Categories to update: " . count($updates) . "\n";
echo "Categories unchanged (remain General): " . count($unchanged) . "\n\n";

// Show what will be updated
if (!empty($updates)) {
    echo "Updates to apply:\n";
    foreach ($updates as $u) {
        echo "  [{$u['id']}] {$u['name']} => {$u['new_type']}\n";
    }
    echo "\n";
}

// Show unchanged
if (!empty($unchanged)) {
    echo "Remaining as General (review manually if needed):\n";
    foreach ($unchanged as $cat) {
        echo "  [{$cat['category_id']}] {$cat['category_name']}\n";
    }
    echo "\n";
}

// Prompt for confirmation
echo "Proceed with updates? (yes/no): ";
$confirm = trim(fgets(STDIN));

if (strtolower($confirm) !== 'yes') {
    echo "Aborted.\n";
    exit(0);
}

// Apply updates
$code_prefixes = [
    'RET' => 'RET',
    'FAC' => 'FAC',
    'O&M' => 'O&M',
    'Meters' => 'MET',
    'ReadyBoards' => 'RB',
    'Tools' => 'TOOL'
];

$updated = 0;
$errors = [];

foreach ($updates as $u) {
    $prefix = $code_prefixes[$u['new_type']] ?? $u['new_type'];
    $new_code = $prefix . '-' . str_pad($u['id'], 3, '0', STR_PAD_LEFT);
    
    try {
        $stmt = $pdo->prepare("
            UPDATE categories 
            SET category_type = ?, category_code = ?
            WHERE category_id = ?
        ");
        $stmt->execute([$u['new_type'], $new_code, $u['id']]);
        $updated++;
        echo "  Updated: {$u['name']} => {$u['new_type']} ({$new_code})\n";
    } catch (PDOException $e) {
        $errors[] = "Failed to update {$u['name']}: " . $e->getMessage();
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
echo "\nAFTER - Categories by type:\n";
$stmt = $pdo->query("SELECT category_type, COUNT(*) as count FROM categories GROUP BY category_type ORDER BY category_type");
while ($row = $stmt->fetch()) {
    echo "  {$row['category_type']}: {$row['count']}\n";
}

echo "\nDone!\n";
