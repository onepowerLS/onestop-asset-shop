<?php
/**
 * Reconcile am_core_assets.quantity with am_core_inventory_levels.quantity_on_hand.
 *
 * Usage:
 *   php scripts/reconcile_inventory_levels.php --asset-id=e3dcd351-627a-490c-8c34-3f42354cd1a2
 *   php scripts/reconcile_inventory_levels.php --all
 *   php scripts/reconcile_inventory_levels.php --all --dry-run
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

$processed = 0;
$updated = 0;
$created = 0;
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
    if ($locCanon === '') {
        fwrite(STDERR, "Skipping $id: cannot resolve location $locRaw\n");
        continue;
    }

    $targetRows = am_inventory_matching_location_rows($id, $locCanon, $allInv, $locByAnyKey, $countryId);
    $targetInv = null;
    if (!empty($targetRows)) {
        $targetInv = am_inventory_pick_keeper_row($targetRows, $locCanon, $locByAnyKey, $asset);
    }

    $allocTotal = 0;
    foreach ($targetRows as $row) {
        $allocTotal += (int)($row['quantity_allocated'] ?? 0);
    }
    $assetQty = (int)($asset['quantity'] ?? 0);
    $qohTarget = $assetQty > 1 ? $assetQty : max($assetQty, 1);
    if ($qohTarget < $allocTotal) {
        fwrite(STDERR, "Warning: $id asset quantity ($qohTarget) is below allocated ($allocTotal); clamping on-hand to $allocTotal\n");
        $qohTarget = $allocTotal;
    }

    $assetTag = (string)($asset['asset_tag'] ?? $id);
    if ($targetInv) {
        $oldQoh = (int)($targetInv['quantity_on_hand'] ?? 0);
        $oldAlloc = (int)($targetInv['quantity_allocated'] ?? 0);
        if ($oldQoh === $qohTarget && $oldAlloc === $allocTotal) {
            echo "OK    $assetTag (qoh=$oldQoh alloc=$oldAlloc)\n";
        } else {
            echo "UPDATE $assetTag qoh $oldQoh -> $qohTarget, alloc $oldAlloc -> $allocTotal\n";
            if (!$dryRun) {
                am_firestore_update_document('am_core_inventory_levels', (string)$targetInv['id'], [
                    'location_id' => $locCanon,
                    'quantity_on_hand' => $qohTarget,
                    'quantity_allocated' => $allocTotal,
                    'country_id' => $countryId,
                    'updated_at' => date('c'),
                ], $adminToken);
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
            ], null, $adminToken);
        }
        $created++;
    }
    $processed++;
}

echo "\nProcessed: $processed, Updated: $updated, Created: $created" . ($dryRun ? ' (dry run)' : '') . "\n";
