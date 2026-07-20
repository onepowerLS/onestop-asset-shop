#!/usr/bin/env php
<?php
/**
 * CLI: confirm an employee exists in Firestore directories used by AM dispatch.
 *
 * Usage:
 *   php scripts/firestore_lookup_employee.php maqhena@1pwrafrica.com
 *   php scripts/firestore_lookup_employee.php --name "Maqhena"
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/web/config/app.php';
require_once $root . '/web/config/firestore.php';
require_once $root . '/web/config/employee_directory.php';

$emailArg = '';
$nameArg = '';
foreach (array_slice($argv, 1) as $arg) {
    if ($arg === '--name' && isset($argv[array_search($arg, $argv, true) + 1])) {
        continue;
    }
    if (str_starts_with($arg, '--name=')) {
        $nameArg = substr($arg, 7);
        continue;
    }
    if ($arg === '--name') {
        continue;
    }
    if (str_contains($arg, '@')) {
        $emailArg = $arg;
        continue;
    }
    if ($nameArg === '' && !str_starts_with($arg, '-')) {
        $nameArg = $arg;
    }
}
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--name' && isset($argv[$i + 1])) {
        $nameArg = $argv[$i + 1];
    }
}

$emailLower = strtolower(trim($emailArg));
$nameKey = $nameArg !== '' ? am_employee_directory_name_key($nameArg) : '';

$collections = ['pr_master_employees', 'am_core_employees', 'users', 'nexus_users'];
$hits = [];

foreach ($collections as $coll) {
    $rows = am_firestore_get_collection($coll, 10000);
    foreach ($rows as $row) {
        if (!is_array($row)) {
            continue;
        }
        $emails = am_employee_directory_emails_lower($row);
        $display = am_employee_directory_display_name($row);
        $docId = (string)($row['id'] ?? '');
        $match = false;
        $reason = [];
        if ($emailLower !== '' && in_array($emailLower, $emails, true)) {
            $match = true;
            $reason[] = 'email';
        }
        if ($nameKey !== '' && am_employee_directory_name_key($display) === $nameKey) {
            $match = true;
            $reason[] = 'name';
        }
        if (!$match) {
            continue;
        }
        $hits[] = [
            'collection' => $coll,
            'doc_id' => $docId,
            'display_name' => $display,
            'canonical_email' => am_employee_directory_canonical_email($row),
            'all_emails' => $emails,
            'match' => $reason,
            'active' => $row['isActive'] ?? $row['active'] ?? null,
        ];
    }
}

$merged = am_employee_directory_load();
$inMerged = false;
foreach ($merged as $row) {
    if ($emailLower !== '' && in_array($emailLower, am_employee_directory_emails_lower($row), true)) {
        $inMerged = true;
        break;
    }
    if ($nameKey !== '' && am_employee_directory_name_key(am_employee_directory_display_name($row)) === $nameKey) {
        $inMerged = true;
        break;
    }
}

echo json_encode([
    'project' => am_firestore_project_id(),
    'query' => ['email' => $emailArg, 'name' => $nameArg],
    'raw_hits' => $hits,
    'raw_hit_count' => count($hits),
    'in_am_employee_directory_merge' => $inMerged,
    'dispatch_would_accept_email' => $emailLower !== '' && am_employee_directory_resolve_receiver(
        am_employee_directory_build_indexes($merged)['byEmail'],
        am_employee_directory_build_indexes($merged)['byNameKey'],
        $nameArg !== '' ? $nameArg : 'Receiver',
        $emailArg
    )['ok'],
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";

exit(count($hits) > 0 ? 0 : 1);
