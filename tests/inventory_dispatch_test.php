#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/config/inventory_dispatch.php';

function expect_same(mixed $expected, mixed $actual, string $message): void {
    if ($expected !== $actual) {
        fwrite(STDERR, "FAIL: {$message}\nExpected: " . var_export($expected, true)
            . "\nActual: " . var_export($actual, true) . "\n");
        exit(1);
    }
}

expect_same(
    'dispatch_request-123_2_fulfillment',
    am_dispatch_event_id('request-123', 2, 'Fulfillment'),
    'event ids are deterministic'
);

expect_same('Consume', am_dispatch_fulfillment_transaction_type(['item_class' => 'Consumable'], true), 'same-site consumables are issued');
expect_same('Deploy', am_dispatch_fulfillment_transaction_type(['item_class' => 'FixedAsset'], true), 'same-site fixed assets are deployed');
expect_same('Transfer', am_dispatch_fulfillment_transaction_type(['item_class' => 'Consumable'], false), 'cross-site stock is transferred');

expect_same(
    ['source_on_hand' => 207, 'source_allocated' => 0, 'destination_on_hand' => null],
    am_dispatch_fulfillment_balances(382, 175, 175, 175, true),
    'same-site fulfillment reduces on-hand and releases allocation without adding stock back'
);

expect_same(
    ['source_on_hand' => 80, 'source_allocated' => 0, 'destination_on_hand' => 25],
    am_dispatch_fulfillment_balances(100, 20, 20, 20, false, 5),
    'cross-site fulfillment moves stock and releases allocation'
);

$locIndex = am_build_location_index([
    ['id' => '1pwr_lesotho_hq', 'location_code' => 'LSO-HQ', 'country_code' => 'LSO', 'location_name' => 'HQ'],
    ['id' => 'mak', 'location_code' => 'LSO-MAK', 'country_code' => 'LSO', 'location_name' => 'Makebe'],
    ['id' => 'seh', 'location_code' => 'LSO-SEH', 'country_code' => 'LSO', 'location_name' => 'Sehlabathebe'],
]);
expect_same(
    'LSO-HQ',
    am_dispatch_resolve_source_location_code([], ['location_id' => 'LSO-SEH'], $locIndex, 'LSO'),
    'legacy requests without source_site_code issue from HQ, not stale catalog location'
);
expect_same(
    'LSO-MAK',
    am_dispatch_resolve_source_location_code(['source_site_code' => 'LSO-MAK'], ['location_id' => 'LSO-SEH'], $locIndex, 'LSO'),
    'operator-chosen source wins over HQ and catalog location'
);
expect_same(
    'LSO-HQ',
    am_dispatch_resolve_source_location_code(['from_location_id' => '1pwr_lesotho_hq'], [], $locIndex, 'LSO'),
    'from_location_id alias resolves to canonical HQ'
);

$noHqIndex = am_build_location_index([
    ['id' => 'seh', 'location_code' => 'LSO-SEH', 'country_code' => 'LSO', 'location_name' => 'Sehlabathebe'],
]);
expect_same(
    'LSO-SEH',
    am_dispatch_resolve_source_location_code([], ['location_id' => 'LSO-SEH'], $noHqIndex, 'LSO'),
    'catalog location is last resort when no HQ exists'
);

echo "inventory_dispatch_test: OK\n";
