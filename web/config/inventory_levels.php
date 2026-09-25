<?php
/**
 * Canonical location resolution and duplicate inventory row merging.
 *
 * Duplicate rows (e.g. site1 + LSO-HQ for the same asset) must not be summed —
 * they represent the same stock counted twice.
 */

/** @return array<string, array<string, mixed>> */
function am_build_location_index(array $locations): array {
    $locByAnyKey = [];
    foreach ($locations as $loc) {
        $lid = (string)($loc['location_id'] ?? $loc['id'] ?? '');
        $lcode = (string)($loc['location_code'] ?? '');
        if ($lid !== '') {
            $locByAnyKey[$lid] = $loc;
        }
        if ($lcode !== '' && $lcode !== $lid) {
            $locByAnyKey[$lcode] = $loc;
        }
    }
    return $locByAnyKey;
}

/**
 * Headquarters location_code for a country (e.g. LSO-HQ).
 *
 * @param list<array<string, mixed>> $locations
 */
function am_hq_location_code(string $countryCode, array $locations): string {
    $countryCode = strtoupper(trim($countryCode));
    $want = $countryCode !== '' ? $countryCode . '-HQ' : '';
    foreach ($locations as $loc) {
        if (!is_array($loc)) {
            continue;
        }
        $code = strtoupper(trim((string)($loc['location_code'] ?? '')));
        $cc = strtoupper(trim((string)($loc['country_code'] ?? '')));
        if ($want !== '' && $code === $want) {
            return $code;
        }
        if ($countryCode !== '' && $cc !== '' && $cc !== $countryCode) {
            continue;
        }
        if ($code === 'HQ' || str_ends_with($code, '-HQ')) {
            return $code === 'HQ' && $countryCode !== '' ? $countryCode . '-HQ' : $code;
        }
    }
    foreach ($locations as $loc) {
        if (!is_array($loc)) {
            continue;
        }
        $cc = strtoupper(trim((string)($loc['country_code'] ?? '')));
        if ($countryCode !== '' && $cc !== '' && $cc !== $countryCode) {
            continue;
        }
        $name = strtolower((string)($loc['location_name'] ?? ''));
        if (!str_contains($name, 'headquarter') && !str_contains($name, 'head office')) {
            continue;
        }
        $code = trim((string)($loc['location_code'] ?? ''));
        if ($code !== '') {
            return strtoupper($code);
        }
    }
    return '';
}

/**
 * Sum stockable inventory rows (already de-duplicated) into headline totals.
 *
 * @param list<array<string, mixed>> $rows
 * @return array{on_hand:int, allocated:int, available:int}
 */
function am_stockable_on_hand_totals(array $rows): array {
    $onHand = 0;
    $allocated = 0;
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $onHand += (int)($row['quantity_on_hand'] ?? 0);
        $allocated += (int)($row['quantity_allocated'] ?? 0);
    }
    return [
        'on_hand' => $onHand,
        'allocated' => $allocated,
        'available' => max(0, $onHand - $allocated),
    ];
}

/**
 * Resolve a location id/code to its canonical location_code.
 * Returns '' when the id cannot be resolved — never echo an unresolved raw id as a key
 * (that created parallel rows like site1 alongside LSO-HQ).
 */
function am_canonical_location_code(string $rawId, array $locByAnyKey, string $countryCode = ''): string {
    $rawId = trim($rawId);
    if ($rawId === '' || $locByAnyKey === []) {
        return '';
    }

    $candidates = [$rawId];
    $countryCode = strtoupper(trim($countryCode));
    if ($countryCode !== '') {
        $candidates[] = $countryCode . '-' . $rawId;
        $candidates[] = $countryCode . '-' . strtoupper($rawId);
    }

    foreach ($candidates as $cand) {
        if (isset($locByAnyKey[$cand])) {
            $resolved = $locByAnyKey[$cand];
            $code = trim((string)($resolved['location_code'] ?? ''));
            return $code !== '' ? $code : (string)$cand;
        }
    }

    // Case-insensitive fallback
    foreach ($candidates as $cand) {
        $candLower = strtolower($cand);
        foreach ($locByAnyKey as $key => $loc) {
            if (strtolower((string)$key) === $candLower) {
                $code = trim((string)($loc['location_code'] ?? ''));
                return $code !== '' ? $code : (string)$key;
            }
        }
    }

    // If the raw code has no dash and no country was supplied, try active country prefixes.
    if ($countryCode === '' && !str_contains($rawId, '-') && strlen($rawId) <= 4) {
        $active = function_exists('am_org_country_codes') ? am_org_country_codes() : ['LSO', 'ZMB', 'BEN'];
        foreach ($active as $cc) {
            $cand = strtoupper($cc) . '-' . strtoupper($rawId);
            if (isset($locByAnyKey[$cand])) {
                $code = trim((string)($locByAnyKey[$cand]['location_code'] ?? ''));
                return $code !== '' ? $code : $cand;
            }
            $candLower = strtolower($cand);
            foreach ($locByAnyKey as $key => $loc) {
                if (strtolower((string)$key) === $candLower) {
                    $code = trim((string)($loc['location_code'] ?? ''));
                    return $code !== '' ? $code : (string)$key;
                }
            }
        }
    }

    return '';
}

/** Grouping key for levels rows: canonical code, or an unresolved marker that never merges across raw ids. */
function am_inventory_location_group_key(string $rawLocationId, array $locByAnyKey, string $countryCode = ''): string {
    $canon = am_canonical_location_code($rawLocationId, $locByAnyKey, $countryCode);
    if ($canon !== '') {
        return $canon;
    }
    $raw = trim($rawLocationId);
    return $raw !== '' ? ('__unresolved__:' . $raw) : '__unresolved__:empty';
}

/**
 * Pick the keeper row when multiple inventory rows map to the same canonical location.
 *
 * @param list<array<string, mixed>> $group
 */
function am_inventory_pick_keeper_row(array $group, string $canonicalLoc, array $locByAnyKey, ?array $asset = null): array {
    foreach ($group as $row) {
        if ((string)($row['location_id'] ?? '') === $canonicalLoc) {
            return $row;
        }
    }

    if ($asset !== null) {
        $assetLocRaw = (string)($asset['location_id'] ?? '');
        $assetLocCanon = am_canonical_location_code($assetLocRaw, $locByAnyKey);
        if ($assetLocCanon === $canonicalLoc) {
            foreach ($group as $row) {
                if ((string)($row['location_id'] ?? '') === $assetLocRaw) {
                    return $row;
                }
            }
        }
    }

    usort($group, function ($a, $b) {
        return strcmp((string)($b['updated_at'] ?? ''), (string)($a['updated_at'] ?? ''));
    });
    return $group[0];
}

/**
 * Merge duplicate inventory rows for the same asset at the same canonical location.
 *
 * @param list<array<string, mixed>> $rows
 * @return list<array<string, mixed>>
 */
function am_inventory_merge_duplicate_rows(array $rows, array $locByAnyKey, ?array $asset = null): array {
    if ($rows === []) {
        return [];
    }

    $groups = [];
    foreach ($rows as $row) {
        $rawLoc = (string)($row['location_id'] ?? '');
        $canon = am_canonical_location_code($rawLoc, $locByAnyKey);
        $groupLoc = $canon !== '' ? $canon : am_inventory_location_group_key($rawLoc, $locByAnyKey);
        $countryId = (string)($row['country_id'] ?? '');
        $key = $groupLoc . '|' . ($countryId !== '' ? $countryId : '_');
        if (!isset($groups[$key])) {
            $groups[$key] = [];
        }
        $groups[$key][] = $row;
    }

    $merged = [];
    foreach ($groups as $group) {
        $raw0 = (string)($group[0]['location_id'] ?? '');
        $canon = am_canonical_location_code($raw0, $locByAnyKey);
        if ($canon === '') {
            // Unresolvable rows: keep as-is (do not invent a key); callers flag reconciliation.
            foreach ($group as $row) {
                $merged[] = $row;
            }
            continue;
        }
        if (count($group) === 1) {
            $row = $group[0];
            $row['location_id'] = $canon;
            $merged[] = $row;
            continue;
        }

        $keeper = am_inventory_pick_keeper_row($group, $canon, $locByAnyKey, $asset);
        $allocTotal = 0;
        foreach ($group as $row) {
            $allocTotal += (int)($row['quantity_allocated'] ?? 0);
        }

        $qoh = (int)($keeper['quantity_on_hand'] ?? 0);
        if ($asset !== null) {
            $assetLocCanon = am_canonical_location_code((string)($asset['location_id'] ?? ''), $locByAnyKey);
            $assetQty = (int)($asset['quantity'] ?? 0);
            if ($assetLocCanon === $canon && $assetQty > 0) {
                $qoh = $assetQty;
            }
        }

        $mergedRow = $keeper;
        $mergedRow['location_id'] = $canon;
        $mergedRow['quantity_on_hand'] = $qoh;
        $mergedRow['quantity_allocated'] = $allocTotal;
        $merged[] = $mergedRow;
    }

    return $merged;
}

/**
 * Inventory rows for one asset, merged by canonical location.
 *
 * @return list<array<string, mixed>>
 */
function am_inventory_rows_for_asset(string $assetId, array $allInventoryLevels, array $locByAnyKey, ?array $asset = null): array {
    $rows = [];
    foreach ($allInventoryLevels as $inv) {
        if ((string)($inv['asset_id'] ?? '') !== $assetId) {
            continue;
        }
        $rows[] = $inv;
    }
    return am_inventory_merge_duplicate_rows($rows, $locByAnyKey, $asset);
}

/**
 * Collapse duplicate inventory rows across all assets (for stock level listings).
 *
 * @param list<array<string, mixed>> $allInventoryLevels
 * @return list<array<string, mixed>>
 */
function am_inventory_dedupe_all_levels(array $allInventoryLevels, array $locByAnyKey, array $assetById = []): array {
    $byAsset = [];
    foreach ($allInventoryLevels as $inv) {
        $aid = (string)($inv['asset_id'] ?? '');
        if ($aid === '') {
            continue;
        }
        if (!isset($byAsset[$aid])) {
            $byAsset[$aid] = [];
        }
        $byAsset[$aid][] = $inv;
    }

    $out = [];
    foreach ($byAsset as $aid => $rows) {
        $asset = $assetById[$aid] ?? null;
        foreach (am_inventory_merge_duplicate_rows($rows, $locByAnyKey, $asset) as $merged) {
            $out[] = $merged;
        }
    }
    return $out;
}

/**
 * Rows at a canonical location for an asset (used when saving edits).
 *
 * @return list<array<string, mixed>>
 */
function am_inventory_matching_location_rows(
    string $assetId,
    string $targetLocCanonical,
    array $allInventoryLevels,
    array $locByAnyKey,
    string $countryId = ''
): array {
    $rows = [];
    foreach ($allInventoryLevels as $inv) {
        if ((string)($inv['asset_id'] ?? '') !== $assetId) {
            continue;
        }
        $invCountry = (string)($inv['country_id'] ?? '');
        if ($countryId !== '' && $invCountry !== '' && $invCountry !== $countryId) {
            continue;
        }
        $invLocCanon = am_canonical_location_code((string)($inv['location_id'] ?? ''), $locByAnyKey);
        if ($invLocCanon === '' || $invLocCanon !== $targetLocCanonical) {
            continue;
        }
        $rows[] = $inv;
    }
    return $rows;
}

/**
 * Pure balance math for a site change: move on-hand from source to destination.
 * Source ends at 0 on-hand (allocation released); destination receives the moved qty.
 *
 * @return array{source_on_hand:int, source_allocated:int, destination_on_hand:int}
 */
function am_inventory_site_change_balances(
    int $sourceOnHand,
    int $sourceAllocated,
    int $destinationOnHand,
    int $movedQuantity
): array {
    $moved = max(0, $movedQuantity);
    return [
        'source_on_hand' => 0,
        'source_allocated' => 0,
        'destination_on_hand' => max(0, $destinationOnHand) + $moved,
    ];
}

/**
 * Detect duplicate (asset, canonical location) pairs among raw levels rows.
 *
 * @param list<array<string, mixed>> $levelsForAsset
 * @return list<string> canonical location codes that appear more than once
 */
function am_inventory_duplicate_location_codes(array $levelsForAsset, array $locByAnyKey): array {
    $counts = [];
    foreach ($levelsForAsset as $row) {
        $canon = am_canonical_location_code((string)($row['location_id'] ?? ''), $locByAnyKey);
        if ($canon === '') {
            continue;
        }
        $counts[$canon] = ($counts[$canon] ?? 0) + 1;
    }
    $dups = [];
    foreach ($counts as $code => $n) {
        if ($n > 1) {
            $dups[] = $code;
        }
    }
    return $dups;
}

/**
 * Reconciliation status for one stockable asset across its levels rows.
 *
 * @param array<string, mixed> $asset
 * @param list<array<string, mixed>> $levelsForAsset raw (pre-dedupe) rows for this asset
 * @return 'ok'|'unverified'|'duplicate_location'|'sum_mismatch'|'unresolvable_location'
 */
function am_inventory_reconciliation_status_for_asset(array $asset, array $levelsForAsset, array $locByAnyKey): string {
    $hasUnresolved = false;
    $sum = 0;
    foreach ($levelsForAsset as $row) {
        $raw = trim((string)($row['location_id'] ?? ''));
        $canon = am_canonical_location_code($raw, $locByAnyKey);
        if ($raw !== '' && $canon === '') {
            $hasUnresolved = true;
        }
        $sum += (int)($row['quantity_on_hand'] ?? 0);
    }
    if ($hasUnresolved) {
        return 'unresolvable_location';
    }
    if (am_inventory_duplicate_location_codes($levelsForAsset, $locByAnyKey) !== []) {
        return 'duplicate_location';
    }
    $assetQty = (int)($asset['quantity'] ?? 0);
    if ($levelsForAsset !== [] && $sum !== $assetQty) {
        return 'sum_mismatch';
    }
    $assetLoc = trim((string)($asset['location_id'] ?? ''));
    if ($assetLoc !== '' && am_canonical_location_code($assetLoc, $locByAnyKey) === '') {
        return 'unresolvable_location';
    }
    // Orphan full-qty rows at sites other than the asset's current location → unverified
    // until a human drum count decides store vs site (brief: do not invent the split).
    $assetCanon = am_canonical_location_code($assetLoc, $locByAnyKey);
    if ($assetCanon !== '' && $assetQty > 0) {
        foreach ($levelsForAsset as $row) {
            $rowCanon = am_canonical_location_code((string)($row['location_id'] ?? ''), $locByAnyKey);
            $qoh = (int)($row['quantity_on_hand'] ?? 0);
            if ($rowCanon !== '' && $rowCanon !== $assetCanon && $qoh >= $assetQty) {
                return 'unverified';
            }
        }
    }
    return 'ok';
}

/**
 * Build Firestore commit ops for a stockable site change: zero source rows, upsert dest,
 * create Transfer + movement ledger. Caller must already have validated canonical locations.
 *
 * @param list<array<string, mixed>> $allInventoryLevels
 * @return array{ok:bool, error:?string, operations:list<array<string,mixed>>}
 */
function am_inventory_build_site_change_operations(
    string $assetId,
    array $assetBefore,
    array $assetAfter,
    string $sourceCanon,
    string $destCanon,
    string $countryId,
    array $allInventoryLevels,
    array $locByAnyKey
): array {
    require_once __DIR__ . '/inventory_movements.php';
    if ($sourceCanon === '' || $destCanon === '') {
        return ['ok' => false, 'error' => 'Source and destination locations must resolve to canonical codes.', 'operations' => []];
    }

    $qty = max(0, (int)($assetAfter['quantity'] ?? $assetBefore['quantity'] ?? 0));
    $operations = [];
    $now = date('c');

    if ($sourceCanon !== $destCanon) {
        $sourceRows = am_inventory_matching_location_rows($assetId, $sourceCanon, $allInventoryLevels, $locByAnyKey, $countryId);
        foreach ($sourceRows as $row) {
            $rid = trim((string)($row['id'] ?? ''));
            if ($rid === '') {
                continue;
            }
            $operations[] = [
                'mode' => 'update',
                'collection' => 'am_core_inventory_levels',
                'id' => $rid,
                'data' => [
                    'location_id' => $sourceCanon,
                    'quantity_on_hand' => 0,
                    'quantity_allocated' => 0,
                    'updated_at' => $now,
                    'reconciliation_status' => 'ok',
                ],
            ];
        }
    }

    $destRows = am_inventory_matching_location_rows($assetId, $destCanon, $allInventoryLevels, $locByAnyKey, $countryId);
    $allocTotal = 0;
    foreach ($destRows as $row) {
        $allocTotal += (int)($row['quantity_allocated'] ?? 0);
    }
    $qohTarget = max($qty, $allocTotal);
    $keeper = !empty($destRows)
        ? am_inventory_pick_keeper_row($destRows, $destCanon, $locByAnyKey, array_merge($assetBefore, $assetAfter))
        : null;

    if ($keeper && !empty($keeper['id'])) {
        $operations[] = [
            'mode' => 'update',
            'collection' => 'am_core_inventory_levels',
            'id' => (string)$keeper['id'],
            'data' => [
                'location_id' => $destCanon,
                'quantity_on_hand' => $qohTarget,
                'quantity_allocated' => $allocTotal,
                'country_id' => $countryId,
                'updated_at' => $now,
                'reconciliation_status' => 'ok',
            ],
        ];
        foreach ($destRows as $dup) {
            $dupId = trim((string)($dup['id'] ?? ''));
            if ($dupId === '' || $dupId === (string)$keeper['id']) {
                continue;
            }
            $operations[] = [
                'mode' => 'delete',
                'collection' => 'am_core_inventory_levels',
                'id' => $dupId,
                'data' => [],
            ];
        }
    } else {
        $newId = function_exists('am_firestore_random_document_id')
            ? am_firestore_random_document_id()
            : bin2hex(random_bytes(10));
        $operations[] = [
            'mode' => 'create',
            'collection' => 'am_core_inventory_levels',
            'id' => $newId,
            'data' => [
                'asset_id' => $assetId,
                'location_id' => $destCanon,
                'country_id' => $countryId,
                'quantity_on_hand' => $qohTarget,
                'quantity_allocated' => $allocTotal,
                'created_at' => $now,
                'updated_at' => $now,
                'reconciliation_status' => 'ok',
            ],
        ];
    }

    if ($sourceCanon !== $destCanon && $qty > 0) {
        $eventId = 'site_change_' . preg_replace('/[^A-Za-z0-9_-]/', '_', $assetId)
            . '_' . substr(sha1($sourceCanon . '|' . $destCanon . '|' . $now), 0, 12);
        $txn = [
            'transaction_type' => 'Transfer',
            'asset_id' => $assetId,
            'quantity' => $qty,
            'from_location_id' => $sourceCanon,
            'to_location_id' => $destCanon,
            'performed_by' => (string)($_SESSION['user_id'] ?? ''),
            'device_type' => 'Desktop',
            'notes' => 'Site changed from ' . $sourceCanon . ' to ' . $destCanon,
            'transaction_date' => $now,
            'quantity_before' => $qty,
            'quantity_after' => $qty,
            'source_workflow' => 'asset_edit',
        ];
        $operations[] = [
            'mode' => 'create',
            'collection' => 'am_core_transactions',
            'id' => $eventId,
            'data' => $txn,
        ];
        if (function_exists('am_inventory_movement_from_transaction') && defined('AM_INVENTORY_MOVEMENTS_COLLECTION')) {
            $tx = $txn;
            $tx['id'] = $eventId;
            $operations[] = [
                'mode' => 'create',
                'collection' => AM_INVENTORY_MOVEMENTS_COLLECTION,
                'id' => 'mv_' . $eventId,
                'data' => am_inventory_movement_from_transaction($tx),
            ];
        }
    }

    return ['ok' => true, 'error' => null, 'operations' => $operations];
}
