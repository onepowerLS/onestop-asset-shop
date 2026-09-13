<?php
/**
 * Pure builders for the inventory read API (Brief 01).
 * Endpoints load Firestore then call these — keep them side-effect free so tests can run.
 */

require_once __DIR__ . '/inventory_levels.php';
require_once __DIR__ . '/inventory_aggregate.php';
require_once __DIR__ . '/inventory_movements.php';

/** @return list<string> */
function am_inventory_read_stockable_classes(): array {
    return am_inventory_stockable_classes();
}

/**
 * Whether a location / inventory row matches a site filter (MAS, LSO-MAS, doc id).
 *
 * @param array<string, mixed> $loc
 */
function am_inventory_read_site_matches(string $filter, string $locationId, array $loc = []): bool {
    $filter = strtoupper(trim($filter));
    if ($filter === '') {
        return true;
    }
    $candidates = [
        strtoupper(trim($locationId)),
        strtoupper(trim((string)($loc['id'] ?? ''))),
        strtoupper(trim((string)($loc['location_code'] ?? ''))),
        strtoupper(trim((string)($loc['location_id'] ?? ''))),
        strtoupper(trim((string)($loc['code'] ?? ''))),
    ];
    foreach ($candidates as $c) {
        if ($c === '') {
            continue;
        }
        if ($c === $filter) {
            return true;
        }
        if (str_ends_with($c, '-' . $filter)) {
            return true;
        }
    }
    return false;
}

/**
 * Canonical site id for API consumers: prefer location_code (LSO-MAS), else raw id.
 *
 * @param array<string, mixed> $loc
 */
function am_inventory_read_site_id(string $locationId, array $loc = []): string {
    $code = trim((string)($loc['location_code'] ?? ''));
    if ($code !== '') {
        return $code;
    }
    $id = trim((string)($loc['id'] ?? $locationId));
    return $id !== '' ? $id : $locationId;
}

/**
 * Public part id: UGP kebab-case when mapped, otherwise the AM asset document id.
 *
 * @param array<string, mixed> $asset
 */
function am_inventory_read_part_id(array $asset): string {
    $ugp = trim((string)($asset['ugp_part_id'] ?? ''));
    if ($ugp !== '') {
        return $ugp;
    }
    return (string)($asset['asset_id'] ?? $asset['id'] ?? '');
}

/**
 * @param list<array<string, mixed>> $levels
 * @param array<string, array<string, mixed>> $assetById
 * @param array<string, array<string, mixed>> $locByAnyKey
 * @param array<string, array<string, mixed>> $categoryById
 * @return list<array<string, mixed>>
 */
function am_inventory_read_build_positions(
    array $levels,
    array $assetById,
    array $locByAnyKey,
    array $categoryById = [],
    string $siteFilter = '',
    string $storeFilter = '',
    string $partFilter = '',
    string $categoryFilter = '',
    string $updatedSince = ''
): array {
    $deduped = am_inventory_dedupe_all_levels($levels, $locByAnyKey, $assetById);
    $sinceTs = $updatedSince !== '' ? strtotime($updatedSince) : false;

    // Pre-compute reconciliation status per asset from raw (pre-dedupe) levels.
    $rawByAsset = [];
    foreach ($levels as $inv) {
        $aid = (string)($inv['asset_id'] ?? '');
        if ($aid === '') {
            continue;
        }
        if (!isset($rawByAsset[$aid])) {
            $rawByAsset[$aid] = [];
        }
        $rawByAsset[$aid][] = $inv;
    }
    $statusByAsset = [];
    foreach ($rawByAsset as $aid => $rows) {
        $asset = $assetById[$aid] ?? [];
        $statusByAsset[$aid] = am_inventory_reconciliation_status_for_asset($asset, $rows, $locByAnyKey);
    }

    $out = [];
    foreach ($deduped as $inv) {
        $aid = (string)($inv['asset_id'] ?? '');
        $asset = $assetById[$aid] ?? [];
        $locId = (string)($inv['location_id'] ?? '');
        $loc = $locByAnyKey[$locId] ?? [];
        if ($siteFilter !== '' && !am_inventory_read_site_matches($siteFilter, $locId, $loc)) {
            continue;
        }
        if ($storeFilter !== '' && !am_inventory_read_site_matches($storeFilter, $locId, $loc)) {
            continue;
        }
        $partId = am_inventory_read_part_id($asset);
        if ($partFilter !== '' && $partId !== $partFilter && $aid !== $partFilter) {
            continue;
        }
        $catId = (string)($asset['category_id'] ?? '');
        $cat = $categoryById[$catId] ?? [];
        $catName = (string)($cat['category_name'] ?? $cat['name'] ?? $catId);
        if ($categoryFilter !== '' && strcasecmp($catName, $categoryFilter) !== 0 && strcasecmp($catId, $categoryFilter) !== 0) {
            continue;
        }
        $asOf = (string)($inv['updated_at'] ?? $inv['last_counted_at'] ?? '');
        if ($sinceTs !== false) {
            $t = strtotime($asOf);
            if ($t !== false && $t < $sinceTs) {
                continue;
            }
        }
        $onHand = (int)($inv['quantity_on_hand'] ?? 0);
        $allocated = (int)($inv['quantity_allocated'] ?? 0);
        $available = $onHand - $allocated;
        $siteId = am_inventory_read_site_id($locId, $loc);
        $recon = $statusByAsset[$aid] ?? 'ok';
        // Row-level unresolvable location overrides asset status for this position.
        if ($locId !== '' && am_canonical_location_code($locId, $locByAnyKey) === '' && !isset($locByAnyKey[$locId])) {
            $recon = 'unresolvable_location';
        }
        $out[] = [
            'part_id' => $partId,
            'part_name' => (string)($asset['name'] ?? ''),
            'category' => $catName,
            'store_id' => $siteId,
            'site_id' => $siteId,
            'qty_on_hand' => $onHand,
            'qty_allocated' => $allocated,
            'qty_available' => $available,
            'unit' => (string)($asset['unit_of_measure'] ?? 'EA'),
            'as_of' => $asOf !== '' ? $asOf : gmdate('c'),
            'last_movement_at' => (string)($inv['last_counted_at'] ?? $asOf),
            'asset_id' => $aid,
            'ugp_part_id' => trim((string)($asset['ugp_part_id'] ?? '')) ?: null,
            'reconciliation_status' => $recon,
        ];
    }
    usort($out, static function ($a, $b) {
        return strcmp((string)$a['site_id'], (string)$b['site_id'])
            ?: strcmp((string)$a['part_name'], (string)$b['part_name']);
    });
    return $out;
}

/**
 * Employee check-outs plus reserved dispatch lines.
 *
 * @param list<array<string, mixed>> $allocations
 * @param list<array<string, mixed>> $requests
 * @param array<string, array<string, mixed>> $assetById
 * @param array<string, array<string, mixed>> $locByAnyKey
 * @return list<array<string, mixed>>
 */
function am_inventory_read_build_allocations(
    array $allocations,
    array $requests,
    array $assetById,
    array $locByAnyKey,
    string $siteFilter = '',
    string $statusFilter = ''
): array {
    $out = [];
    foreach ($allocations as $row) {
        $aid = (string)($row['asset_id'] ?? '');
        $asset = $assetById[$aid] ?? [];
        $locId = (string)($asset['location_id'] ?? $row['location_id'] ?? '');
        $loc = $locByAnyKey[$locId] ?? [];
        if ($siteFilter !== '' && !am_inventory_read_site_matches($siteFilter, $locId, $loc)) {
            continue;
        }
        $rawStatus = strtolower((string)($row['status'] ?? 'Active'));
        $status = match ($rawStatus) {
            'returned' => 'returned',
            'overdue', 'active' => 'reserved',
            default => 'reserved',
        };
        if ($statusFilter !== '' && strcasecmp($status, $statusFilter) !== 0) {
            continue;
        }
        $out[] = [
            'allocation_id' => (string)($row['id'] ?? ''),
            'part_id' => am_inventory_read_part_id($asset),
            'qty' => max(1, (int)($row['quantity'] ?? 1)),
            'site_id' => am_inventory_read_site_id($locId, $loc),
            'work_package_id' => null,
            'allocated_at' => (string)($row['allocation_date'] ?? $row['created_at'] ?? ''),
            'allocated_by' => (string)($row['allocated_by'] ?? ''),
            'status' => $status,
            'asset_id' => $aid,
            'source' => 'am_core_allocations',
        ];
    }

    foreach ($requests as $req) {
        $reqStatus = (string)($req['status'] ?? '');
        $payload = is_array($req['payload'] ?? null) ? $req['payload'] : [];
        $items = $payload['line_items'] ?? $req['line_items'] ?? [];
        if (!is_array($items)) {
            continue;
        }
        $dest = trim((string)($payload['site_code'] ?? $req['site_code'] ?? $req['destination_site_id'] ?? ''));
        $loc = $locByAnyKey[$dest] ?? [];
        if ($siteFilter !== '' && !am_inventory_read_site_matches($siteFilter, $dest, $loc)) {
            continue;
        }
        foreach ($items as $idx => $li) {
            if (!is_array($li)) {
                continue;
            }
            $aid = (string)($li['asset_id'] ?? '');
            $asset = $assetById[$aid] ?? [];
            $qty = (int)($li['allocated_qty'] ?? $li['quantity'] ?? 0);
            if ($qty < 1) {
                continue;
            }
            $lineStatus = match ($reqStatus) {
                'Approved' => 'reserved',
                'Fulfilled' => 'issued',
                'Cancelled' => 'returned',
                default => '',
            };
            if ($lineStatus === '') {
                continue;
            }
            if ($statusFilter !== '' && strcasecmp($lineStatus, $statusFilter) !== 0) {
                continue;
            }
            $out[] = [
                'allocation_id' => (string)($req['id'] ?? '') . ':' . $idx,
                'part_id' => am_inventory_read_part_id($asset !== [] ? $asset : $li),
                'qty' => $qty,
                'site_id' => am_inventory_read_site_id($dest, $loc),
                'work_package_id' => null,
                'allocated_at' => (string)($req['updated_at'] ?? $req['created_at'] ?? ''),
                'allocated_by' => (string)($req['created_by'] ?? ''),
                'status' => $lineStatus,
                'asset_id' => $aid,
                'source' => 'dispatch_request',
            ];
        }
    }

    usort($out, static function ($a, $b) {
        return strcmp((string)$b['allocated_at'], (string)$a['allocated_at']);
    });
    return $out;
}

/**
 * @param list<array<string, mixed>> $assets
 * @param array<string, array<string, mixed>> $categoryById
 * @return list<array<string, mixed>>
 */
/**
 * @param list<array<string, mixed>> $assets
 * @param array<string, array<string, mixed>> $categoryById
 * @param array<string, array<string, mixed>> $definitionById
 * @param array<string, list<array<string, mixed>>> $openTasksByAsset
 * @return list<array<string, mixed>>
 */
function am_inventory_read_build_parts(
    array $assets,
    array $categoryById = [],
    bool $unmappedOnly = false,
    array $definitionById = [],
    array $openTasksByAsset = []
): array {
    if (!function_exists('am_catalogue_status_for_asset')) {
        require_once __DIR__ . '/part_definitions.php';
    }
    $out = [];
    foreach ($assets as $asset) {
        $cls = (string)($asset['item_class'] ?? '');
        if (!in_array($cls, am_inventory_read_stockable_classes(), true)) {
            continue;
        }
        $ugp = trim((string)($asset['ugp_part_id'] ?? ''));
        if ($unmappedOnly && $ugp !== '') {
            continue;
        }
        $catId = (string)($asset['category_id'] ?? '');
        $cat = $categoryById[$catId] ?? [];
        $status = (string)($asset['status'] ?? '');
        $active = !in_array($status, ['Retired', 'WrittenOff', 'Missing'], true);
        $aid = (string)($asset['asset_id'] ?? $asset['id'] ?? '');
        $defId = trim((string)($asset['definition_id'] ?? ''));
        $definition = $defId !== '' ? ($definitionById[$defId] ?? null) : null;
        if ($definition === null && $defId !== '') {
            $definition = ['id' => $defId];
        }
        $catalogue = am_catalogue_status_for_asset($asset, $definition, $openTasksByAsset[$aid] ?? []);
        // Prefer published ugp from definition when asset lacks one
        if ($ugp === '' && !empty($catalogue['definition_id']) && $definition) {
            $ugp = trim((string)($definition['ugp_part_id'] ?? ''));
        }
        $out[] = [
            'part_id' => am_inventory_read_part_id(array_merge($asset, $ugp !== '' ? ['ugp_part_id' => $ugp] : [])),
            'name' => (string)($asset['name'] ?? ''),
            'category' => (string)($cat['category_name'] ?? $cat['name'] ?? $catId),
            'unit' => (string)($asset['unit_of_measure'] ?? 'EA'),
            'active' => $active,
            'ugp_part_id' => $ugp !== '' ? $ugp : null,
            'asset_id' => $aid,
            'asset_tag' => (string)($asset['asset_tag'] ?? ''),
            'legacy_tag' => (string)($asset['legacy_tag'] ?? ''),
            'item_class' => $cls,
            'definition_id' => $catalogue['definition_id'],
            'classification' => $catalogue['classification'],
            'forecast_ready' => $catalogue['forecast_ready'],
            'catalogue_status' => $catalogue['catalogue_status'],
            'catalogue_reason' => $catalogue['catalogue_reason'],
        ];
    }
    usort($out, static fn($a, $b) => strcasecmp((string)$a['name'], (string)$b['name']));
    return $out;
}

/**
 * @param list<array<string, mixed>> $ledgerRows
 * @param list<array<string, mixed>> $transactions
 * @param array<string, array<string, mixed>> $assetById
 * @param array<string, array<string, mixed>> $locByAnyKey
 * @return list<array<string, mixed>>
 */
function am_inventory_read_build_movements(
    array $ledgerRows,
    array $transactions,
    array $assetById,
    array $locByAnyKey,
    string $siteFilter = '',
    string $partFilter = '',
    string $typeFilter = '',
    string $occurredSince = ''
): array {
    $byId = [];
    foreach ($ledgerRows as $row) {
        $m = am_inventory_movement_from_ledger_row($row);
        $id = (string)$m['movement_id'];
        if ($id !== '') {
            $byId[$id] = $m;
        }
    }
    foreach ($transactions as $tx) {
        $m = am_inventory_movement_from_transaction($tx);
        $id = (string)$m['movement_id'];
        if ($id === '' || isset($byId[$id])) {
            continue;
        }
        $byId[$id] = $m;
    }

    $sinceTs = $occurredSince !== '' ? strtotime($occurredSince) : false;
    $out = [];
    foreach ($byId as $m) {
        $aid = (string)($m['asset_id'] ?? '');
        $asset = $assetById[$aid] ?? [];
        if ($asset !== []) {
            $m['part_id'] = am_inventory_read_part_id($asset);
        }
        $site = (string)$m['site_id'];
        $loc = $locByAnyKey[$site] ?? $locByAnyKey[(string)$m['to_store']] ?? $locByAnyKey[(string)$m['from_store']] ?? [];
        if ($siteFilter !== '' && !am_inventory_read_site_matches($siteFilter, $site, $loc)
            && !am_inventory_read_site_matches($siteFilter, (string)$m['from_store'], $loc)
            && !am_inventory_read_site_matches($siteFilter, (string)$m['to_store'], $loc)) {
            continue;
        }
        if ($partFilter !== '' && $m['part_id'] !== $partFilter && $aid !== $partFilter) {
            continue;
        }
        if ($typeFilter !== '' && strcasecmp((string)$m['movement_type'], $typeFilter) !== 0) {
            continue;
        }
        if ($sinceTs !== false) {
            $t = strtotime((string)$m['occurred_at']);
            if ($t !== false && $t < $sinceTs) {
                continue;
            }
        }
        $out[] = $m;
    }
    usort($out, static function ($a, $b) {
        return strcmp((string)$b['occurred_at'], (string)$a['occurred_at']);
    });
    return $out;
}

/**
 * Forecast-shaped loadout from an am_core_loadout_manifests document.
 *
 * @param array<string, mixed> $manifest
 * @param array<string, array<string, mixed>> $assetById
 * @return array<string, mixed>
 */
function am_inventory_read_build_loadout(array $manifest, array $assetById = []): array {
    $lines = [];
    $raw = $manifest['lines'] ?? $manifest['line_items'] ?? [];
    if (is_array($raw)) {
        foreach ($raw as $row) {
            if (!is_array($row)) {
                continue;
            }
            $aid = (string)($row['asset_id'] ?? '');
            $asset = $assetById[$aid] ?? [];
            $lines[] = [
                'part_id' => am_inventory_read_part_id($asset !== [] ? $asset : $row),
                'qty' => (int)($row['quantity'] ?? $row['qty'] ?? 0),
                'asset_id' => $aid,
            ];
        }
    }
    $status = (string)($manifest['status'] ?? '');
    return [
        'loadout_id' => (string)($manifest['id'] ?? $manifest['manifest_number'] ?? ''),
        'site_id' => (string)($manifest['destination_site_id'] ?? ''),
        'crew_id' => $manifest['crew_id'] ?? null,
        'dispatched_at' => (string)($manifest['shipped_at'] ?? (($status === 'Shipped' || $status === 'Delivered') ? ($manifest['updated_at'] ?? '') : '')),
        'received_at' => (string)($manifest['delivered_at'] ?? ($status === 'Delivered' ? ($manifest['updated_at'] ?? '') : '')),
        'status' => $status,
        'lines' => $lines,
        'manifest_number' => (string)($manifest['manifest_number'] ?? ''),
        'title' => (string)($manifest['title'] ?? ''),
        'trip_id' => (string)($manifest['trip_id'] ?? ''),
    ];
}
