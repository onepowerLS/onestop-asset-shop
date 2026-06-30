<?php
/**
 * One-off CLI migration: seed additional Consumable categories in pr_master_categories.
 *
 * Run from project root:
 *   php scripts/seed_consumable_categories.php
 *   php scripts/seed_consumable_categories.php --dry-run
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/web/config/app.php';
require_once $root . '/web/config/firestore.php';

$dryRun = in_array('--dry-run', $argv, true);

$newCategories = [
    [
        'category_code' => 'CON-MNT',
        'category_name' => 'Maintenance Supplies',
        'item_class' => 'Consumable',
        'department_scope' => 'O&M',
        'description' => 'Lubricants, fasteners, tape, cable ties, sealant, adhesives',
    ],
    [
        'category_code' => 'CON-LUB',
        'category_name' => 'Lubricants & Fluids',
        'item_class' => 'Consumable',
        'department_scope' => 'O&M',
        'description' => 'Engine oil, grease, hydraulic fluid, coolant, brake fluid',
    ],
    [
        'category_code' => 'CON-FUE',
        'category_name' => 'Fuel',
        'item_class' => 'Consumable',
        'department_scope' => 'General',
        'description' => 'Petrol, diesel, gas for generators and vehicles',
    ],
    [
        'category_code' => 'CON-PRN',
        'category_name' => 'Print & Stationery',
        'item_class' => 'Consumable',
        'department_scope' => 'General',
        'description' => 'Paper, toner, ink, pens, notebooks, printer consumables',
    ],
    [
        'category_code' => 'CON-ITC',
        'category_name' => 'IT Consumables',
        'item_class' => 'Consumable',
        'department_scope' => 'General',
        'description' => 'Toner, ink, cables, adapters, storage media, batteries',
    ],
    [
        'category_code' => 'CON-MSC',
        'category_name' => 'Miscellaneous Consumables',
        'item_class' => 'Consumable',
        'department_scope' => 'General',
        'description' => 'General consumables not covered by other categories',
    ],
    [
        'category_code' => 'CON-SAF',
        'category_name' => 'Safety & First Aid',
        'item_class' => 'Consumable',
        'department_scope' => 'General',
        'description' => 'First aid kits, fire extinguishers refills, signage, spotters',
    ],
    [
        'category_code' => 'CON-FOD',
        'category_name' => 'Food & Catering',
        'item_class' => 'Consumable',
        'department_scope' => 'General',
        'description' => 'Site rations, water, catering supplies for field work',
    ],
];

$existing = am_firestore_get_collection('pr_master_categories', 1000);
$existingByCode = [];
foreach ($existing as $cat) {
    $code = strtoupper(trim((string)($cat['category_code'] ?? '')));
    if ($code !== '') {
        $existingByCode[$code] = $cat;
    }
}

echo "Existing categories: " . count($existingByCode) . PHP_EOL;
echo "Dry run: " . ($dryRun ? 'YES' : 'NO') . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

$created = 0;
$skipped = 0;
foreach ($newCategories as $new) {
    $code = strtoupper($new['category_code']);
    if (isset($existingByCode[$code])) {
        echo "SKIP  {$code} — already exists ({$existingByCode[$code]['category_name']})" . PHP_EOL;
        $skipped++;
        continue;
    }

    $data = [
        'category_code' => $new['category_code'],
        'category_name' => $new['category_name'],
        'item_class' => $new['item_class'],
        'department_scope' => $new['department_scope'],
        'description' => $new['description'],
        'useful_life_years' => null,
        'depreciation_method' => 'None',
        'reorder_enabled' => 1,
        'active' => 1,
        'created_at' => date('c'),
    ];

    if ($dryRun) {
        echo "DRY  {$code} — would create: {$new['category_name']}" . PHP_EOL;
        continue;
    }

    $result = am_firestore_create_document('pr_master_categories', $data);
    if ($result['ok']) {
        echo "OK   {$code} — created: {$new['category_name']}" . PHP_EOL;
        $created++;
    } else {
        echo "FAIL {$code} — " . ($result['error'] ?? 'Unknown error') . PHP_EOL;
    }
}

echo str_repeat('-', 60) . PHP_EOL;
echo "Created: {$created}, Skipped: {$skipped}" . PHP_EOL;
