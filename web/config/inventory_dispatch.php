<?php
/**
 * Inventory-dispatch ledger helpers.
 *
 * Dispatch reservations and movements are balance-changing events.  Every such
 * event must be committed atomically with an immutable am_core_transactions row.
 */
require_once __DIR__ . '/inventory_movements.php';

function am_dispatch_event_id(string $requestId, int $lineIndex, string $phase): string {
    $safeRequest = preg_replace('/[^A-Za-z0-9_-]/', '_', $requestId) ?: 'request';
    $safePhase = preg_replace('/[^A-Za-z0-9_-]/', '_', strtolower($phase)) ?: 'event';
    return 'dispatch_' . $safeRequest . '_' . $lineIndex . '_' . $safePhase;
}

function am_dispatch_fulfillment_transaction_type(array $asset, bool $sameLocation): string {
    if (!$sameLocation) {
        return 'Transfer';
    }
    return in_array((string)($asset['item_class'] ?? ''), ['Consumable', 'Material', 'Inventory'], true)
        ? 'Consume'
        : 'Deploy';
}

/**
 * Pure balance calculation used by the workflow and regression tests.
 * A same-location fulfillment is an issue to a receiver: stock leaves on-hand
 * and is not added back to the same row.
 *
 * @return array{source_on_hand:int, source_allocated:int, destination_on_hand:?int}
 */
function am_dispatch_fulfillment_balances(
    int $sourceOnHand,
    int $sourceAllocated,
    int $reservedQuantity,
    int $movedQuantity,
    bool $sameLocation,
    ?int $destinationOnHand = null
): array {
    return [
        'source_on_hand' => max(0, $sourceOnHand - $movedQuantity),
        'source_allocated' => max(0, $sourceAllocated - $reservedQuantity),
        'destination_on_hand' => $sameLocation
            ? null
            : max(0, (int)$destinationOnHand) + $movedQuantity,
    ];
}

/** @return array<string, mixed> */
function am_dispatch_transaction_data(
    string $type,
    string $assetId,
    int $quantity,
    string $sourceLocation,
    string $destinationLocation,
    array $request,
    array $payload,
    string $phase
): array {
    $requestNumber = (string)($request['request_number'] ?? $request['id'] ?? '');
    $receiverName = trim((string)($payload['receiver_name'] ?? ''));
    $receiverEmail = strtolower(trim((string)($payload['receiver_email'] ?? '')));
    $notes = 'Inventory dispatch ' . $requestNumber . ' — ' . $phase;
    if ($receiverName !== '') {
        $notes .= ' for ' . $receiverName;
    }

    return [
        'transaction_type' => $type,
        'asset_id' => $assetId,
        'quantity' => max(0, $quantity),
        'from_location_id' => $sourceLocation,
        'to_location_id' => $destinationLocation,
        'employee_name' => $receiverName,
        'employee_email' => $receiverEmail,
        'performed_by' => (string)($_SESSION['user_id'] ?? ''),
        'device_type' => 'Desktop',
        'notes' => $notes,
        'transaction_date' => date('c'),
        'source_workflow' => 'inventory_dispatch',
        'source_request_id' => (string)($request['id'] ?? ''),
        'source_request_number' => $requestNumber,
        'source_phase' => $phase,
    ];
}

/**
 * Commit inventory/asset operations together with one immutable ledger event.
 *
 * @param list<array{mode:string, collection:string, id:string, data:array<string,mixed>}> $operations
 * @return array{ok:bool, error:?string, duplicate?:bool}
 */
function am_dispatch_commit_event(array $operations, string $eventId, array $transaction): array {
    $existing = am_firestore_get_document('am_core_transactions', $eventId);
    if ($existing) {
        return ['ok' => true, 'error' => null, 'duplicate' => true];
    }

    $operations[] = [
        'mode' => 'create',
        'collection' => 'am_core_transactions',
        'id' => $eventId,
        'data' => $transaction,
    ];
    if (function_exists('am_inventory_movement_from_transaction')) {
        $tx = $transaction;
        $tx['id'] = $eventId;
        $operations[] = [
            'mode' => 'create',
            'collection' => AM_INVENTORY_MOVEMENTS_COLLECTION,
            'id' => 'mv_' . $eventId,
            'data' => am_inventory_movement_from_transaction($tx),
        ];
    }
    $result = am_firestore_commit_operations($operations);
    if (!$result['ok'] && stripos((string)($result['error'] ?? ''), 'ALREADY_EXISTS') !== false) {
        // A concurrent retry won the deterministic event id. Its atomic commit
        // already contains the balance change, so this request is safely done.
        return ['ok' => true, 'error' => null, 'duplicate' => true];
    }
    return ['ok' => (bool)($result['ok'] ?? false), 'error' => $result['error'] ?? null, 'duplicate' => false];
}
