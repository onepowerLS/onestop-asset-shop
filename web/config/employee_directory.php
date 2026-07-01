<?php
/**
 * Unified employee directory for dispatch receiver validation, checkout, etc.
 * Merges HR master list (pr_master_employees) with portal users (users / nexus_users)
 * so staff set up in PR/AM but not yet synced to pr_master_employees still validate.
 */

/** @return list<string> */
function am_employee_directory_email_fields(): array {
    return ['email', 'work_email', 'workEmail', 'personal_email', 'company_email', 'mail'];
}

function am_employee_directory_display_name(array $row): string {
    $fn = trim((string)($row['first_name'] ?? $row['firstName'] ?? ''));
    $ln = trim((string)($row['last_name'] ?? $row['lastName'] ?? ''));
    $full = trim($fn . ' ' . $ln);
    if ($full !== '') {
        return preg_replace('/\s+/u', ' ', $full);
    }
    foreach (['display_name', 'displayName', 'full_name', 'name', 'employee_name'] as $k) {
        $v = trim((string)($row[$k] ?? ''));
        if ($v !== '') {
            return preg_replace('/\s+/u', ' ', $v);
        }
    }

    return '';
}

function am_employee_directory_canonical_email(array $row): string {
    foreach (am_employee_directory_email_fields() as $k) {
        $raw = trim((string)($row[$k] ?? ''));
        if ($raw !== '' && filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            return $raw;
        }
    }

    return '';
}

/** @return list<string> */
function am_employee_directory_emails_lower(array $row): array {
    $out = [];
    foreach (am_employee_directory_email_fields() as $k) {
        $raw = trim((string)($row[$k] ?? ''));
        if ($raw === '' || !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            continue;
        }
        $out[strtolower($raw)] = true;
    }

    return array_keys($out);
}

function am_employee_directory_name_key(string $displayName): string {
    $s = preg_replace('/\s+/u', ' ', trim($displayName));
    if ($s === '') {
        return '';
    }
    if (preg_match('/^(.+?)\s*<([^>]+@[^>]+)>$/u', $s, $m)) {
        $s = trim($m[1]);
    }

    return function_exists('mb_strtolower') ? mb_strtolower($s, 'UTF-8') : strtolower($s);
}

/**
 * Normalize a portal user doc (users / nexus_users) into HR-shaped row, or null if unusable.
 *
 * @return array<string, mixed>|null
 */
function am_employee_directory_row_from_portal_user(array $u): ?array {
    if (array_key_exists('isActive', $u) && $u['isActive'] === false) {
        return null;
    }
    if (array_key_exists('active', $u) && (int)($u['active'] ?? 1) === 0) {
        return null;
    }

    $email = am_employee_directory_canonical_email($u);
    if ($email === '') {
        return null;
    }

    $fn = trim((string)($u['firstName'] ?? $u['first_name'] ?? ''));
    $ln = trim((string)($u['lastName'] ?? $u['last_name'] ?? ''));
    $display = trim((string)($u['displayName'] ?? $u['display_name'] ?? ''));
    if ($fn === '' && $ln === '' && $display !== '') {
        $parts = preg_split('/\s+/u', $display, 2);
        if (is_array($parts) && count($parts) >= 2) {
            $fn = $parts[0];
            $ln = $parts[1];
        } else {
            $fn = $display;
        }
    }

    return [
        'first_name' => $fn,
        'last_name' => $ln,
        'email' => $email,
        'display_name' => $display !== '' ? $display : trim($fn . ' ' . $ln),
        '_directory_source' => 'portal_user',
    ];
}

/**
 * @param list<array<string, mixed>> $hrRows
 * @param list<array<string, mixed>> $portalRows
 * @return list<array<string, mixed>>
 */
function am_employee_directory_merge_hr_and_portal(array $hrRows, array $portalRows): array {
    $byEmail = [];
    foreach ($hrRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        foreach (am_employee_directory_emails_lower($row) as $el) {
            $byEmail[$el] = $row;
        }
    }
    $merged = array_values($hrRows);
    foreach ($portalRows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $norm = am_employee_directory_row_from_portal_user($row);
        if ($norm === null) {
            continue;
        }
        $el = strtolower(am_employee_directory_canonical_email($norm));
        if ($el === '' || isset($byEmail[$el])) {
            continue;
        }
        $byEmail[$el] = $norm;
        $merged[] = $norm;
    }

    return $merged;
}

/**
 * Full directory: HR master + portal users not already in HR list.
 *
 * @return list<array<string, mixed>>
 */
function am_employee_directory_load(): array {
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }

    $hr = am_firestore_get_collection('pr_master_employees', 10000);
    if ($hr === []) {
        $hr = am_firestore_get_collection('am_core_employees', 10000);
    }

    $portal = [];
    foreach (['users', 'nexus_users'] as $coll) {
        $chunk = am_firestore_get_collection($coll, 5000);
        if ($chunk !== []) {
            $portal = array_merge($portal, $chunk);
        }
    }

    $cache = am_employee_directory_merge_hr_and_portal($hr, $portal);

    return $cache;
}

/**
 * @param list<array<string, mixed>> $employees
 * @return array{byEmail: array<string, array<string, mixed>>, byNameKey: array<string, list<array<string, mixed>>>}
 */
function am_employee_directory_build_indexes(array $employees): array {
    $byEmail = [];
    $byNameKey = [];
    foreach ($employees as $emp) {
        if (!is_array($emp)) {
            continue;
        }
        if (am_employee_directory_canonical_email($emp) === '') {
            continue;
        }
        foreach (am_employee_directory_emails_lower($emp) as $el) {
            $byEmail[$el] = $emp;
        }
        $full = am_employee_directory_display_name($emp);
        if ($full === '') {
            continue;
        }
        $nk = am_employee_directory_name_key($full);
        if ($nk === '') {
            continue;
        }
        if (!isset($byNameKey[$nk])) {
            $byNameKey[$nk] = [];
        }
        $dedupe = strtolower(am_employee_directory_canonical_email($emp));
        $already = false;
        foreach ($byNameKey[$nk] as $row) {
            if (strtolower(am_employee_directory_canonical_email($row)) === $dedupe) {
                $already = true;
                break;
            }
        }
        if (!$already) {
            $byNameKey[$nk][] = $emp;
        }
    }

    return ['byEmail' => $byEmail, 'byNameKey' => $byNameKey];
}

function am_dispatch_normalize_receiver_display_name(string $raw): string {
    $s = preg_replace('/\s+/u', ' ', trim($raw));
    if ($s === '') {
        return '';
    }
    if (preg_match('/^(.+?)\s*<([^>]+@[^>]+)>$/u', $s, $m)) {
        return trim($m[1]);
    }

    return $s;
}

/**
 * @param array<string, array<string, mixed>> $byEmail
 * @param array<string, list<array<string, mixed>>> $byNameKey
 * @return array{ok: bool, email: string, name: string, error: string}
 */
function am_employee_directory_resolve_receiver(
    array $byEmail,
    array $byNameKey,
    string $receiverNameRaw,
    string $receiverEmailRaw
): array {
    $nameIn = am_dispatch_normalize_receiver_display_name($receiverNameRaw);
    $emailIn = trim($receiverEmailRaw);
    if ($emailIn === '' || !filter_var($emailIn, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'email' => '', 'name' => $nameIn, 'error' => ''];
    }
    $el = strtolower($emailIn);
    if (isset($byEmail[$el])) {
        $emp = $byEmail[$el];
        $nm = am_employee_directory_display_name($emp);

        return ['ok' => true, 'email' => am_employee_directory_canonical_email($emp), 'name' => $nm, 'error' => ''];
    }
    $nk = am_employee_directory_name_key($nameIn);
    if ($nk !== '') {
        $bucket = $byNameKey[$nk] ?? [];
        if (count($bucket) === 1) {
            $emp = $bucket[0];

            return [
                'ok' => true,
                'email' => am_employee_directory_canonical_email($emp),
                'name' => am_employee_directory_display_name($emp),
                'error' => '',
            ];
        }
        if (count($bucket) > 1) {
            foreach ($bucket as $emp) {
                foreach (am_employee_directory_emails_lower($emp) as $candLower) {
                    if ($candLower === $el) {
                        return [
                            'ok' => true,
                            'email' => am_employee_directory_canonical_email($emp),
                            'name' => am_employee_directory_display_name($emp),
                            'error' => '',
                        ];
                    }
                }
            }

            return [
                'ok' => false,
                'email' => '',
                'name' => $nameIn,
                'error' => 'Multiple employees share this name. Pick the suggestion with the correct email, or open Admin → Employees to copy the exact address.',
            ];
        }
    }

    // Logged-in AM user receiving at their own @1pwrafrica.com address (common when HR row is missing).
    $sessionEmail = strtolower(trim((string)($_SESSION['email'] ?? '')));
    if ($sessionEmail !== '' && $el === $sessionEmail && filter_var($sessionEmail, FILTER_VALIDATE_EMAIL)) {
        $nm = trim((string)($_SESSION['username'] ?? ''));
        if ($nm === '') {
            $nm = $nameIn !== '' ? $nameIn : $sessionEmail;
        }

        return ['ok' => true, 'email' => trim((string)($_SESSION['email'] ?? $emailIn)), 'name' => $nm, 'error' => ''];
    }

    return [
        'ok' => false,
        'email' => '',
        'name' => $nameIn,
        'error' => 'Receiver email not found in the employee directory. Pick a name from the suggestions so the email auto-fills. If this person has PR/AM login but is missing here, ask IT to sync them into pr_master_employees or ensure their users profile has a valid email.',
    ];
}
