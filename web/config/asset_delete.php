<?php
require_once __DIR__ . '/app.php';
require_once __DIR__ . '/firestore.php';
require_once __DIR__ . '/authz.php';

const AM_DELETED_ASSETS_COLLECTION = 'am_core_deleted_assets';

/**
 * Archive an asset snapshot, then delete from active catalog.
 *
 * Guardrails:
 * - blocks delete when active allocation exists
 * - blocks delete when inventory is still on hand/allocated
 *
 * @return array{ok: bool, error?: string, archived_id?: string}
 */
function am_asset_archive_and_delete(string $assetId, string $reason = '', ?string $idTokenOverride = null): array {
    $assetId = trim($assetId);
    if ($assetId === '') {
        return ['ok' => false, 'error' => 'Missing asset id.'];
    }

    $asset = am_firestore_get_document('am_core_assets', $assetId, $idTokenOverride);
    if (!$asset || !is_array($asset)) {
        return ['ok' => false, 'error' => 'Item not found or already deleted.'];
    }

    // Guard: active allocations must be cleared first.
    $allocs = am_firestore_get_collection('am_core_allocations', 4000, $idTokenOverride);
    foreach ($allocs as $al) {
        if ((string)($al['asset_id'] ?? '') !== $assetId) {
            continue;
        }
        if ((string)($al['status'] ?? '') === 'Active') {
            return ['ok' => false, 'error' => 'Cannot delete: item has active allocations. Check it in / close allocations first.'];
        }
    }

    // Guard: stock must be zero first.
    $levels = am_firestore_get_collection('am_core_inventory_levels', 5000, $idTokenOverride);
    foreach ($levels as $lvl) {
        if ((string)($lvl['asset_id'] ?? '') !== $assetId) {
            continue;
        }
        $qoh = (int)($lvl['quantity_on_hand'] ?? 0);
        $qa = (int)($lvl['quantity_allocated'] ?? 0);
        if ($qoh > 0 || $qa > 0) {
            return ['ok' => false, 'error' => 'Cannot delete: item still has inventory on hand/allocated.'];
        }
    }

    $uid = (string)($_SESSION['user_id'] ?? $_SESSION['firebase_uid'] ?? '');
    $email = trim((string)($_SESSION['email'] ?? $_SESSION['user_email'] ?? ''));
    $display = trim((string)($_SESSION['username'] ?? ''));

    $archive = [
        'asset_id' => $assetId,
        'archived_asset' => $asset,
        'deleted_at' => gmdate('c'),
        'deleted_by_uid' => $uid,
        'deleted_by_email' => $email,
        'deleted_by_display_name' => $display,
        'delete_reason' => trim($reason),
        'status' => 'archived',
    ];

    $cr = am_firestore_create_document(AM_DELETED_ASSETS_COLLECTION, $archive, null, $idTokenOverride);
    if (empty($cr['ok'])) {
        return ['ok' => false, 'error' => 'Could not archive item before delete: ' . (string)($cr['error'] ?? '')];
    }

    $del = am_firestore_delete_document('am_core_assets', $assetId, $idTokenOverride);
    if (empty($del['ok'])) {
        return ['ok' => false, 'error' => 'Archive saved, but delete failed: ' . (string)($del['error'] ?? '')];
    }

    return ['ok' => true, 'archived_id' => (string)($cr['id'] ?? '')];
}
