<?php
/**
 * Canonical inventory transaction helpers.
 *
 * Asset and inventory mutations must call these helpers after the authoritative
 * write succeeds. Transaction documents are append-only under Firestore rules.
 */
require_once __DIR__ . '/firestore.php';

/** @return array<string, mixed> */
function am_transaction_actor_fields(): array {
    return [
        'performed_by' => (string)($_SESSION['user_id'] ?? ''),
        'performed_by_name' => (string)($_SESSION['username'] ?? ''),
        'performed_by_email' => (string)($_SESSION['email'] ?? ''),
        'device_type' => 'Desktop',
    ];
}

/**
 * Append one immutable, item-linked ledger entry.
 *
 * @param array<string, mixed> $context
 * @return array{ok: bool, error: ?string, id: string}
 */
function am_log_asset_transaction(
    string $assetId,
    string $transactionType,
    int $quantity,
    array $context = []
): array {
    $assetId = trim($assetId);
    if ($assetId === '') {
        return ['ok' => false, 'error' => 'Missing asset ID', 'id' => ''];
    }

    $data = array_merge(am_transaction_actor_fields(), [
        'transaction_type' => trim($transactionType) ?: 'Adjustment',
        'asset_id' => $assetId,
        'quantity' => max(0, $quantity),
        'transaction_date' => date('c'),
        'created_at' => date('c'),
    ], $context);

    // Keep transaction identity canonical even if a caller passes context from
    // an older payload that contains an empty/different asset_id.
    $data['asset_id'] = $assetId;
    $data['quantity'] = max(0, (int)($data['quantity'] ?? $quantity));

    return am_firestore_create_document('am_core_transactions', $data);
}

/**
 * Describe the one primary activity represented by an item edit.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return array{type: string, quantity: int, notes: string, context: array<string, mixed>}|null
 */
function am_describe_asset_change(array $before, array $after): ?array {
    $oldLocation = trim((string)($before['location_id'] ?? ''));
    $newLocation = trim((string)($after['location_id'] ?? $oldLocation));
    $oldStatus = trim((string)($before['status'] ?? ''));
    $newStatus = trim((string)($after['status'] ?? $oldStatus));
    $oldQuantity = (int)($before['quantity'] ?? 1);
    $newQuantity = (int)($after['quantity'] ?? $oldQuantity);

    $changes = [];
    if ($oldLocation !== $newLocation) {
        $changes[] = 'Site changed from ' . ($oldLocation ?: 'unassigned') . ' to ' . ($newLocation ?: 'unassigned');
    }
    if ($oldStatus !== $newStatus) {
        $changes[] = 'Status changed from ' . ($oldStatus ?: 'unassigned') . ' to ' . ($newStatus ?: 'unassigned');
    }
    if ($oldQuantity !== $newQuantity) {
        $changes[] = 'Quantity changed from ' . $oldQuantity . ' to ' . $newQuantity;
    }
    if (empty($changes)) {
        return null;
    }

    $type = 'ItemUpdate';
    if ($oldLocation !== $newLocation) {
        $type = 'Transfer';
    } elseif ($oldQuantity !== $newQuantity) {
        $type = 'StockAdjustment';
    } elseif ($oldStatus !== $newStatus) {
        $type = match ($newStatus) {
            'Allocated', 'CheckedOut', 'InProject' => 'Allocation',
            'Consumed' => 'Consume',
            'Deployed' => 'Deploy',
            'WrittenOff', 'Retired' => 'WriteOff',
            'Available' => 'Return',
            default => 'StatusChange',
        };
    }

    $affectedQuantity = ($oldQuantity !== $newQuantity && $oldLocation === $newLocation)
        ? abs($newQuantity - $oldQuantity)
        : max(0, $newQuantity);

    return [
        'type' => $type,
        'quantity' => $affectedQuantity,
        'notes' => implode('; ', $changes),
        'context' => [
            'asset_name' => (string)($after['name'] ?? $before['name'] ?? ''),
            'asset_tag' => (string)($after['asset_tag'] ?? $before['asset_tag'] ?? ''),
            'from_location_id' => $oldLocation,
            'to_location_id' => $newLocation,
            'site_code' => $newLocation ?: $oldLocation,
            'status_before' => $oldStatus,
            'status_after' => $newStatus,
            'quantity_before' => $oldQuantity,
            'quantity_after' => $newQuantity,
        ],
    ];
}

/**
 * Log a tracked quantity/status/site change made through the item editor.
 *
 * @param array<string, mixed> $before
 * @param array<string, mixed> $after
 * @return array{ok: bool, error: ?string, id: string, skipped?: bool}
 */
function am_log_asset_change(string $assetId, array $before, array $after): array {
    $change = am_describe_asset_change($before, $after);
    if ($change === null) {
        return ['ok' => true, 'error' => null, 'id' => '', 'skipped' => true];
    }
    $context = $change['context'];
    $context['notes'] = $change['notes'];
    return am_log_asset_transaction($assetId, $change['type'], $change['quantity'], $context);
}

/** Return the best available site/location identifier for a ledger row. */
function am_transaction_site_id(array $transaction): string {
    foreach (['site_code', 'to_location_id', 'from_location_id', 'location_id'] as $field) {
        $value = trim((string)($transaction[$field] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }
    return '';
}

/**
 * Query the ledger by asset identity instead of downloading the entire global
 * ledger and filtering it in PHP. Multiple IDs cover legacy records that used
 * either the Firestore document ID or the older asset_id field.
 *
 * @param list<string> $assetIds
 * @return list<array<string, mixed>>
 */
function am_get_asset_transactions(array $assetIds, int $limit = 500, ?bool &$querySucceeded = null): array {
    $token = am_firestore_resolve_id_token();
    $querySucceeded = $token !== '';
    if ($token === '') return [];

    $assetIds = array_values(array_unique(array_filter(array_map(
        fn($id) => trim((string)$id),
        $assetIds
    ), fn($id) => $id !== '')));
    $byDocumentId = [];

    foreach ($assetIds as $assetId) {
        $payload = [
            'structuredQuery' => [
                'from' => [['collectionId' => 'am_core_transactions']],
                'where' => [
                    'fieldFilter' => [
                        'field' => ['fieldPath' => 'asset_id'],
                        'op' => 'EQUAL',
                        'value' => ['stringValue' => $assetId],
                    ],
                ],
                'limit' => max(1, min(1000, $limit)),
            ],
        ];
        $result = am_http_request_json('POST', am_firestore_base_url() . ':runQuery', $payload, [
            'Authorization: Bearer ' . $token,
        ]);
        if (!$result['ok']) {
            $querySucceeded = false;
            error_log('[AM transaction] Query failed for asset ' . $assetId . ': ' . ($result['error'] ?? 'HTTP ' . $result['status']));
            continue;
        }
        foreach ($result['json'] as $row) {
            if (!is_array($row) || !isset($row['document']) || !is_array($row['document'])) continue;
            $transaction = am_firestore_document_to_array($row['document']);
            $key = (string)($transaction['id'] ?? md5(json_encode($transaction)));
            $byDocumentId[$key] = $transaction;
        }
    }

    $transactions = array_values($byDocumentId);
    usort($transactions, function ($a, $b) {
        return strtotime((string)($b['transaction_date'] ?? $b['created_at'] ?? '1970-01-01'))
            <=> strtotime((string)($a['transaction_date'] ?? $a['created_at'] ?? '1970-01-01'));
    });
    return array_slice($transactions, 0, max(1, $limit));
}
