#!/usr/bin/env php
<?php
/**
 * Repair legacy same-site dispatches that were marked Fulfilled without
 * releasing quantity_allocated, reducing on-hand, or writing transactions.
 *
 * Dry-run is the default:
 *   php scripts/reconcile_dispatch_history.php --asset-tag=1PWR-CON-LSO-000276
 *   php scripts/reconcile_dispatch_history.php --asset-tag=1PWR-CON-LSO-000276 --apply
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/web/config/app.php';
require_once $root . '/web/config/firestore.php';
require_once $root . '/web/config/firebase_admin_token.php';
require_once $root . '/web/config/inventory_dispatch.php';

$apply = in_array('--apply', $argv, true);
$assetTag = '';
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--asset-tag=')) {
        $assetTag = trim(substr($arg, 12));
    }
}
if ($assetTag === '') {
    fwrite(STDERR, "Usage: php scripts/reconcile_dispatch_history.php --asset-tag=<tag> [--apply]\n");
    exit(2);
}

$token = am_firestore_admin_access_token();
if ($token === '') {
    fwrite(STDERR, "No Firestore admin token available.\n");
    exit(2);
}
$_SESSION['firebase_id_token'] = $token;

$assets = am_firestore_get_collection('am_core_assets', 10000, $token);
$asset = null;
foreach ($assets as $candidate) {
    if ((string)($candidate['asset_tag'] ?? '') === $assetTag) {
        $asset = $candidate;
        break;
    }
}
if (!$asset) {
    fwrite(STDERR, "Asset not found: {$assetTag}\n");
    exit(1);
}
$assetId = (string)($asset['id'] ?? '');
$sourceLocation = (string)($asset['location_id'] ?? '');

$inventoryRows = array_values(array_filter(
    am_firestore_get_collection('am_core_inventory_levels', 10000, $token),
    fn(array $row): bool => (string)($row['asset_id'] ?? '') === $assetId
        && (string)($row['location_id'] ?? '') === $sourceLocation
));
if (count($inventoryRows) !== 1) {
    fwrite(STDERR, 'Expected exactly one source inventory row; found ' . count($inventoryRows) . ".\n");
    exit(1);
}
$inventory = $inventoryRows[0];

$events = [];
$correctionQuantity = 0;
$requests = am_firestore_get_collection('am_core_requests', 1000, $token);
foreach ($requests as $request) {
    if ((string)($request['workflow_type'] ?? '') !== 'inventory_dispatch'
        || (string)($request['status'] ?? '') !== 'Fulfilled') {
        continue;
    }
    $payload = is_array($request['payload'] ?? null) ? $request['payload'] : [];
    $destination = (string)($payload['site_code'] ?? '');
    if ($destination !== $sourceLocation) {
        continue;
    }
    $lines = is_array($payload['line_items'] ?? null) ? $payload['line_items'] : [];
    foreach ($lines as $index => $line) {
        if ((string)($line['asset_id'] ?? '') !== $assetId) {
            continue;
        }
        $requestId = (string)($request['id'] ?? '');
        $allocated = (int)($line['allocated_quantity'] ?? 0);
        $fulfilled = (int)($line['fulfilled_quantity'] ?? 0);
        if ($fulfilled <= 0) {
            continue;
        }

        $allocationId = am_dispatch_event_id($requestId, (int)$index, 'allocation');
        if (!am_firestore_get_document('am_core_transactions', $allocationId, $token)) {
            $allocation = am_dispatch_transaction_data(
                'Allocation', $assetId, $allocated, $sourceLocation, $destination,
                array_merge($request, ['id' => $requestId]), $payload, 'reserved (historical repair)'
            );
            $allocation['transaction_date'] = (string)($line['allocation_updated_at'] ?? $payload['allocation_applied_at'] ?? date('c'));
            $allocation['performed_by'] = (string)($request['requested_by'] ?? '');
            $allocation['reconciled_from_legacy_dispatch'] = true;
            $events[$allocationId] = $allocation;
        }

        $fulfillmentId = am_dispatch_event_id($requestId, (int)$index, 'fulfillment');
        if (!am_firestore_get_document('am_core_transactions', $fulfillmentId, $token)) {
            $fulfillment = am_dispatch_transaction_data(
                am_dispatch_fulfillment_transaction_type($asset, true),
                $assetId, $fulfilled, $sourceLocation, '',
                array_merge($request, ['id' => $requestId]), $payload, 'issued (historical repair)'
            );
            $fulfillment['transaction_date'] = (string)($line['fulfilled_updated_at'] ?? $payload['fulfilled_apportionment_at'] ?? date('c'));
            $fulfillment['performed_by'] = (string)($request['requested_by'] ?? '');
            $fulfillment['reconciled_from_legacy_dispatch'] = true;
            $events[$fulfillmentId] = $fulfillment;
            $correctionQuantity += $fulfilled;
        }
    }
}

$oldOnHand = (int)($inventory['quantity_on_hand'] ?? 0);
$oldAllocated = (int)($inventory['quantity_allocated'] ?? 0);
$oldAssetQuantity = (int)($asset['quantity'] ?? $oldOnHand);
if ($correctionQuantity > $oldOnHand || $correctionQuantity > $oldAllocated || $correctionQuantity > $oldAssetQuantity) {
    fwrite(STDERR, "Safety check failed: correction {$correctionQuantity}, on-hand {$oldOnHand}, allocated {$oldAllocated}, asset quantity {$oldAssetQuantity}.\n");
    exit(1);
}

$plan = [
    'mode' => $apply ? 'apply' : 'dry-run',
    'asset_tag' => $assetTag,
    'asset_id' => $assetId,
    'source_location' => $sourceLocation,
    'legacy_fulfilled_quantity_to_apply' => $correctionQuantity,
    'inventory_before' => ['on_hand' => $oldOnHand, 'allocated' => $oldAllocated],
    'inventory_after' => ['on_hand' => $oldOnHand - $correctionQuantity, 'allocated' => $oldAllocated - $correctionQuantity],
    'asset_quantity_before' => $oldAssetQuantity,
    'asset_quantity_after' => $oldAssetQuantity - $correctionQuantity,
    'events_to_create' => array_keys($events),
];
echo json_encode($plan, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";

if (!$apply || ($correctionQuantity === 0 && $events === [])) {
    exit(0);
}

$now = date('c');
$operations = [
    [
        'mode' => 'update',
        'collection' => 'am_core_inventory_levels',
        'id' => (string)$inventory['id'],
        'data' => [
            'quantity_on_hand' => $oldOnHand - $correctionQuantity,
            'quantity_allocated' => $oldAllocated - $correctionQuantity,
            'updated_at' => $now,
        ],
    ],
    [
        'mode' => 'update',
        'collection' => 'am_core_assets',
        'id' => $assetId,
        'data' => [
            'quantity' => $oldAssetQuantity - $correctionQuantity,
            'updated_at' => $now,
        ],
    ],
];
foreach ($events as $eventId => $event) {
    $operations[] = [
        'mode' => 'create',
        'collection' => 'am_core_transactions',
        'id' => $eventId,
        'data' => $event,
    ];
}

$result = am_firestore_commit_operations($operations, $token);
if (!$result['ok']) {
    fwrite(STDERR, 'Atomic repair failed: ' . (string)($result['error'] ?? 'unknown error') . "\n");
    exit(1);
}
echo "Repair committed atomically.\n";
