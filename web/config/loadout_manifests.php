<?php
/**
 * Load-out manifests (packing lists) — Firestore collection am_core_loadout_manifests.
 */

const AM_LOADOUT_COLLECTION = 'am_core_loadout_manifests';

function am_loadout_statuses(): array {
    return ['Draft', 'Packed', 'Shipped', 'Delivered', 'Cancelled'];
}

function am_next_loadout_manifest_number(array $existingManifests): string {
    $year = date('Y');
    $prefix = 'LO-' . $year . '-';
    $max = 0;
    foreach ($existingManifests as $m) {
        $n = (string)($m['manifest_number'] ?? '');
        if (!str_starts_with($n, $prefix)) {
            continue;
        }
        $rest = substr($n, strlen($prefix));
        if (preg_match('/^(\d+)$/', $rest, $mm)) {
            $max = max($max, (int)$mm[1]);
        }
    }
    return $prefix . str_pad((string)($max + 1), 4, '0', STR_PAD_LEFT);
}

/**
 * @param array<int, array<string, mixed>> $lines
 * @param array<string, array> $assetById Firestore asset docs keyed by document id
 * @return array<int, array<string, mixed>>
 */
function am_loadout_normalize_lines(array $lines, array $assetById): array {
    $out = [];
    $i = 0;
    foreach ($lines as $row) {
        if (!is_array($row)) {
            continue;
        }
        $assetId = trim((string)($row['asset_id'] ?? ''));
        if ($assetId === '') {
            continue;
        }
        $qty = (int)($row['quantity'] ?? 1);
        if ($qty < 1) {
            $qty = 1;
        }
        $snapName = '';
        $snapTag = '';
        if (isset($assetById[$assetId])) {
            $a = $assetById[$assetId];
            $snapName = (string)($a['name'] ?? '');
            $snapTag = (string)($a['asset_tag'] ?? $a['qr_code_id'] ?? '');
        }
        $out[] = [
            'line_no'       => ++$i,
            'asset_id'      => $assetId,
            'quantity'      => $qty,
            'notes'         => trim((string)($row['notes'] ?? '')),
            'name_snapshot' => $snapName !== '' ? $snapName : (string)($row['name_snapshot'] ?? ''),
            'tag_snapshot'  => $snapTag !== '' ? $snapTag : (string)($row['tag_snapshot'] ?? ''),
        ];
    }
    return $out;
}
