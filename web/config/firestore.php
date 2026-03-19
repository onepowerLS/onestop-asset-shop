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
        curl_close($ch);

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

function am_firestore_get_document(string $collection, string $documentId): ?array {
    $token = am_firestore_id_token();
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

function am_firestore_create_document(string $collection, array $data, ?string $documentId = null): array {
    $token = am_firestore_id_token();
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

function am_firestore_update_document(string $collection, string $documentId, array $data): array {
    $token = am_firestore_id_token();
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

function am_firestore_delete_document(string $collection, string $documentId): array {
    $token = am_firestore_id_token();
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

function am_firestore_get_collection(string $collectionName, int $pageSize = 1000): array {
    $token = am_firestore_id_token();
    if ($token === '') {
        return [];
    }

    $project = am_firestore_project_id();
    $url = 'https://firestore.googleapis.com/v1/projects/' . rawurlencode($project) .
        '/databases/(default)/documents/' . rawurlencode($collectionName) .
        '?pageSize=' . max(1, min(1000, $pageSize));

    $result = am_http_get_json($url, ['Authorization: Bearer ' . $token]);
    if (!$result['ok']) {
        return [];
    }

    $docs = $result['json']['documents'] ?? [];
    if (!is_array($docs)) {
        return [];
    }

    $out = [];
    foreach ($docs as $doc) {
        if (is_array($doc)) {
            $out[] = am_firestore_document_to_array($doc);
        }
    }
    return $out;
}
