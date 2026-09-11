<?php
/** Trusted callables verify the signed Nexus AM claim independently of this session. */
require_once __DIR__ . '/firestore.php';
function am_call_receipt_function(string $name, array $data): array {
    if (!in_array($name, ['recordAmOrderReceipt', 'reverseAmOrderReceipt', 'confirmAmUgpMapping'], true)) {
        return ['ok' => false, 'message' => 'Unsupported operation'];
    }
    am_firestore_refresh_session_token_from_refresh_token();
    $token = am_firestore_id_token();
    if ($token === '') return ['ok' => false, 'message' => 'Relaunch AM from Nexus.'];
    $result = am_http_request_json('POST', 'https://us-central1-' . am_firestore_project_id() . '.cloudfunctions.net/' . $name,
        ['data' => $data], ['Authorization: Bearer ' . $token]);
    if (!$result['ok'] || isset($result['json']['error'])) {
        return ['ok' => false, 'message' => (string)($result['json']['error']['message'] ?? 'Receipt service unavailable. No successful receipt is assumed; retry with the same receipt ID.')];
    }
    return ['ok' => true, 'result' => $result['json']['result'] ?? $result['json']['data'] ?? []];
}
function am_receipt_csrf_valid(): bool {
    return is_string($_POST['csrf'] ?? null) && hash_equals((string)($_SESSION['am_receipt_csrf'] ?? ''), $_POST['csrf']) && !empty($_SESSION['am_receipt_csrf']);
}
