#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/config/part_definitions.php';

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

$ok = am_part_definition_normalize([
    'name' => 'ABC conductor',
    'unit_of_measure' => 'M',
    'classification' => 'needs_classification',
]);
expect_true($ok['ok'], 'draft definition normalizes');

$badAmOnly = am_part_definition_normalize([
    'name' => 'Widget',
    'classification' => 'am_only',
]);
expect_true(!$badAmOnly['ok'], 'am_only requires reason');

$forced = am_part_definition_normalize([
    'name' => 'Network part',
    'classification' => 'am_only',
    'am_only_reason' => 'office use',
    'requires_ugp' => true,
]);
expect_true(!$forced['ok'], 'cannot bypass required UGP with am_only');

$forecast = am_part_definition_normalize([
    'name' => 'ABC',
    'classification' => 'needs_classification',
    'unit_of_measure' => 'M',
    'forecast_ready' => true,
]);
expect_true(!$forecast['ok'], 'forecast-ready requires classification');

$ugpReady = am_part_definition_normalize([
    'name' => 'ABC',
    'classification' => 'ugp_linked',
    'ugp_part_id' => 'abc-1ph',
    'unit_of_measure' => 'M',
    'forecast_ready' => true,
]);
expect_true($ugpReady['ok'], 'ugp_linked forecast-ready ok');

$defs = [
    ['id' => '1', 'name' => 'Risen Solar Panels 645W', 'manufacturer' => 'Risen', 'active' => true],
    ['id' => '2', 'name' => 'Office stapler', 'active' => true],
];
$hits = am_part_definition_search($defs, 'risen', 10);
expect_same(1, count($hits), 'search finds Risen panel');

$status = am_catalogue_status_for_asset(
    ['id' => 'a1', 'item_class' => 'Material'],
    null,
    []
);
expect_same('incomplete_catalogue', $status['catalogue_status'], 'no definition = incomplete_catalogue');

$status2 = am_catalogue_status_for_asset(
    ['id' => 'a1', 'definition_id' => 'd1', 'ugp_part_id' => ''],
    ['id' => 'd1', 'classification' => 'needs_classification', 'forecast_ready' => false],
    []
);
expect_same('needs_classification', $status2['catalogue_status'], 'needs classification surfaced');

$status3 = am_catalogue_status_for_asset(
    ['id' => 'a1', 'definition_id' => 'd1'],
    ['id' => 'd1', 'classification' => 'ugp_linked', 'ugp_part_id' => 'abc', 'forecast_ready' => true],
    [['reason' => 'Unit conflict M vs EA']]
);
expect_same('task_open', $status3['catalogue_status'], 'open task wins');
expect_same('Unit conflict M vs EA', $status3['catalogue_reason'], 'actionable reason returned');

echo "part_definitions_test: OK\n";
