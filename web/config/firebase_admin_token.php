<?php
/**
 * Firebase Admin Token Helper for AM
 *
 * Mints a Firebase ID token from the service account key, cached with a
 * 50-minute TTL (ID tokens last 60 min). Used by API endpoints that accept
 * X-API-Key for server-to-server Firestore reads.
 *
 * Requires: firebase-service-account.json in the AM root (path in .env:
 * FIREBASE_SERVICE_ACCOUNT_KEY)
 */

function am_firebase_admin_token(): string {
    static $cached = null;
    static $cachedAt = 0;

    // Cache for 50 minutes (tokens last 60 min)
    if ($cached !== null && (time() - $cachedAt) < 3000) {
        return $cached;
    }

    // Also try file-based cache (for CLI scripts + multi-request)
    $cacheFile = sys_get_temp_dir() . '/am_firebase_admin_token.cache';
    if (file_exists($cacheFile)) {
        $age = time() - filemtime($cacheFile);
        if ($age < 3000) {
            $cached = trim(file_get_contents($cacheFile));
            $cachedAt = time();
            if ($cached !== '') return $cached;
        }
    }

    $keyPath = am_env('FIREBASE_SERVICE_ACCOUNT_KEY', __DIR__ . '/../../firebase-service-account.json');
    if (!file_exists($keyPath)) {
        return '';
    }

    $sa = json_decode(file_get_contents($keyPath), true);
    if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) {
        return '';
    }

    // Build a Firebase custom token (JWT signed with the service account private key)
    $now = time();
    $payload = [
        'iss' => $sa['client_email'],
        'sub' => $sa['client_email'],
        'aud' => 'https://identitytoolkit.googleapis.com/google.identity.identitytoolkit.v1.IdentityToolkit',
        'iat' => $now,
        'exp' => $now + 3600,
        'uid' => 'am-admin-service',
        'claims' => [
            'am_service_account' => true,
        ],
    ];

    $header = ['typ' => 'JWT', 'alg' => 'RS256'];
    $segments = [
        am_jwt_base64url(json_encode($header)),
        am_jwt_base64url(json_encode($payload)),
    ];
    $signingInput = implode('.', $segments);

    $signature = '';
    openssl_sign($signingInput, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256);
    $segments[] = am_jwt_base64url($signature);
    $customToken = implode('.', $segments);

    // Exchange custom token for ID token
    $apiKey = am_env('FIREBASE_WEB_API_KEY', 'AIzaSyD0tA1fvWs5dCr-7JqJv_bxlay2Bhs72jQ');
    $url = "https://identitytoolkit.googleapis.com/v1/accounts:signInWithCustomToken?key=" . urlencode($apiKey);
    $body = json_encode(['token' => $customToken, 'returnSecureToken' => true]);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => !am_allow_insecure_ssl_for_local(),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        error_log("am_firebase_admin_token: failed to mint token (HTTP $code)");
        return '';
    }

    $data = json_decode($resp, true);
    $idToken = $data['idToken'] ?? '';
    if ($idToken === '') {
        error_log("am_firebase_admin_token: no idToken in response");
        return '';
    }

    // Cache
    $cached = $idToken;
    $cachedAt = time();
    @file_put_contents($cacheFile, $idToken);

    return $idToken;
}

function am_jwt_base64url(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Mint a Google OAuth2 access token for the Firebase Admin SDK service account
 * with the Firestore (datastore) scope. Firestore REST treats a service-account
 * OAuth2 access token as ADMIN — security rules are bypassed — so this is the
 * right credential for server-side writes to AM-owned collections (e.g. the
 * canonical sync cache) where the service account is the project owner but is
 * not a Firebase Auth "AM admin" user.
 *
 * Cached for 50 minutes (access tokens last 60 min). Returns '' on failure.
 */
function am_firestore_admin_access_token(): string {
    static $cached = null;
    static $cachedAt = 0;

    if ($cached !== null && (time() - $cachedAt) < 3000) {
        return $cached;
    }

    $cacheFile = sys_get_temp_dir() . '/am_firestore_admin_access_token.cache';
    if (file_exists($cacheFile)) {
        $age = time() - filemtime($cacheFile);
        if ($age < 3000) {
            $cached = trim((string) file_get_contents($cacheFile));
            $cachedAt = time();
            if ($cached !== '') {
                return $cached;
            }
        }
    }

    $keyPath = am_env('FIREBASE_SERVICE_ACCOUNT_KEY', __DIR__ . '/../../firebase-service-account.json');
    if (!file_exists($keyPath)) {
        return '';
    }
    $sa = json_decode((string) file_get_contents($keyPath), true);
    if (!$sa || empty($sa['private_key']) || empty($sa['client_email'])) {
        return '';
    }

    $now = time();
    $assertion = [
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/datastore',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'exp'   => $now + 3600,
        'iat'   => $now,
    ];

    $header = ['typ' => 'JWT', 'alg' => 'RS256'];
    $signingInput = implode('.', [
        am_jwt_base64url(json_encode($header)),
        am_jwt_base64url(json_encode($assertion)),
    ]);
    $signature = '';
    if (!openssl_sign($signingInput, $signature, $sa['private_key'], OPENSSL_ALGO_SHA256)) {
        error_log('am_firestore_admin_access_token: openssl_sign failed');
        return '';
    }
    $jwt = $signingInput . '.' . am_jwt_base64url($signature);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
        CURLOPT_HTTPHEADER => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => !am_allow_insecure_ssl_for_local(),
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200 || !$resp) {
        error_log("am_firestore_admin_access_token: token endpoint HTTP $code");
        return '';
    }
    $data = json_decode((string) $resp, true);
    $accessToken = (string)($data['access_token'] ?? '');
    if ($accessToken === '') {
        error_log('am_firestore_admin_access_token: no access_token in response');
        return '';
    }

    $cached = $accessToken;
    $cachedAt = time();
    @file_put_contents($cacheFile, $accessToken);

    return $accessToken;
}
