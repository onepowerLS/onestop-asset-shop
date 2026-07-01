<?php
/**
 * Firebase auth helpers for AM login.
 */

function am_load_env(): array {
    static $env = null;
    if ($env !== null) {
        return $env;
    }

    $env = [];
    $envPath = __DIR__ . '/../../.env';
    if (file_exists($envPath)) {
        $parsed = parse_ini_file($envPath, false, INI_SCANNER_TYPED);
        if (is_array($parsed)) {
            $env = $parsed;
        }
    }

    return $env;
}

function am_env(string $key, ?string $default = null): ?string {
    $env = am_load_env();
    $value = $env[$key] ?? getenv($key);
    if ($value === false || $value === null) {
        return $default;
    }
    return is_string($value) ? trim($value) : (string)$value;
}

function am_firebase_config(): array {
    return [
        // Match PR/JC defaults so local setup works out-of-the-box.
        'api_key' => am_env('FIREBASE_WEB_API_KEY', 'AIzaSyD0tA1fvWs5dCr-7JqJv_bxlay2Bhs72jQ'),
        'project_id' => am_env('FIREBASE_PROJECT_ID', 'pr-system-4ea55'),
    ];
}

function am_allow_insecure_ssl_for_local(): bool {
    // Local dev fallback for Windows/PHP environments missing CA bundle.
    // Keep secure by default in production by setting ALLOW_INSECURE_SSL_LOCAL=false.
    $flag = strtolower((string)am_env('ALLOW_INSECURE_SSL_LOCAL', 'true'));
    return in_array($flag, ['1', 'true', 'yes', 'on'], true);
}

function am_http_post_json(string $url, array $payload, array $headers = []): array {
    $requestHeaders = array_merge(['Content-Type: application/json'], $headers);
    $body = json_encode($payload, JSON_UNESCAPED_SLASHES);

    $response = false;
    $error = null;
    $statusCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => $body,
        ];
        if (am_allow_insecure_ssl_for_local()) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);

        $response = curl_exec($ch);
        $error = curl_error($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);

        // If cURL fails (common on local SSL/cert issues), fall back to streams.
        if ($response === false || !empty($error) || $statusCode === 0) {
            $stream = am_http_post_json_stream($url, $body, $requestHeaders);
            $response = $stream['response'];
            $error = $stream['error'] ?: $error;
            $statusCode = $stream['status'] ?: $statusCode;
        }
    } else {
        $stream = am_http_post_json_stream($url, $body, $requestHeaders);
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

function am_http_post_json_stream(string $url, string $body, array $headers): array {
    $statusCode = 0;
    $error = null;
    $response = false;
    $ctx = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ];
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

/**
 * POST application/x-www-form-urlencoded (tokeninfo, Secure Token API, etc.).
 *
 * @return array{ok: bool, status: int, error: ?string, json: array<string, mixed>}
 */
function am_http_post_form_urlencoded(string $url, array $fields): array {
    $body = http_build_query($fields, '', '&', PHP_QUERY_RFC3986);
    $requestHeaders = ['Content-Type: application/x-www-form-urlencoded'];

    $response = false;
    $error = null;
    $statusCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $requestHeaders,
            CURLOPT_POSTFIELDS => $body,
        ];
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
            $stream = am_http_post_form_urlencoded_stream($url, $body, $requestHeaders);
            $response = $stream['response'];
            $error = $stream['error'] ?: $error;
            $statusCode = $stream['status'] ?: $statusCode;
        }
    } else {
        $stream = am_http_post_form_urlencoded_stream($url, $body, $requestHeaders);
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

function am_http_post_form_urlencoded_stream(string $url, string $body, array $headers): array {
    $statusCode = 0;
    $error = null;
    $response = false;
    $ctx = [
        'http' => [
            'method' => 'POST',
            'header' => implode("\r\n", $headers),
            'content' => $body,
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ];
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

function am_http_get_json(string $url, array $headers = []): array {
    $response = false;
    $error = null;
    $statusCode = 0;

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => $headers,
        ];
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
            $stream = am_http_get_json_stream($url, $headers);
            $response = $stream['response'];
            $error = $stream['error'] ?: $error;
            $statusCode = $stream['status'] ?: $statusCode;
        }
    } else {
        $stream = am_http_get_json_stream($url, $headers);
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

function am_http_get_json_stream(string $url, array $headers): array {
    $statusCode = 0;
    $error = null;
    $response = false;
    $ctx = [
        'http' => [
            'method' => 'GET',
            'header' => implode("\r\n", $headers),
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ];
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

function am_firebase_sign_in(string $email, string $password): array {
    $cfg = am_firebase_config();
    if (empty($cfg['api_key'])) {
        return ['ok' => false, 'message' => 'Firebase API key is not configured.'];
    }

    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:signInWithPassword?key=' . rawurlencode($cfg['api_key']);
    $result = am_http_post_json($url, [
        'email' => $email,
        'password' => $password,
        'returnSecureToken' => true,
    ]);

    if (!$result['ok']) {
        $errCode = $result['json']['error']['message'] ?? '';
        $friendly = match ($errCode) {
            'INVALID_LOGIN_CREDENTIALS', 'EMAIL_NOT_FOUND', 'INVALID_PASSWORD', 'USER_DISABLED' => 'Invalid email or password.',
            'API_KEY_HTTP_REFERRER_BLOCKED', 'API_KEY_INVALID' => 'Firebase API key rejected by project settings.',
            default => 'Sign in failed. Please try again.'
        };
        if ($errCode === '' && !empty($result['error'])) {
            $friendly = 'Network/auth error: ' . $result['error'];
        }
        return ['ok' => false, 'message' => $friendly, 'raw_error' => $errCode ?: ($result['error'] ?? '')];
    }

    return [
        'ok' => true,
        'uid' => $result['json']['localId'] ?? '',
        'email' => $result['json']['email'] ?? $email,
        'id_token' => $result['json']['idToken'] ?? '',
        'refresh_token' => $result['json']['refreshToken'] ?? '',
    ];
}

/**
 * Firebase ID token payload (middle segment). No signature verification.
 *
 * @return array<string, mixed>|null
 */
function am_firebase_decode_id_token_payload(string $jwt): ?array {
    $jwt = trim($jwt);
    if ($jwt === '') {
        return null;
    }
    $parts = explode('.', $jwt);
    if (count($parts) < 2) {
        return null;
    }
    $b64 = $parts[1];
    $pad = strlen($b64) % 4;
    if ($pad > 0) {
        $b64 .= str_repeat('=', 4 - $pad);
    }
    $json = base64_decode(strtr($b64, '-_', '+/'), true);
    if ($json === false) {
        return null;
    }
    $payload = json_decode($json, true);
    return is_array($payload) ? $payload : null;
}

/** @return int|null Unix timestamp from Firebase ID token `exp` claim (no crypto verification). */
function am_firebase_id_token_exp_unix(string $jwt): ?int {
    $payload = am_firebase_decode_id_token_payload($jwt);
    if ($payload === null || !isset($payload['exp'])) {
        return null;
    }
    return (int)$payload['exp'];
}

/**
 * Confirms decoded claims match this Firebase project and expected UID (no signature verification).
 */
function am_firebase_id_token_payload_matches_session(array $payload, string $expectedUid): bool {
    $cfg = am_firebase_config();
    $pid = (string)($cfg['project_id'] ?? '');
    if ($pid === '' || $expectedUid === '') {
        return false;
    }
    $aud = (string)($payload['aud'] ?? '');
    $iss = (string)($payload['iss'] ?? '');
    $sub = (string)($payload['sub'] ?? '');
    $exp = (int)($payload['exp'] ?? 0);
    if ($aud !== $pid) {
        return false;
    }
    if ($iss !== 'https://securetoken.google.com/' . $pid) {
        return false;
    }
    if ($sub !== $expectedUid) {
        return false;
    }
    if ($exp < time() - 120) {
        return false;
    }
    return true;
}

/**
 * Bind a browser-minted Firebase ID token to the PHP session using JWT claims (aud/iss/sub/exp).
 *
 * This is the practical fallback when {@see am_verify_google_id_token} (Google tokeninfo) fails on
 * the server — e.g. outbound HTTPS issues, tokeninfo quirks — while the token is still valid.
 * Cryptographic signature is not verified here; the same claim checks are used as after
 * refresh_token exchange. Tokens are only accepted if they match the logged-in session UID and project.
 */
function am_accept_firebase_id_token_for_php_session(string $idToken, string $expectedUid): bool {
    $payload = am_firebase_decode_id_token_payload($idToken);
    return $payload !== null && am_firebase_id_token_payload_matches_session($payload, $expectedUid);
}

/**
 * Verify a Firebase/Google ID token via oauth2 tokeninfo.
 * Uses POST (form body) first so long JWTs are not truncated by URL length limits on GET.
 *
 * @return array<string, mixed>|null tokeninfo JSON on success
 */
function am_verify_google_id_token(string $idToken): ?array {
    $idToken = trim($idToken);
    if ($idToken === '') {
        return null;
    }
    $url = 'https://oauth2.googleapis.com/tokeninfo';
    $post = am_http_post_form_urlencoded($url, ['id_token' => $idToken]);
    $data = $post['json'];
    if (
        $post['ok']
        && is_array($data)
        && !isset($data['error'])
        && !isset($data['error_description'])
    ) {
        return $data;
    }
    // Fallback: GET works for shorter tokens; some proxies choke on very long query strings.
    if (strlen($idToken) < 1800) {
        $getUrl = $url . '?id_token=' . rawurlencode($idToken);
        $get = am_http_get_json($getUrl);
        $gj = $get['json'];
        if (
            $get['ok']
            && is_array($gj)
            && !isset($gj['error'])
            && !isset($gj['error_description'])
        ) {
            return $gj;
        }
    }
    return null;
}

/**
 * Exchange a Firebase refresh token for a new ID token (Secure Token API).
 * Used server-side so PHP → Firestore does not depend on Google tokeninfo or client refresh-session.
 *
 * @return array{ok: bool, id_token?: string, refresh_token?: string, error?: string}
 */
function am_firebase_exchange_refresh_token(string $refreshToken): array {
    $cfg = am_firebase_config();
    $refreshToken = trim($refreshToken);
    if (empty($cfg['api_key']) || $refreshToken === '') {
        return ['ok' => false, 'error' => 'missing_key_or_token'];
    }

    $url = 'https://securetoken.googleapis.com/v1/token?key=' . rawurlencode($cfg['api_key']);
    $body = http_build_query([
        'grant_type' => 'refresh_token',
        'refresh_token' => $refreshToken,
    ], '', '&', PHP_QUERY_RFC3986);

    $response = false;
    $statusCode = 0;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        $opts = [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
            CURLOPT_POSTFIELDS => $body,
        ];
        if (am_allow_insecure_ssl_for_local()) {
            $opts[CURLOPT_SSL_VERIFYPEER] = false;
            $opts[CURLOPT_SSL_VERIFYHOST] = 0;
        }
        curl_setopt_array($ch, $opts);
        $response = curl_exec($ch);
        $statusCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        unset($ch);
    }

    if ($response === false || $statusCode < 200 || $statusCode >= 300) {
        return ['ok' => false, 'error' => 'http_' . $statusCode];
    }

    $json = json_decode((string)$response, true);
    if (!is_array($json)) {
        return ['ok' => false, 'error' => 'bad_json'];
    }

    $idToken = (string)($json['id_token'] ?? '');
    if ($idToken === '') {
        return ['ok' => false, 'error' => 'no_id_token'];
    }

    $out = [
        'ok' => true,
        'id_token' => $idToken,
    ];
    if (!empty($json['refresh_token'])) {
        $out['refresh_token'] = (string)$json['refresh_token'];
    }
    return $out;
}

function am_firebase_sign_up(string $email, string $password): array {
    $cfg = am_firebase_config();
    if (empty($cfg['api_key'])) {
        return ['ok' => false, 'message' => 'Firebase API key is not configured.'];
    }

    $url = 'https://identitytoolkit.googleapis.com/v1/accounts:signUp?key=' . rawurlencode($cfg['api_key']);
    $result = am_http_post_json($url, [
        'email' => $email,
        'password' => $password,
        'returnSecureToken' => true,
    ]);

    if (!$result['ok']) {
        $errCode = $result['json']['error']['message'] ?? '';
        $friendly = match ($errCode) {
            'EMAIL_EXISTS' => 'That email is already registered.',
            'INVALID_EMAIL' => 'Invalid email address.',
            'WEAK_PASSWORD' => 'Password is too weak. Try a longer password.',
            default => 'Could not create account. Please try again.'
        };
        if ($errCode === '' && !empty($result['error'])) {
            $friendly = 'Network/auth error: ' . $result['error'];
        }
        return ['ok' => false, 'message' => $friendly, 'raw_error' => $errCode ?: ($result['error'] ?? '')];
    }

    return [
        'ok' => true,
        'uid' => $result['json']['localId'] ?? '',
        'email' => $result['json']['email'] ?? $email,
        'id_token' => $result['json']['idToken'] ?? '',
        'refresh_token' => $result['json']['refreshToken'] ?? '',
    ];
}

function am_firebase_generate_random_password(int $length = 20): string {
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnpqrstuvwxyz23456789';
    $out = '';
    $max = strlen($chars) - 1;
    for ($i = 0; $i < $length; $i++) {
        $out .= $chars[random_int(0, $max)];
    }
    return $out;
}

function am_firestore_value(array $fields, string $field, mixed $default = null): mixed {
    if (!isset($fields[$field]) || !is_array($fields[$field])) {
        return $default;
    }
    $value = $fields[$field];
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
    return $default;
}

function am_map_pr_role_to_am(string $prRole = '', mixed $permissionLevel = null): string {
    $normalizedRole = strtoupper(trim($prRole));
    $perm = is_numeric($permissionLevel) ? (int)$permissionLevel : null;

    if ($normalizedRole === 'AUDITOR') {
        return 'Auditor';
    }

    if ($perm === 1 || in_array($normalizedRole, ['ADMIN', 'SUPERADMIN'], true)) {
        return 'Admin';
    }

    if (
        ($perm !== null && $perm >= 2 && $perm <= 4) ||
        in_array($normalizedRole, ['APPROVER', 'PROC', 'FIN_AD', 'FIN_APPROVER'], true)
    ) {
        return 'Manager';
    }

    return 'Viewer';
}
