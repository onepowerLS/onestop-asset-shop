<?php
/**
 * AM authorization helpers (Auditor = read-only in UI; Firestore still enforces writes).
 */
require_once __DIR__ . '/app.php';

function am_has_signed_nexus_privilege(): bool {
    return (($_SESSION['privilege_system'] ?? '') === 'am')
        && trim((string)($_SESSION['privilege_version'] ?? '')) !== '';
}

function am_has_privilege_action(string $action): bool {
    if (!am_has_signed_nexus_privilege()) {
        return false;
    }
    $actions = $_SESSION['privilege_actions'] ?? [];
    return is_array($actions) && in_array($action, $actions, true);
}

/**
 * Authorization is claim-only. The signed Nexus effectivePrivilege claim
 * (actions ladder view_assets < operate_assets < approve_assets <
 * administer_assets) is the sole authority. Sessions without a signed claim
 * (the ?fallback=1 emergency login) are read-only: every privileged check
 * below returns false for them.
 *
 * The retired fallbacks — $_SESSION['role'] ('Admin'/'Manager'/'Operator')
 * and the Firestore-profile capability flags (sim_team_assign,
 * sim_phone_link, it_queue_manage, am_ops_queue_manage, duplicate_review) —
 * are no longer consulted: Nexus grants the corresponding level instead.
 */
function am_is_admin_role(): bool {
    return am_has_privilege_action('administer_assets');
}

function am_is_manager_role(): bool {
    return am_has_privilege_action('approve_assets') || am_has_privilege_action('administer_assets');
}

function am_can_operate_assets(): bool {
    return am_has_privilege_action('operate_assets');
}

/** Personal dispatch / phone / workflow requests (Level D+). */
function am_can_request_assets(): bool {
    return am_has_privilege_action('request_assets')
        || am_can_operate_assets()
        || am_is_manager_role()
        || am_is_admin_role();
}

/** SIM: assign to team / cost pool — delegated operator task (Level C+). */
function am_can_sim_team_assign(): bool {
    return am_can_operate_assets();
}

/** SIM: link to phone handset asset — delegated operator task (Level C+). */
function am_can_sim_phone_link(): bool {
    return am_can_operate_assets();
}

/** IT support queue (hardware/software) — supervisory (Level B+). */
function am_can_it_queue_manage(): bool {
    return am_is_manager_role();
}

/** AM operations queue (non-IT, non-vehicle) — supervisory (Level B+). */
function am_can_am_ops_queue_manage(): bool {
    return am_is_manager_role();
}

function am_is_auditor_readonly(): bool {
    return !am_has_privilege_action('operate_assets');
}

/** @return array<string, mixed> */
function am_privilege_denial_detail(array $requiredRoles, string $action): array {
    $signed = am_has_signed_nexus_privilege();
    $assigned = $signed
        ? ['Level ' . (string)($_SESSION['privilege_level'] ?? 'NONE')]
        : array_values(array_filter([
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
        'assigned_level' => $signed ? (string)($_SESSION['privilege_level'] ?? 'NONE') : null,
        'assigned_actions' => $signed && is_array($_SESSION['privilege_actions'] ?? null)
            ? array_values($_SESSION['privilege_actions'])
            : [],
        'privilege_version' => $signed ? (string)($_SESSION['privilege_version'] ?? '') : '',
        'scope_countries' => $signed && is_array($_SESSION['privilege_scope_countries'] ?? null)
            ? array_values($_SESSION['privilege_scope_countries'])
            : [],
        'scope_organizations' => $signed && is_array($_SESSION['privilege_scope_organizations'] ?? null)
            ? array_values($_SESSION['privilege_scope_organizations'])
            : [],
        'required_roles' => array_values($requiredRoles),
        'message' => sprintf(
            'Your assigned AM access is %s. To %s, you need one of: %s.',
            implode(', ', $assigned ?: ['Viewer']),
            $action,
            implode(', ', $requiredRoles)
        ),
        'role_crud_owners' => $signed && !empty($_SESSION['privilege_role_crud_owners'])
            ? array_map(
                fn($owner) => ['owner' => (string)$owner, 'manages' => 'Assignments contributing to the signed Nexus Asset Management privilege.'],
                (array)$_SESSION['privilege_role_crud_owners']
            )
            : [
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
 * Supervisory action — Level B (approve_assets) or higher.
 */
function am_can_duplicate_review(): bool {
    return am_is_manager_role();
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
        $_SESSION['flash_error'] = am_privilege_denial_text(['Level B (approve assets) or higher'], 'review suspected duplicate assets');
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

function am_require_duplicate_merge_execute(): void {
    if (!am_can_duplicate_merge_execute()) {
        $_SESSION['flash_error'] = am_privilege_denial_text(['Level B (approve assets) or higher'], 'merge or delete duplicate asset records');
        header('Location: ' . base_url('reviews/duplicate-review.php'));
        exit;
    }
}

function am_require_can_request(): void {
    if (!am_can_request_assets()) {
        $_SESSION['flash_error'] = am_privilege_denial_text(['Level D (request assets) or higher'], 'submit a personal Asset Management request');
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

function am_require_can_mutate(): void {
    if (!am_can_operate_assets()) {
        $_SESSION['flash_error'] = am_privilege_denial_text(['Level C (operate assets) or higher'], 'create or change Asset Management records');
        header('Location: ' . base_url('index.php'));
        exit;
    }
}

function am_require_can_mutate_json(): void {
    if (!am_can_operate_assets()) {
        http_response_code(403);
        header('Content-Type: application/json');
        $detail = am_privilege_denial_detail(['Level C (operate assets) or higher'], 'create or change Asset Management records');
        echo json_encode(['ok' => false, 'success' => false, 'error' => $detail['message'], 'detail' => $detail]);
        exit;
    }
}

function am_require_admin(string $redirect = 'index.php'): void {
    if (!am_is_admin_role()) {
        $_SESSION['flash_error'] = am_privilege_denial_text(
            ['Level A / Admin'],
            'administer Asset Management configuration and protected controls'
        );
        header('Location: ' . base_url($redirect));
        exit;
    }
}

function am_require_admin_json(): void {
    if (!is_logged_in()) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['ok' => false, 'success' => false, 'error' => 'Authentication required.']);
        exit;
    }
    if (!am_is_admin_role()) {
        http_response_code(403);
        header('Content-Type: application/json');
        $detail = am_privilege_denial_detail(
            ['Level A / Admin'],
            'administer Asset Management configuration and protected controls'
        );
        echo json_encode(['ok' => false, 'success' => false, 'error' => $detail['message'], 'detail' => $detail]);
        exit;
    }
}
