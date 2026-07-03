<?php
/**
 * Canonical data sync — API-based distribution from PR / HR / FM into AM-owned
 * Firestore cache collections.
 *
 * Principle: AM reads canonical reference data (sites, organizations, countries,
 * employees, departments, vehicles) from an AM-owned cache populated by pulling
 * the upstream system's HTTP API with a service API key. Runtime loaders read
 * the cache with FIREBASE_ADMIN_BEARER_TOKEN — never the user's session token
 * against PR/HR/FM-owned collections — so auth state never blocks the UI.
 *
 * See docs/CANONICAL_DATA_SYNC_PLAN.md for the full design.
 *
 * Upstream APIs (all already exist, documented in their respective repos):
 *   PR  → GET prCatalogApi/api/organizations | /api/countries        (X-API-Key: PR_CATALOG_API_KEY)
 *   HR  → GET hr.1pwrafrica.com/api/employees/directory | /api/departments  (X-API-Key: HR_API_KEY_AM_PORTAL)
 *   FM  → GET fm.1pwrafrica.com/api/integrations/v1/vehicles          (X-Fleet-Integration-Key: FLEET_INTEGRATION_API_KEY)
 *
 * Sites are pushed by PR's fanoutSiteChanges Cloud Function into
 * web/api/sync/site-ingest.php (am_reference_sites); no pull client needed.
 */

declare(strict_types=1);

require_once __DIR__ . '/firebase.php';
require_once __DIR__ . '/firestore.php';
// Mints a Firebase ID token from the service account on demand (the .env
// FIREBASE_ADMIN_BEARER_TOKEN is a placeholder on AM; tokens are minted, not
// stored). Falls back to the env var if a long-lived bearer is configured.
if (file_exists(__DIR__ . '/firebase_admin_token.php')) {
    require_once __DIR__ . '/firebase_admin_token.php';
}

// ── Source + type registry ───────────────────────────────────────────

const AM_CANONICAL_SOURCES = [
    'sites'         => 'pr',   // populated by fanout (site-ingest.php), no pull client
    'organizations' => 'pr',
    'countries'     => 'pr',
    'employees'     => 'hr',
    'departments'   => 'hr',
    'vehicles'      => 'fm',
];

const AM_CANONICAL_TYPES = ['sites', 'organizations', 'countries', 'employees', 'departments', 'vehicles'];

const AM_CANONICAL_DEFAULT_TTL = [
    'sites'         => 900,    // 15 min (push-driven; cache is always fresh)
    'organizations' => 86400,  // 24 hr
    'countries'     => 86400,  // 24 hr
    'employees'     => 3600,   // 1 hr
    'departments'   => 3600,   // 1 hr
    'vehicles'      => 3600,   // 1 hr
];

function am_canonical_admin_token(): string {
    // Prefer a freshly minted Firebase ID token (service account) — AM does not
    // store a long-lived admin bearer. Falls back to FIREBASE_ADMIN_BEARER_TOKEN
    // if a long-lived bearer is configured.
    if (function_exists('am_firebase_admin_token')) {
        $minted = trim((string) am_firebase_admin_token());
        if ($minted !== '') {
            return $minted;
        }
    }
    return trim((string) am_env('FIREBASE_ADMIN_BEARER_TOKEN', ''));
}

function am_canonical_cache_collection(string $type): string {
    return 'am_reference_' . $type;
}

function am_canonical_state_collection(): string {
    return 'am_canonical_sync_state';
}

function am_canonical_events_collection(): string {
    return 'am_canonical_sync_events';
}

function am_canonical_state_doc_id(string $type): string {
    return 'state_' . $type;
}

function am_canonical_ttl(string $type): int {
    $env = am_env('AM_CANONICAL_TTL_' . strtoupper($type), null);
    if ($env !== null && $env !== '' && ctype_digit($env)) {
        return (int)$env;
    }
    return AM_CANONICAL_DEFAULT_TTL[$type] ?? 900;
}

// ── Admin-bearer cache read/write ────────────────────────────────────

/**
 * Read every doc in the cache collection for a type, using the admin bearer.
 * Returns list of item arrays (top-level Firestore fields + `cached_at`).
 */
function am_canonical_cache_read(string $type): array {
    $token = am_canonical_admin_token();
    if ($token === '') {
        return [];
    }
    return am_firestore_get_collection(am_canonical_cache_collection($type), 1000, $token);
}

/**
 * Upsert a single cache doc by deterministic ID. Returns ['ok'=>bool,'error'=>?string].
 */
function am_canonical_cache_upsert(string $type, string $docId, array $fields): array {
    $token = am_canonical_admin_token();
    if ($token === '' || $docId === '') {
        return ['ok' => false, 'error' => 'No admin token or doc ID'];
    }

    $collection = am_canonical_cache_collection($type);
    $payload = $fields;
    $payload['cache_doc_id'] = $docId;
    $payload['cached_at']    = date('c');
    $payload['source_system'] = AM_CANONICAL_SOURCES[$type] ?? 'unknown';

    $existing = am_firestore_get_document($collection, $docId, $token);
    if (is_array($existing) && !empty($existing['id'])) {
        $write = am_firestore_update_document($collection, $docId, $payload, $token);
    } else {
        $payload['createdAt'] = date('c');
        $write = am_firestore_create_document($collection, $payload, $docId, $token);
        // Race: another worker created it between our get and create.
        if (!$write['ok'] && stripos((string)($write['error'] ?? ''), 'ALREADY_EXISTS') !== false) {
            $write = am_firestore_update_document($collection, $docId, $payload, $token);
        }
    }
    return ['ok' => (bool)($write['ok'] ?? false), 'error' => $write['error'] ?? null];
}

// ── Sync state + events ──────────────────────────────────────────────

function am_canonical_record_state(string $type, string $mode, int $count, ?string $error): void {
    $token = am_canonical_admin_token();
    if ($token === '') {
        return;
    }
    $docId = am_canonical_state_doc_id($type);
    $payload = [
        'type'           => $type,
        'last_sync_at'   => date('c'),
        'last_mode'      => $mode,        // 'full' | 'incremental'
        'last_count'     => $count,
        'last_error'     => $error,
        'updated_at'     => date('c'),
    ];
    $existing = am_firestore_get_document(am_canonical_state_collection(), $docId, $token);
    if (is_array($existing) && !empty($existing['id'])) {
        am_firestore_update_document(am_canonical_state_collection(), $docId, $payload, $token);
    } else {
        $payload['createdAt'] = date('c');
        $create = am_firestore_create_document(am_canonical_state_collection(), $payload, $docId, $token);
        if (!($create['ok'] ?? false)) {
            am_firestore_update_document(am_canonical_state_collection(), $docId, $payload, $token);
        }
    }
}

function am_canonical_read_state(string $type): ?array {
    $token = am_canonical_admin_token();
    if ($token === '') {
        return null;
    }
    $doc = am_firestore_get_document(am_canonical_state_collection(), am_canonical_state_doc_id($type), $token);
    return is_array($doc) && !empty($doc['id']) ? $doc : null;
}

function am_canonical_record_event(string $type, string $mode, int $count, ?string $error): void {
    $token = am_canonical_admin_token();
    if ($token === '') {
        return;
    }
    $eventId = $type . '_' . date('YmdHis') . '_' . substr(bin2hex(random_bytes(4)), 0, 8);
    am_firestore_create_document(am_canonical_events_collection(), [
        'id'         => $eventId,
        'type'       => $type,
        'mode'       => $mode,
        'count'      => $count,
        'error'      => $error,
        'occurred_at' => date('c'),
    ], $eventId, $token);
}

// ── HTTP pull helper ─────────────────────────────────────────────────

/**
 * GET an upstream JSON endpoint with an API-key header. Returns ['ok'=>bool,'json'=>?array,'error'=>?string].
 */
function am_canonical_http_get(string $url, string $keyHeaderName, string $key): array {
    if ($key === '') {
        return ['ok' => false, 'json' => null, 'error' => "missing API key for $keyHeaderName"];
    }
    $headers = [
        $keyHeaderName . ': ' . $key,
        'Accept: application/json',
    ];
    $result = am_http_get_json($url, $headers);
    if (!$result['ok']) {
        $msg = $result['error'] ?? ('HTTP ' . ($result['status'] ?? '?'));
        return ['ok' => false, 'json' => null, 'error' => $msg];
    }
    return ['ok' => true, 'json' => $result['json'], 'error' => null];
}

// ── Per-type pull clients ────────────────────────────────────────────

function am_canonical_pr_base_url(): string {
    $project = trim((string) am_env('FIREBASE_PROJECT_ID', 'pr-system-4ea55'));
    return 'https://us-central1-' . rawurlencode($project) . '.cloudfunctions.net/prCatalogApi/api';
}

function am_canonical_pull_pr_organizations(): array {
    $key = trim((string) am_env('PR_CATALOG_API_KEY', ''));
    $r = am_canonical_http_get(am_canonical_pr_base_url() . '/organizations', 'X-API-Key', $key);
    if (!$r['ok']) {
        return ['ok' => false, 'items' => [], 'error' => $r['error']];
    }
    $items = $r['json']['organizations'] ?? [];
    return ['ok' => true, 'items' => $items, 'error' => null];
}

function am_canonical_pull_pr_countries(): array {
    $key = trim((string) am_env('PR_CATALOG_API_KEY', ''));
    $r = am_canonical_http_get(am_canonical_pr_base_url() . '/countries', 'X-API-Key', $key);
    if (!$r['ok']) {
        return ['ok' => false, 'items' => [], 'error' => $r['error']];
    }
    $items = $r['json']['countries'] ?? [];
    return ['ok' => true, 'items' => $items, 'error' => null];
}

function am_canonical_hr_base_url(): string {
    return rtrim((string) am_env('HR_API_BASE_URL', 'https://hr.1pwrafrica.com'), '/');
}

function am_canonical_pull_hr_employees(?string $since = null): array {
    $key = trim((string) am_env('HR_API_KEY_AM_PORTAL', ''));
    $url = am_canonical_hr_base_url() . '/api/employees/directory';
    if ($since !== null && $since !== '') {
        $url .= '?since=' . rawurlencode($since);
    }
    $r = am_canonical_http_get($url, 'X-API-Key', $key);
    if (!$r['ok']) {
        return ['ok' => false, 'items' => [], 'error' => $r['error']];
    }
    $items = $r['json']['employees'] ?? [];
    return ['ok' => true, 'items' => $items, 'error' => null];
}

function am_canonical_pull_hr_departments(?string $since = null): array {
    $key = trim((string) am_env('HR_API_KEY_AM_PORTAL', ''));
    $url = am_canonical_hr_base_url() . '/api/departments';
    if ($since !== null && $since !== '') {
        $url .= '?since=' . rawurlencode($since);
    }
    $r = am_canonical_http_get($url, 'X-API-Key', $key);
    if (!$r['ok']) {
        return ['ok' => false, 'items' => [], 'error' => $r['error']];
    }
    $items = $r['json']['departments'] ?? [];
    return ['ok' => true, 'items' => $items, 'error' => null];
}

function am_canonical_pull_fm_vehicles(): array {
    $key = trim((string) am_env('FLEET_INTEGRATION_API_KEY', ''));
    $org = trim((string) am_env('AM_CANONICAL_FM_ORG', '1pwr_lesotho'));
    $url = 'https://fm.1pwrafrica.com/api/integrations/v1/vehicles?org=' . rawurlencode($org) . '&includeInactive=true';
    $r = am_canonical_http_get($url, 'X-Fleet-Integration-Key', $key);
    if (!$r['ok']) {
        return ['ok' => false, 'items' => [], 'error' => $r['error']];
    }
    $items = $r['json']['vehicles'] ?? [];
    return ['ok' => true, 'items' => $items, 'error' => null];
}

// ── Doc-ID + field normalization per type ────────────────────────────

/**
 * Returns the deterministic cache doc ID for an upstream item, or '' if the item
 * is missing its identity field (it will be skipped).
 */
function am_canonical_doc_id(string $type, array $item): string {
    switch ($type) {
        case 'organizations':
            $id = trim((string)($item['id'] ?? ''));
            return $id !== '' ? strtolower($id) : '';
        case 'countries':
            $code = strtoupper(trim((string)($item['code'] ?? '')));
            return $code !== '' ? $code : '';
        case 'employees':
            $eid = trim((string)($item['employee_id'] ?? ''));
            if ($eid !== '') {
                return strtolower($eid);
            }
            $hid = trim((string)($item['id'] ?? ''));
            return $hid !== '' ? 'hr_' . $hid : '';
        case 'departments':
            $did = trim((string)($item['id'] ?? ''));
            return $did !== '' ? 'hr_' . $did : '';
        case 'vehicles':
            $vid = trim((string)($item['fmVehicleId'] ?? ''));
            return $vid;
        case 'sites':
            // Sites are fanout-populated; not pulled here.
            return '';
        default:
            return '';
    }
}

// ── Refresh orchestration ────────────────────────────────────────────

/**
 * Refresh one type's cache from its upstream API and record state + event.
 *
 * Modes:
 *   'full'        — pull everything, upsert each item. Default.
 *   'incremental' — (HR employees/departments only) pull only items changed
 *                   since the last successful sync, upsert them. No deletion.
 *
 * Returns ['ok'=>bool,'count'=>int,'mode'=>string,'error'=>?string].
 */
function am_canonical_refresh(string $type, string $mode = 'full'): array {
    if (!in_array($type, AM_CANONICAL_TYPES, true)) {
        return ['ok' => false, 'count' => 0, 'mode' => $mode, 'error' => "Unknown type: $type"];
    }
    if (am_canonical_admin_token() === '') {
        $err = 'FIREBASE_ADMIN_BEARER_TOKEN not set';
        am_canonical_record_state($type, $mode, 0, $err);
        am_canonical_record_event($type, $mode, 0, $err);
        return ['ok' => false, 'count' => 0, 'mode' => $mode, 'error' => $err];
    }

    $since = null;
    if ($mode === 'incremental' && in_array($type, ['employees', 'departments'], true)) {
        $state = am_canonical_read_state($type);
        $last = trim((string)($state['last_sync_at'] ?? ''));
        if ($last !== '') {
            $since = $last;
        } else {
            $mode = 'full';   // no prior state → must do full
        }
    }

    switch ($type) {
        case 'organizations':
            $pull = am_canonical_pull_pr_organizations(); break;
        case 'countries':
            $pull = am_canonical_pull_pr_countries(); break;
        case 'employees':
            $pull = am_canonical_pull_hr_employees($since); break;
        case 'departments':
            $pull = am_canonical_pull_hr_departments($since); break;
        case 'vehicles':
            $pull = am_canonical_pull_fm_vehicles(); break;
        case 'sites':
            $err = 'sites are push-driven (site-ingest.php); no pull client. Use scripts/canonical_backfill_sites.php for one-time backfill.';
            am_canonical_record_state($type, $mode, 0, $err);
            am_canonical_record_event($type, $mode, 0, $err);
            return ['ok' => false, 'count' => 0, 'mode' => $mode, 'error' => $err];
        default:
            $pull = ['ok' => false, 'items' => [], 'error' => "no pull client for $type"];
    }

    if (!$pull['ok']) {
        am_canonical_record_state($type, $mode, 0, $pull['error']);
        am_canonical_record_event($type, $mode, 0, $pull['error']);
        return ['ok' => false, 'count' => 0, 'mode' => $mode, 'error' => $pull['error']];
    }

    $items = $pull['items'] ?? [];
    $written = 0;
    $lastError = null;
    foreach ($items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $docId = am_canonical_doc_id($type, $item);
        if ($docId === '') {
            continue;
        }
        $up = am_canonical_cache_upsert($type, $docId, $item);
        if ($up['ok']) {
            $written++;
        } else {
            $lastError = $up['error'];
        }
    }

    am_canonical_record_state($type, $mode, $written, $lastError);
    am_canonical_record_event($type, $mode, $written, $lastError);

    return ['ok' => true, 'count' => $written, 'mode' => $mode, 'error' => $lastError];
}

/**
 * Refresh every type (except sites, which are push-driven).
 * Returns a per-type result map.
 */
function am_canonical_refresh_all(string $mode = 'full'): array {
    $out = [];
    foreach (AM_CANONICAL_TYPES as $type) {
        if ($type === 'sites') {
            continue;   // push-driven
        }
        try {
            $out[$type] = am_canonical_refresh($type, $mode);
        } catch (\Throwable $e) {
            $err = $type . ': ' . $e->getMessage();
            am_canonical_record_state($type, $mode, 0, $err);
            am_canonical_record_event($type, $mode, 0, $err);
            $out[$type] = ['ok' => false, 'count' => 0, 'mode' => $mode, 'error' => $err];
        }
    }
    return $out;
}

// ── TTL + high-level read ────────────────────────────────────────────

function am_canonical_ttl_expired(string $type): bool {
    $state = am_canonical_read_state($type);
    if (!$state) {
        return true;
    }
    $last = strtotime((string)($state['last_sync_at'] ?? ''));
    if (!$last) {
        return true;
    }
    return (time() - $last) > am_canonical_ttl($type);
}

/**
 * High-level cache read. Returns list of cached items. Lazily refreshes when
 * stale unless `force_refresh` is passed false. On a failed refresh, serves
 * the existing cache (stale-but-available).
 */
function am_canonical_get(string $type, array $opts = []): array {
    $forceRefresh = $opts['force_refresh'] ?? true;
    if ($type !== 'sites' && $forceRefresh && am_canonical_ttl_expired($type)) {
        am_canonical_refresh($type, 'full');
    }
    return am_canonical_cache_read($type);
}

// ── Status (for admin UI) ────────────────────────────────────────────

/**
 * Per-type status row: collection, cached item count, last sync at, mode, ttl,
 * freshness, last error.
 */
function am_canonical_status(): array {
    $rows = [];
    foreach (AM_CANONICAL_TYPES as $type) {
        $state = am_canonical_read_state($type);
        $cache = am_canonical_cache_read($type);
        $count = is_array($cache) ? count($cache) : 0;
        $lastSync = (string)($state['last_sync_at'] ?? '');
        $ttl = am_canonical_ttl($type);
        $fresh = $lastSync !== '' && !am_canonical_ttl_expired($type);
        $rows[] = [
            'type'          => $type,
            'source'        => AM_CANONICAL_SOURCES[$type] ?? '',
            'collection'    => am_canonical_cache_collection($type),
            'count'         => $count,
            'last_sync_at'  => $lastSync,
            'last_mode'     => (string)($state['last_mode'] ?? ''),
            'last_error'    => (string)($state['last_error'] ?? ''),
            'ttl_seconds'   => $ttl,
            'fresh'         => $fresh,
            'push_driven'   => $type === 'sites',
        ];
    }
    return $rows;
}
