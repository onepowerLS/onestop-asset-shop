<?php
/**
 * Roll up bulk / serialized stockable lines (Material, Consumable, Inventory) for display.
 * Fixed assets are never aggregated — each tag stays its own row.
 *
 * Group key: ugp_part_id + location + country, else category + normalized name + location + country + class.
 */
require_once __DIR__ . '/ugp_parts.php';

/** @return list<string> */
function am_inventory_stockable_classes(): array {
    return ['Material', 'Consumable', 'Inventory'];
}

function am_inventory_aggregate_eligible(array $asset): bool {
    return in_array((string)($asset['item_class'] ?? ''), am_inventory_stockable_classes(), true);
}

/**
 * Stable key for rolling up duplicate catalog/stock lines at the same site.
 *
 * @param array<string, mixed> $asset
 */
function am_inventory_roll_group_key(array $asset, string $countryId, string $locationId): string {
    if (!am_inventory_aggregate_eligible($asset)) {
        $id = (string)($asset['asset_id'] ?? $asset['id'] ?? '');
        return 'unit:' . $id;
    }
    $ugp = trim((string)($asset['ugp_part_id'] ?? ''));
    if ($ugp !== '') {
        return 'ugp|' . $ugp . '|' . $locationId . '|' . $countryId;
    }
    $cat = (string)($asset['category_id'] ?? '');
    $nameKey = am_ugp_normalize_key((string)($asset['name'] ?? ''));
    $cls = (string)($asset['item_class'] ?? '');
    return 'nm|' . $cat . '|' . $nameKey . '|' . $locationId . '|' . $countryId . '|' . $cls;
}

/**
 * Prefer asset_tag, then legacy_tag, then qr_code_id for human-readable UID lists.
 */
function am_inventory_primary_uid(array $asset): string {
    foreach (['asset_tag', 'legacy_tag', 'qr_code_id'] as $f) {
        $t = trim((string)($asset[$f] ?? ''));
        if ($t !== '') {
            return $t;
        }
    }
    return '';
}

/**
 * Natural-sort unique tags and show a compact range or list.
 *
 * @param list<array<string, mixed>> $assets
 */
function am_inventory_format_uid_range(array $assets): string {
    $tags = [];
    foreach ($assets as $a) {
        $u = am_inventory_primary_uid($a);
        if ($u !== '') {
            $tags[$u] = true;
        }
    }
    $list = array_keys($tags);
    $n = count($list);
    if ($n === 0) {
        return '—';
    }
    if ($n === 1) {
        return $list[0];
    }
    sort($list, SORT_NATURAL | SORT_FLAG_CASE);
    if ($n <= 4) {
        return implode(', ', $list);
    }
    return $list[0] . ' … ' . $list[$n - 1] . ' (' . $n . ' UIDs)';
}

/**
 * @param list<array{inv: array<string, mixed>, asset: array<string, mixed>, country: array, category: array, location: array, is_low: bool, country_id_resolved?: string}> $stockItems
 * @return list<array{type: 'group'|'single', rows: list<array>, label?: string, name?: string, category?: array, location?: array, country?: array, cls?: string, qoh?: int, alloc?: int, reorder?: mixed, is_low?: bool, uid_summary?: string, line_count?: int, representative_id?: string}>
 */
function am_inventory_aggregate_stock_items(array $stockItems): array {
    $bucketMap = [];
    $bucketOrder = [];

    foreach ($stockItems as $idx => $si) {
        $asset = $si['asset'];
        if ($asset === []) {
            $k = '_orphan_' . $idx;
            $bucketMap[$k] = [$si];
            $bucketOrder[] = $k;
            continue;
        }
        if (!am_inventory_aggregate_eligible($asset)) {
            $aid = (string)($asset['asset_id'] ?? $asset['id'] ?? '');
            $k = '_unit_' . $aid;
            $bucketMap[$k] = [$si];
            $bucketOrder[] = $k;
            continue;
        }
        $inv = $si['inv'];
        $cid = (string)($inv['country_id'] ?? '');
        if ($cid === '' && isset($si['country_id_resolved'])) {
            $cid = (string)$si['country_id_resolved'];
        }
        $lid = (string)($inv['location_id'] ?? '');
        $key = am_inventory_roll_group_key($asset, $cid, $lid);
        if (!isset($bucketMap[$key])) {
            $bucketMap[$key] = [];
            $bucketOrder[] = $key;
        }
        $bucketMap[$key][] = $si;
    }

    $seen = [];
    $orderedKeys = [];
    foreach ($bucketOrder as $k) {
        if (isset($seen[$k])) {
            continue;
        }
        $seen[$k] = true;
        $orderedKeys[] = $k;
    }

    $out = [];
    foreach ($orderedKeys as $key) {
        $rows = $bucketMap[$key];
        if (str_starts_with($key, '_orphan_') || str_starts_with($key, '_unit_')) {
            $out[] = ['type' => 'single', 'rows' => $rows];
            continue;
        }
        if (count($rows) === 1) {
            $out[] = ['type' => 'single', 'rows' => $rows];
            continue;
        }

        $qoh = 0;
        $alloc = 0;
        $reorder = null;
        $isLow = false;
        $assets = [];
        foreach ($rows as $r) {
            $inv = $r['inv'];
            $a = $r['asset'];
            $qoh += (int)($inv['quantity_on_hand'] ?? 0);
            $alloc += (int)($inv['quantity_allocated'] ?? 0);
            $rl = $inv['reorder_level'] ?? null;
            if ($rl !== null && $rl !== '') {
                $reorder = $reorder === null ? (int)$rl : max((int)$reorder, (int)$rl);
            }
            if (!empty($r['is_low'])) {
                $isLow = true;
            }
            if ($a !== []) {
                $assets[] = $a;
            }
        }
        $first = $rows[0];
        $asset = $first['asset'];
        $repId = (string)($asset['id'] ?? $asset['asset_id'] ?? '');
        $uidSummary = am_inventory_format_uid_range($assets);

        $out[] = [
            'type' => 'group',
            'rows' => $rows,
            'label' => (string)($asset['name'] ?? ''),
            'name' => (string)($asset['name'] ?? ''),
            'category' => $first['category'],
            'location' => $first['location'],
            'country' => $first['country'],
            'cls' => (string)($asset['item_class'] ?? ''),
            'qoh' => $qoh,
            'alloc' => $alloc,
            'reorder' => $reorder,
            'is_low' => $isLow,
            'uid_summary' => $uidSummary,
            'line_count' => count($rows),
            'representative_id' => $repId,
        ];
    }
    return $out;
}

/**
 * Group catalog assets (stockable only) for registry rollup; fixed assets returned as single-row groups.
 *
 * @param list<array<string, mixed>> $assets Enriched asset rows (country_name, etc.)
 * @param array<int, array<string, mixed>> $countries
 * @return list<array{type: 'group'|'single', line_count: int, qty_sum: int, uid_summary: string, representative_id: string, assets: list<array<string, mixed>>, name: string, cls: string, category_name: string, country_code: string, location_name: string}>
 */
function am_inventory_aggregate_catalog_rows(array $assets, array $countries): array {
    $buckets = [];
    foreach ($assets as $asset) {
        if (!am_inventory_aggregate_eligible($asset)) {
            $buckets['unit:' . ($asset['asset_id'] ?? $asset['id'] ?? '')] = [$asset];
            continue;
        }
        $cid = am_resolve_asset_country_id($asset, $countries);
        $lid = (string)($asset['location_id'] ?? '');
        $key = am_inventory_roll_group_key($asset, $cid, $lid);
        if (!isset($buckets[$key])) {
            $buckets[$key] = [];
        }
        $buckets[$key][] = $asset;
    }

    $out = [];
    foreach ($buckets as $key => $groupAssets) {
        $first = $groupAssets[0];
        $repId = (string)($first['asset_id'] ?? $first['id'] ?? '');
        if (str_starts_with($key, 'unit:') || count($groupAssets) === 1) {
            $out[] = [
                'type' => 'single',
                'line_count' => 1,
                'qty_sum' => (int)($first['quantity'] ?? 1),
                'uid_summary' => am_inventory_format_uid_range($groupAssets),
                'representative_id' => $repId,
                'assets' => $groupAssets,
                'name' => (string)($first['name'] ?? ''),
                'cls' => (string)($first['item_class'] ?? ''),
                'category_name' => (string)($first['category_name'] ?? ''),
                'country_code' => (string)($first['country_code'] ?? ''),
                'location_name' => (string)($first['location_name'] ?? ''),
            ];
            continue;
        }
        $qtySum = 0;
        foreach ($groupAssets as $a) {
            $qtySum += (int)($a['quantity'] ?? 1);
        }
        $out[] = [
            'type' => 'group',
            'line_count' => count($groupAssets),
            'qty_sum' => $qtySum,
            'uid_summary' => am_inventory_format_uid_range($groupAssets),
            'representative_id' => $repId,
            'assets' => $groupAssets,
            'name' => (string)($first['name'] ?? ''),
            'cls' => (string)($first['item_class'] ?? ''),
            'category_name' => (string)($first['category_name'] ?? ''),
            'country_code' => (string)($first['country_code'] ?? ''),
            'location_name' => (string)($first['location_name'] ?? ''),
        ];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}
