<?php
/**
 * Reconcile am_core_assets.quantity with am_core_inventory_levels.
 *
 * Detects:
 *   - sum(levels.quantity_on_hand) != assets.quantity
 *   - duplicate (asset_id, canonical location) pairs
 *   - unresolvable location_id values (e.g. site1)
 *   - orphan full-qty rows at sites other than the asset's current location
 *
 * Apply mode may align the *current* site row to assets.quantity and canonicalize
 * assets.location_id. Cross-site orphans are marked reconciliation_status=unverified
 * (never invent which site holds the stock — that needs a human drum count).
 *
 * Usage:
 *   php scripts/reconcile_inventory_levels.php --asset-id=<id> [--dry-run]
 *   php scripts/reconcile_inventory_levels.php --all [--dry-run]
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/web/config/app.php';
require_once $root . '/web/config/firestore.php';
require_once $root . '/web/config/firebase_admin_token.php';
require_once $root . '/web/config/inventory_levels.php';

$dryRun = in_array('--dry-run', $argv, true);
$all = in_array('--all', $argv, true);
$assetId = null;
foreach ($argv as $arg) {
    if (str_starts_with($arg, '--asset-id=')) {
        $assetId = substr($arg, 11);
    }
}

if ($assetId === null && !$all) {
    fwrite(STDERR, "Usage: php scripts/reconcile_inventory_levels.php --asset-id=<id> [--dry-run]\n");
    fwrite(STDERR, "       php scripts/reconcile_inventory_levels.php --all [--dry-run]\n");
    exit(1);
}

$adminToken = am_firestore_admin_access_token();
if ($adminToken === '') {
    $adminToken = am_firebase_admin_token();
}
if ($adminToken === '') {
    fwrite(STDERR, "No admin token available. Check firebase-service-account.json or FIREBASE_ADMIN_BEARER_TOKEN in .env\n");
    exit(1);
}
$_SESSION['firebase_id_token'] = $adminToken;

$stockable = ['Material', 'Consumable', 'Inventory'];
$locations = am_get_pr_sites();
$locByAnyKey = am_build_location_index($locations);
$allInv = am_firestore_get_collection('am_core_inventory_levels', 5000);

$assets = [];
if ($all) {
    $assets = am_firestore_get_collection('am_core_assets', 10000);
} else {
    $asset = am_firestore_get_document('am_core_assets', (string)$assetId);
    if (!$asset) {
        fwrite(STDERR, "Asset not found: $assetId\n");
        exit(1);
    }
    $assets = [$asset];
}

$invByAsset = [];
foreach ($allInv as $inv) {
    $aid = (string)($inv['asset_id'] ?? '');
    if ($aid === '') {
        continue;
    }
    if (!isset($invByAsset[$aid])) {
        $invByAsset[$aid] = [];
    }
    $invByAsset[$aid][] = $inv;
}

/** Named docs from BRIEF_INVENTORY_LEVELS_DOUBLE_COUNT_20260910.md */
$briefNamedDocs = [
    'gQx9i79IFSIG8BRmYQ3f' => 'ABC LSO-HQ — keep if asset location is LSO-HQ; else unverified',
    'Vyud5NIDOWeAgPrZvQ90' => 'ABC LSO-MAK orphan after site change — quarantine unverified',
    'qkzZNXY6UT7JTMxSObaL' => 'ABC LSO-MAS partial — unverified until drum count',
    '12358a4ab8f4ba0dc8c1' => 'ABC LSO-TOS zero — ok to leave at 0',
    'FZ4FyRxkUTTekXHk2gAX' => 'Squirrel LSO-MAK — verify vs asset location',
    'HhKMly0i0lvXKyX80C37' => 'Squirrel LSO-HQ stale snapshot — unverified',
    'IDGcJKlMaA5RMzGf3GwF' => 'Squirrel LSO-MAS zero — ok',
    'eWiPKMosZFvqiSbOcUQq' => 'Airdac LSO-HQ — verify vs asset location',
    'KMCb3iCNFuh3QjZLRe3W' => 'Airdac site1 unresolvable — quarantine unverified',
    'dTaIsFSrvwU4OIshDV6A' => 'Airdac LSO-TOS partial — unverified until drum count',
];

$processed = 0;
$updated = 0;
$created = 0;
$quarantined = 0;
$violations = 0;

foreach ($assets as $asset) {
    $cls = (string)($asset['item_class'] ?? '');
    if (!in_array($cls, $stockable, true)) {
        continue;
    }
    $id = (string)($asset['id'] ?? $asset['asset_id'] ?? '');
    if ($id === '') {
        continue;
    }
    $countryId = (string)($asset['country_id'] ?? '');
    if ($countryId === '') {
        fwrite(STDERR, "Skipping $id: no country_id\n");
        continue;
    }
    $locRaw = (string)($asset['location_id'] ?? '');
    if ($locRaw === '') {
        fwrite(STDERR, "Skipping $id: no location_id\n");
        continue;
    }
    $locCanon = am_canonical_location_code($locRaw, $locByAnyKey);
    $assetTag = (string)($asset['asset_tag'] ?? $id);
    $assetQty = (int)($asset['quantity'] ?? 0);
    $rows = $invByAsset[$id] ?? [];

    $status = am_inventory_reconciliation_status_for_asset($asset, $rows, $locByAnyKey);
    $sum = 0;
    foreach ($rows as $r) {
        $sum += (int)($r['quantity_on_hand'] ?? 0);
    }
    $dups = am_inventory_duplicate_location_codes($rows, $locByAnyKey);

    if ($status !== 'ok') {
        $violations++;
        echo "VIOLATION $assetTag status=$status sum=$sum asset_qty=$assetQty";
        if ($dups !== []) {
            echo ' dups=[' . implode(',', $dups) . ']';
        }
        echo "\n";
    }

    if ($locCanon === '') {
        echo "  UNRESOLVABLE asset location_id=$locRaw — skip apply; fix asset location first\n";
        $processed++;
        continue;
    }

    // Align current-site keeper to assets.quantity
    $targetRows = am_inventory_matching_location_rows($id, $locCanon, $rows, $locByAnyKey, $countryId);
    $targetInv = null;
    if (!empty($targetRows)) {
        $targetInv = am_inventory_pick_keeper_row($targetRows, $locCanon, $locByAnyKey, $asset);
    }

    $allocTotal = 0;
    foreach ($targetRows as $row) {
        $allocTotal += (int)($row['quantity_allocated'] ?? 0);
    }
    $qohTarget = max($assetQty, $allocTotal);
    if ($assetQty < $allocTotal) {
        fwrite(STDERR, "Warning: $id asset quantity ($assetQty) is below allocated ($allocTotal); clamping on-hand to $allocTotal\n");
    }

    $assetLocChanged = ($locRaw !== $locCanon);
    if ($targetInv) {
        $oldQoh = (int)($targetInv['quantity_on_hand'] ?? 0);
        $oldAlloc = (int)($targetInv['quantity_allocated'] ?? 0);
        $oldLoc = (string)($targetInv['location_id'] ?? '');
        if ($oldQoh === $qohTarget && $oldAlloc === $allocTotal && $oldLoc === $locCanon) {
            if ($status === 'ok') {
                echo "OK    $assetTag (qoh=$oldQoh alloc=$oldAlloc at $locCanon)\n";
            }
        } else {
            echo "UPDATE $assetTag current-site qoh $oldQoh -> $qohTarget, alloc $oldAlloc -> $allocTotal, loc $oldLoc -> $locCanon\n";
            if (!$dryRun) {
                am_firestore_update_document('am_core_inventory_levels', (string)$targetInv['id'], [
                    'location_id' => $locCanon,
                    'quantity_on_hand' => $qohTarget,
                    'quantity_allocated' => $allocTotal,
                    'country_id' => $countryId,
                    'updated_at' => date('c'),
                    'reconciliation_status' => 'ok',
                ], $adminToken);
            }
            $updated++;
        }
        // Delete same-canonical alias dupes
        foreach ($targetRows as $dup) {
            $dupId = (string)($dup['id'] ?? '');
            if ($dupId === '' || $dupId === (string)$targetInv['id']) {
                continue;
            }
            echo "  DELETE alias dup $dupId at $locCanon\n";
            if (!$dryRun) {
                am_firestore_delete_document('am_core_inventory_levels', $dupId, $adminToken);
            }
            $updated++;
        }
    } else {
        echo "CREATE $assetTag qoh=$qohTarget alloc=$allocTotal at $locCanon\n";
        if (!$dryRun) {
            am_firestore_create_document('am_core_inventory_levels', [
                'asset_id' => $id,
                'location_id' => $locCanon,
                'country_id' => $countryId,
                'quantity_on_hand' => $qohTarget,
                'quantity_allocated' => $allocTotal,
                'created_at' => date('c'),
                'updated_at' => date('c'),
                'reconciliation_status' => 'ok',
            ], null, $adminToken);
        }
        $created++;
    }

    // Quarantine orphans / unresolvable rows — do not invent store vs site split
    foreach ($rows as $row) {
        $rid = (string)($row['id'] ?? '');
        $rawLoc = trim((string)($row['location_id'] ?? ''));
        $rowCanon = am_canonical_location_code($rawLoc, $locByAnyKey);
        $qoh = (int)($row['quantity_on_hand'] ?? 0);
        $reason = '';
        if ($rowCanon === '' && $rawLoc !== '') {
            $reason = 'unresolvable_location';
        } elseif ($rowCanon !== '' && $rowCanon !== $locCanon && $qoh > 0) {
            $reason = 'orphan_non_current_site';
        }
        if ($reason === '') {
            continue;
        }
        if ($targetInv && $rid === (string)$targetInv['id']) {
            continue;
        }
        $briefNote = $briefNamedDocs[$rid] ?? '';
        echo "  QUARANTINE $rid loc=$rawLoc qoh=$qoh reason=$reason"
            . ($briefNote !== '' ? " ($briefNote)" : '') . "\n";
        if (!$dryRun) {
            am_firestore_update_document('am_core_inventory_levels', $rid, [
                'reconciliation_status' => 'unverified',
                'reconciliation_note' => $reason . ($briefNote !== '' ? '; ' . $briefNote : ''),
                'updated_at' => date('c'),
            ], $adminToken);
        }
        $quarantined++;
    }

    if ($assetLocChanged && !$dryRun) {
        am_firestore_update_document('am_core_assets', $id, [
            'location_id' => $locCanon,
            'updated_at' => date('c'),
        ], $adminToken);
    }
    $processed++;
}

echo "\nProcessed: $processed, Updated: $updated, Created: $created, Quarantined: $quarantined, Violations: $violations"
    . ($dryRun ? ' (dry run)' : '') . "\n";
exit($violations > 0 ? 2 : 0);
