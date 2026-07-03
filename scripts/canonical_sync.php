#!/usr/bin/env php
<?php
/**
 * CLI: refresh AM canonical data cache from upstream APIs (PR / HR / FM).
 *
 * Usage:
 *   php scripts/canonical_sync.php                       # refresh all (full)
 *   php scripts/canonical_sync.php --type=all            # refresh all (full)
 *   php scripts/canonical_sync.php --type=employees      # refresh one type
 *   php scripts/canonical_sync.php --type=employees --mode=incremental
 *   php scripts/canonical_sync.php --status              # print status table
 *
 * Required env (in project .env):
 *   FIREBASE_ADMIN_BEARER_TOKEN  (Firestore cache writes)
 *   PR_CATALOG_API_KEY           (PR organizations + countries)
 *   HR_API_KEY_AM_PORTAL         (HR employees + departments)
 *   FLEET_INTEGRATION_API_KEY    (FM vehicles)
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/web/config/app.php';
require_once $root . '/web/config/firebase.php';
require_once $root . '/web/config/firestore.php';
require_once $root . '/web/config/canonical_sync.php';

$type = 'all';
$mode = 'full';
$wantStatus = false;

for ($i = 1; $i < $argc; $i++) {
    $arg = $argv[$i];
    if ($arg === '--status') {
        $wantStatus = true;
        continue;
    }
    if ($arg === '--type' && isset($argv[$i + 1])) {
        $type = $argv[$i + 1];
        $i++;
        continue;
    }
    if (str_starts_with($arg, '--type=')) {
        $type = substr($arg, 7);
        continue;
    }
    if ($arg === '--mode' && isset($argv[$i + 1])) {
        $mode = $argv[$i + 1];
        $i++;
        continue;
    }
    if (str_starts_with($arg, '--mode=')) {
        $mode = substr($arg, 7);
        continue;
    }
    if ($arg === '-h' || $arg === '--help') {
        fwrite(STDOUT, basename(__FILE__) . " --type=all|employees|departments|organizations|countries|vehicles [--mode=full|incremental] [--status]\n");
        exit(0);
    }
}

if (am_canonical_admin_token() === '') {
    fwrite(STDERR, "FIREBASE_ADMIN_BEARER_TOKEN not set in .env\n");
    exit(1);
}

if ($wantStatus) {
    $rows = am_canonical_status();
    fprintf(STDOUT, "%-14s %-4s %-26s %6s %-25s %-7s %s\n",
        'TYPE', 'SRC', 'COLLECTION', 'COUNT', 'LAST_SYNC', 'FRESH', 'ERROR');
    foreach ($rows as $r) {
        fprintf(STDOUT, "%-14s %-4s %-26s %6d %-25s %-7s %s\n",
            $r['type'], $r['source'], $r['collection'], $r['count'],
            $r['last_sync_at'] ?: '(never)', $r['fresh'] ? 'yes' : 'NO',
            $r['last_error'] ?: ($r['push_driven'] ? '(push-driven)' : ''));
    }
    exit(0);
}

fprintf(STDOUT, "Canonical sync — mode=%s type=%s\n", $mode, $type);

if ($type === 'all') {
    $results = am_canonical_refresh_all($mode);
    $exit = 0;
    foreach ($results as $t => $r) {
        fprintf(STDOUT, "  %-14s ok=%s count=%d mode=%s%s\n",
            $t, $r['ok'] ? 'yes' : 'NO', $r['count'], $r['mode'],
            $r['error'] ? ' error=' . $r['error'] : '');
        if (!$r['ok']) {
            $exit = 2;
        }
    }
    exit($exit);
}

$r = am_canonical_refresh($type, $mode);
fprintf(STDOUT, "  %-14s ok=%s count=%d mode=%s%s\n",
    $type, $r['ok'] ? 'yes' : 'NO', $r['count'], $r['mode'],
    $r['error'] ? ' error=' . $r['error'] : '');
exit($r['ok'] ? 0 : 2);
