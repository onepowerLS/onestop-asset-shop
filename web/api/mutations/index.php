<?php
/**
 * Mutation log API — read am_core_mutation_logs scoped to the caller’s allowed countries.
 *
 * Auth (pick one):
 *   - Authorization: Bearer <Firebase ID token>
 *   - GET id_token=<Firebase ID token>
 *   - Same-origin browser session with AM login (uses session Firebase ID token)
 *   - api_key matching AM_MUTATION_LOG_API_KEY in .env plus FIREBASE_ADMIN_BEARER_TOKEN (server automation)
 *
 * GET /api/mutations/index.php
 *   &limit=200          — max rows after filter (default 200, cap 5000)
 *   &since=2026-01-01   — only mutation_at >= this ISO date (inclusive, server-local parse)
 *   &country=LSO        — optional extra filter (must be in caller’s allow list). Ignored for narrow admin use if not set.
 *   &include_unscoped=1 — admin / service-account only: include rows with empty country_code
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/firebase.php';
require_once __DIR__ . '/../../config/firestore.php';
require_once __DIR__ . '/../../config/country_scope.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed'], JSON_UNESCAPED_SLASHES);
    exit;
}

function am_mutation_api_expected_key(): string {
    return (string)am_env('AM_MUTATION_LOG_API_KEY', '');
}

/**
 * @return array{token: string, admin_bearer: bool}
 */
function am_mutation_api_resolve_token(): array {
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? '');
    if (preg_match('/Bearer\s+(\S+)/i', $h, $m)) {
        $t = trim($m[1]);
        $admin = trim((string)am_env('FIREBASE_ADMIN_BEARER_TOKEN', ''));
        if ($admin !== '' && hash_equals($admin, $t)) {
            return ['token' => $t, 'admin_bearer' => true];
        }
        return ['token' => $t, 'admin_bearer' => false];
    }
    $q = trim((string)($_GET['id_token'] ?? ''));
    if ($q !== '') {
        return ['token' => $q, 'admin_bearer' => false];
    }
    $apiKey = trim((string)($_GET['api_key'] ?? ''));
    if ($apiKey !== '' && am_mutation_api_expected_key() !== '' && hash_equals(am_mutation_api_expected_key(), $apiKey)) {
        $admin = trim((string)am_env('FIREBASE_ADMIN_BEARER_TOKEN', ''));
        if ($admin !== '') {
            return ['token' => $admin, 'admin_bearer' => true];
        }
    }
    if (function_exists('is_logged_in') && is_logged_in()) {
        $tok = am_firestore_resolve_id_token(null);
        if ($tok !== '') {
            return ['token' => $tok, 'admin_bearer' => false];
        }
    }
    return ['token' => '', 'admin_bearer' => false];
}

/** @return array{active: list<string>, may_unscoped: bool, error?: string} */
function am_mutation_api_country_scope(string $token, bool $adminBearer): array {
    if ($adminBearer) {
        $allow = am_org_country_codes();
        $param = strtoupper(trim((string)($_GET['country'] ?? 'all')));
        if ($param !== 'ALL' && in_array($param, am_org_country_codes(), true)) {
            return [
                'active' => [$param],
                'may_unscoped' => trim((string)($_GET['include_unscoped'] ?? '')) === '1',
            ];
        }
        return [
            'active' => $allow,
            'may_unscoped' => trim((string)($_GET['include_unscoped'] ?? '')) === '1',
        ];
    }

    $payload = am_firebase_decode_id_token_payload($token);
    $uid = (string)($payload['sub'] ?? '');
    if ($uid === '') {
        return ['active' => [], 'may_unscoped' => false, 'error' => 'Invalid token'];
    }

    $prof = am_fetch_pr_user_profile($token, $uid);
    if (empty($prof['ok'])) {
        return ['active' => [], 'may_unscoped' => false, 'error' => 'Could not load user profile for country scope'];
    }
    $allow = am_apply_default_country_allow_if_empty($prof['data']['amCountryAccess'] ?? []);
    $allow = am_normalize_country_codes($allow);

    $param = strtoupper(trim((string)($_GET['country'] ?? 'all')));
    if ($param !== 'ALL' && in_array($param, am_org_country_codes(), true)) {
        if (!in_array($param, $allow, true)) {
            return ['active' => [], 'may_unscoped' => false, 'error' => 'country not permitted for this user'];
        }
        $active = [$param];
    } else {
        $active = $allow;
    }

    return [
        'active' => $active,
        'may_unscoped' => am_mutation_log_may_see_unscoped_entries($allow),
    ];
}

$auth = am_mutation_api_resolve_token();
if ($auth['token'] === '') {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'error' => 'Authentication required. Use Authorization: Bearer <Firebase ID token>, id_token query, AM session cookie, or api_key with FIREBASE_ADMIN_BEARER_TOKEN.',
    ], JSON_UNESCAPED_SLASHES);
    exit;
}

$scope = am_mutation_api_country_scope($auth['token'], $auth['admin_bearer']);
if (!empty($scope['error']) || $scope['active'] === []) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => $scope['error'] ?? 'No country access'], JSON_UNESCAPED_SLASHES);
    exit;
}

$limit = (int)($_GET['limit'] ?? 200);
$limit = max(1, min(5000, $limit));

$since = trim((string)($_GET['since'] ?? ''));
$sinceTs = $since !== '' ? strtotime($since) : false;

$raw = am_firestore_get_collection(AM_CORE_MUTATION_LOGS_COLLECTION, 3000, $auth['token']);
$scoped = am_mutation_log_filter_by_country_scope($raw, $scope['active'], (bool)$scope['may_unscoped']);

$out = [];
foreach ($scoped as $row) {
    if (!is_array($row)) {
        continue;
    }
    if ($sinceTs !== false) {
        $mt = strtotime((string)($row['mutation_at'] ?? ''));
        if ($mt !== false && $mt < $sinceTs) {
            continue;
        }
    }
    $row = am_mutation_log_enrich_entry_for_display($row, $auth['token']);
    $out[] = [
        'id' => (string)($row['id'] ?? ''),
        'mutation_at' => (string)($row['mutation_at'] ?? ''),
        'operation' => (string)($row['operation'] ?? ''),
        'target_collection' => (string)($row['target_collection'] ?? ''),
        'target_document_id' => (string)($row['target_document_id'] ?? ''),
        'actor_uid' => (string)($row['actor_uid'] ?? ''),
        'actor_email' => (string)($row['actor_email'] ?? ''),
        'actor_display_name' => (string)($row['actor_display_name'] ?? ''),
        'actor_employee_number' => (string)($row['actor_employee_number'] ?? ''),
        'actor_line' => (string)($row['actor_line'] ?? ''),
        'country_code' => (string)($row['country_code'] ?? ''),
        'location_id' => (string)($row['location_id'] ?? ''),
        'source' => (string)($row['source'] ?? ''),
        'summary' => (string)($row['summary'] ?? ''),
        'updated_fields' => is_array($row['updated_fields'] ?? null) ? $row['updated_fields'] : [],
    ];
}

usort($out, static function ($a, $b) {
    $ta = strtotime((string)($a['mutation_at'] ?? ''));
    $tb = strtotime((string)($b['mutation_at'] ?? ''));
    return $tb <=> $ta;
});

$out = array_slice($out, 0, $limit);

echo json_encode([
    'success' => true,
    'count' => count($out),
    'scope' => [
        'country_codes' => $scope['active'],
        'include_unscoped' => (bool)$scope['may_unscoped'],
    ],
    'mutations' => $out,
], JSON_UNESCAPED_SLASHES);
exit;
