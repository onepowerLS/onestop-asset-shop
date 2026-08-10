#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/config/firebase.php';
require_once dirname(__DIR__) . '/web/config/authz.php';

function authz_expect(bool $condition, string $message): void {
    if (!$condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function test_jwt(array $payload): string {
    $encode = static fn(array $value): string => rtrim(strtr(base64_encode(json_encode($value)), '+/', '-_'), '=');
    return $encode(['alg' => 'none', 'typ' => 'JWT']) . '.' . $encode($payload) . '.fixture';
}

$privilege = am_nexus_privilege_from_verified_id_token(test_jwt([
    'nexus_sso' => true,
    'targetSystem' => 'am',
    'privilegeVersion' => '2026.08.10.3',
    'effectivePrivilege' => [
        'level' => 'C',
        'actions' => ['view_assets', 'operate_assets'],
        'scopeCountries' => ['BJ'],
        'scopeOrganizations' => ['1pwr-bj'],
        'roleCrudOwners' => ['Benin HR team'],
    ],
]));
authz_expect(is_array($privilege), 'signed AM privilege is extracted');
authz_expect($privilege['level'] === 'C', 'signed level is retained');
authz_expect($privilege['actions'] === ['view_assets', 'operate_assets'], 'signed actions are retained');
authz_expect($privilege['scope_countries'] === ['BJ'], 'country scope is retained');

$_SESSION = [
    'role' => 'Viewer',
    'privilege_system' => 'am',
    'privilege_level' => 'C',
    'privilege_actions' => ['view_assets', 'operate_assets'],
    'privilege_version' => '2026.08.10.3',
];
authz_expect(am_can_operate_assets(), 'signed Level C operator can mutate assets');
authz_expect(!am_is_admin_role(), 'signed Level C operator is not an administrator');

$_SESSION = [
    'role' => 'Admin',
    'privilege_system' => 'am',
    'privilege_level' => 'D',
    'privilege_actions' => ['view_assets'],
    'privilege_version' => '2026.08.10.3',
];
authz_expect(!am_can_operate_assets(), 'signed grant overrides a stale local Admin display role');
authz_expect(am_is_auditor_readonly(), 'signed Level D remains read-only');

$_SESSION = [
    'role' => 'Viewer',
    'privilege_system' => 'am',
    'privilege_level' => 'A',
    'privilege_actions' => ['view_assets', 'operate_assets', 'approve_assets', 'administer_assets'],
    'privilege_version' => '2026.08.10.3',
];
authz_expect(am_is_admin_role(), 'signed Level A can administer AM even when the legacy display role is stale');

$_SESSION = ['role' => 'Viewer'];
authz_expect(!am_can_operate_assets(), 'legacy Viewer is read-only');
$_SESSION = ['role' => 'Operator'];
authz_expect(am_can_operate_assets(), 'legacy emergency Operator retains write access');

authz_expect(am_map_nexus_privilege_level_to_role('A') === 'Admin', 'Level A maps to Admin display role');
authz_expect(am_map_nexus_privilege_level_to_role('B') === 'Manager', 'Level B maps to Manager display role');
authz_expect(am_map_nexus_privilege_level_to_role('C') === 'Operator', 'Level C maps to Operator display role');
authz_expect(am_map_nexus_privilege_level_to_role('D') === 'Viewer', 'Level D maps to Viewer display role');

fwrite(STDOUT, "authz_privilege_test: OK\n");
