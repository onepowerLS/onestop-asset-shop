#!/usr/bin/env php
<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/web/config/firebase.php';
require_once dirname(__DIR__) . '/web/config/authz.php';
require_once dirname(__DIR__) . '/web/config/country_scope.php';

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
authz_expect(!am_can_request_assets(), 'view-only Level D cannot submit requests');
authz_expect(am_is_auditor_readonly(), 'signed view-only Level D remains warehouse read-only');

$_SESSION = [
    'role' => 'Viewer',
    'privilege_system' => 'am',
    'privilege_level' => 'D',
    'privilege_actions' => ['view_assets', 'request_assets'],
    'privilege_version' => '2026.08.25.1',
];
authz_expect(am_can_request_assets(), 'Level D requester can submit personal requests');
authz_expect(!am_can_operate_assets(), 'Level D requester cannot operate warehouse records');
authz_expect(am_is_auditor_readonly(), 'Level D requester is still warehouse read-only');

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
// Direct (non-SSO) logins are read-only by policy: no signed claim, no
// privileged action — regardless of the legacy display role or retired
// capability flags.
$_SESSION = ['role' => 'Operator'];
authz_expect(!am_can_operate_assets(), 'unsigned Operator display role grants nothing');
$_SESSION = ['role' => 'Admin', 'capabilities' => ['sim_team_assign' => true]];
authz_expect(!am_is_admin_role(), 'unsigned Admin display role grants nothing');
authz_expect(!am_can_sim_team_assign(), 'retired capability flag grants nothing');
authz_expect(!am_is_manager_role(), 'unsigned session is not a manager');
authz_expect(am_is_auditor_readonly(), 'unsigned session is read-only');

authz_expect(am_map_nexus_privilege_level_to_role('A') === 'Admin', 'Level A maps to Admin display role');
authz_expect(am_map_nexus_privilege_level_to_role('B') === 'Manager', 'Level B maps to Manager display role');
authz_expect(am_map_nexus_privilege_level_to_role('C') === 'Operator', 'Level C maps to Operator display role');
authz_expect(am_map_nexus_privilege_level_to_role('D') === 'Viewer', 'Level D maps to Viewer display role');

// Nexus signs scopeCountries as ISO-2; AM internals are ISO-3. The aliases
// must resolve or a single-country grant would silently widen to global.
authz_expect(am_normalize_country_codes(['BJ']) === ['BEN'], 'ISO-2 BJ maps to internal BEN');
authz_expect(am_normalize_country_codes(['BN']) === ['BEN'], 'alias BN maps to internal BEN');
authz_expect(am_normalize_country_codes(['ls', 'zm']) === ['LSO', 'ZMB'], 'ISO-2 LS/ZM map case-insensitively');
authz_expect(am_normalize_country_codes(['LSO', 'ZMB', 'BEN']) === ['LSO', 'ZMB', 'BEN'], 'ISO-3 codes pass through');
// A scoped claim must survive end-to-end: BJ-only grant yields BEN-only allow.
authz_expect(am_apply_default_country_allow_if_empty(['BJ']) === ['BEN'], 'scoped Benin grant is not widened');
// Empty signed scope is a GLOBAL grant (unscoped assignment), not "no access".
authz_expect(am_apply_default_country_allow_if_empty([]) === am_org_country_codes(), 'empty scope defaults to global');

fwrite(STDOUT, "authz_privilege_test: OK\n");
