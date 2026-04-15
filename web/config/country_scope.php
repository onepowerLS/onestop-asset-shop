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

/**
 * When Firestore has no amCountryAccess (or only invalid entries), default to all org countries.
 * Matches legacy behaviour before country scope and aligns with coarse Firestore read rules.
 *
 * @param list<string> $codes
 * @return list<string>
 */
function am_apply_default_country_allow_if_empty(array $codes): array {
    $codes = am_normalize_country_codes($codes);
    return $codes !== [] ? $codes : am_org_country_codes();
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
 * LSO / ZMB / BEN for scope checks: uses pr_master_countries when possible, otherwise
 * asset.country_code or tag inference so listings still work if the master list failed to
 * load or country_id values no longer match Firestore rows.
 */
function am_asset_effective_org_country_code(array $asset, array $countries): string {
    require_once __DIR__ . '/firestore.php';
    $valid = array_flip(am_org_country_codes());

    $raw = am_normalize_asset_country_code_field((string)($asset['country_code'] ?? ''));
    if ($raw !== '' && isset($valid[$raw])) {
        return $raw;
    }

    $tagCode = am_infer_country_code_from_tags(
        (string)($asset['asset_tag'] ?? ''),
        (string)($asset['qr_code_id'] ?? '')
    );
    if ($tagCode !== '' && isset($valid[$tagCode])) {
        return $tagCode;
    }

    $cid = trim((string)($asset['country_id'] ?? ''));
    if ($cid !== '') {
        $fromMaster = am_country_code_for_id($cid, $countries);
        if ($fromMaster !== '') {
            $u = strtoupper(trim($fromMaster));
            if (isset($valid[$u])) {
                return $u;
            }
        }
    }

    $resolvedId = am_resolve_asset_country_id($asset, $countries);
    if ($resolvedId !== '') {
        $fromMaster = am_country_code_for_id($resolvedId, $countries);
        if ($fromMaster !== '') {
            $u = strtoupper(trim($fromMaster));
            if (isset($valid[$u])) {
                return $u;
            }
        }
    }

    return '';
}

/**
 * Grouping key for dashboard / reports: resolved master id when possible, else synthetic __code__LSO.
 */
function am_asset_country_bucket_id_for_ui(array $asset, array $countries): string {
    $cid = am_resolve_asset_country_id($asset, $countries);
    if ($cid !== '') {
        return $cid;
    }
    $code = am_asset_effective_org_country_code($asset, $countries);
    if ($code === '') {
        return '';
    }
    foreach ($countries as $c) {
        $cc = strtoupper(trim((string)($c['country_code'] ?? '')));
        if ($cc === $code) {
            $id = (string)($c['country_id'] ?? $c['id'] ?? '');
            if ($id !== '') {
                return $id;
            }
        }
    }
    return '__code__' . $code;
}

/**
 * Whether the current user may see this asset in list/detail (active scope).
 */
function am_asset_passes_country_scope(array $asset, array $countries): bool {
    $active = am_country_active_codes();
    if (empty($active)) {
        return false;
    }
    $code = am_asset_effective_org_country_code($asset, $countries);
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
    if (!is_logged_in()) {
        return;
    }
    $existing = $_SESSION['am_country_allow'] ?? null;
    if (is_array($existing) && !empty(am_normalize_country_codes($existing))) {
        return;
    }
    $tok = (string)($_SESSION['firebase_id_token'] ?? '');
    $uid = (string)($_SESSION['user_id'] ?? '');
    if ($tok === '' || $uid === '') {
        $_SESSION['am_country_allow'] = am_org_country_codes();
        if (!isset($_SESSION['am_country_filter'])) {
            $_SESSION['am_country_filter'] = 'all';
        }
        return;
    }
    require_once __DIR__ . '/firestore.php';
    $profile = am_fetch_pr_user_profile($tok, $uid);
    $data = ($profile['ok'] ?? false) ? ($profile['data'] ?? []) : [];
    $allow = $data['amCountryAccess'] ?? [];
    if (!is_array($allow)) {
        $allow = [];
    }
    $_SESSION['am_country_allow'] = am_apply_default_country_allow_if_empty($allow);
    if (!isset($_SESSION['am_country_filter'])) {
        $_SESSION['am_country_filter'] = 'all';
    }
}
