<?php
/**
 * Shared X-API-Key auth for AM integration (read) APIs.
 *
 * Extends the existing scheme used by /api/vehicles, /api/mutations and
 * /api/loadout-manifests. A valid key resolves to the minted admin Firestore
 * token via am_firestore_admin_access_token() so user-session expiry cannot
 * block machine consumers.
 *
 * Keys (any one match grants access):
 *   AM_API_KEY_UGRIDPREDICT
 *   AM_API_KEY_NEXUS
 *   AM_API_KEY_REPORTING
 *   AM_MUTATION_LOG_API_KEY          (legacy; treated as "reporting")
 *   LOADOUT_MANIFEST_API_KEY         (legacy; treated as "fm")
 *   AM_INTEGRATION_API_KEYS          (optional JSON: {"ugridpredict":"...","nexus":"..."})
 *
 * Header: X-API-Key: <key>
 * Also accepted: Authorization: Bearer <key> (same key store — not a new scheme)
 *                ?api_key=  (legacy query, same store)
 */

require_once __DIR__ . '/firebase.php';
if (file_exists(__DIR__ . '/firebase_admin_token.php')) {
    require_once __DIR__ . '/firebase_admin_token.php';
}

const AM_INTEGRATION_RATE_LIMIT_PER_MIN = 60;

/**
 * @return array<string, string> consumer => key
 */
function am_integration_api_keys(): array {
    $out = [];
    $named = [
        'ugridpredict' => am_env('AM_API_KEY_UGRIDPREDICT', ''),
        'nexus' => am_env('AM_API_KEY_NEXUS', ''),
        'reporting' => am_env('AM_API_KEY_REPORTING', ''),
        'reporting_legacy' => am_env('AM_MUTATION_LOG_API_KEY', ''),
        'fm' => am_env('LOADOUT_MANIFEST_API_KEY', ''),
    ];
    foreach ($named as $consumer => $key) {
        $key = trim((string)$key);
        if ($key !== '') {
            $out[$consumer] = $key;
        }
    }
    $json = trim((string)am_env('AM_INTEGRATION_API_KEYS', ''));
    if ($json !== '') {
        $decoded = json_decode($json, true);
        if (is_array($decoded)) {
            foreach ($decoded as $consumer => $key) {
                $key = trim((string)$key);
                if ($key !== '' && is_string($consumer) && $consumer !== '') {
                    $out[$consumer] = $key;
                }
            }
        }
    }
    return $out;
}

function am_integration_api_extract_presented_key(): string {
    $h = (string)($_SERVER['HTTP_X_API_KEY'] ?? '');
    if ($h !== '') {
        return trim($h);
    }
    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(\S+)/i', $auth, $m)) {
        return trim($m[1]);
    }
    return trim((string)($_GET['api_key'] ?? ''));
}

/**
 * @return array{ok:bool, consumer:string, error:string}
 */
function am_integration_api_identify_consumer(string $presented): array {
    if ($presented === '') {
        return ['ok' => false, 'consumer' => '', 'error' => 'Authentication required. Use X-API-Key.'];
    }
    foreach (am_integration_api_keys() as $consumer => $key) {
        if (hash_equals($key, $presented)) {
            $name = $consumer === 'reporting_legacy' ? 'reporting' : $consumer;
            return ['ok' => true, 'consumer' => $name, 'error' => ''];
        }
    }
    return ['ok' => false, 'consumer' => '', 'error' => 'Invalid API key.'];
}

function am_integration_api_admin_token(): string {
    if (function_exists('am_canonical_admin_token')) {
        $t = trim((string)am_canonical_admin_token());
        if ($t !== '') {
            return $t;
        }
    }
    if (function_exists('am_firestore_admin_access_token')) {
        $t = trim((string)am_firestore_admin_access_token());
        if ($t !== '') {
            return $t;
        }
    }
    if (function_exists('am_firebase_admin_token')) {
        $t = trim((string)am_firebase_admin_token());
        if ($t !== '') {
            return $t;
        }
    }
    return trim((string)am_env('FIREBASE_ADMIN_BEARER_TOKEN', ''));
}

function am_integration_api_rate_limit_ok(string $consumer): bool {
    $safe = preg_replace('/[^A-Za-z0-9_-]/', '_', $consumer) ?: 'unknown';
    $bucket = (string)(int)floor(time() / 60);
    $path = sys_get_temp_dir() . '/am_api_rl_' . $safe . '_' . $bucket;
    $count = 0;
    if (is_readable($path)) {
        $count = (int)trim((string)@file_get_contents($path));
    }
    $count++;
    @file_put_contents($path, (string)$count);
    return $count <= AM_INTEGRATION_RATE_LIMIT_PER_MIN;
}

function am_integration_api_log(string $consumer, string $endpoint, int $status, float $startedAt): void {
    $ms = (int)round((microtime(true) - $startedAt) * 1000);
    error_log(sprintf(
        '[am_integration_api] consumer=%s endpoint=%s status=%d duration_ms=%d',
        $consumer !== '' ? $consumer : '-',
        $endpoint,
        $status,
        $ms
    ));
}

/**
 * Authenticate a v1 read endpoint. Sends JSON error and exits on failure.
 *
 * @return array{consumer:string, token:string, started_at:float}
 */
function am_integration_api_require(): array {
    $started = microtime(true);
    $endpoint = (string)($_SERVER['SCRIPT_NAME'] ?? 'api');
    $presented = am_integration_api_extract_presented_key();
    $id = am_integration_api_identify_consumer($presented);
    if (!$id['ok']) {
        am_integration_api_log('', $endpoint, 401, $started);
        http_response_code(401);
        echo json_encode(['success' => false, 'error' => $id['error']], JSON_UNESCAPED_SLASHES);
        exit;
    }
    if (!am_integration_api_rate_limit_ok($id['consumer'])) {
        am_integration_api_log($id['consumer'], $endpoint, 429, $started);
        http_response_code(429);
        echo json_encode(['success' => false, 'error' => 'Rate limit exceeded (60/min).'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    $token = am_integration_api_admin_token();
    if ($token === '') {
        am_integration_api_log($id['consumer'], $endpoint, 503, $started);
        http_response_code(503);
        echo json_encode(['success' => false, 'error' => 'Server cannot mint an admin Firestore token.'], JSON_UNESCAPED_SLASHES);
        exit;
    }
    register_shutdown_function(static function () use ($id, $endpoint, $started) {
        $code = http_response_code();
        if (!is_int($code) || $code === 0) {
            $code = 200;
        }
        am_integration_api_log($id['consumer'], $endpoint, $code, $started);
    });
    return ['consumer' => $id['consumer'], 'token' => $token, 'started_at' => $started];
}

function am_integration_api_limit(): int {
    $limit = (int)($_GET['limit'] ?? 100);
    return max(1, min(1000, $limit));
}

function am_integration_api_cursor_offset(): int {
    $raw = trim((string)($_GET['cursor'] ?? ''));
    if ($raw === '') {
        return 0;
    }
    if (ctype_digit($raw)) {
        return max(0, (int)$raw);
    }
    $decoded = base64_decode($raw, true);
    if ($decoded !== false && ctype_digit($decoded)) {
        return max(0, (int)$decoded);
    }
    return 0;
}

function am_integration_api_next_cursor(int $offset, int $limit, int $total): ?string {
    $next = $offset + $limit;
    if ($next >= $total) {
        return null;
    }
    return (string)$next;
}

/**
 * @param list<array<string, mixed>> $items
 */
function am_integration_api_emit_page(array $items, string $itemsKey = 'items'): void {
    $limit = am_integration_api_limit();
    $offset = am_integration_api_cursor_offset();
    $total = count($items);
    $slice = array_slice($items, $offset, $limit);
    $etag = '"' . md5(json_encode($slice, JSON_UNESCAPED_SLASHES)) . '"';
    header('ETag: ' . $etag);
    header('Cache-Control: no-store');
    echo json_encode([
        $itemsKey => array_values($slice),
        'count' => count($slice),
        'total' => $total,
        'next_cursor' => am_integration_api_next_cursor($offset, $limit, $total),
    ], JSON_UNESCAPED_SLASHES);
}
