<?php
declare(strict_types=1);

require_once __DIR__ . '/../web/config/transactions.php';

function expect_true(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

$transfer = am_describe_asset_change(
    ['name' => 'Breaker', 'asset_tag' => 'TAG-1', 'location_id' => 'HQ', 'status' => 'Available', 'quantity' => 10],
    ['name' => 'Breaker', 'asset_tag' => 'TAG-1', 'location_id' => 'SEH', 'status' => 'Allocated', 'quantity' => 8]
);
expect_true($transfer !== null && $transfer['type'] === 'Transfer', 'site movement is classified as a transfer');
expect_true($transfer['quantity'] === 8, 'transaction records the resulting affected quantity');
expect_true(($transfer['context']['from_location_id'] ?? '') === 'HQ' && ($transfer['context']['to_location_id'] ?? '') === 'SEH', 'transaction preserves both sites');
expect_true(str_contains($transfer['notes'], 'Status changed') && str_contains($transfer['notes'], 'Quantity changed'), 'combined edits retain all audit details');

$quantity = am_describe_asset_change(
    ['location_id' => 'HQ', 'status' => 'Available', 'quantity' => 10],
    ['location_id' => 'HQ', 'status' => 'Available', 'quantity' => 12]
);
expect_true($quantity !== null && $quantity['type'] === 'StockAdjustment', 'quantity changes are classified as stock adjustments');
expect_true($quantity['quantity'] === 2, 'stock adjustments record the changed quantity rather than the resulting balance');

$deployment = am_describe_asset_change(
    ['location_id' => 'SEH', 'status' => 'Available', 'quantity' => 1],
    ['location_id' => 'SEH', 'status' => 'Deployed', 'quantity' => 1]
);
expect_true($deployment !== null && $deployment['type'] === 'Deploy', 'deployed status is classified correctly');

$unchanged = am_describe_asset_change(['location_id' => 'HQ', 'status' => 'Available', 'quantity' => 10], ['location_id' => 'HQ', 'status' => 'Available', 'quantity' => 10]);
expect_true($unchanged === null, 'non-ledger edits do not create misleading stock movements');
expect_true(am_transaction_site_id(['from_location_id' => 'HQ', 'to_location_id' => 'SEH']) === 'SEH', 'destination site is preferred for display');
expect_true(am_transaction_site_id(['from_location_id' => 'HQ']) === 'HQ', 'source site is used when there is no destination');

fwrite(STDOUT, "transactions_test: OK\n");
