<?php
/**
 * Country scope: profile-based allowed countries + session UI filter (LSO / ZMB / BEN).
 * User profile should set `amCountryAccess` (array of codes) or Nexus `systemAccess.am.countryAccess`.
 */
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/authz.php';

/** @return list<string> */
function am_org_country_codes(): array {
    return ['LSO', 'ZMB', 'BEN'];
}

/**
 * @param array<int|string, mixed> $codes
 * @return list<string>
 */
function am_normalize_country_codes(array $codes): array {
    $valid = array_flip(am_org_country_codes());
    $out = [];
    foreach ($codes as $c) {
        $u = strtoupper(trim((string)$c));
        if ($u !== '' && isset($valid[$u])) {
            $out[$u] = true;
        }
    }
    return array_keys($out);
}

/**
 * Extract AM country codes from Firestore users/{uid} document (flat or Nexus-shaped).
 *
 * @param array<string, mixed> $doc
 * @return list<string>
 */
function am_extract_am_country_access_codes(array $doc): array {
    if (isset($doc['amCountryAccess']) && is_array($doc['amCountryAccess'])) {
        return am_normalize_country_codes($doc['amCountryAccess']);
    }
    $sa = $doc['systemAccess'] ?? null;
    if (is_array($sa)) {
        $am = $sa['am'] ?? null;
        if (is_array($am) && isset($am['countryAccess']) && is_array($am['countryAccess'])) {
            return am_normalize_country_codes($am['countryAccess']);
        }
    }
    return [];
}

/** @return list<string> */
function am_country_allow_codes(): array {
    $a = $_SESSION['am_country_allow'] ?? null;
    if (!is_array($a)) {
        return [];
    }
    return am_normalize_country_codes($a);
}

/**
 * Session filter: "all" or a single org country code (only countries in allow list are meaningful).
 */
function am_country_filter_mode(): string {
    $f = $_SESSION['am_country_filter'] ?? 'all';
    if ($f === 'all' || $f === null || $f === '') {
        return 'all';
    }
    $f = strtoupper((string)$f);
    return in_array($f, am_org_country_codes(), true) ? $f : 'all';
}

/** Active country codes for listings (intersection of allow + filter). @return list<string> */
function am_country_active_codes(): array {
    $allow = am_country_allow_codes();
    if (empty($allow)) {
        return [];
    }
    $mode = am_country_filter_mode();
    if ($mode === 'all') {
        return $allow;
    }
    if (in_array($mode, $allow, true)) {
        return [$mode];
    }
    return $allow;
}

/** Resolve asset country_id to 3-letter code using pr_master_countries list. */
function am_country_code_for_id(string $countryId, array $countries): string {
    foreach ($countries as $c) {
        $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
        if ($cid !== '' && $cid === $countryId) {
            return strtoupper(trim((string)($c['country_code'] ?? '')));
        }
    }
    return '';
}

/**
 * Whether the current user may see this asset in list/detail (active scope).
 */
function am_asset_passes_country_scope(array $asset, array $countries): bool {
    require_once __DIR__ . '/firestore.php';
    $active = am_country_active_codes();
    if (empty($active)) {
        return false;
    }
    $cid = am_resolve_asset_country_id($asset, $countries);
    if ($cid === '') {
        return false;
    }
    $code = am_country_code_for_id($cid, $countries);
    return $code !== '' && in_array($code, $active, true);
}

/**
 * Whether user may mutate data tied to this country_id (allowed list, not UI filter).
 */
function am_user_may_access_country_id(string $countryId, array $countries): bool {
    $allow = am_country_allow_codes();
    if (empty($allow)) {
        return false;
    }
    $code = am_country_code_for_id($countryId, $countries);
    return $code !== '' && in_array($code, $allow, true);
}

function am_require_asset_country_mutate(string $countryId, array $countries): void {
    if (am_is_admin_role()) {
        return;
    }
    if (am_user_may_access_country_id($countryId, $countries)) {
        return;
    }
    $_SESSION['flash_error'] = 'You do not have access to manage assets for this country.';
    header('Location: ' . base_url('assets/index.php'));
    exit;
}

function am_require_asset_visible(array $asset, array $countries): void {
    if (am_asset_passes_country_scope($asset, $countries)) {
        return;
    }
    $_SESSION['flash_error'] = 'This item is outside your country scope.';
    header('Location: ' . base_url('assets/index.php'));
    exit;
}

/**
 * Countries for dropdowns: only those the user is allowed to operate in.
 *
 * @param array<int, array<string, mixed>> $countries
 * @return array<int, array<string, mixed>>
 */
function am_countries_for_user_select(array $countries): array {
    $allow = am_country_allow_codes();
    if (empty($allow)) {
        return [];
    }
    if (am_is_admin_role()) {
        // Admin may still be scoped; if allow is full use all active pr_master rows matching allow
    }
    return array_values(array_filter($countries, function ($c) use ($allow) {
        $cc = strtoupper(trim((string)($c['country_code'] ?? '')));
        return $cc !== '' && in_array($cc, $allow, true);
    }));
}

/**
 * Lazy backfill for sessions created before country scope existed.
 */
/**
 * Load-out manifest (or similar) has optional country_id — empty means visible if user has any active scope.
 */
function am_record_in_country_scope(array $record, array $countries): bool {
    $active = am_country_active_codes();
    if (empty($active)) {
        return false;
    }
    $cid = trim((string)($record['country_id'] ?? ''));
    if ($cid === '') {
        // Legacy rows without country: restrict to Admin so country-scoped staff do not see unknown-scope data.
        return am_is_admin_role();
    }
    $code = am_country_code_for_id($cid, $countries);
    return $code !== '' && in_array($code, $active, true);
}

function am_ensure_country_scope_from_session(): void {
    if (!is_logged_in() || isset($_SESSION['am_country_allow'])) {
        return;
    }
    $tok = (string)($_SESSION['firebase_id_token'] ?? '');
    $uid = (string)($_SESSION['user_id'] ?? '');
    if ($tok === '' || $uid === '') {
        return;
    }
    require_once __DIR__ . '/firestore.php';
    $profile = am_fetch_pr_user_profile($tok, $uid);
    $data = ($profile['ok'] ?? false) ? ($profile['data'] ?? []) : [];
    $allow = $data['amCountryAccess'] ?? [];
    if (!is_array($allow)) {
        $allow = [];
    }
    $allow = am_normalize_country_codes($allow);
    if (empty($allow) && (($_SESSION['role'] ?? '') === 'Admin')) {
        $allow = am_org_country_codes();
    }
    $_SESSION['am_country_allow'] = $allow;
    if (!isset($_SESSION['am_country_filter'])) {
        $_SESSION['am_country_filter'] = 'all';
    }
}
