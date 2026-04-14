<?php
/**
 * Firestore helpers for AM/PR source-of-truth split.
 *
 * Collections (single Firebase project, split by namespace):
 * - AM source: am_core_*
 * - PR source: pr_master_*
 */
require_once __DIR__ . '/firebase.php';

function am_firestore_project_id(): string {
    $cfg = am_firebase_config();
    return (string)($cfg['project_id'] ?? 'pr-system-4ea55');
}

function am_firestore_id_token(): string {
    return (string)($_SESSION['firebase_id_token'] ?? '');
}

/** Session token, or a Bearer override (e.g. FM / API callers passing a Firebase ID token). */
function am_firestore_resolve_id_token(?string $overrideToken = null): string {
    $t = trim((string)$overrideToken);
    if ($t !== '') {
        return $t;
    }
    return am_firestore_id_token();
}

function am_firestore_base_url(): string {
    $project = am_firestore_project_id();
    return 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($project) .
        '/databases/(default)/documents';
}

// ── Generic HTTP helper (supports GET, POST, PATCH, DELETE) ─────────

function am_http_request_json(string $method, string $url, ?array $payload = null, array $headers = []): array {
    $method = strtoupper($method);
    $requestHeaders = array_merge(['Content-Type: application/json'], $headers);
    $body = $payload !== null ? json_encode($payload, JSON_UNESCAPED_SLASHES) : null;

    $response = false;
    $error = null;
    $statusCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $requestHeaders,
        ];
        if ($body !== null) {
            $opts[CURLOPT_POSTFIELDS] = $body;
        }
        if (am_allow_insecure_ssl_for_local()) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        if ($response === false || !empty($error) || $statusCode === 0) {
            $stream = am_http_request_json_stream($method, $url, $body, $requestHeaders);
            $response = $stream['response'];
            $error = $stream['error'] ?: $error;
            $statusCode = $stream['status'] ?: $statusCode;
        }
    } else {
        $stream = am_http_request_json_stream($method, $url, $body, $requestHeaders);
        $response = $stream['response'];
        $error = $stream['error'];
        $statusCode = $stream['status'];
    }

    $decoded = is_string($response) ? json_decode($response, true) : null;
    return [
        'ok' => empty($error) && $statusCode >= 200 && $statusCode < 300,
        'status' => $statusCode,
        'error' => $error ?: null,
        'json' => is_array($decoded) ? $decoded : [],
    ];
}

function am_http_request_json_stream(string $method, string $url, ?string $body, array $headers): array {
    $statusCode = 0;
    $error = null;
    $response = false;
    $ctx = [
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ];
    if ($body !== null) {
        $ctx['http']['content'] = $body;
    }
    if (am_allow_insecure_ssl_for_local()) {
        $ctx['ssl'] = [
            'verify_peer' => false,
            'verify_peer_name' => false,
            'allow_self_signed' => true,
        ];
    }
    $context = stream_context_create($ctx);
    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        $error = 'HTTP request failed.';
    }
    $respHeaders = function_exists('http_get_last_response_headers')
        ? http_get_last_response_headers()
        : ($http_response_header ?? []);
    if (is_array($respHeaders) && isset($respHeaders[0])) {
        if (preg_match('/\s(\d{3})\s/', $respHeaders[0], $m)) {
            $statusCode = (int)$m[1];
        }
    }
    return ['response' => $response, 'error' => $error, 'status' => $statusCode];
}

// ── PHP <-> Firestore value conversion ──────────────────────────────

function am_php_to_firestore_value(mixed $value): array {
    if ($value === null) {
        return ['nullValue' => null];
    }
    if (is_bool($value)) {
        return ['booleanValue' => $value];
    }
    if (is_int($value)) {
        return ['integerValue' => (string)$value];
    }
    if (is_float($value)) {
        return ['doubleValue' => $value];
    }
    if (is_string($value)) {
        return ['stringValue' => $value];
    }
    if (is_array($value)) {
        if (array_is_list($value)) {
            $vals = [];
            foreach ($value as $v) {
                $vals[] = am_php_to_firestore_value($v);
            }
            return ['arrayValue' => ['values' => $vals]];
        }
        $fields = [];
        foreach ($value as $k => $v) {
            $fields[(string)$k] = am_php_to_firestore_value($v);
        }
        return ['mapValue' => ['fields' => $fields]];
    }
    return ['stringValue' => (string)$value];
}

function am_php_to_firestore_fields(array $data): array {
    $fields = [];
    foreach ($data as $key => $value) {
        $fields[(string)$key] = am_php_to_firestore_value($value);
    }
    return $fields;
}

// ── Single-document read ────────────────────────────────────────────

function am_firestore_get_document(string $collection, string $documentId, ?string $idTokenOverride = null): ?array {
    $token = am_firestore_resolve_id_token($idTokenOverride);
    if ($token === '' || $documentId === '') {
        return null;
    }

    $url = am_firestore_base_url() . '/' . rawurlencode($collection) . '/' . rawurlencode($documentId);
    $result = am_http_get_json($url, ['Authorization: Bearer ' . $token]);
    if (!$result['ok']) {
        return null;
    }

    return am_firestore_document_to_array($result['json']);
}

// ── Create document ─────────────────────────────────────────────────

function am_firestore_create_document(string $collection, array $data, ?string $documentId = null, ?string $idTokenOverride = null): array {
    $token = am_firestore_resolve_id_token($idTokenOverride);
    if ($token === '') {
        return ['ok' => false, 'error' => 'Not authenticated', 'id' => ''];
    }

    $url = am_firestore_base_url() . '/' . rawurlencode($collection);
    if ($documentId !== null && $documentId !== '') {
        $url .= '?documentId=' . rawurlencode($documentId);
    }

    $payload = ['fields' => am_php_to_firestore_fields($data)];
    $result = am_http_post_json($url, $payload, ['Authorization: Bearer ' . $token]);

    if (!$result['ok']) {
        $msg = $result['json']['error']['message'] ?? ($result['error'] ?? 'Create failed');
        return ['ok' => false, 'error' => $msg, 'id' => ''];
    }

    $docName = $result['json']['name'] ?? '';
    $parts = explode('/', (string)$docName);
    $createdId = end($parts);

    return ['ok' => true, 'error' => null, 'id' => $createdId, 'data' => am_firestore_document_to_array($result['json'])];
}

// ── Update document ─────────────────────────────────────────────────

function am_firestore_update_document(string $collection, string $documentId, array $data, ?string $idTokenOverride = null): array {
    $token = am_firestore_resolve_id_token($idTokenOverride);
    if ($token === '' || $documentId === '') {
        return ['ok' => false, 'error' => 'Not authenticated or missing document ID'];
    }

    $url = am_firestore_base_url() . '/' . rawurlencode($collection) . '/' . rawurlencode($documentId);

    $fieldPaths = array_keys($data);
    $queryParts = [];
    foreach ($fieldPaths as $fp) {
        $queryParts[] = 'updateMask.fieldPaths=' . rawurlencode($fp);
    }
    if (!empty($queryParts)) {
        $url .= '?' . implode('&', $queryParts);
    }

    $payload = ['fields' => am_php_to_firestore_fields($data)];
    $result = am_http_request_json('PATCH', $url, $payload, ['Authorization: Bearer ' . $token]);

    if (!$result['ok']) {
        $msg = $result['json']['error']['message'] ?? ($result['error'] ?? 'Update failed');
        return ['ok' => false, 'error' => $msg];
    }

    return ['ok' => true, 'error' => null, 'data' => am_firestore_document_to_array($result['json'])];
}

// ── Delete document ─────────────────────────────────────────────────

function am_firestore_delete_document(string $collection, string $documentId, ?string $idTokenOverride = null): array {
    $token = am_firestore_resolve_id_token($idTokenOverride);
    if ($token === '' || $documentId === '') {
        return ['ok' => false, 'error' => 'Not authenticated or missing document ID'];
    }

    $url = am_firestore_base_url() . '/' . rawurlencode($collection) . '/' . rawurlencode($documentId);
    $result = am_http_request_json('DELETE', $url, null, ['Authorization: Bearer ' . $token]);

    if (!$result['ok']) {
        $msg = $result['json']['error']['message'] ?? ($result['error'] ?? 'Delete failed');
        return ['ok' => false, 'error' => $msg];
    }

    return ['ok' => true, 'error' => null];
}

// ── Auto-generate next asset_tag ────────────────────────────────────

function am_generate_asset_tag(string $itemClass, string $countryCode, array $existingAssets): string {
    $prefixes = [
        'FixedAsset' => 'FA', 'Material' => 'MAT', 'Consumable' => 'CON', 'Inventory' => 'INV',
    ];
    $prefix = $prefixes[$itemClass] ?? 'ITM';
    $cc = strtoupper(substr($countryCode, 0, 3));
    $tagPrefix = "1PWR-{$prefix}-{$cc}-";

    $maxNum = 0;
    foreach ($existingAssets as $a) {
        $tag = (string)($a['asset_tag'] ?? '');
        if (str_starts_with($tag, $tagPrefix)) {
            $numPart = (int)substr($tag, strlen($tagPrefix));
            if ($numPart > $maxNum) {
                $maxNum = $numPart;
            }
        }
    }

    return $tagPrefix . str_pad((string)($maxNum + 1), 6, '0', STR_PAD_LEFT);
}

function am_firestore_field_value(array $value): mixed {
    if (isset($value['stringValue'])) {
        return (string)$value['stringValue'];
    }
    if (isset($value['integerValue'])) {
        return (int)$value['integerValue'];
    }
    if (isset($value['doubleValue'])) {
        return (float)$value['doubleValue'];
    }
    if (isset($value['booleanValue'])) {
        return (bool)$value['booleanValue'];
    }
    if (isset($value['timestampValue'])) {
        return (string)$value['timestampValue'];
    }
    if (isset($value['nullValue'])) {
        return null;
    }
    if (isset($value['arrayValue'])) {
        $values = $value['arrayValue']['values'] ?? [];
        $out = [];
        foreach ($values as $v) {
            if (is_array($v)) {
                $out[] = am_firestore_field_value($v);
            }
        }
        return $out;
    }
    if (isset($value['mapValue'])) {
        $fields = $value['mapValue']['fields'] ?? [];
        $out = [];
        foreach ($fields as $k => $v) {
            if (is_array($v)) {
                $out[$k] = am_firestore_field_value($v);
            }
        }
        return $out;
    }
    return null;
}

function am_firestore_document_to_array(array $doc): array {
    $data = [];
    $fields = $doc['fields'] ?? [];
    foreach ($fields as $key => $value) {
        if (is_array($value)) {
            $data[$key] = am_firestore_field_value($value);
        }
    }
    if (!empty($doc['name'])) {
        $parts = explode('/', (string)$doc['name']);
        $data['id'] = end($parts);
    }
    return $data;
}

/**
 * Resolve pr_master_countries document id for dashboard/joins.
 * Migration rows often set country_code (e.g. LSO) without country_id; AM forms set country_id.
 */
function am_infer_country_code_from_tags(string $assetTag, string $qrCodeId): string {
    foreach ([$assetTag, $qrCodeId] as $s) {
        if ($s === '') {
            continue;
        }
        // asset_tag: 1PWR-FA-LSO-000001 → middle segment is class, next is 3-letter country
        if (preg_match('/^1PWR-[A-Z]+-([A-Z]{3})-\d+/i', $s, $m)) {
            return strtoupper($m[1]);
        }
        // qr_code_id: 1PWR-LSO-FA-000001 → country first after prefix
        if (preg_match('/^1PWR-([A-Z]{3})-[A-Z]+-\d+/i', $s, $m)) {
            return strtoupper($m[1]);
        }
    }
    return '';
}

function am_resolve_asset_country_id(array $asset, array $countries): string {
    $cid = trim((string)($asset['country_id'] ?? ''));
    if ($cid !== '') {
        return $cid;
    }

    $code = strtoupper(trim((string)($asset['country_code'] ?? '')));
    if ($code === '') {
        $code = am_infer_country_code_from_tags(
            (string)($asset['asset_tag'] ?? ''),
            (string)($asset['qr_code_id'] ?? '')
        );
    }
    if ($code === '') {
        return '';
    }

    foreach ($countries as $c) {
        $cc = strtoupper(trim((string)($c['country_code'] ?? '')));
        if ($cc !== '' && $cc === $code) {
            return (string)($c['country_id'] ?? $c['id'] ?? '');
        }
    }
    return '';
}

function am_firestore_get_collection(string $collectionName, int $pageSize = 1000, ?string $idTokenOverride = null): array {
    $token = am_firestore_resolve_id_token($idTokenOverride);
    if ($token === '') {
        return [];
    }

    $project = am_firestore_project_id();
    $baseUrl = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($project) .
        '/databases/(default)/documents/' . rawurlencode($collectionName);

    $ps = max(1, min(1000, $pageSize));
    $out = [];
    $pageToken = '';
    $guard = 0;

    do {
        $url = $baseUrl . '?pageSize=' . $ps;
        if ($pageToken !== '') {
            $url .= '&pageToken=' . rawurlencode($pageToken);
        }

        $result = am_http_get_json($url, ['Authorization: Bearer ' . $token]);
        if (!$result['ok']) {
            break;
        }

        $docs = $result['json']['documents'] ?? [];
        if (is_array($docs)) {
            foreach ($docs as $doc) {
                if (is_array($doc)) {
                    $out[] = am_firestore_document_to_array($doc);
                }
            }
        }

        $pageToken = (string)($result['json']['nextPageToken'] ?? '');
        $guard++;
        if ($guard > 10000) {
            break;
        }
    } while ($pageToken !== '');

    return $out;
}

// ── PR-portal site sync ─────────────────────────────────────────────
// Reads from the PR portal's canonical `sites` collection (Lesotho)
// and `referenceData_sites` (Benin, Zambia, multi-org) so that AM
// location dropdowns always reflect the single source of truth
// maintained by the PR / Ops team.

function am_get_pr_sites(): array {
    $orgToCountry = [
        '1pwr_lesotho' => 'LSO',
        '1pwr_benin'   => 'BEN',
        '1pwr_zambia'  => 'ZMB',
    ];

    $seen = [];
    $locations = [];

    // 1. `sites` collection — Lesotho field sites (canonical)
    $sites = am_firestore_get_collection('sites', 500);
    foreach ($sites as $s) {
        $code = (string)($s['locationCode'] ?? $s['code'] ?? '');
        $name = (string)($s['name'] ?? '');
        if ($code === '' || $name === '') continue;
        $orgId = strtolower((string)($s['organizationId'] ?? ''));
        $countryCode = $orgToCountry[$orgId] ?? 'LSO';
        $key = strtoupper($code) . '|' . $countryCode;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $locations[] = [
            'id'                   => $s['id'] ?? $code,
            'location_code'        => strtoupper($countryCode) . '-' . strtoupper($code),
            'location_name'        => $name,
            'location_type'        => $s['type'] ?? 'Site',
            'country_code'         => $countryCode,
            'region'               => $s['region'] ?? '',
            'parent_location_code' => '',
            'active'               => ($s['active'] ?? true) ? 1 : 0,
        ];
    }

    // 2. `referenceData_sites` — multi-org (Benin, Zambia, etc.)
    //    Only pull sites from primary 1PWR orgs to avoid duplicates
    //    from sub-orgs (pueco_benin, mgb, smp, neo1, etc.)
    $refSites = am_firestore_get_collection('referenceData_sites', 500);
    foreach ($refSites as $s) {
        $orgId = strtolower((string)($s['organizationId'] ?? ''));
        if (!isset($orgToCountry[$orgId])) continue;
        $countryCode = $orgToCountry[$orgId];

        $code = (string)($s['code'] ?? '');
        $name = (string)($s['name'] ?? '');
        if ($code === '' || $name === '') continue;

        $key = strtoupper($code) . '|' . $countryCode;
        if (isset($seen[$key])) continue;
        $seen[$key] = true;
        $locations[] = [
            'id'                   => $s['id'] ?? $code,
            'location_code'        => strtoupper($countryCode) . '-' . strtoupper($code),
            'location_name'        => $name,
            'location_type'        => 'Site',
            'country_code'         => $countryCode,
            'region'               => '',
            'parent_location_code' => '',
            'active'               => ($s['active'] ?? true) ? 1 : 0,
        ];
    }

    usort($locations, fn($a, $b) =>
        strcmp($a['country_code'], $b['country_code']) ?: strcmp($a['location_name'], $b['location_name'])
    );

    return $locations;
}

/**
 * Load shared `users/{uid}` profile (same document as PR portal).
 * Includes optional `capabilities` map (e.g. sim_team_assign, sim_phone_link).
 */
function am_fetch_pr_user_profile(string $idToken, string $uid): array {
    $cfg = am_firebase_config();
    if (empty($cfg['project_id']) || empty($idToken) || empty($uid)) {
        return ['ok' => false, 'data' => []];
    }

    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($cfg['project_id']) .
        '/databases/(default)/documents/users/' . rawurlencode($uid);

    $result = am_http_get_json($url, ['Authorization: Bearer ' . $idToken]);
    if (!$result['ok']) {
        return ['ok' => false, 'data' => []];
    }

    $data = am_firestore_document_to_array($result['json']);
    $caps = $data['capabilities'] ?? [];
    if (!is_array($caps)) {
        $caps = [];
    }

    require_once __DIR__ . '/country_scope.php';
    $amCountryAccess = am_extract_am_country_access_codes($data);

    return [
        'ok' => true,
        'data' => [
            'firstName' => (string)($data['firstName'] ?? ''),
            'lastName' => (string)($data['lastName'] ?? ''),
            'role' => (string)($data['role'] ?? ''),
            'permissionLevel' => $data['permissionLevel'] ?? null,
            'department' => (string)($data['department'] ?? ''),
            'organization' => (string)($data['organization'] ?? ''),
            'isActive' => $data['isActive'] ?? true,
            'capabilities' => $caps,
            'amCountryAccess' => $amCountryAccess,
        ],
    ];
}
