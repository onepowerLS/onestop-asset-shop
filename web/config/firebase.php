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
        curl_close($ch);

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
    if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
        if (preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
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
        curl_close($ch);

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
    if (isset($http_response_header) && is_array($http_response_header) && isset($http_response_header[0])) {
        if (preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
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

    $fields = $result['json']['fields'] ?? [];
    if (!is_array($fields)) {
        $fields = [];
    }

    return [
        'ok' => true,
        'data' => [
            'firstName' => am_firestore_value($fields, 'firstName', ''),
            'lastName' => am_firestore_value($fields, 'lastName', ''),
            'role' => am_firestore_value($fields, 'role', ''),
            'permissionLevel' => am_firestore_value($fields, 'permissionLevel', null),
            'department' => am_firestore_value($fields, 'department', ''),
            'organization' => am_firestore_value($fields, 'organization', ''),
            'isActive' => am_firestore_value($fields, 'isActive', true),
        ],
    ];
}

function am_map_pr_role_to_am(string $prRole = '', mixed $permissionLevel = null): string {
    $normalizedRole = strtoupper(trim($prRole));
    $perm = is_numeric($permissionLevel) ? (int)$permissionLevel : null;

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
