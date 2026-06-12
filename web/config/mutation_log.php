<?php
/**
 * Full mutation audit: one Firestore row per successful AM write (create / update / delete).
 * Hooked from am_firestore_* in firestore.php after that file is fully loaded.
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/country_scope.php';

const AM_CORE_MUTATION_LOGS_COLLECTION = 'am_core_mutation_logs';

function am_mutation_log_enabled(): bool {
    $v = strtolower(trim((string)am_env('AM_MUTATION_LOG_ENABLED', 'true')));
    return in_array($v, ['1', 'true', 'yes', 'on'], true);
}

function am_mutation_log_should_record(string $targetCollection): bool {
    return $targetCollection !== AM_CORE_MUTATION_LOGS_COLLECTION;
}

function am_mutation_log_countries(): array {
    static $c = null;
    if ($c !== null) {
        return $c;
    }
    $c = am_firestore_get_collection('pr_master_countries', 200);
    return is_array($c) ? $c : [];
}

/** @return array<string, array<string, mixed>> */
function am_mutation_log_locations_by_id(): array {
    static $map = null;
    if ($map !== null) {
        return $map;
    }
    $map = [];
    foreach (am_get_pr_sites() as $row) {
        $id = (string)($row['location_id'] ?? $row['id'] ?? '');
        if ($id !== '') {
            $map[$id] = $row;
        }
    }
    return $map;
}

function am_mutation_log_actor_uid(?string $idTokenOverride): string {
    $t = trim((string)$idTokenOverride);
    if ($t !== '') {
        $p = am_firebase_decode_id_token_payload($t);
        return (string)($p['sub'] ?? '');
    }
    return (string)($_SESSION['user_id'] ?? '');
}

function am_mutation_log_actor_email(): string {
    return trim((string)($_SESSION['user_email'] ?? $_SESSION['email'] ?? ''));
}

function am_mutation_log_read_token(?string $idTokenOverride): string {
    $t = trim((string)$idTokenOverride);
    return $t !== '' ? $t : am_firestore_resolve_id_token(null);
}

function am_mutation_log_jwt_is_service_account(array $jwt): bool {
    $e = strtolower((string)($jwt['email'] ?? ''));
    return str_contains($e, 'gserviceaccount.com');
}

function am_mutation_log_display_name_from_user_doc(array $u): string {
    $dn = trim((string)($u['displayName'] ?? ''));
    if ($dn !== '') {
        return $dn;
    }
    $fn = trim((string)($u['firstName'] ?? ''));
    $ln = trim((string)($u['lastName'] ?? ''));
    $n = trim($fn . ' ' . $ln);
    if ($n !== '') {
        return $n;
    }
    $fn = trim((string)($u['first_name'] ?? ''));
    $ln = trim((string)($u['last_name'] ?? ''));
    $n = trim($fn . ' ' . $ln);
    if ($n !== '') {
        return $n;
    }
    return trim((string)($u['email'] ?? ''));
}

function am_mutation_log_employee_number_from_user_doc(array $u): string {
    $sa = $u['systemAccess'] ?? null;
    if (is_array($sa)) {
        $hr = $sa['hr'] ?? null;
        if (is_array($hr)) {
            foreach (['employeeId', 'employee_id', 'employeeNumber', 'employee_number'] as $k) {
                $v = trim((string)($hr[$k] ?? ''));
                if ($v !== '') {
                    return $v;
                }
            }
        }
    }
    foreach (['employeeNumber', 'employee_number', 'employeeId', 'employee_id', 'staffNumber', 'badge_number', 'payrollNumber', 'personnel_number', 'emp_no'] as $k) {
        $v = trim((string)($u[$k] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    return '';
}

/** @return list<array<string, mixed>> */
function am_mutation_log_employees_directory(?string $readTok): array {
    static $loaded = false;
    static $list = [];
    if ($loaded) {
        return $list;
    }
    $loaded = true;
    $tok = trim((string)$readTok) !== '' ? $readTok : am_firestore_resolve_id_token(null);
    if ($tok === '') {
        return [];
    }
    $list = am_firestore_get_collection('pr_master_employees', 2500, $tok);
    if ($list === []) {
        $list = am_firestore_get_collection('am_core_employees', 2500, $tok);
    }
    return $list;
}

/**
 * @return array<string, mixed>|null
 */
function am_mutation_log_find_employee_row_for_actor(string $actorUid, string $userEmailLower, ?string $readTok): ?array {
    foreach (am_mutation_log_employees_directory($readTok) as $e) {
        if (!is_array($e)) {
            continue;
        }
        foreach (['firebase_uid', 'firebaseUid', 'firebase_user_id', 'auth_uid'] as $k) {
            if (!empty($e[$k]) && trim((string)$e[$k]) === $actorUid) {
                return $e;
            }
        }
        if ($userEmailLower !== '') {
            foreach (['email', 'work_email'] as $k) {
                $em = strtolower(trim((string)($e[$k] ?? '')));
                if ($em !== '' && $em === $userEmailLower) {
                    return $e;
                }
            }
        }
    }
    return null;
}

function am_mutation_log_employee_number_from_employee_row(array $e): string {
    foreach (['employee_number', 'employeeNumber', 'staff_number', 'badge_number', 'payroll_id', 'personnel_number', 'emp_no', 'hr_employee_id'] as $k) {
        $v = trim((string)($e[$k] ?? ''));
        if ($v !== '') {
            return $v;
        }
    }
    return trim((string)($e['employee_id'] ?? $e['id'] ?? ''));
}

/**
 * Resolve Firebase UID to display name + HR employee number for readable audit rows.
 *
 * @return array{name: string, employee_number: string, email: string}
 */
function am_mutation_log_resolve_actor(string $actorUid, ?string $idTokenOverride): array {
    static $cache = [];
    if ($actorUid === '') {
        return ['name' => '', 'employee_number' => '', 'email' => ''];
    }
    if (isset($cache[$actorUid])) {
        return $cache[$actorUid];
    }

    if ($actorUid === 'system') {
        return $cache[$actorUid] = ['name' => 'Tablet / device (no user id)', 'employee_number' => '', 'email' => ''];
    }

    $readTok = am_mutation_log_read_token($idTokenOverride);
    if ($readTok === '') {
        return $cache[$actorUid] = ['name' => '', 'employee_number' => '', 'email' => ''];
    }

    $jwt = am_firebase_decode_id_token_payload($readTok);
    if (am_mutation_log_jwt_is_service_account($jwt) && (string)($jwt['sub'] ?? '') === $actorUid) {
        $em = trim((string)($jwt['email'] ?? ''));
        return $cache[$actorUid] = [
            'name' => 'Service account',
            'employee_number' => '',
            'email' => $em,
        ];
    }

    $userDoc = am_firestore_get_document('users', $actorUid, $readTok);
    $userDoc = is_array($userDoc) ? $userDoc : [];

    $name = am_mutation_log_display_name_from_user_doc($userDoc);
    if ($name === '' && $actorUid === (string)($_SESSION['user_id'] ?? '')) {
        $name = trim((string)($_SESSION['username'] ?? ''));
    }

    $mailDisplay = trim((string)($userDoc['email'] ?? ''));
    $mailLower = strtolower($mailDisplay);
    if ($mailDisplay === '' && $actorUid === (string)($_SESSION['user_id'] ?? '')) {
        $mailDisplay = trim(am_mutation_log_actor_email());
        $mailLower = strtolower($mailDisplay);
    }

    $num = am_mutation_log_employee_number_from_user_doc($userDoc);
    if ($num === '') {
        $emp = am_mutation_log_find_employee_row_for_actor($actorUid, $mailLower, $readTok);
        if ($emp !== null) {
            $num = am_mutation_log_employee_number_from_employee_row($emp);
            if ($name === '') {
                $name = trim((string)(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')));
            }
        }
    }

    if ($name === '' && $mailDisplay !== '') {
        $name = $mailDisplay;
    }

    return $cache[$actorUid] = [
        'name' => $name,
        'employee_number' => $num,
        'email' => $mailDisplay,
    ];
}

/**
 * Single-line actor label for summaries and tables.
 *
 * @param array{name?: string, employee_number?: string, email?: string} $r
 */
function am_mutation_log_actor_line(array $r): string {
    $n = trim((string)($r['name'] ?? ''));
    $num = trim((string)($r['employee_number'] ?? ''));
    $e = trim((string)($r['email'] ?? ''));
    if ($n !== '' && $num !== '') {
        return $n . ' (#' . $num . ')';
    }
    if ($n !== '') {
        return $n;
    }
    if ($num !== '') {
        return '#' . $num;
    }
    return $e;
}

/**
 * Fill actor_display_name / actor_employee_number / actor_line for older log rows or partial writes.
 *
 * @param array<string, mixed> $entry
 * @return array<string, mixed>
 */
function am_mutation_log_enrich_entry_for_display(array $entry, string $readToken): array {
    $uid = trim((string)($entry['actor_uid'] ?? ''));
    if ($uid === '') {
        return $entry;
    }
    $hasName = trim((string)($entry['actor_display_name'] ?? '')) !== '';
    $hasNum = trim((string)($entry['actor_employee_number'] ?? '')) !== '';
    if ($hasName && $hasNum) {
        $entry['actor_line'] = am_mutation_log_actor_line([
            'name' => (string)($entry['actor_display_name'] ?? ''),
            'employee_number' => (string)($entry['actor_employee_number'] ?? ''),
            'email' => (string)($entry['actor_email'] ?? ''),
        ]);
        return $entry;
    }
    $resolved = am_mutation_log_resolve_actor($uid, $readToken);
    if (trim((string)($entry['actor_display_name'] ?? '')) === '' && $resolved['name'] !== '') {
        $entry['actor_display_name'] = $resolved['name'];
    }
    if (trim((string)($entry['actor_employee_number'] ?? '')) === '' && $resolved['employee_number'] !== '') {
        $entry['actor_employee_number'] = $resolved['employee_number'];
    }
    if (trim((string)($entry['actor_email'] ?? '')) === '' && $resolved['email'] !== '') {
        $entry['actor_email'] = $resolved['email'];
    }
    $entry['actor_line'] = am_mutation_log_actor_line([
        'name' => (string)($entry['actor_display_name'] ?? $resolved['name']),
        'employee_number' => (string)($entry['actor_employee_number'] ?? $resolved['employee_number']),
        'email' => (string)($entry['actor_email'] ?? $resolved['email']),
    ]);
    return $entry;
}

function am_mutation_log_http_source(): string {
    $sn = (string)($_SERVER['SCRIPT_NAME'] ?? '');
    if (str_contains($sn, '/tablet/')) {
        return 'tablet';
    }
    if (str_contains($sn, '/cron/')) {
        return 'cron';
    }
    if (str_contains($sn, '/api/')) {
        return 'api';
    }
    return 'web';
}

function am_mutation_log_asset_row_has_country_hints(array $row): bool {
    foreach (['country_id', 'country_code', 'location_id', 'asset_tag', 'qr_code_id'] as $k) {
        if (trim((string)($row[$k] ?? '')) !== '') {
            return true;
        }
    }
    return false;
}

/**
 * Snapshot document(s) before delete for country / summary inference.
 *
 * @return array<string, mixed>
 */
function am_mutation_log_prefetch_before_delete(string $collection, string $documentId, ?string $idTokenOverride): array {
    $doc = am_firestore_get_document($collection, $documentId, $idTokenOverride);
    if (!is_array($doc)) {
        return [];
    }
    if ($collection === 'am_core_allocations' || $collection === 'am_core_transactions') {
        $aid = trim((string)($doc['asset_id'] ?? ''));
        if ($aid !== '') {
            $asset = am_firestore_get_document('am_core_assets', $aid, $idTokenOverride);
            if (is_array($asset)) {
                $doc['_mutation_related_asset'] = $asset;
            }
        }
    }
    return $doc;
}

/**
 * @param array<string, mixed> $patchOrFull row used for country inference (may include merged current doc)
 */
function am_mutation_log_infer_country_code(string $collection, array $patchOrFull, ?string $idTokenOverride): string {
    $row = $patchOrFull;
    if ($collection === 'am_core_assets') {
        return am_asset_effective_org_country_code($row, am_mutation_log_countries(), am_mutation_log_locations_by_id());
    }
    if (!empty($row['_mutation_related_asset']) && is_array($row['_mutation_related_asset'])) {
        return am_asset_effective_org_country_code(
            $row['_mutation_related_asset'],
            am_mutation_log_countries(),
            am_mutation_log_locations_by_id()
        );
    }
    $cid = trim((string)($row['country_id'] ?? ''));
    if ($cid !== '') {
        $cc = am_country_code_for_id($cid, am_mutation_log_countries());
        if ($cc !== '') {
            return strtoupper($cc);
        }
    }
    if ($collection === 'am_core_allocations' || $collection === 'am_core_transactions') {
        $aid = trim((string)($row['asset_id'] ?? ''));
        if ($aid !== '') {
            $asset = am_firestore_get_document('am_core_assets', $aid, $idTokenOverride);
            if (is_array($asset)) {
                return am_asset_effective_org_country_code(
                    $asset,
                    am_mutation_log_countries(),
                    am_mutation_log_locations_by_id()
                );
            }
        }
    }
    return '';
}

/**
 * @param list<string> $fieldKeys
 */
function am_mutation_log_build_summary(string $op, string $collection, string $documentId, array $inferRow, array $fieldKeys, string $actorLine = ''): string {
    $tag = (string)($inferRow['asset_tag'] ?? $inferRow['name'] ?? '');
    $tagShort = $tag !== '' ? substr($tag, 0, 40) : '';
    $tag = $tag !== '' ? (' "' . $tagShort . (strlen($tag) > 40 ? '…' : '') . '"') : '';
    $keys = $fieldKeys !== [] ? implode(',', array_slice($fieldKeys, 0, 25)) : '—';
    if (count($fieldKeys) > 25) {
        $keys .= ',…';
    }
    $body = $op . ' ' . $collection . '/' . $documentId . $tag . ' [' . $keys . ']';
    if ($actorLine !== '') {
        return $actorLine . ' → ' . $body;
    }
    return $body;
}

/**
 * @param array<string, mixed> $rowForInfer merged row or snapshot for country inference
 * @param list<string> $updatedFieldKeys field names written (create/update) or [] for delete
 */
function am_mutation_log_record(
    string $op,
    string $collection,
    string $documentId,
    array $rowForInfer,
    ?string $idTokenOverride,
    array $updatedFieldKeys = []
): void {
    if (!am_mutation_log_enabled() || !am_mutation_log_should_record($collection)) {
        return;
    }
    $actorUid = am_mutation_log_actor_uid($idTokenOverride);
    if ($actorUid === '') {
        return;
    }

    $actorResolved = am_mutation_log_resolve_actor($actorUid, $idTokenOverride);
    $actorLine = am_mutation_log_actor_line($actorResolved);
    $actorEmailStored = am_mutation_log_actor_email();
    if ($actorEmailStored === '' && $actorResolved['email'] !== '') {
        $actorEmailStored = $actorResolved['email'];
    }

    $cleanInfer = $rowForInfer;
    unset($cleanInfer['_mutation_related_asset']);

    $countryCode = am_mutation_log_infer_country_code($collection, $rowForInfer, $idTokenOverride);
    $summary = am_mutation_log_build_summary($op, $collection, $documentId, $cleanInfer, $updatedFieldKeys, $actorLine);
    if (strlen($summary) > 1500) {
        $summary = substr($summary, 0, 1497) . '…';
    }

    $entry = [
        'mutation_at' => date('c'),
        'operation' => $op,
        'target_collection' => $collection,
        'target_document_id' => $documentId,
        'actor_uid' => $actorUid,
        'actor_email' => $actorEmailStored,
        'actor_display_name' => $actorResolved['name'],
        'actor_employee_number' => $actorResolved['employee_number'],
        'country_code' => $countryCode,
        'location_id' => trim((string)($cleanInfer['location_id'] ?? '')),
        'source' => am_mutation_log_http_source(),
        'summary' => $summary,
        'updated_fields' => $updatedFieldKeys,
    ];

    $res = am_firestore_create_document(AM_CORE_MUTATION_LOGS_COLLECTION, $entry, null, $idTokenOverride);
    if (empty($res['ok']) && function_exists('error_log')) {
        error_log('am_mutation_log_record failed: ' . (string)($res['error'] ?? ''));
    }
}

/** True when the caller has access to all org countries (same idea as unscoped assets). */
function am_mutation_log_may_see_unscoped_entries(array $allowCountryCodes): bool {
    $norm = am_normalize_country_codes($allowCountryCodes);
    foreach (am_org_country_codes() as $c) {
        if (!in_array($c, $norm, true)) {
            return false;
        }
    }
    return true;
}

/**
 * @param list<array<string, mixed>> $entries
 * @param list<string> $activeCountryCodes from am_country_active_codes() or API equivalent
 * @return list<array<string, mixed>>
 */
function am_mutation_log_filter_by_country_scope(array $entries, array $activeCountryCodes, bool $maySeeUnscoped): array {
    $activeCountryCodes = am_normalize_country_codes($activeCountryCodes);
    if ($activeCountryCodes === []) {
        return [];
    }
    $out = [];
    foreach ($entries as $e) {
        if (!is_array($e)) {
            continue;
        }
        $cc = strtoupper(trim((string)($e['country_code'] ?? '')));
        if ($cc !== '' && in_array($cc, $activeCountryCodes, true)) {
            $out[] = $e;
            continue;
        }
        if ($cc === '' && $maySeeUnscoped) {
            $out[] = $e;
        }
    }
    return $out;
}

/**
 * Prepare infer row for updates (merge current document when patch lacks country hints).
 *
 * @param array<string, mixed> $patch
 * @return array<string, mixed>
 */
function am_mutation_log_merge_row_for_infer(string $collection, string $documentId, array $patch, ?string $idTokenOverride): array {
    if ($collection !== 'am_core_assets') {
        return $patch;
    }
    if (am_mutation_log_asset_row_has_country_hints($patch)) {
        return $patch;
    }
    $cur = am_firestore_get_document($collection, $documentId, $idTokenOverride);
    if (!is_array($cur)) {
        return $patch;
    }
    return array_merge($cur, $patch);
}
