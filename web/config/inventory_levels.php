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

function am_canonical_location_code(string $rawId, array $locByAnyKey): string {
    if ($rawId === '') {
        return '';
    }
    $resolved = $locByAnyKey[$rawId] ?? [];
    return (string)($resolved['location_code'] ?? $rawId);
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
        $canon = am_canonical_location_code((string)($row['location_id'] ?? ''), $locByAnyKey);
        $countryId = (string)($row['country_id'] ?? '');
        $key = $canon . '|' . ($countryId !== '' ? $countryId : '_');
        if (!isset($groups[$key])) {
            $groups[$key] = [];
        }
        $groups[$key][] = $row;
    }

    $merged = [];
    foreach ($groups as $group) {
        $canon = am_canonical_location_code((string)($group[0]['location_id'] ?? ''), $locByAnyKey);
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
        if ($invLocCanon !== $targetLocCanonical) {
            continue;
        }
        $rows[] = $inv;
    }
    return $rows;
}
