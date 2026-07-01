<?php
/**
 * UGP (ugp.1pwrafrica.com) part ↔ AM inventory alignment.
 * Canonical join: ugp_part_id on am_core_assets (no duplicate rows when descriptions differ).
 */
require_once __DIR__ . '/firestore.php';

/** Normalize for de-dup / fuzzy linking (lowercase, strip punctuation, collapse spaces). */
function am_ugp_normalize_key(string $s): string {
    $s = strtolower(trim($s));
    $s = preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s);
    return trim(preg_replace('/\s+/', ' ', $s));
}

function am_ugp_find_asset_by_part_id(string $ugpPartId, array $assets): ?array {
    $ugpPartId = trim($ugpPartId);
    if ($ugpPartId === '') {
        return null;
    }
    foreach ($assets as $a) {
        if (trim((string)($a['ugp_part_id'] ?? '')) === $ugpPartId) {
            return $a;
        }
    }
    return null;
}

/**
 * Inventory items in same country, no ugp_part_id yet, matching normalized name.
 *
 * @param array<int, array<string, mixed>> $assets
 * @param array<int, array<string, mixed>> $countries
 * @return list<array<string, mixed>>
 */
function am_ugp_name_match_candidates(string $normalizedNameKey, array $assets, string $countryId, array $countries): array {
    if ($normalizedNameKey === '' || $countryId === '') {
        return [];
    }
    $out = [];
    foreach ($assets as $a) {
        if ((string)($a['item_class'] ?? '') !== 'Inventory') {
            continue;
        }
        if (trim((string)($a['ugp_part_id'] ?? '')) !== '') {
            continue;
        }
        if (am_resolve_asset_country_id($a, $countries) !== $countryId) {
            continue;
        }
        if (am_ugp_normalize_key((string)($a['name'] ?? '')) === $normalizedNameKey) {
            $out[] = $a;
        }
    }
    return $out;
}

/**
 * @param array<int, array<string, mixed>> $categories
 */
function am_ugp_default_inventory_category_id(array $categories): string {
    foreach ($categories as $c) {
        $cc = strtoupper((string)($c['category_code'] ?? ''));
        $ic = (string)($c['item_class'] ?? '');
        if ($ic === 'Inventory' && (str_contains($cc, 'INV-SPR') || str_contains(strtolower((string)($c['category_name'] ?? '')), 'spare'))) {
            return (string)($c['category_id'] ?? $c['id'] ?? '');
        }
    }
    foreach ($categories as $c) {
        if ((string)($c['item_class'] ?? '') === 'Inventory') {
            return (string)($c['category_id'] ?? $c['id'] ?? '');
        }
    }
    return '';
}

/**
 * Upsert one UGP part into AM as an Inventory catalog row.
 *
 * Expected $part keys:
 * - ugp_part_id (required) — stable id from UGP
 * - name (required)
 * - description (optional)
 * - country_id (required) — pr_master_countries id
 * - quantity (optional, default 0)
 * - unit_of_measure (optional, default EA)
 * - location_id (optional)
 *
 * @return array{ok: bool, action: string, asset_id?: string, message?: string, candidates?: list<array<string, mixed>>}
 */
function am_ugp_sync_single_part(array $part, array $ctx): array {
    $countries = $ctx['countries'] ?? [];
    $categories = $ctx['categories'] ?? [];
    $allAssets = $ctx['all_assets'] ?? [];
    $token = $ctx['id_token_override'] ?? null;
    $linkOnName = (bool)($ctx['link_on_normalized_name'] ?? true);
    $dryRun = (bool)($ctx['dry_run'] ?? false);

    $ugpId = trim((string)($part['ugp_part_id'] ?? $part['id'] ?? ''));
    $name = trim((string)($part['name'] ?? ''));
    $countryId = trim((string)($part['country_id'] ?? ''));
    if ($ugpId === '' || $name === '' || $countryId === '') {
        return ['ok' => false, 'action' => 'error', 'message' => 'ugp_part_id, name, and country_id are required.'];
    }

    $existingByUgp = am_ugp_find_asset_by_part_id($ugpId, $allAssets);
    if ($existingByUgp !== null) {
        $docId = (string)($existingByUgp['asset_id'] ?? $existingByUgp['id'] ?? '');
        if ($docId === '') {
            return ['ok' => false, 'action' => 'error', 'message' => 'Linked asset has no id.'];
        }
        $patch = [
            'ugp_last_sync_at' => date('c'),
            'updated_at' => date('c'),
        ];
        $desc = trim((string)($part['description'] ?? ''));
        $curDesc = trim((string)($existingByUgp['description'] ?? ''));
        if ($desc !== '' && $curDesc === '') {
            $patch['description'] = $desc;
        } elseif ($desc !== '' && $curDesc !== '' && $desc !== $curDesc) {
            $patch['notes'] = am_ugp_merge_note((string)($existingByUgp['notes'] ?? ''), 'UGP description sync: ' . $desc);
        }
        if ($dryRun) {
            return ['ok' => true, 'action' => 'updated', 'asset_id' => $docId, 'message' => 'Would refresh metadata for existing UGP link.'];
        }
        $r = am_firestore_update_document('am_core_assets', $docId, $patch, $token);
        if (!$r['ok']) {
            return ['ok' => false, 'action' => 'error', 'message' => (string)($r['error'] ?? 'Update failed')];
        }
        return ['ok' => true, 'action' => 'updated', 'asset_id' => $docId, 'message' => 'Refreshed existing UGP-linked item.'];
    }

    $norm = am_ugp_normalize_key($name);
    $candidates = am_ugp_name_match_candidates($norm, $allAssets, $countryId, $countries);
    if (count($candidates) > 1) {
        return [
            'ok' => false,
            'action' => 'ambiguous',
            'message' => 'Multiple Inventory items match normalized name; resolve manually.',
            'candidates' => $candidates,
        ];
    }
    if (count($candidates) === 1 && $linkOnName) {
        $row = $candidates[0];
        $docId = (string)($row['asset_id'] ?? $row['id'] ?? '');
        if ($docId === '') {
            return ['ok' => false, 'action' => 'error', 'message' => 'Candidate asset missing id.'];
        }
        $patch = [
            'ugp_part_id' => $ugpId,
            'ugp_last_sync_at' => date('c'),
            'updated_at' => date('c'),
            'notes' => am_ugp_merge_note((string)($row['notes'] ?? ''), 'Linked from UGP part ' . $ugpId . ' by normalized name match.'),
        ];
        $desc = trim((string)($part['description'] ?? ''));
        if ($desc !== '' && trim((string)($row['description'] ?? '')) === '') {
            $patch['description'] = $desc;
        }
        if ($dryRun) {
            return ['ok' => true, 'action' => 'linked', 'asset_id' => $docId, 'message' => 'Would link ugp_part_id to existing Inventory row.'];
        }
        $r = am_firestore_update_document('am_core_assets', $docId, $patch, $token);
        if (!$r['ok']) {
            return ['ok' => false, 'action' => 'error', 'message' => (string)($r['error'] ?? 'Link failed')];
        }
        return ['ok' => true, 'action' => 'linked', 'asset_id' => $docId, 'message' => 'Linked UGP id to existing Inventory item (name match).'];
    }

    $catId = am_ugp_default_inventory_category_id($categories);
    if ($catId === '') {
        return ['ok' => false, 'action' => 'error', 'message' => 'No Inventory category found in pr_master_categories.'];
    }

    $ccode = '';
    foreach ($countries as $c) {
        if ((string)($c['country_id'] ?? $c['id'] ?? '') === $countryId) {
            $ccode = (string)($c['country_code'] ?? 'LSO');
            break;
        }
    }

    $qty = (int)($part['quantity'] ?? 0);
    if ($qty < 0) {
        $qty = 0;
    }
    $uom = trim((string)($part['unit_of_measure'] ?? 'EA'));
    if ($uom === '') {
        $uom = 'EA';
    }

    $assetTag = am_generate_asset_tag('Inventory', $ccode, $allAssets);

    $uid = (string)($ctx['created_by'] ?? '');
    $data = [
        'name' => $name,
        'description' => trim((string)($part['description'] ?? '')),
        'item_class' => 'Inventory',
        'category_id' => $catId,
        'country_id' => $countryId,
        'location_id' => trim((string)($part['location_id'] ?? '')),
        'condition_status' => 'New',
        'status' => 'Available',
        'quantity' => $qty,
        'unit_of_measure' => $uom,
        'ugp_part_id' => $ugpId,
        'ugp_last_sync_at' => date('c'),
        'source' => 'UGP',
        'notes' => trim((string)($part['notes'] ?? '')),
        'asset_tag' => $assetTag,
        'qr_code_id' => '',
        'created_at' => date('c'),
        'updated_at' => date('c'),
        'created_by' => $uid,
    ];

    if ($dryRun) {
        return ['ok' => true, 'action' => 'created', 'message' => 'Would create new Inventory row with tag ' . $assetTag];
    }

    $result = am_firestore_create_document('am_core_assets', $data, null, $token);
    if (!$result['ok']) {
        return ['ok' => false, 'action' => 'error', 'message' => (string)($result['error'] ?? 'Create failed')];
    }
    return [
        'ok' => true,
        'action' => 'created',
        'asset_id' => (string)($result['id'] ?? ''),
        'asset_tag' => $assetTag,
        'message' => 'Created Inventory item for UGP part.',
    ];
}

function am_ugp_merge_note(string $existing, string $line): string {
    $existing = trim($existing);
    $line = trim($line);
    if ($line === '') {
        return $existing;
    }
    if ($existing === '') {
        return $line;
    }
    if (str_contains($existing, $line)) {
        return $existing;
    }
    return $existing . "\n\n" . $line;
}
