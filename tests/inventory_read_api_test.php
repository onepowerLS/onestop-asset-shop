#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/config/inventory_read.php';
require_once dirname(__DIR__) . '/web/config/integration_api.php';

function expect_same(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

function expect_true(bool $cond, string $message): void {
    if (!$cond) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

// qty_available is computed server-side
$levels = [[
    'asset_id' => 'a1',
    'location_id' => 'LSO-MAS',
    'country_id' => '1',
    'quantity_on_hand' => 412,
    'quantity_allocated' => 260,
    'updated_at' => '2026-09-05T14:22:00Z',
]];
$assets = ['a1' => [
    'id' => 'a1',
    'asset_id' => 'a1',
    'name' => 'Wooden pole 11m',
    'ugp_part_id' => 'pole-wooden-11m',
    'category_id' => 'Poles',
    'unit_of_measure' => 'EA',
    'item_class' => 'Inventory',
    'location_id' => 'LSO-MAS',
]];
$locs = ['LSO-MAS' => ['id' => 'lso_mas', 'location_code' => 'LSO-MAS', 'location_name' => 'Matsoaing']];
$cats = ['Poles' => ['category_name' => 'Poles']];

$rows = am_inventory_read_build_positions($levels, $assets, $locs, $cats, 'MAS');
expect_same(1, count($rows), 'MAS filter finds LSO-MAS row');
expect_same(152, $rows[0]['qty_available'], 'qty_available = on_hand - allocated');
expect_same(412, $rows[0]['qty_on_hand'], 'qty_on_hand passthrough');
expect_same(260, $rows[0]['qty_allocated'], 'qty_allocated passthrough');
expect_same('pole-wooden-11m', $rows[0]['part_id'], 'part_id prefers ugp_part_id');
expect_same('LSO-MAS', $rows[0]['site_id'], 'site_id is location_code');
expect_same('LSO-MAS', $rows[0]['store_id'], 'store_id equals site_id (no separate store)');

expect_true(am_inventory_read_site_matches('MAS', 'LSO-MAS', $locs['LSO-MAS']), 'MAS matches LSO-MAS');
expect_true(!am_inventory_read_site_matches('SEY', 'LSO-MAS', $locs['LSO-MAS']), 'SEY does not match LSO-MAS');

$parts = am_inventory_read_build_parts(array_values($assets), $cats, false);
expect_same(1, count($parts), 'stockable part listed');
$unmapped = am_inventory_read_build_parts([[
    'id' => 'a2',
    'name' => 'Mystery conductor',
    'item_class' => 'Material',
    'unit_of_measure' => 'M',
]], $cats, true);
expect_same(1, count($unmapped), 'unmapped=true returns parts without ugp_part_id');
expect_same(null, $unmapped[0]['ugp_part_id'], 'unmapped ugp_part_id is null');

expect_same('receipt', am_inventory_movement_type_from_transaction('StockIngestion'), 'ingestion is receipt');
expect_same('issue', am_inventory_movement_type_from_transaction('Consume'), 'consume is issue');
expect_same('transfer', am_inventory_movement_type_from_transaction('Transfer'), 'transfer stays transfer');
expect_same('return', am_inventory_movement_type_from_transaction('CheckIn'), 'check-in is return');
expect_same(-40, am_inventory_movement_signed_qty('issue', 40), 'issue is negative');
expect_same(12, am_inventory_movement_signed_qty('receipt', 12), 'receipt is positive');
expect_same(-3, am_inventory_movement_signed_qty('adjustment', 3, ['quantity_before' => 10, 'quantity_after' => 7]), 'adjustment uses before/after');

$tx = [
    'id' => 'tx1',
    'transaction_type' => 'Consume',
    'asset_id' => 'a1',
    'quantity' => 10,
    'from_location_id' => 'LSO-MAS',
    'to_location_id' => 'LSO-MAS',
    'transaction_date' => '2026-08-15T10:00:00Z',
    'source_request_number' => 'AMW-2026-00100',
    'performed_by' => 'uid1',
];
$mov = am_inventory_read_build_movements([], [$tx], $assets, $locs, 'MAS', '', '', '2026-08-01');
expect_same(1, count($mov), 'transaction projects into movements for MAS since Aug');
expect_same(-10, $mov[0]['qty'], 'projected consume is signed issue');
expect_same('AMW-2026-00100', $mov[0]['reference'], 'reference is request number');

$allocs = am_inventory_read_build_allocations(
    [['id' => 'al1', 'asset_id' => 'a1', 'status' => 'Active', 'allocation_date' => '2026-09-01T00:00:00Z', 'allocated_by' => 'u']],
    [],
    $assets,
    $locs,
    'MAS',
    ''
);
expect_same('reserved', $allocs[0]['status'], 'Active allocation maps to reserved');

$loadout = am_inventory_read_build_loadout([
    'id' => 'lo1',
    'destination_site_id' => 'LSO-MAS',
    'status' => 'Shipped',
    'updated_at' => '2026-09-03T12:00:00Z',
    'lines' => [['asset_id' => 'a1', 'quantity' => 4]],
], $assets);
expect_same('pole-wooden-11m', $loadout['lines'][0]['part_id'], 'loadout line uses ugp part id');
expect_same(4, $loadout['lines'][0]['qty'], 'loadout line qty');

$id = am_integration_api_identify_consumer('');
expect_same(false, $id['ok'], 'empty key rejected');

echo "inventory_read_api_test: OK\n";
