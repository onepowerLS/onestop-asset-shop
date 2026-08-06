<?php
/**
 * Detail view for inventory dispatch requests.
 * Reads am_core_requests — renders line items, destination, receiver cards.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/request_workflows.php';
require_once __DIR__ . '/../config/inventory_dispatch.php';
require_login();

$docId = trim($_GET['id'] ?? '');
if ($docId === '') {
    header('Location: ' . base_url('requests/workflow-index.php'));
    exit;
}

$req = am_firestore_get_document('am_core_requests', $docId);
if (!$req) {
    $_SESSION['flash_error'] = 'Request not found.';
    header('Location: ' . base_url('requests/workflow-index.php'));
    exit;
}

// Redirect non-dispatch types to generic view
$wfType = (string)($req['workflow_type'] ?? '');
if ($wfType !== 'inventory_dispatch') {
    header('Location: ' . base_url('requests/workflow-view.php?id=' . urlencode($docId)));
    exit;
}

$currentStatus = (string)($req['status'] ?? '');
$operationErrors = [];

// ── Status update (POST) ────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    am_require_can_mutate();
    $action = $_POST['action'] ?? '';
    if ($action === 'update_status') {
        $newStatus = trim($_POST['new_status'] ?? '');
        $alsoApprove = false;
        if ($newStatus === 'approve_and_fulfill') {
            $newStatus = 'Fulfilled';
            $alsoApprove = true;
        }
        if (in_array($newStatus, ['Approved', 'Rejected', 'Fulfilled', 'Cancelled'], true)) {
            $update = ['status' => $newStatus];
            $workPayload = $req['payload'] ?? [];
            if (!is_array($workPayload)) {
                $workPayload = [];
            }

            // For Approved/Fulfilled, apportion against available stock at source location.
            if ($newStatus === 'Approved' || $newStatus === 'Fulfilled') {
                $allInvLevels = am_firestore_get_collection('am_core_inventory_levels', 5000);
                $allLocations = am_get_pr_sites();
                $allAssets = am_firestore_get_collection('am_core_assets', 10000);

                $assetById = [];
                foreach ($allAssets as $a) {
                    $aid = (string)($a['asset_id'] ?? $a['id'] ?? '');
                    if ($aid !== '') {
                        $assetById[$aid] = $a;
                    }
                }

                $locByAnyKey = [];
                foreach ($allLocations as $l) {
                    $lid = (string)($l['id'] ?? $l['location_id'] ?? '');
                    $lcode = (string)($l['location_code'] ?? '');
                    if ($lid !== '') {
                        $locByAnyKey[$lid] = $l;
                    }
                    if ($lcode !== '' && $lcode !== $lid) {
                        $locByAnyKey[$lcode] = $l;
                    }
                }

                $reqCountryId = (string)($req['requested_for_country'] ?? '');
                $destSiteCode = (string)($workPayload['site_code'] ?? '');
                $destLoc = $locByAnyKey[$destSiteCode] ?? [];
                $destLocationCode = (string)($destLoc['location_code'] ?? $destSiteCode);

                $invByKey = [];
                foreach ($allInvLevels as $inv) {
                    $iaid = (string)($inv['asset_id'] ?? '');
                    $iloc = (string)($inv['location_id'] ?? '');
                    $icid = (string)($inv['country_id'] ?? '');
                    if ($iaid !== '' && $iloc !== '') {
                        $invByKey[$iaid . '|' . $iloc . '|' . $icid] = $inv;
                    }
                }

                $items = $workPayload['line_items'] ?? [];
                if (!is_array($items)) {
                    $items = [];
                }

                $shortNotes = [];
                foreach ($items as $idx => $li) {
                    $liAssetId = (string)($li['asset_id'] ?? '');
                    $reqQty = (int)($li['quantity'] ?? 0);
                    if ($liAssetId === '' || $reqQty <= 0) {
                        continue;
                    }
                    $asset = $assetById[$liAssetId] ?? [];
                    if (!$asset) {
                        continue;
                    }

                    $srcLocId = (string)($asset['location_id'] ?? '');
                    $srcLoc = $locByAnyKey[$srcLocId] ?? [];
                    $srcLocationCode = (string)($srcLoc['location_code'] ?? $srcLocId);
                    if ($srcLocationCode === '') {
                        continue;
                    }

                    $srcKey = $liAssetId . '|' . $srcLocationCode . '|' . $reqCountryId;
                    $srcInv = $invByKey[$srcKey] ?? null;
                    $srcQoh = $srcInv ? (int)($srcInv['quantity_on_hand'] ?? 0) : (int)($asset['quantity'] ?? 0);
                    $srcAlloc = $srcInv ? (int)($srcInv['quantity_allocated'] ?? 0) : 0;
                    $available = max(0, $srcQoh - $srcAlloc);

                    // Approved: reserve available quantity and write the immutable
                    // allocation event in the same Firestore commit.
                    if ($newStatus === 'Approved' || $alsoApprove) {
                        $allocationEventId = am_dispatch_event_id($docId, (int)$idx, 'allocation');
                        $existingAllocationEvent = am_firestore_get_document('am_core_transactions', $allocationEventId);
                        $allocQty = $existingAllocationEvent
                            ? (int)($existingAllocationEvent['quantity'] ?? 0)
                            : min($reqQty, $available);
                        $shortQty = max(0, $reqQty - $allocQty);
                        $items[$idx]['allocated_quantity'] = $allocQty;
                        $items[$idx]['short_quantity'] = $shortQty;
                        $items[$idx]['allocation_updated_at'] = date('c');

                        if ($allocQty > 0 && !$existingAllocationEvent) {
                            $inventoryOps = [];
                            $newAlloc = $srcAlloc + $allocQty;
                            if ($srcInv) {
                                $inventoryOps[] = [
                                    'mode' => 'update',
                                    'collection' => 'am_core_inventory_levels',
                                    'id' => (string)$srcInv['id'],
                                    'data' => [
                                        'quantity_allocated' => $newAlloc,
                                        'updated_at' => date('c'),
                                    ],
                                ];
                            } else {
                                $inventoryId = am_firestore_random_document_id();
                                $inventoryOps[] = [
                                    'mode' => 'create',
                                    'collection' => 'am_core_inventory_levels',
                                    'id' => $inventoryId,
                                    'data' => [
                                        'asset_id' => $liAssetId,
                                        'location_id' => $srcLocationCode,
                                        'country_id' => $reqCountryId,
                                        'quantity_on_hand' => $srcQoh,
                                        'quantity_allocated' => $allocQty,
                                        'created_at' => date('c'),
                                        'updated_at' => date('c'),
                                    ],
                                ];
                                $srcInv = [
                                    'id' => $inventoryId,
                                    'asset_id' => $liAssetId,
                                    'location_id' => $srcLocationCode,
                                    'country_id' => $reqCountryId,
                                    'quantity_on_hand' => $srcQoh,
                                    'quantity_allocated' => 0,
                                ];
                            }

                            $allocationTxn = am_dispatch_transaction_data(
                                'Allocation',
                                $liAssetId,
                                $allocQty,
                                $srcLocationCode,
                                $destLocationCode,
                                array_merge($req, ['id' => $docId]),
                                $workPayload,
                                'reserved'
                            );
                            $commit = am_dispatch_commit_event($inventoryOps, $allocationEventId, $allocationTxn);
                            if (!$commit['ok']) {
                                $operationErrors[] = 'Could not reserve ' . (string)($li['name'] ?? $liAssetId)
                                    . ': ' . (string)($commit['error'] ?? 'unknown Firestore error');
                                break;
                            }
                            $srcInv['quantity_allocated'] = $newAlloc;
                            $invByKey[$srcKey] = $srcInv;
                        }
                        if ($shortQty > 0) {
                            $shortNotes[] = (string)($li['name'] ?? ('Item #' . ($idx + 1))) . ': short by ' . $shortQty;
                        }
                    }

                    // Fulfilled: issue stock to the receiver at the same site, or
                    // transfer it to a different site. Source balance changes and
                    // the immutable fulfillment event are one atomic commit.
                    if ($newStatus === 'Fulfilled') {
                        $allocQty = $alsoApprove ? (int)($items[$idx]['allocated_quantity'] ?? 0) : (int)($li['allocated_quantity'] ?? 0);
                        $fulfillmentEventId = am_dispatch_event_id($docId, (int)$idx, 'fulfillment');
                        $existingFulfillmentEvent = am_firestore_get_document('am_core_transactions', $fulfillmentEventId);
                        $moveQty = $existingFulfillmentEvent
                            ? (int)($existingFulfillmentEvent['quantity'] ?? 0)
                            : ($allocQty > 0 ? $allocQty : min($reqQty, $available));
                        $unfulfilled = max(0, $reqQty - $moveQty);
                        $items[$idx]['fulfilled_quantity'] = $moveQty;
                        $items[$idx]['unfulfilled_quantity'] = $unfulfilled;
                        $items[$idx]['fulfilled_updated_at'] = date('c');
                        if ($moveQty <= 0 || $existingFulfillmentEvent) {
                            continue;
                        }

                        $sameLocation = $srcLocationCode === $destLocationCode;
                        $inventoryOps = [];

                        // Source: deduct on-hand and release allocation.
                        if ($srcInv) {
                            $balances = am_dispatch_fulfillment_balances(
                                (int)($srcInv['quantity_on_hand'] ?? 0),
                                (int)($srcInv['quantity_allocated'] ?? 0),
                                $allocQty,
                                $moveQty,
                                $sameLocation
                            );
                            $newQoh = $balances['source_on_hand'];
                            $newAlloc = $balances['source_allocated'];
                            $inventoryOps[] = [
                                'mode' => 'update',
                                'collection' => 'am_core_inventory_levels',
                                'id' => (string)$srcInv['id'],
                                'data' => [
                                    'quantity_on_hand' => $newQoh,
                                    'quantity_allocated' => $newAlloc,
                                    'updated_at' => date('c'),
                                ],
                            ];
                        } else {
                            $sourceInventoryId = am_firestore_random_document_id();
                            $newQoh = max(0, (int)($asset['quantity'] ?? 0) - $moveQty);
                            $newAlloc = 0;
                            $inventoryOps[] = [
                                'mode' => 'create',
                                'collection' => 'am_core_inventory_levels',
                                'id' => $sourceInventoryId,
                                'data' => [
                                    'asset_id' => $liAssetId,
                                    'location_id' => $srcLocationCode,
                                    'country_id' => $reqCountryId,
                                    'quantity_on_hand' => $newQoh,
                                    'quantity_allocated' => 0,
                                    'created_at' => date('c'),
                                    'updated_at' => date('c'),
                                ],
                            ];
                            $srcInv = [
                                'id' => $sourceInventoryId,
                                'asset_id' => $liAssetId,
                                'location_id' => $srcLocationCode,
                                'country_id' => $reqCountryId,
                            ];
                        }

                        if ($sameLocation && in_array((string)($asset['item_class'] ?? ''), ['Consumable', 'Material', 'Inventory'], true)) {
                            $newAssetQuantity = max(0, (int)($asset['quantity'] ?? $srcQoh) - $moveQty);
                            $inventoryOps[] = [
                                'mode' => 'update',
                                'collection' => 'am_core_assets',
                                'id' => $liAssetId,
                                'data' => [
                                    'quantity' => $newAssetQuantity,
                                    'updated_at' => date('c'),
                                ],
                            ];
                        }

                        // A same-site dispatch is an issue/consumption, not a
                        // transfer back into the same stock row.
                        $destKey = '';
                        $destInv = null;
                        if (!$sameLocation) {
                            $destKey = $liAssetId . '|' . $destLocationCode . '|' . $reqCountryId;
                            $destInv = $invByKey[$destKey] ?? null;
                            if ($destInv) {
                                $destBalances = am_dispatch_fulfillment_balances(
                                    (int)($srcInv['quantity_on_hand'] ?? 0),
                                    (int)($srcInv['quantity_allocated'] ?? 0),
                                    $allocQty,
                                    $moveQty,
                                    false,
                                    (int)($destInv['quantity_on_hand'] ?? 0)
                                );
                                $destQoh = (int)$destBalances['destination_on_hand'];
                                $inventoryOps[] = [
                                    'mode' => 'update',
                                    'collection' => 'am_core_inventory_levels',
                                    'id' => (string)$destInv['id'],
                                    'data' => [
                                        'quantity_on_hand' => $destQoh,
                                        'updated_at' => date('c'),
                                    ],
                                ];
                            } else {
                                $destinationInventoryId = am_firestore_random_document_id();
                                $destQoh = $moveQty;
                                $inventoryOps[] = [
                                    'mode' => 'create',
                                    'collection' => 'am_core_inventory_levels',
                                    'id' => $destinationInventoryId,
                                    'data' => [
                                        'asset_id' => $liAssetId,
                                        'location_id' => $destLocationCode,
                                        'country_id' => $reqCountryId,
                                        'quantity_on_hand' => $moveQty,
                                        'quantity_allocated' => 0,
                                        'created_at' => date('c'),
                                        'updated_at' => date('c'),
                                    ],
                                ];
                                $destInv = [
                                    'id' => $destinationInventoryId,
                                    'asset_id' => $liAssetId,
                                    'location_id' => $destLocationCode,
                                    'country_id' => $reqCountryId,
                                    'quantity_allocated' => 0,
                                ];
                            }

                            $destinationName = (string)($destLoc['location_name'] ?? $destSiteCode);
                            if ((string)($asset['location_id'] ?? '') !== $destLocationCode) {
                                $inventoryOps[] = [
                                    'mode' => 'update',
                                    'collection' => 'am_core_assets',
                                    'id' => $liAssetId,
                                    'data' => [
                                        'location_id' => $destLocationCode,
                                        'location_name' => $destinationName,
                                        'updated_at' => date('c'),
                                    ],
                                ];
                            }
                        }

                        $fulfillmentTxn = am_dispatch_transaction_data(
                            am_dispatch_fulfillment_transaction_type($asset, $sameLocation),
                            $liAssetId,
                            $moveQty,
                            $srcLocationCode,
                            $sameLocation ? '' : $destLocationCode,
                            array_merge($req, ['id' => $docId]),
                            $workPayload,
                            $sameLocation ? 'issued' : 'transferred'
                        );
                        $commit = am_dispatch_commit_event($inventoryOps, $fulfillmentEventId, $fulfillmentTxn);
                        if (!$commit['ok']) {
                            $operationErrors[] = 'Could not fulfill ' . (string)($li['name'] ?? $liAssetId)
                                . ': ' . (string)($commit['error'] ?? 'unknown Firestore error');
                            break;
                        }
                        $srcInv['quantity_on_hand'] = $newQoh;
                        $srcInv['quantity_allocated'] = $newAlloc;
                        $invByKey[$srcKey] = $srcInv;
                        if (isset($newAssetQuantity)) {
                            $assetById[$liAssetId]['quantity'] = $newAssetQuantity;
                            unset($newAssetQuantity);
                        }
                        if (!$sameLocation && $destInv && $destKey !== '') {
                            $destInv['quantity_on_hand'] = $destQoh;
                            $invByKey[$destKey] = $destInv;
                        }
                    }
                }

                $workPayload['line_items'] = array_values($items);
                if ($newStatus === 'Approved' || $alsoApprove) {
                    $workPayload['allocation_applied_at'] = date('c');
                    if (!empty($shortNotes)) {
                        $workPayload['allocation_note'] = 'Partially apportioned: ' . implode('; ', $shortNotes);
                    } else {
                        unset($workPayload['allocation_note']);
                    }
                }
                if ($newStatus === 'Fulfilled') {
                    $workPayload['fulfilled_apportionment_at'] = date('c');
                }
                $update['payload'] = $workPayload;

                if ($newStatus === 'Fulfilled') {
                    $update['fulfilled_date'] = date('c');
                }
            }

            // Cancelled: release allocated stock back to inventory.
            if ($newStatus === 'Cancelled' && $currentStatus === 'Approved') {
                $items = $workPayload['line_items'] ?? [];
                if (!is_array($items)) {
                    $items = [];
                }
                $allInvLevels = am_firestore_get_collection('am_core_inventory_levels', 5000);
                $invByKey = [];
                foreach ($allInvLevels as $inv) {
                    $iaid = (string)($inv['asset_id'] ?? '');
                    $iloc = (string)($inv['location_id'] ?? '');
                    $icid = (string)($inv['country_id'] ?? '');
                    if ($iaid !== '' && $iloc !== '') {
                        $invByKey[$iaid . '|' . $iloc . '|' . $icid] = $inv;
                    }
                }
                $reqCountryId = (string)($req['requested_for_country'] ?? '');
                $allLocations = am_get_pr_sites();
                $allAssets = am_firestore_get_collection('am_core_assets', 10000);
                $assetById = [];
                foreach ($allAssets as $a) {
                    $aid = (string)($a['asset_id'] ?? $a['id'] ?? '');
                    if ($aid !== '') {
                        $assetById[$aid] = $a;
                    }
                }
                $locByAnyKey = [];
                foreach ($allLocations as $l) {
                    $lid = (string)($l['id'] ?? $l['location_id'] ?? '');
                    $lcode = (string)($l['location_code'] ?? '');
                    if ($lid !== '') {
                        $locByAnyKey[$lid] = $l;
                    }
                    if ($lcode !== '' && $lcode !== $lid) {
                        $locByAnyKey[$lcode] = $l;
                    }
                }
                foreach ($items as $idx => $li) {
                    $liAssetId = (string)($li['asset_id'] ?? '');
                    $allocQty = (int)($li['allocated_quantity'] ?? 0);
                    if ($liAssetId === '' || $allocQty <= 0) {
                        continue;
                    }
                    $asset = $assetById[$liAssetId] ?? [];
                    $srcLocId = (string)($asset['location_id'] ?? '');
                    $srcLoc = $locByAnyKey[$srcLocId] ?? [];
                    $srcLocationCode = (string)($srcLoc['location_code'] ?? $srcLocId);
                    if ($srcLocationCode === '') {
                        continue;
                    }
                    $srcKey = $liAssetId . '|' . $srcLocationCode . '|' . $reqCountryId;
                    $srcInv = $invByKey[$srcKey] ?? null;
                    if ($srcInv) {
                        $newAlloc = max(0, (int)($srcInv['quantity_allocated'] ?? 0) - $allocQty);
                        $releaseEventId = am_dispatch_event_id($docId, (int)$idx, 'release');
                        $releaseTxn = am_dispatch_transaction_data(
                            'Return',
                            $liAssetId,
                            $allocQty,
                            $srcLocationCode,
                            '',
                            array_merge($req, ['id' => $docId]),
                            $workPayload,
                            'reservation released'
                        );
                        $commit = am_dispatch_commit_event([[
                            'mode' => 'update',
                            'collection' => 'am_core_inventory_levels',
                            'id' => (string)$srcInv['id'],
                            'data' => [
                                'quantity_allocated' => $newAlloc,
                                'updated_at' => date('c'),
                            ],
                        ]], $releaseEventId, $releaseTxn);
                        if (!$commit['ok']) {
                            $operationErrors[] = 'Could not release ' . (string)($li['name'] ?? $liAssetId)
                                . ': ' . (string)($commit['error'] ?? 'unknown Firestore error');
                            break;
                        }
                    }
                    $items[$idx]['allocated_quantity'] = 0;
                    $items[$idx]['short_quantity'] = 0;
                    $items[$idx]['allocation_released_at'] = date('c');
                }
                $workPayload['line_items'] = array_values($items);
                $workPayload['allocation_released_at'] = date('c');
                unset($workPayload['allocation_note']);
                $update['payload'] = $workPayload;
            }
            if ($operationErrors !== []) {
                $_SESSION['flash_error'] = implode(' ', $operationErrors);
                header('Location: ' . base_url('requests/dispatch-view.php?id=' . urlencode($docId)));
                exit;
            }
            $requestUpdate = am_firestore_update_document('am_core_requests', $docId, $update);
            if (!$requestUpdate['ok']) {
                $_SESSION['flash_error'] = 'Inventory was safely recorded, but the request status could not be saved. Retry this action; inventory events are idempotent. '
                    . (string)($requestUpdate['error'] ?? '');
                header('Location: ' . base_url('requests/dispatch-view.php?id=' . urlencode($docId)));
                exit;
            }
            $_SESSION['flash_success'] = $alsoApprove ? 'Request approved and fulfilled.' : ('Status updated to ' . $newStatus . '.');
            header('Location: ' . base_url('requests/dispatch-view.php?id=' . urlencode($docId)));
            exit;
        }
    }
}

// ── Resolve references ──────────────────────────────────────
$page_title = (string)($req['request_number'] ?? 'Dispatch request');
$payload = $req['payload'] ?? [];
if (!is_array($payload)) $payload = [];

$lineItems = $payload['line_items'] ?? [];
if (!is_array($lineItems)) $lineItems = [];

$countries = am_get_countries();
$cid = (string)($req['requested_for_country'] ?? '');
$countryLabel = '—';
foreach ($countries as $c) {
    $id = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($id === $cid) {
        $countryLabel = (string)($c['country_name'] ?? '') . ' (' . (string)($c['country_code'] ?? '') . ')';
        break;
    }
}

$status = (string)($req['status'] ?? '');
$canProcess = in_array($_SESSION['role'] ?? '', ['Admin', 'Manager'], true);

$classBadges = ['FixedAsset' => 'primary', 'Material' => 'warning', 'Consumable' => 'info', 'Inventory' => 'success'];

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="<?php echo base_url('requests/workflow-index.php'); ?>">Service workflows</a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($page_title); ?></li>
        </ol>
    </nav>

    <?php
    $flash = $_SESSION['flash_success'] ?? '';
    unset($_SESSION['flash_success']);
    if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($flash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php
    $flashError = $_SESSION['flash_error'] ?? '';
    unset($_SESSION['flash_error']);
    if ($flashError): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($flashError); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div>
            <h1 class="h2"><?php echo htmlspecialchars($req['workflow_label'] ?? 'Dispatch request'); ?></h1>
            <p class="mb-0 text-gray-600">
                <strong><?php echo htmlspecialchars($req['request_number'] ?? ''); ?></strong>
                · <span class="badge bg-<?php echo match($status) { 'Submitted' => 'primary', 'Approved' => 'success', 'Rejected' => 'danger', 'Fulfilled' => 'info', 'Cancelled' => 'secondary', default => 'secondary' }; ?>"><?php echo htmlspecialchars($status); ?></span>
            </p>
        </div>
        <div class="d-flex gap-2">
            <?php if ($canProcess && $status === 'Submitted'): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Approve this request and reserve stock?')">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="Approved">
                <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check me-1"></i>Approve</button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Approve and fulfill this request now? Stock will be moved to the destination immediately.')">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="approve_and_fulfill">
                <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-check-double me-1"></i>Approve &amp; Fulfill</button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Reject this request?')">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="Rejected">
                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-times me-1"></i>Reject</button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Cancel this request?')">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="Cancelled">
                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-ban me-1"></i>Cancel</button>
            </form>
            <?php elseif ($canProcess && $status === 'Approved'): ?>
            <form method="post" class="d-inline" onsubmit="return confirm('Mark this request as fulfilled? Stock will be moved to the destination.')">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="Fulfilled">
                <button type="submit" class="btn btn-sm btn-info"><i class="fas fa-check-double me-1"></i>Mark fulfilled</button>
            </form>
            <form method="post" class="d-inline" onsubmit="return confirm('Cancel this request? Allocated stock will be released.')">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="Cancelled">
                <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-ban me-1"></i>Cancel</button>
            </form>
            <?php endif; ?>
            <a href="<?php echo base_url('requests/workflow-index.php'); ?>" class="btn btn-outline-secondary btn-sm">Back to list</a>
        </div>
    </div>

    <div class="row g-4">
        <!-- Line items -->
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Items requested</h2></div>
                <div class="card-body p-0">
                    <table class="table table-hover mb-0">
                        <thead>
                            <tr>
                                <th>#</th><th>Item</th><th>Tag</th><th>Class</th><th class="text-end">Qty</th><th>Unit</th>
                                <?php if ($status === 'Approved' || $status === 'Fulfilled'): ?>
                                <th class="text-end">Allocated</th>
                                <?php endif; ?>
                                <?php if ($status === 'Fulfilled'): ?>
                                <th class="text-end">Fulfilled</th>
                                <?php endif; ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lineItems)): ?>
                            <tr><td colspan="<?php echo $status === 'Fulfilled' ? 8 : ($status === 'Approved' ? 7 : 6); ?>" class="text-center text-gray-500 py-4">No line items.</td></tr>
                            <?php else: ?>
                            <?php foreach ($lineItems as $idx => $li):
                                $aid = (string)($li['asset_id'] ?? '');
                                $cls = (string)($li['item_class'] ?? '');
                            ?>
                            <tr>
                                <td class="text-gray-400"><?php echo $idx + 1; ?></td>
                                <td>
                                    <?php if ($aid !== ''): ?>
                                    <a href="<?php echo base_url('assets/view.php?id=' . urlencode($aid)); ?>" class="fw-semibold">
                                        <?php echo htmlspecialchars($li['name'] ?? '—'); ?>
                                    </a>
                                    <?php else: ?>
                                    <?php echo htmlspecialchars($li['name'] ?? '—'); ?>
                                    <?php endif; ?>
                                </td>
                                <td><code class="text-muted"><?php echo htmlspecialchars($li['asset_tag'] ?? '—'); ?></code></td>
                                <td><span class="badge bg-<?php echo $classBadges[$cls] ?? 'secondary'; ?>"><?php echo htmlspecialchars($cls ?: '—'); ?></span></td>
                                <td class="text-end fw-bold"><?php echo (int)($li['quantity'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars($li['unit'] ?? 'EA'); ?></td>
                                <?php if ($status === 'Approved' || $status === 'Fulfilled'): ?>
                                <td class="text-end"><?php
                                    $alloc = (int)($li['allocated_quantity'] ?? 0);
                                    $short = (int)($li['short_quantity'] ?? 0);
                                    echo $alloc;
                                    if ($short > 0) { echo ' <span class="text-danger small">(short ' . $short . ')</span>'; }
                                ?></td>
                                <?php endif; ?>
                                <?php if ($status === 'Fulfilled'): ?>
                                <td class="text-end"><?php
                                    $fulfilled = (int)($li['fulfilled_quantity'] ?? 0);
                                    $unfulfilled = (int)($li['unfulfilled_quantity'] ?? 0);
                                    echo $fulfilled;
                                    if ($unfulfilled > 0) { echo ' <span class="text-warning small">(' . $unfulfilled . ' pending)</span>'; }
                                ?></td>
                                <?php endif; ?>
                            </tr>
                            <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Sidebar cards -->
        <div class="col-12 col-lg-4">
            <!-- Requester -->
            <div class="card border-0 shadow mb-3">
                <div class="card-header"><h2 class="fs-6 fw-bold mb-0"><i class="fas fa-user me-2 text-primary"></i>Requester</h2></div>
                <div class="card-body">
                    <p class="mb-1"><strong><?php echo htmlspecialchars($payload['submitter_name'] ?? '—'); ?></strong></p>
                    <p class="mb-1 small text-gray-600"><?php echo htmlspecialchars($payload['submitter_email'] ?? '—'); ?></p>
                    <p class="mb-0 small text-gray-400">Requested <?php echo htmlspecialchars(substr((string)($req['requested_date'] ?? ''), 0, 10)); ?></p>
                </div>
            </div>

            <!-- Destination -->
            <div class="card border-0 shadow mb-3">
                <div class="card-header"><h2 class="fs-6 fw-bold mb-0"><i class="fas fa-location-dot me-2 text-success"></i>Destination</h2></div>
                <div class="card-body">
                    <p class="mb-1"><strong><?php echo htmlspecialchars($payload['site_name'] ?? $payload['site_code'] ?? '—'); ?></strong></p>
                    <p class="mb-1 small text-gray-600"><?php echo htmlspecialchars($countryLabel); ?></p>
                    <p class="mb-0 small text-gray-600">
                        <i class="fas fa-calendar me-1"></i>Dispatch: <strong><?php echo htmlspecialchars($payload['dispatch_date'] ?? '—'); ?></strong>
                    </p>
                </div>
            </div>

            <!-- Receiver -->
            <div class="card border-0 shadow mb-3">
                <div class="card-header"><h2 class="fs-6 fw-bold mb-0"><i class="fas fa-user-check me-2 text-warning"></i>Receiver</h2></div>
                <div class="card-body">
                    <p class="mb-1"><strong><?php echo htmlspecialchars($payload['receiver_name'] ?? '—'); ?></strong></p>
                    <p class="mb-0 small text-gray-600"><?php echo htmlspecialchars($payload['receiver_email'] ?? '—'); ?></p>
                </div>
            </div>

            <!-- Status info -->
            <div class="card border-0 shadow mb-3">
                <div class="card-header"><h2 class="fs-6 fw-bold mb-0"><i class="fas fa-info-circle me-2 text-info"></i>Status</h2></div>
                <div class="card-body">
                    <p class="mb-1"><?php echo htmlspecialchars($status); ?></p>
                    <?php if (!empty($req['fulfilled_date'])): ?>
                    <p class="mb-0 small text-gray-600">Fulfilled: <?php echo htmlspecialchars(substr((string)$req['fulfilled_date'], 0, 10)); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($payload['allocation_note'])): ?>
                    <hr class="my-2">
                    <p class="mb-0 small text-warning"><i class="fas fa-triangle-exclamation me-1"></i><?php echo htmlspecialchars($payload['allocation_note']); ?></p>
                    <?php endif; ?>
                    <?php if (!empty($payload['allocation_applied_at']) && $status === 'Approved'): ?>
                    <hr class="my-2">
                    <p class="mb-0 small text-gray-600"><i class="fas fa-box me-1"></i>Stock allocated: <?php echo htmlspecialchars(substr((string)$payload['allocation_applied_at'], 0, 10)); ?></p>
                    <?php endif; ?>
                    <hr class="my-2">
                    <p class="mb-0 small text-gray-500">Request #: <?php echo htmlspecialchars($req['request_number'] ?? ''); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
