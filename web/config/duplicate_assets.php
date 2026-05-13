<?php
/**
 * Duplicate asset detection by shared UID (asset_tag, qr_code_id, or legacy_tag) and merge.
 */
require_once __DIR__ . '/firestore.php';
require_once __DIR__ . '/it_am.php';
require_once __DIR__ . '/country_scope.php';

const AM_ASSET_PURGATORY_COLLECTION = 'am_core_asset_purgatory';
const AM_DUPLICATE_DISMISSALS_COLLECTION = 'am_core_duplicate_dismissals';
const AM_DUPLICATE_MERGE_REQUESTS_COLLECTION = 'am_core_duplicate_merge_requests';

/** Normalize UID for equality (trim, case-fold for ASCII tags). */
function am_duplicate_uid_normalize(string $s): string {
    $s = trim($s);
    if ($s === '') {
        return '';
    }
    return strtoupper($s);
}

/**
 * Ensure no other asset uses the same normalized value on asset_tag or qr_code_id.
 *
 * @param list<array<string, mixed>> $assets
 */
function am_duplicate_uid_field_unique_among_assets(
    ?string $excludeDocId,
    string $field,
    string $raw,
    array $assets,
): ?string {
    if ($field !== 'asset_tag' && $field !== 'qr_code_id') {
        return 'Invalid field for uniqueness check.';
    }
    $norm = am_duplicate_uid_normalize($raw);
    if ($field === 'qr_code_id' && $norm === '') {
        return null;
    }
    if ($norm === '') {
        return 'Asset tag cannot be empty.';
    }
    foreach ($assets as $a) {
        $id = (string)($a['id'] ?? $a['asset_id'] ?? '');
        if ($id === '') {
            continue;
        }
        if ($excludeDocId !== null && $excludeDocId !== '' && $id === $excludeDocId) {
            continue;
        }
        if (am_duplicate_uid_normalize((string)($a[$field] ?? '')) === $norm) {
            return 'Another item already uses this value (catalog id ' . $id . '). Choose a unique tag or QR id.';
        }
    }
    return null;
}

/**
 * Next sequential asset tag that does not collide with any row in $existingAssets (create flow).
 * Bumps the numeric suffix if the first candidate is already taken (race / partial scan / manual tags).
 */
function am_generate_unique_asset_tag(string $itemClass, string $countryCode, array $existingAssets): string {
    $tag = am_generate_asset_tag($itemClass, $countryCode, $existingAssets);
    for ($i = 0; $i < 5000; $i++) {
        if (am_duplicate_uid_field_unique_among_assets(null, 'asset_tag', $tag, $existingAssets) === null) {
            return $tag;
        }
        if (preg_match('/^(.+-)(\d{6})$/', $tag, $m)) {
            $tag = $m[1] . str_pad((string)((int)$m[2] + 1), 6, '0', STR_PAD_LEFT);
        } else {
            $tag .= 'X';
        }
    }
    return $tag . '-' . substr(bin2hex(random_bytes(3)), 0, 6);
}

/**
 * Heuristic richness: more populated fields and relations → higher score (keeper wins).
 *
 * @param array<string, mixed> $asset
 */
function am_asset_data_richness_score(array $asset): int {
    $score = 0;
    $textFields = [
        'name', 'description', 'serial_number', 'manufacturer', 'model',
        'purchase_date', 'purchase_price', 'current_value', 'warranty_expiry',
        'notes', 'location_id', 'category_id', 'country_id',
        'qr_code_id', 'asset_tag', 'legacy_tag', 'ugp_part_id',
        'condition_status', 'status', 'item_class',
    ];
    foreach ($textFields as $f) {
        $v = $asset[$f] ?? null;
        if ($v === null || $v === '') {
            continue;
        }
        if (is_string($v) && trim($v) === '') {
            continue;
        }
        $score += 3;
    }
    $q = (int)($asset['quantity'] ?? 0);
    if ($q > 1) {
        $score += 2;
    }
    return $score;
}

/**
 * Tie-break when scores match: prefer more recently updated, then lexicographically larger id.
 *
 * @param array<string, mixed> $a
 * @param array<string, mixed> $b
 */
function am_duplicate_compare_keepers(array $a, array $b): int {
    $sa = am_asset_data_richness_score($a);
    $sb = am_asset_data_richness_score($b);
    if ($sa !== $sb) {
        return $sb <=> $sa;
    }
    $ua = strtotime((string)($a['updated_at'] ?? $a['created_at'] ?? '')) ?: 0;
    $ub = strtotime((string)($b['updated_at'] ?? $b['created_at'] ?? '')) ?: 0;
    if ($ua !== $ub) {
        return $ub <=> $ua;
    }
    $ida = (string)($a['id'] ?? $a['asset_id'] ?? '');
    $idb = (string)($b['id'] ?? $b['asset_id'] ?? '');
    return strcmp($idb, $ida);
}

/**
 * Build duplicate groups: assets linked if they share any non-empty normalized UID on asset_tag, qr_code_id, or legacy_tag.
 *
 * @param list<array<string, mixed>> $assets
 * @return list<list<array<string, mixed>>>
 */
function am_duplicate_find_groups(array $assets): array {
    $byUid = [];
    foreach ($assets as $a) {
        $docId = (string)($a['id'] ?? $a['asset_id'] ?? '');
        if ($docId === '') {
            continue;
        }
        foreach (['asset_tag', 'qr_code_id', 'legacy_tag'] as $field) {
            $u = am_duplicate_uid_normalize((string)($a[$field] ?? ''));
            if ($u === '') {
                continue;
            }
            if (!isset($byUid[$u])) {
                $byUid[$u] = [];
            }
            $byUid[$u][$docId] = true;
        }
    }

    $parent = [];
    foreach ($assets as $a) {
        $id = (string)($a['id'] ?? $a['asset_id'] ?? '');
        if ($id !== '') {
            $parent[$id] = $id;
        }
    }

    $find = function (string $x) use (&$parent, &$find): string {
        if (!isset($parent[$x])) {
            return $x;
        }
        if ($parent[$x] !== $x) {
            $parent[$x] = $find($parent[$x]);
        }
        return $parent[$x];
    };

    $union = function (string $a, string $b) use (&$parent, $find): void {
        $ra = $find($a);
        $rb = $find($b);
        if ($ra !== $rb) {
            $parent[$ra] = $rb;
        }
    };

    foreach ($byUid as $ids) {
        $list = array_keys($ids);
        if (count($list) < 2) {
            continue;
        }
        $first = $list[0];
        for ($i = 1; $i < count($list); $i++) {
            $union($first, $list[$i]);
        }
    }

    $groups = [];
    $idToAsset = [];
    foreach ($assets as $a) {
        $id = (string)($a['id'] ?? $a['asset_id'] ?? '');
        if ($id !== '') {
            $idToAsset[$id] = $a;
        }
    }

    foreach ($idToAsset as $id => $a) {
        $r = $find($id);
        if (!isset($groups[$r])) {
            $groups[$r] = [];
        }
        $groups[$r][] = $a;
    }

    $out = [];
    foreach ($groups as $members) {
        if (count($members) < 2) {
            continue;
        }
        usort($members, fn($x, $y) => am_duplicate_compare_keepers($x, $y));
        $out[] = $members;
    }

    usort($out, fn($a, $b) => strcmp((string)($a[0]['name'] ?? ''), (string)($b[0]['name'] ?? '')));
    return $out;
}

/**
 * Recommended keeper: highest richness score (tie-break by compare).
 *
 * @param list<array<string, mixed>> $group
 * @return array<string, mixed>
 */
function am_duplicate_pick_recommended_keeper(array $group): array {
    $best = $group[0];
    foreach ($group as $a) {
        if (am_duplicate_compare_keepers($a, $best) < 0) {
            $best = $a;
        }
    }
    return $best;
}

/**
 * Merge loser into keeper: repoint inventory/allocations, optional purgatory snapshot, delete loser.
 *
 * @param 'purgatory_30d'|'immediate' $mode
 * @return array{ok: bool, error?: string, purgatory_id?: string}
 */
function am_duplicate_merge_loser_into_keeper(
    string $keeperDocId,
    string $loserDocId,
    string $mode,
    ?string $idTokenOverride = null
): array {
    $keeperDocId = trim($keeperDocId);
    $loserDocId = trim($loserDocId);
    if ($keeperDocId === '' || $loserDocId === '' || $keeperDocId === $loserDocId) {
        return ['ok' => false, 'error' => 'Invalid keeper or loser id.'];
    }
    if (!in_array($mode, ['purgatory_30d', 'immediate'], true)) {
        return ['ok' => false, 'error' => 'Invalid mode.'];
    }

    $keeper = am_firestore_get_document('am_core_assets', $keeperDocId, $idTokenOverride);
    $loser = am_firestore_get_document('am_core_assets', $loserDocId, $idTokenOverride);
    if (!$keeper || !$loser) {
        return ['ok' => false, 'error' => 'Could not load keeper or loser document.'];
    }

    $sk = am_asset_data_richness_score($keeper);
    $sl = am_asset_data_richness_score($loser);
    if ($sl > $sk) {
        return ['ok' => false, 'error' => 'Loser has a higher data richness score than keeper. Swap roles or enrich the keeper first.'];
    }
    if ($sl === $sk && am_duplicate_compare_keepers($loser, $keeper) < 0) {
        return ['ok' => false, 'error' => 'Scores tie; the recommended keeper is the other document. Use the suggested keeper as the merge target.'];
    }

    $uid = (string)($_SESSION['user_id'] ?? $_SESSION['firebase_uid'] ?? '');

    $cr = ['ok' => true, 'id' => null];
    if ($mode === 'purgatory_30d') {
        $purgeAfter = gmdate('c', time() + 30 * 86400);
        $arch = $loser;
        unset($arch['id']);
        $purg = [
            'archived_asset' => $arch,
            'original_asset_id' => $loserDocId,
            'keeper_asset_id' => $keeperDocId,
            'purge_after' => $purgeAfter,
            'status' => 'pending_purge',
            'merged_at' => gmdate('c'),
            'merged_by_uid' => $uid,
        ];
        $cr = am_firestore_create_document(AM_ASSET_PURGATORY_COLLECTION, $purg, null, $idTokenOverride);
        if (!$cr['ok']) {
            return ['ok' => false, 'error' => 'Purgatory write failed: ' . ($cr['error'] ?? '')];
        }
    }

    $phones = am_firestore_get_collection(AM_PHONE_REQUESTS_COLLECTION, 2000, $idTokenOverride);
    foreach ($phones as $pr) {
        if ((string)($pr['asset_id'] ?? '') !== $loserDocId) {
            continue;
        }
        $prid = (string)($pr['id'] ?? '');
        if ($prid !== '') {
            am_firestore_update_document(AM_PHONE_REQUESTS_COLLECTION, $prid, ['asset_id' => $keeperDocId], $idTokenOverride);
        }
    }

    $invLevels = am_firestore_get_collection('am_core_inventory_levels', 4000, $idTokenOverride);
    $loserLevels = array_values(array_filter($invLevels, fn($row) => (string)($row['asset_id'] ?? '') === $loserDocId));
    $keeperByLoc = [];
    foreach ($invLevels as $row) {
        if ((string)($row['asset_id'] ?? '') !== $keeperDocId) {
            continue;
        }
        $lid = (string)($row['location_id'] ?? '');
        $cid = (string)($row['country_id'] ?? '');
        $keeperByLoc[$lid . '|' . $cid] = $row;
    }

    foreach ($loserLevels as $row) {
        $invId = (string)($row['id'] ?? '');
        if ($invId === '') {
            continue;
        }
        $lid = (string)($row['location_id'] ?? '');
        $cid = (string)($row['country_id'] ?? '');
        $key = $lid . '|' . $cid;
        $qoh = (int)($row['quantity_on_hand'] ?? 0);
        $qa = (int)($row['quantity_allocated'] ?? 0);
        if (isset($keeperByLoc[$key])) {
            $k = $keeperByLoc[$key];
            $kid = (string)($k['id'] ?? '');
            if ($kid === '') {
                continue;
            }
            $patch = [
                'quantity_on_hand' => (int)($k['quantity_on_hand'] ?? 0) + $qoh,
                'quantity_allocated' => (int)($k['quantity_allocated'] ?? 0) + $qa,
            ];
            $up = am_firestore_update_document('am_core_inventory_levels', $kid, $patch, $idTokenOverride);
            if (!$up['ok']) {
                return ['ok' => false, 'error' => 'Inventory merge failed: ' . ($up['error'] ?? '')];
            }
            $keeperByLoc[$key]['quantity_on_hand'] = $patch['quantity_on_hand'];
            $keeperByLoc[$key]['quantity_allocated'] = $patch['quantity_allocated'];
            am_firestore_delete_document('am_core_inventory_levels', $invId, $idTokenOverride);
        } else {
            $up = am_firestore_update_document('am_core_inventory_levels', $invId, ['asset_id' => $keeperDocId], $idTokenOverride);
            if (!$up['ok']) {
                return ['ok' => false, 'error' => 'Could not repoint inventory level: ' . ($up['error'] ?? '')];
            }
        }
    }

    $allocs = am_firestore_get_collection('am_core_allocations', 4000, $idTokenOverride);
    foreach ($allocs as $al) {
        if ((string)($al['asset_id'] ?? '') !== $loserDocId) {
            continue;
        }
        $aid = (string)($al['id'] ?? '');
        if ($aid === '') {
            continue;
        }
        $up = am_firestore_update_document('am_core_allocations', $aid, ['asset_id' => $keeperDocId], $idTokenOverride);
        if (!$up['ok']) {
            return ['ok' => false, 'error' => 'Allocation repoint failed: ' . ($up['error'] ?? '')];
        }
    }

    $noteMerge = "\n[Merge " . gmdate('Y-m-d') . ": duplicate of " . $loserDocId . ' removed';
    if ($mode === 'purgatory_30d') {
        $noteMerge .= ', snapshot in purgatory]';
    } else {
        $noteMerge .= ']';
    }
    $notes = trim((string)($keeper['notes'] ?? '')) . $noteMerge;
    am_firestore_update_document('am_core_assets', $keeperDocId, [
        'notes' => $notes,
        'updated_at' => gmdate('c'),
    ], $idTokenOverride);

    $del = am_firestore_delete_document('am_core_assets', $loserDocId, $idTokenOverride);
    if (!$del['ok']) {
        return ['ok' => false, 'error' => 'Could not delete duplicate asset: ' . ($del['error'] ?? '')];
    }

    return [
        'ok' => true,
        'purgatory_id' => ($mode === 'purgatory_30d' && !empty($cr['id'])) ? (string)$cr['id'] : null,
    ];
}

/**
 * Delete purgatory documents whose purge_after is in the past (run from cron).
 *
 * @return array{ok: bool, deleted: int, error?: string}
 */
function am_duplicate_purge_expired_purgatory(?string $idTokenOverride = null): array {
    $token = am_firestore_resolve_id_token($idTokenOverride);
    if ($token === '') {
        return ['ok' => false, 'deleted' => 0, 'error' => 'Not authenticated'];
    }
    $rows = am_firestore_get_collection(AM_ASSET_PURGATORY_COLLECTION, 2000, $idTokenOverride);
    $now = time();
    $deleted = 0;
    foreach ($rows as $row) {
        $pid = (string)($row['id'] ?? '');
        if ($pid === '') {
            continue;
        }
        $pa = strtotime((string)($row['purge_after'] ?? '')) ?: 0;
        $st = (string)($row['status'] ?? '');
        if ($st !== 'pending_purge') {
            continue;
        }
        if ($pa > $now) {
            continue;
        }
        $d = am_firestore_delete_document(AM_ASSET_PURGATORY_COLLECTION, $pid, $idTokenOverride);
        if ($d['ok']) {
            $deleted++;
        }
    }
    return ['ok' => true, 'deleted' => $deleted];
}

/**
 * Stable key for a duplicate group (sorted asset doc ids).
 *
 * @param list<array<string, mixed>> $group
 */
function am_duplicate_group_key_from_assets(array $group): string {
    $ids = [];
    foreach ($group as $a) {
        $ids[] = (string)($a['id'] ?? $a['asset_id'] ?? '');
    }
    $ids = array_values(array_filter(array_unique($ids)));
    sort($ids);
    return sha1(implode('|', $ids));
}

/** @param list<array<string, mixed>> $group */
function am_duplicate_group_visible_in_country_scope(array $group, array $countries, array $locationById): bool {
    foreach ($group as $a) {
        if (!am_asset_passes_country_scope($a, $countries, $locationById)) {
            return false;
        }
    }
    return true;
}

/**
 * @return array<string, bool> group_key => true
 */
function am_duplicate_load_dismissed_group_keys(?string $idTokenOverride = null): array {
    $rows = am_firestore_get_collection(AM_DUPLICATE_DISMISSALS_COLLECTION, 3000, $idTokenOverride);
    $out = [];
    foreach ($rows as $row) {
        $k = trim((string)($row['group_key'] ?? ''));
        if ($k === '') {
            $k = (string)($row['id'] ?? '');
        }
        if ($k !== '') {
            $out[$k] = true;
        }
    }
    return $out;
}

/**
 * @param list<list<array<string, mixed>>> $groups
 * @param array<string, bool> $dismissedKeys
 * @return list<list<array<string, mixed>>>
 */
function am_duplicate_filter_groups_not_dismissed(array $groups, array $dismissedKeys): array {
    $out = [];
    foreach ($groups as $g) {
        $key = am_duplicate_group_key_from_assets($g);
        if (isset($dismissedKeys[$key])) {
            continue;
        }
        $out[] = $g;
    }
    return $out;
}

/**
 * @return array{ok: bool, error?: string}
 */
function am_duplicate_save_dismissal(
    string $groupKey,
    array $assetIds,
    string $dismissedByUid,
    string $reason,
    ?string $idTokenOverride = null
): array {
    $groupKey = trim($groupKey);
    if ($groupKey === '') {
        return ['ok' => false, 'error' => 'Missing group key.'];
    }
    $data = [
        'group_key' => $groupKey,
        'asset_ids' => array_values($assetIds),
        'dismissed_at' => gmdate('c'),
        'dismissed_by_uid' => $dismissedByUid,
        'reason' => $reason,
    ];
    $r = am_firestore_create_document(AM_DUPLICATE_DISMISSALS_COLLECTION, $data, $groupKey, $idTokenOverride);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => (string)($r['error'] ?? 'Save failed')];
    }
    return ['ok' => true];
}

/**
 * @return list<array<string, mixed>>
 */
function am_duplicate_merge_requests_pending(?string $idTokenOverride = null): array {
    $rows = am_firestore_get_collection(AM_DUPLICATE_MERGE_REQUESTS_COLLECTION, 2000, $idTokenOverride);
    $out = [];
    foreach ($rows as $row) {
        if ((string)($row['status'] ?? '') === 'pending') {
            $out[] = $row;
        }
    }
    usort($out, fn($a, $b) => strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? '')));
    return $out;
}

/**
 * @return array{ok: bool, error?: string, id?: string}
 */
function am_duplicate_create_merge_request(
    string $keeperId,
    string $loserId,
    string $notes,
    string $requestedByUid,
    string $mergeMode,
    ?string $idTokenOverride = null
): array {
    $keeperId = trim($keeperId);
    $loserId = trim($loserId);
    if ($keeperId === '' || $loserId === '' || $keeperId === $loserId) {
        return ['ok' => false, 'error' => 'Invalid keeper or loser.'];
    }
    if (!in_array($mergeMode, ['purgatory_30d', 'immediate'], true)) {
        $mergeMode = 'purgatory_30d';
    }
    $data = [
        'status' => 'pending',
        'keeper_id' => $keeperId,
        'loser_id' => $loserId,
        'notes' => $notes,
        'merge_mode' => $mergeMode,
        'requested_by_uid' => $requestedByUid,
        'created_at' => gmdate('c'),
    ];
    $r = am_firestore_create_document(AM_DUPLICATE_MERGE_REQUESTS_COLLECTION, $data, null, $idTokenOverride);
    if (!$r['ok']) {
        return ['ok' => false, 'error' => (string)($r['error'] ?? 'Save failed')];
    }
    return ['ok' => true, 'id' => (string)($r['id'] ?? '')];
}

/**
 * @return array{ok: bool, error?: string}
 */
function am_duplicate_complete_merge_request(string $requestDocId, ?string $idTokenOverride = null): array {
    $up = am_firestore_update_document(AM_DUPLICATE_MERGE_REQUESTS_COLLECTION, $requestDocId, [
        'status' => 'completed',
        'resolved_at' => gmdate('c'),
    ], $idTokenOverride);
    if (!$up['ok']) {
        return ['ok' => false, 'error' => (string)($up['error'] ?? '')];
    }
    return ['ok' => true];
}

/**
 * @return array{ok: bool, error?: string}
 */
function am_duplicate_cancel_merge_request(string $requestDocId, ?string $idTokenOverride = null): array {
    $up = am_firestore_update_document(AM_DUPLICATE_MERGE_REQUESTS_COLLECTION, $requestDocId, [
        'status' => 'cancelled',
        'resolved_at' => gmdate('c'),
    ], $idTokenOverride);
    if (!$up['ok']) {
        return ['ok' => false, 'error' => (string)($up['error'] ?? '')];
    }
    return ['ok' => true];
}

/**
 * Whether the user may act on a merge request (keeper asset in allowed countries).
 */
function am_duplicate_merge_request_visible_for_user(array $request, array $countries): bool {
    $kid = (string)($request['keeper_id'] ?? '');
    if ($kid === '') {
        return false;
    }
    $keeper = am_firestore_get_document('am_core_assets', $kid);
    if (!$keeper) {
        return false;
    }
    return am_user_may_access_country_id((string)am_resolve_asset_country_id($keeper, $countries), $countries);
}
