<?php
/**
 * Append-only stock movement ledger (am_core_inventory_movements).
 *
 * Distinct from am_core_mutation_logs (record-edit audit) and complementary
 * to am_core_transactions (workflow ledger). Movements carry signed quantity,
 * from/to store, movement_type and a join reference (PR/PO or loadout id).
 *
 * GET /api/v1/movements projects existing am_core_transactions into this
 * shape so history is readable before new writes accumulate.
 */

const AM_INVENTORY_MOVEMENTS_COLLECTION = 'am_core_inventory_movements';

/** @return list<string> */
function am_inventory_movement_types(): array {
    return ['receipt', 'issue', 'transfer', 'adjustment', 'return'];
}

function am_inventory_movement_type_from_transaction(string $transactionType): string {
    return match ($transactionType) {
        'StockIngestion', 'StockTake' => 'receipt',
        'Consume', 'Deploy', 'CheckOut', 'Allocation' => 'issue',
        'Transfer' => 'transfer',
        'Return', 'CheckIn' => 'return',
        'WriteOff', 'StockAdjustment', 'ItemUpdate', 'StatusChange' => 'adjustment',
        default => 'adjustment',
    };
}

function am_inventory_movement_signed_qty(string $movementType, int $quantity, array $tx = []): int {
    $qty = abs($quantity);
    if ($movementType === 'adjustment') {
        $before = $tx['quantity_before'] ?? null;
        $after = $tx['quantity_after'] ?? null;
        if ($before !== null && $after !== null) {
            return (int)$after - (int)$before;
        }
        return $qty;
    }
    if (in_array($movementType, ['issue'], true)) {
        return -1 * $qty;
    }
    return $qty;
}

/**
 * Project an am_core_transactions row into the movement API shape.
 *
 * @param array<string, mixed> $tx
 * @return array<string, mixed>
 */
function am_inventory_movement_from_transaction(array $tx): array {
    $type = am_inventory_movement_type_from_transaction((string)($tx['transaction_type'] ?? ''));
    $qty = (int)($tx['quantity'] ?? 0);
    $from = trim((string)($tx['from_location_id'] ?? ''));
    $to = trim((string)($tx['to_location_id'] ?? $tx['site_code'] ?? $tx['location_id'] ?? ''));
    $occurred = (string)($tx['transaction_date'] ?? $tx['created_at'] ?? '');
    $ref = trim((string)($tx['source_request_number'] ?? ''));
    if ($ref === '') {
        $ref = trim((string)($tx['source_request_id'] ?? ''));
    }
    $site = $to !== '' ? $to : $from;
    return [
        'movement_id' => (string)($tx['id'] ?? ''),
        'part_id' => (string)($tx['asset_id'] ?? ''),
        'qty' => am_inventory_movement_signed_qty($type, $qty, $tx),
        'from_store' => $from,
        'to_store' => $to,
        'site_id' => $site,
        'movement_type' => $type,
        'occurred_at' => $occurred,
        'recorded_at' => (string)($tx['created_at'] ?? $occurred),
        'reference' => $ref,
        'recorded_by' => (string)($tx['performed_by'] ?? $tx['performed_by_email'] ?? ''),
        'source' => 'am_core_transactions',
        'asset_id' => (string)($tx['asset_id'] ?? ''),
    ];
}

/**
 * @param array<string, mixed> $row
 * @return array<string, mixed>
 */
function am_inventory_movement_from_ledger_row(array $row): array {
    return [
        'movement_id' => (string)($row['id'] ?? $row['movement_id'] ?? ''),
        'part_id' => (string)($row['part_id'] ?? $row['asset_id'] ?? ''),
        'qty' => (int)($row['qty'] ?? 0),
        'from_store' => (string)($row['from_store'] ?? $row['from_location_id'] ?? ''),
        'to_store' => (string)($row['to_store'] ?? $row['to_location_id'] ?? ''),
        'site_id' => (string)($row['site_id'] ?? $row['to_location_id'] ?? $row['from_location_id'] ?? ''),
        'movement_type' => (string)($row['movement_type'] ?? 'adjustment'),
        'occurred_at' => (string)($row['occurred_at'] ?? ''),
        'recorded_at' => (string)($row['recorded_at'] ?? $row['occurred_at'] ?? ''),
        'reference' => (string)($row['reference'] ?? ''),
        'recorded_by' => (string)($row['recorded_by'] ?? ''),
        'source' => 'am_core_inventory_movements',
        'asset_id' => (string)($row['asset_id'] ?? $row['part_id'] ?? ''),
    ];
}

/**
 * Best-effort append. Never fails the caller — a missing movement must not
 * roll back an already-committed inventory write.
 *
 * @param array<string, mixed> $fields
 */
function am_inventory_movement_record(array $fields, ?string $idTokenOverride = null, ?string $docId = null): void {
    if (!function_exists('am_firestore_create_document')) {
        return;
    }
    $now = date('c');
    $type = (string)($fields['movement_type'] ?? 'adjustment');
    if (!in_array($type, am_inventory_movement_types(), true)) {
        $type = 'adjustment';
    }
    $payload = [
        'part_id' => (string)($fields['part_id'] ?? $fields['asset_id'] ?? ''),
        'asset_id' => (string)($fields['asset_id'] ?? $fields['part_id'] ?? ''),
        'qty' => (int)($fields['qty'] ?? 0),
        'from_store' => (string)($fields['from_store'] ?? $fields['from_location_id'] ?? ''),
        'to_store' => (string)($fields['to_store'] ?? $fields['to_location_id'] ?? ''),
        'site_id' => (string)($fields['site_id'] ?? $fields['to_location_id'] ?? $fields['from_location_id'] ?? ''),
        'movement_type' => $type,
        'occurred_at' => (string)($fields['occurred_at'] ?? $now),
        'recorded_at' => $now,
        'reference' => (string)($fields['reference'] ?? ''),
        'recorded_by' => (string)($fields['recorded_by'] ?? ($_SESSION['user_id'] ?? '')),
    ];
    try {
        $res = am_firestore_create_document(AM_INVENTORY_MOVEMENTS_COLLECTION, $payload, $docId, $idTokenOverride);
        if (empty($res['ok'])) {
            error_log('[am_inventory_movement_record] ' . (string)($res['error'] ?? 'create failed'));
        }
    } catch (Throwable $e) {
        error_log('[am_inventory_movement_record] ' . $e->getMessage());
    }
}

/**
 * Record a movement that mirrors a just-written am_core_transactions row.
 *
 * @param array<string, mixed> $transaction
 */
function am_inventory_movement_record_from_transaction(array $transaction, ?string $idTokenOverride = null, ?string $docId = null): void {
    $projected = am_inventory_movement_from_transaction($transaction);
    am_inventory_movement_record($projected, $idTokenOverride, $docId);
}
