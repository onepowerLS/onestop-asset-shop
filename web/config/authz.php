<?php
/**
 * AM authorization helpers (Auditor = read-only in UI; Firestore still enforces writes).
 */
require_once __DIR__ . '/app.php';

function am_is_auditor_readonly(): bool {
    return (($_SESSION['role'] ?? '') === 'Auditor');
}

function am_require_can_mutate(): void {
    if (am_is_auditor_readonly()) {
        $_SESSION['flash_error'] = 'Your account has read-only access.';
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

function am_require_can_mutate_json(): void {
    if (am_is_auditor_readonly()) {
        http_response_code(403);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'success' => false, 'error' => 'Read-only access.']);
        exit;
    }
}
