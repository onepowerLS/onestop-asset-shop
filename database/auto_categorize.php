<?php
/**
 * Auto-categorize uncategorized assets based on name patterns
 */

require_once __DIR__ . '/../web/config/database.php';

echo "=== Auto-Categorize Assets ===\n\n";

// Get existing categories
$categories = $pdo->query("SELECT category_id, category_name, category_type FROM categories")->fetchAll(PDO::FETCH_ASSOC);
$catMap = [];
foreach ($categories as $cat) {
    $catMap[$cat['category_name']] = $cat['category_id'];
}

// Define patterns -> category mappings
$patterns = [
    // Tools - Power Tools
    '/\b(drill|grinder|saw|sander|jigsaw|router|planer|polisher|angle.?grinder|cut.?off|reciprocating|circular|miter|chop|jackhammer|rivet.?gun)\b/i' => 'Power Tools',
    
    // Tools - Hand Tools
    '/\b(hammer|hummer|screwdriver|plier|wrench|spanner|socket|chisel|punch|file|clamp|vise|vice|ratchet|allen|hex.?key|torque|crowbar|pry.?bar|mallet|axe|hacksaw|hand.?saw|bolt.?cutter|cutter|nose.?lock|long.?nose|lug|chuck.?key)\b/i' => 'Hand Tools',
    
    // Tools - Measuring Tools
    '/\b(tape.?measure|measuring|caliper|vernier|micrometer|level|ruler|square|protractor|gauge|multimeter|clamp.?meter|thermometer|hygrometer|indicator|thermal.?imager)\b/i' => 'Measuring Tools',
    
    // Tools - Welding
    '/\b(weld|soldering|brazing|electrode|flux|welding)\b/i' => 'Welding Tools',
    
    // Tools - Lifting
    '/\b(jack|pulley|hoist|crane|winch|chain.?block|come.?along|lever|lifting|ton\b)\b/i' => 'Lifting Tools',
    
    // Equipment - Electrical
    '/\b(transformer|inverter|converter|charger|battery|power.?supply|ups|voltage|capacitor|relay|contactor|breaker|fuse|switch|plug|cable|wire|conductor|busbar|housewire|airdac|adaptor|adapter|ldnio)\b/i' => 'Electrical Equipment',
    
    // Equipment - Test
    '/\b(oscilloscope|multimeter|tester|analyzer|meter|probe|signal|scope|dmm|bms)\b/i' => 'Test Equipment',
    
    // Communications/IT
    '/\b(computer|laptop|tablet|monitor|keyboard|mouse|printer|scanner|router|modem|server|camera|cctv|surveillance|radio|walkie|antenna|phone|telephone|notebook|vivobook|asus|samsung|hisense|mobicel|azumi|kicka|nexus|wifi|hotspot|speaker|jabra|bluetooth)\b/i' => 'IT Equipment',
    
    // Plant - Compressors/Generators
    '/\b(compressor|generator|genset|pump|motor|engine|blower|fan|vacuum|tractor|augar|rig)\b/i' => 'Plant and Machinery',
    
    // Plant - Construction
    '/\b(concrete|mixer|vibrator|scaffolding|ladder|wheelbarrow|shovel|pickaxe|rake|hoe|spade|trowel|float|screed|base.?plate)\b/i' => 'Construction Equipment',
    
    // Safety
    '/\b(helmet|harness|safety|glove|goggle|mask|respirator|vest|boot|ear.?plug|ear.?muff|fire.?extinguisher|first.?aid|ppe|hard.?hat|hi.?vis|dry.?powder|co2|stp)\b/i' => 'Safety Equipment',
    
    // Furniture
    '/\b(desk|chair|table|cabinet|shelf|shelv|drawer|cupboard|locker|bench|stool|couch|sofa|bookcase|filing|padded)\b/i' => 'Furniture',
    
    // Kitchen/Appliances
    '/\b(fridge|refrigerator|freezer|microwave|kettle|toaster|coffee|stove|oven|cooker|dishwasher|blender)\b/i' => 'Kitchen Equipment',
    
    // Cleaning
    '/\b(vacuum.?cleaner|mop|broom|bucket|cleaning|washer|pressure.?wash|steam.?clean|smoke.?absor)\b/i' => 'Cleaning Equipment',
    
    // Storage
    '/\b(container|bin|box|crate|pallet|rack|tank|jojo|drum|barrel|storage)\b/i' => 'Storage Equipment',
    
    // HVAC
    '/\b(hvac|air.?condition|ac.?unit|heater|heating|cooling|ventilat|aircon)\b/i' => 'Heating Equipment',
    
    // Electrical Appliances
    '/\b(light|lamp|bulb|flood.?light|spotlight|led|fluorescent|extension.?cord|power.?strip|surge|television|tv|solar.?panel|solar.?charger)\b/i' => 'Electrical Appliances',
    
    // Office Equipment
    '/\b(stapler|punch|paper|binder|laminator|shredder|whiteboard|projector|calculator|copier|binding.?machine)\b/i' => 'Office Equipment',
    
    // Vehicles
    '/\b(atv|vehicle|car|truck|bakkie|trailer)\b/i' => 'Vehicles',
    
    // General Tools (catch-all for tool-like items)
    '/\b(tool|kit|set|bit|blade|accessory|attachment|strap)\b/i' => 'General Tools',
];

// Get uncategorized assets
$stmt = $pdo->query("SELECT asset_id, name, description FROM assets WHERE category_id IS NULL");
$assets = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($assets) . " uncategorized assets\n\n";

$updates = [];
$unmatched = [];

foreach ($assets as $asset) {
    $searchText = $asset['name'] . ' ' . ($asset['description'] ?? '');
    $matched = false;
    
    foreach ($patterns as $pattern => $categoryName) {
        if (preg_match($pattern, $searchText)) {
            if (isset($catMap[$categoryName])) {
                $updates[] = [
                    'asset_id' => $asset['asset_id'],
                    'name' => $asset['name'],
                    'category_id' => $catMap[$categoryName],
                    'category_name' => $categoryName
                ];
                $matched = true;
                break;
            }
        }
    }
    
    if (!$matched) {
        $unmatched[] = $asset['name'];
    }
}

echo "Matched: " . count($updates) . " assets\n";
echo "Unmatched: " . count($unmatched) . " assets\n\n";

// Group by category for summary
$byCat = [];
foreach ($updates as $u) {
    $byCat[$u['category_name']] = ($byCat[$u['category_name']] ?? 0) + 1;
}
arsort($byCat);

echo "=== Category Distribution ===\n";
foreach ($byCat as $cat => $count) {
    echo sprintf("  %-25s %d\n", $cat, $count);
}

// Ask for confirmation
echo "\n";
if (php_sapi_name() === 'cli') {
    echo "Apply these changes? (yes/no): ";
    $confirm = trim(fgets(STDIN));
} else {
    $confirm = 'yes';
}

if (strtolower($confirm) === 'yes') {
    $stmt = $pdo->prepare("UPDATE assets SET category_id = ? WHERE asset_id = ?");
    $count = 0;
    foreach ($updates as $u) {
        $stmt->execute([$u['category_id'], $u['asset_id']]);
        $count++;
    }
    echo "\nUpdated $count assets.\n";
} else {
    echo "\nNo changes made.\n";
}

// Show sample of unmatched
if (count($unmatched) > 0) {
    echo "\n=== Sample Unmatched (first 30) ===\n";
    foreach (array_slice($unmatched, 0, 30) as $name) {
        echo "  - $name\n";
    }
}

echo "\n=== Done ===\n";
