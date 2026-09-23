#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/config/inventory_levels.php';
require_once dirname(__DIR__) . '/web/config/inventory_read.php';

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

$locs = [
    'LSO-HQ' => ['id' => '1pwr_lesotho_hq', 'location_code' => 'LSO-HQ', 'location_name' => 'HQ'],
    '1pwr_lesotho_hq' => ['id' => '1pwr_lesotho_hq', 'location_code' => 'LSO-HQ', 'location_name' => 'HQ'],
    'LSO-MAK' => ['id' => 'mak', 'location_code' => 'LSO-MAK', 'location_name' => 'Makebe'],
    'mak' => ['id' => 'mak', 'location_code' => 'LSO-MAK', 'location_name' => 'Makebe'],
];

// Reject unresolvable ids (never echo raw as key)
expect_same('', am_canonical_location_code('site1', $locs), 'site1 does not resolve');
expect_same('', am_canonical_location_code('site1', []), 'empty index does not echo raw');
expect_same('LSO-HQ', am_canonical_location_code('1pwr_lesotho_hq', $locs), 'PR site id maps to LSO-HQ');
expect_same('LSO-MAK', am_canonical_location_code('LSO-MAK', $locs), 'canonical code passes through');

$hqSites = [
    ['location_code' => 'LSO-MAK', 'country_code' => 'LSO', 'location_name' => 'Makebe'],
    ['location_code' => 'LSO-HQ', 'country_code' => 'LSO', 'location_name' => 'Headquarters'],
    ['location_code' => 'ZMB-HQ', 'country_code' => 'ZMB', 'location_name' => 'Zambia HQ'],
];
expect_same('LSO-HQ', am_hq_location_code('LSO', $hqSites), 'LSO headquarters is LSO-HQ');
expect_same('ZMB-HQ', am_hq_location_code('ZMB', $hqSites), 'country filter picks that country HQ');
expect_same(
    'LSO-MAS',
    am_hq_location_code('LSO', [
        ['location_code' => 'LSO-MAS', 'country_code' => 'LSO', 'location_name' => 'Maseru Headquarters'],
    ]),
    'name containing headquarters is used when no -HQ code exists'
);
expect_same(
    ['on_hand' => 3034, 'allocated' => 50, 'available' => 2984],
    am_stockable_on_hand_totals([
        ['quantity_on_hand' => 2734, 'quantity_allocated' => 50],
        ['quantity_on_hand' => 200, 'quantity_allocated' => 0],
        ['quantity_on_hand' => 100, 'quantity_allocated' => 0],
    ]),
    'item detail totals sum every site row, not the catalog location slice'
);

// Site change moves quantity (source zeroed, dest receives)
expect_same(
    ['source_on_hand' => 0, 'source_allocated' => 0, 'destination_on_hand' => 92260],
    am_inventory_site_change_balances(92260, 0, 0, 92260),
    'site change moves full qty; source ends at 0'
);
expect_same(
    ['source_on_hand' => 0, 'source_allocated' => 0, 'destination_on_hand' => 100],
    am_inventory_site_change_balances(80, 5, 20, 80),
    'destination receives moved qty on top of existing'
);

// Duplicate (asset, location) detection
$dupLevels = [
    ['id' => 'a', 'asset_id' => 'x', 'location_id' => 'LSO-HQ', 'quantity_on_hand' => 10],
    ['id' => 'b', 'asset_id' => 'x', 'location_id' => '1pwr_lesotho_hq', 'quantity_on_hand' => 10],
];
expect_same(['LSO-HQ'], am_inventory_duplicate_location_codes($dupLevels, $locs), 'alias pair is one duplicate location');

$merged = am_inventory_merge_duplicate_rows($dupLevels, $locs, [
    'location_id' => 'LSO-HQ',
    'quantity' => 10,
]);
expect_same(1, count($merged), 'merge collapses alias pair to one row');
expect_same('LSO-HQ', $merged[0]['location_id'], 'merged row uses canonical location');

// Build site-change ops: zero source, upsert dest, create Transfer
$levels = [
    ['id' => 'src1', 'asset_id' => 'assetA', 'location_id' => 'LSO-MAK', 'country_id' => 'c1', 'quantity_on_hand' => 100, 'quantity_allocated' => 0],
];
$built = am_inventory_build_site_change_operations(
    'assetA',
    ['quantity' => 100, 'location_id' => 'LSO-MAK', 'name' => 'ABC'],
    ['quantity' => 100, 'location_id' => 'LSO-HQ', 'name' => 'ABC'],
    'LSO-MAK',
    'LSO-HQ',
    'c1',
    $levels,
    $locs
);
expect_true($built['ok'], 'site change ops build ok');
$modes = array_map(static fn($op) => $op['mode'] . ':' . $op['collection'], $built['operations']);
expect_true(in_array('update:am_core_inventory_levels', $modes, true), 'updates source or dest levels');
expect_true(in_array('create:am_core_inventory_levels', $modes, true) || in_array('update:am_core_inventory_levels', $modes, true), 'dest upsert present');
expect_true(in_array('create:am_core_transactions', $modes, true), 'Transfer transaction in same commit');

$sourceOp = null;
foreach ($built['operations'] as $op) {
    if (($op['id'] ?? '') === 'src1') {
        $sourceOp = $op;
        break;
    }
}
expect_true($sourceOp !== null, 'source row operation exists');
expect_same(0, (int)($sourceOp['data']['quantity_on_hand'] ?? -1), 'source on-hand zeroed (move not copy)');

// Reject empty source/dest in builder
$bad = am_inventory_build_site_change_operations('a', [], [], '', 'LSO-HQ', 'c1', [], $locs);
expect_true(!$bad['ok'], 'unresolvable source rejected by builder');

// reconciliation_status on sum mismatch / orphan / ok
$asset = ['id' => 'a1', 'quantity' => 100, 'location_id' => 'LSO-HQ', 'item_class' => 'Material', 'name' => 'Cond'];
expect_same(
    'sum_mismatch',
    am_inventory_reconciliation_status_for_asset($asset, [
        ['location_id' => 'LSO-HQ', 'quantity_on_hand' => 100],
        ['location_id' => 'LSO-MAK', 'quantity_on_hand' => 100],
    ], $locs),
    'full qty at two sites is sum_mismatch (or unverified)'
);
// Actually: orphan full-qty at other site returns unverified before sum check... 
// Looking at my function: sum_mismatch is checked before unverified orphan. sum=200 != 100 → sum_mismatch. Good.

expect_same(
    'unresolvable_location',
    am_inventory_reconciliation_status_for_asset($asset, [
        ['location_id' => 'site1', 'quantity_on_hand' => 100],
    ], $locs),
    'site1 row is unresolvable_location'
);

expect_same(
    'ok',
    am_inventory_reconciliation_status_for_asset($asset, [
        ['location_id' => 'LSO-HQ', 'quantity_on_hand' => 100],
    ], $locs),
    'single matching row is ok'
);

expect_same(
    'duplicate_location',
    am_inventory_reconciliation_status_for_asset(
        ['quantity' => 10, 'location_id' => 'LSO-HQ'],
        [
            ['location_id' => 'LSO-HQ', 'quantity_on_hand' => 5],
            ['location_id' => '1pwr_lesotho_hq', 'quantity_on_hand' => 5],
        ],
        $locs
    ),
    'two alias rows before merge = duplicate_location'
);

// Position builder exposes reconciliation_status
$positions = am_inventory_read_build_positions(
    [
        ['asset_id' => 'a1', 'location_id' => 'LSO-HQ', 'quantity_on_hand' => 100, 'quantity_allocated' => 0, 'updated_at' => '2026-09-10T00:00:00Z'],
        ['asset_id' => 'a1', 'location_id' => 'LSO-MAK', 'quantity_on_hand' => 100, 'quantity_allocated' => 0, 'updated_at' => '2026-09-10T00:00:00Z'],
    ],
    ['a1' => ['id' => 'a1', 'name' => 'ABC', 'quantity' => 100, 'location_id' => 'LSO-HQ', 'item_class' => 'Material', 'unit_of_measure' => 'M']],
    $locs,
    []
);
expect_true(count($positions) >= 1, 'positions returned even when mismatched');
foreach ($positions as $p) {
    expect_true(
        in_array($p['reconciliation_status'], ['sum_mismatch', 'unverified', 'duplicate_location'], true),
        'mismatched asset positions are not ok: ' . $p['reconciliation_status']
    );
}

$okPos = am_inventory_read_build_positions(
    [
        ['asset_id' => 'a1', 'location_id' => 'LSO-HQ', 'quantity_on_hand' => 100, 'quantity_allocated' => 0, 'updated_at' => '2026-09-10T00:00:00Z'],
    ],
    ['a1' => ['id' => 'a1', 'name' => 'ABC', 'quantity' => 100, 'location_id' => 'LSO-HQ', 'item_class' => 'Material', 'unit_of_measure' => 'M']],
    $locs,
    []
);
expect_same(1, count($okPos), 'one ok position');
expect_same('ok', $okPos[0]['reconciliation_status'], 'ok status when sum matches');

echo "inventory_levels_site_change_test: OK\n";
