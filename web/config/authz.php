<?php
/**
 * AM authorization helpers (Auditor = read-only in UI; Firestore still enforces writes).
 */
require_once __DIR__ . '/app.php';

/** @return array<string, mixed> */
function am_session_capabilities(): array {
    $c = $_SESSION['capabilities'] ?? [];
    return is_array($c) ? $c : [];
}

function am_capability_bool(string $key): bool {
    $v = am_session_capabilities()[$key] ?? false;
    return $v === true || $v === 1 || $v === '1';
}

function am_is_admin_role(): bool {
    return (($_SESSION['role'] ?? '') === 'Admin');
}

function am_is_manager_role(): bool {
    $r = $_SESSION['role'] ?? '';
    return $r === 'Admin' || $r === 'Manager';
}

/** SIM: assign to team / cost pool — Finance workflow; Admin always. */
function am_can_sim_team_assign(): bool {
    if (am_is_auditor_readonly()) {
        return false;
    }
    return am_is_admin_role() || am_capability_bool('sim_team_assign');
}

/** SIM: link to phone handset asset — IT workflow; Admin always. */
function am_can_sim_phone_link(): bool {
    if (am_is_auditor_readonly()) {
        return false;
    }
    return am_is_admin_role() || am_capability_bool('sim_phone_link');
}

/** IT support queue (hardware/software). Managers + Admin + capability. */
function am_can_it_queue_manage(): bool {
    if (am_is_auditor_readonly()) {
        return false;
    }
    return am_is_manager_role() || am_capability_bool('it_queue_manage');
}

/** AM operations queue (non-IT, non-vehicle). */
function am_can_am_ops_queue_manage(): bool {
    if (am_is_auditor_readonly()) {
        return false;
    }
    return am_is_manager_role() || am_capability_bool('am_ops_queue_manage');
}

function am_is_auditor_readonly(): bool {
    return (($_SESSION['role'] ?? '') === 'Auditor');
}

/** @return array<string, mixed> */
function am_privilege_denial_detail(array $requiredRoles, string $action): array {
    $assigned = array_values(array_filter([
        (string)($_SESSION['role'] ?? ''),
        isset($_SESSION['permission_level']) ? 'permissionLevel ' . (string)$_SESSION['permission_level'] : '',
        (string)($_SESSION['department'] ?? ''),
    ]));
    $country = (string)($_SESSION['country'] ?? $_SESSION['country_code'] ?? 'your country');
    return [
        'code' => 'privilege_denied',
        'system' => 'am',
        'action' => $action,
        'assigned_roles' => $assigned ?: ['Viewer'],
        'required_roles' => array_values($requiredRoles),
        'message' => sprintf(
            'Your assigned AM access is %s. To %s, you need one of: %s.',
            implode(', ', $assigned ?: ['Viewer']),
            $action,
            implode(', ', $requiredRoles)
        ),
        'role_crud_owners' => [
            ['owner' => $country . ' HR team', 'manages' => 'Primary/secondary department assignments, Lead status, and scope.'],
            ['owner' => 'Nexus/IS&T User Administrator', 'manages' => 'Explicit Asset Management access or denial in Nexus.'],
            ['owner' => 'Asset Management Superadmin', 'manages' => 'Protected local AM roles and administrator actions.'],
        ],
        'resolution' => 'Ask the appropriate owner to correct the assignment, then sign out and back in to refresh your signed privileges.',
    ];
}

function am_privilege_denial_text(array $requiredRoles, string $action): string {
    $detail = am_privilege_denial_detail($requiredRoles, $action);
    $owners = array_map(
        fn($owner) => ($owner['owner'] ?? 'Access owner') . ': ' . ($owner['manages'] ?? ''),
        $detail['role_crud_owners']
    );
    return implode(' ', [$detail['message'], implode(' ', $owners), $detail['resolution']]);
}

/**
 * Data quality: review suspected duplicate assets (dismiss, request merge, edit links).
 * Managers/Admins always; others need capability duplicate_review or am_ops_queue_manage.
 */
function am_can_duplicate_review(): bool {
    if (am_is_auditor_readonly()) {
        return false;
    }
    if (am_is_manager_role()) {
        return true;
    }
    return am_capability_bool('duplicate_review') || am_can_am_ops_queue_manage();
}

/** Execute merge (delete loser, repoint stock) — Managers and Admins only. */
function am_can_duplicate_merge_execute(): bool {
    if (am_is_auditor_readonly()) {
        return false;
    }
    return am_is_manager_role();
}

function am_require_duplicate_review_access(): void {
    if (!am_can_duplicate_review()) {
        $_SESSION['flash_error'] = am_privilege_denial_text(['Manager', 'Admin', 'duplicate_review capability'], 'review suspected duplicate assets');
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

function am_require_duplicate_merge_execute(): void {
    if (!am_can_duplicate_merge_execute()) {
        $_SESSION['flash_error'] = am_privilege_denial_text(['Manager', 'Admin'], 'merge or delete duplicate asset records');
        header('Location: ' . base_url('reviews/duplicate-review.php'));
        exit;
    }
}

function am_require_can_mutate(): void {
    if (am_is_auditor_readonly()) {
        $_SESSION['flash_error'] = am_privilege_denial_text(['Operator', 'Manager', 'Admin'], 'create or change Asset Management records');
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

function am_require_can_mutate_json(): void {
    if (am_is_auditor_readonly()) {
        http_response_code(403);
        header('Content-Type: application/json');
        $detail = am_privilege_denial_detail(['Operator', 'Manager', 'Admin'], 'create or change Asset Management records');
        echo json_encode(['ok' => false, 'success' => false, 'error' => $detail['message'], 'detail' => $detail]);
        exit;
    }
}
