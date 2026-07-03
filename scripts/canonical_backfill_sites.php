#!/usr/bin/env php
<?php
/**
 * One-time backfill: ensure am_reference_sites is populated from the existing
 * `sites` + `referenceData_sites` Firestore collections.
 *
 * Sites are normally push-driven by PR's fanoutSiteChanges Cloud Function into
 * web/api/sync/site-ingest.php. This script copies any sites already visible
 * in the legacy collections into am_reference_sites so the cache is complete
 * from day one (no PR-side endpoint needed).
 *
 * Usage:
 *   php scripts/canonical_backfill_sites.php
 *
 * Requires: FIREBASE_ADMIN_BEARER_TOKEN in .env.
 */

declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/web/config/app.php';
require_once $root . '/web/config/firebase.php';
require_once $root . '/web/config/firestore.php';
require_once $root . '/web/config/canonical_sync.php';

if (am_canonical_admin_token() === '') {
    fwrite(STDERR, "FIREBASE_ADMIN_BEARER_TOKEN not set in .env\n");
    exit(1);
}

$token = am_canonical_admin_token();

// Reuse the existing resolver so the doc shape matches site-ingest.php + am_get_pr_sites().
$orgToCountry = [
    '1pwr_lesotho' => 'LSO',
    '1pwr_benin'   => 'BEN',
    '1pwr_zambia'  => 'ZMB',
];

$sources = [
    'sites'             => 'sites',
    'referenceData_sites' => 'referenceData_sites',
];

$written = 0;
$skipped = 0;

foreach ($sources as $collection => $label) {
    fprintf(STDOUT, "Reading %s ...\n", $collection);
    $docs = am_firestore_get_collection($collection, 1000, $token);
    fprintf(STDOUT, "  %d docs\n", count($docs));
    foreach ($docs as $s) {
        $orgId = strtolower(trim((string)($s['organizationId'] ?? '')));
        if (!isset($orgToCountry[$orgId])) {
            $skipped++;
            continue;
        }
        $code = strtoupper(trim((string)($s['code'] ?? $s['locationCode'] ?? '')));
        $name = trim((string)($s['name'] ?? ''));
        if ($code === '' || $name === '') {
            $skipped++;
            continue;
        }
        $docId = strtolower($orgId . '_' . strtolower($code));
        $payload = [
            'id'             => $docId,
            'organizationId' => $orgId,
            'countryCode'    => $s['countryCode'] ?? $orgToCountry[$orgId],
            'code'           => $code,
            'name'           => $name,
            'active'         => isset($s['active']) ? (bool)$s['active'] : true,
            'latitude'       => isset($s['latitude']) ? (float)$s['latitude'] : null,
            'longitude'      => isset($s['longitude']) ? (float)$s['longitude'] : null,
            'externalIds'    => is_array($s['externalIds'] ?? null) ? $s['externalIds'] : [],
            'source'         => 'backfill_' . $label,
            'lastEventType'  => 'backfill',
            'lastUpdatedAt'  => date('c'),
        ];
        $existing = am_firestore_get_document('am_reference_sites', $docId, $token);
        if (is_array($existing) && !empty($existing['id'])) {
            $skipped++;   // don't clobber fanout-written docs
            continue;
        }
        $payload['createdAt'] = date('c');
        $w = am_firestore_create_document('am_reference_sites', $payload, $docId, $token);
        if ($w['ok']) {
            $written++;
        } else {
            fprintf(STDERR, "  failed %s: %s\n", $docId, $w['error'] ?? 'unknown');
        }
    }
}

am_canonical_record_state('sites', 'backfill', $written, null);

fprintf(STDOUT, "Backfill done: written=%d skipped=%d\n", $written, $skipped);
exit(0);
