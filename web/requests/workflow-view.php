<?php
/**
 * Detail view for one am_core_requests document.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/request_workflows.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/inventory_levels.php';
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

$page_title = (string)($req['request_number'] ?? 'Workflow request');
$payload = $req['payload'] ?? [];
if (!is_array($payload)) {
    $payload = [];
}

$wfType = (string)($req['workflow_type'] ?? '');
$template = am_request_workflow_template($wfType);
$fieldLabels = [];
if ($template) {
    foreach ($template['fields'] ?? [] as $f) {
        $n = (string)($f['name'] ?? '');
        if ($n !== '') {
            $fieldLabels[$n] = (string)($f['label'] ?? $n);
        }
    }
}

// Bulk fulfill: assign ready boards (Inventory class, Ready Boards category) to this request.
$bulkErrors = [];
$bulkSuccess = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'bulk_fulfill') {
    am_require_can_mutate();
    $selectedAssetIds = $_POST['asset_ids'] ?? [];
    if (!is_array($selectedAssetIds)) {
        $selectedAssetIds = [];
    }
    $selectedAssetIds = array_values(array_filter(array_map('strval', $selectedAssetIds), fn($s) => $s !== ''));

    $newLocation = trim($_POST['new_location'] ?? '');
    $newStatus = trim($_POST['new_status'] ?? 'Allocated');
    $newDepartment = trim($_POST['new_department'] ?? '');
    $projectNote = trim($_POST['project_note'] ?? '');

    if (empty($selectedAssetIds)) {
        $bulkErrors[] = 'Select at least one ready board to allocate.';
    }
    if (!in_array($newStatus, ['Allocated', 'CheckedOut', 'InProject', 'Deployed'], true)) {
        $bulkErrors[] = 'Invalid target status.';
    }

    if (empty($bulkErrors)) {
        $allAssets = am_firestore_get_collection('am_core_assets', 8000);
        $assetById = [];
        foreach ($allAssets as $a) {
            $aid = (string)($a['asset_id'] ?? $a['id'] ?? '');
            if ($aid !== '') {
                $assetById[$aid] = $a;
            }
        }
        $updatedCount = 0;
        foreach ($selectedAssetIds as $aid) {
            $asset = $assetById[$aid] ?? null;
            if (!$asset) {
                $bulkErrors[] = 'Asset ' . $aid . ' not found.';
                continue;
            }
            $patch = [
                'status' => $newStatus,
                'updated_at' => date('c'),
                'allocated_department' => $newDepartment,
                'allocated_project' => $projectNote !== '' ? $projectNote : ((string)($req['request_number'] ?? '') . ' · ' . (string)($req['site_code'] ?? '')),
                'allocated_to_request' => $docId,
            ];
            if ($newLocation !== '') {
                $patch['location_id'] = $newLocation;
            }
            $r = am_firestore_update_document('am_core_assets', $aid, $patch);
            if ($r['ok']) {
                $updatedCount++;
                $bulkSuccess[] = (string)($asset['asset_tag'] ?? $aid);
            } else {
                $bulkErrors[] = 'Failed to update ' . ($asset['asset_tag'] ?? $aid) . ': ' . ($r['error'] ?? 'Unknown');
            }
        }
        if ($updatedCount > 0) {
            // Mark the request fulfilled if not already.
            $updateData = [
                'status' => 'Fulfilled',
                'fulfilled_date' => date('c'),
                'fulfilled_asset_ids' => $selectedAssetIds,
                'fulfilled_count' => $updatedCount,
            ];
            am_firestore_update_document('am_core_requests', $docId, $updateData);
            $_SESSION['flash_success'] = 'Allocated ' . $updatedCount . ' ready board(s) and marked request fulfilled.';
            header('Location: ' . base_url('requests/workflow-view.php?id=' . urlencode($docId)));
            exit;
        }
    }
}

$countries = am_firestore_get_collection('pr_master_countries', 500);
$cid = (string)($req['requested_for_country'] ?? '');
$countryLabel = '—';
foreach ($countries as $c) {
    $id = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($id === $cid) {
        $countryLabel = (string)($c['country_name'] ?? '') . ' (' . (string)($c['country_code'] ?? '') . ')';
        break;
    }
}

$classLabels = ['FixedAsset' => 'Fixed Asset', 'Material' => 'Material', 'Consumable' => 'Consumable', 'Inventory' => 'Inventory'];
$classColors = ['FixedAsset' => 'primary', 'Material' => 'warning', 'Consumable' => 'info', 'Inventory' => 'success'];
$priColors = ['Low' => 'secondary', 'Normal' => 'primary', 'High' => 'warning', 'Urgent' => 'danger'];

// Ready board candidates: Inventory class, Ready Boards category, Available status, in request country.
$readyBoardCandidates = [];
$alreadyAllocatedIds = [];
$isReadyBoardRequest = $wfType === 'ready_board';
$reqStatus = (string)($req['status'] ?? '');
$reqQty = (int)($req['quantity'] ?? 0);
$reqCountryId = (string)($req['requested_for_country'] ?? '');
$reqSiteCode = (string)($req['site_code'] ?? '');
$reqDepartment = (string)($req['department_scope'] ?? '');
$fulfilledIds = $req['fulfilled_asset_ids'] ?? [];
if (is_array($fulfilledIds)) {
    foreach ($fulfilledIds as $fid) {
        $alreadyAllocatedIds[(string)$fid] = true;
    }
}

if ($isReadyBoardRequest) {
    $allAssets = am_firestore_get_collection('am_core_assets', 8000);
    $categories = am_firestore_get_collection('pr_master_categories', 1000);
    $locations = am_get_pr_sites();
    $locByAnyKey = am_build_location_index($locations);

    $readyBoardCategoryIds = [];
    foreach ($categories as $cat) {
        $nameLower = strtolower((string)($cat['category_name'] ?? ''));
        $code = (string)($cat['category_code'] ?? '');
        if ((string)($cat['item_class'] ?? '') === 'Inventory'
            && (str_contains($nameLower, 'ready board') || $code === 'INV-RDB')) {
            $cid = (string)($cat['category_id'] ?? $cat['id'] ?? '');
            if ($cid !== '') {
                $readyBoardCategoryIds[$cid] = true;
            }
        }
    }

    foreach ($allAssets as $asset) {
        $aid = (string)($asset['asset_id'] ?? $asset['id'] ?? '');
        if ($aid === '') {
            continue;
        }
        if ((string)($asset['item_class'] ?? '') !== 'Inventory') {
            continue;
        }
        if (!isset($readyBoardCategoryIds[(string)($asset['category_id'] ?? '')])) {
            // Still allow if the name contains "ready board"
            $nameLower = strtolower((string)($asset['name'] ?? ''));
            if (!str_contains($nameLower, 'ready board')) {
                continue;
            }
        }
        if ($reqCountryId !== '' && am_resolve_asset_country_id($asset, $countries) !== $reqCountryId) {
            continue;
        }
        $status = (string)($asset['status'] ?? '');
        if (in_array($status, ['WrittenOff', 'Retired', 'Consumed'], true)) {
            continue;
        }
        $locRaw = (string)($asset['location_id'] ?? '');
        $locCanonical = am_canonical_location_code($locRaw, $locByAnyKey);
        $loc = $locByAnyKey[$locCanonical] ?? [];
        $readyBoardCandidates[] = [
            'id' => $aid,
            'name' => (string)($asset['name'] ?? ''),
            'asset_tag' => (string)($asset['asset_tag'] ?? ''),
            'status' => $status,
            'location_id' => $locCanonical,
            'location_name' => (string)($loc['location_name'] ?? $locCanonical),
            'allocated_department' => (string)($asset['allocated_department'] ?? ''),
            'allocated_project' => (string)($asset['allocated_project'] ?? ''),
            'allocated_to_request' => (string)($asset['allocated_to_request'] ?? ''),
            'already_allocated_to_this' => isset($alreadyAllocatedIds[$aid]),
        ];
    }
    usort($readyBoardCandidates, function ($a, $b) {
        // Already-allocated-to-this-request first, then Available, then by tag.
        if ($a['already_allocated_to_this'] !== $b['already_allocated_to_this']) {
            return $a['already_allocated_to_this'] ? -1 : 1;
        }
        $aAvail = $a['status'] === 'Available' ? 0 : 1;
        $bAvail = $b['status'] === 'Available' ? 0 : 1;
        if ($aAvail !== $bAvail) {
            return $aAvail <=> $bAvail;
        }
        return strcasecmp($a['asset_tag'], $b['asset_tag']);
    });
}

$fulfilledAssetTags = [];
if (!empty($alreadyAllocatedIds) && $isReadyBoardRequest) {
    foreach ($readyBoardCandidates as $c) {
        if (isset($alreadyAllocatedIds[$c['id']])) {
            $fulfilledAssetTags[] = $c['asset_tag'] ?: $c['id'];
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="<?php echo base_url('requests/workflow-index.php'); ?>">Service workflows</a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars($page_title); ?></li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div>
            <h1 class="h2"><?php echo htmlspecialchars($req['workflow_label'] ?? 'Workflow'); ?></h1>
            <p class="mb-0 text-gray-600"><strong><?php echo htmlspecialchars($req['request_number'] ?? ''); ?></strong>
                · <span class="badge bg-secondary"><?php echo htmlspecialchars((string)($req['status'] ?? '')); ?></span></p>
        </div>
        <a href="<?php echo base_url('requests/workflow-index.php'); ?>" class="btn btn-outline-secondary btn-sm">Back to list</a>
    </div>

    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Details</h2></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-gray-600">Summary</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars((string)($req['summary'] ?? '')); ?></dd>
                        <dt class="col-sm-4 text-gray-600">Country</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars($countryLabel); ?></dd>
                        <dt class="col-sm-4 text-gray-600">Requested</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars(substr((string)($req['requested_date'] ?? ''), 0, 19)); ?></dd>
                        <?php if (!empty($req['fulfilled_date'])): ?>
                        <dt class="col-sm-4 text-gray-600">Fulfilled</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars(substr((string)$req['fulfilled_date'], 0, 19)); ?></dd>
                        <?php endif; ?>
                    </dl>

                    <?php if ($wfType === 'ready_board'): ?>
                    <hr>
                    <h3 class="h6 text-gray-600">Request details</h3>
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-gray-600">Item class</dt>
                        <dd class="col-sm-8">
                            <span class="badge bg-<?php echo $classColors[(string)($req['item_class'] ?? '')] ?? 'secondary'; ?>">
                                <?php echo htmlspecialchars($classLabels[(string)($req['item_class'] ?? '')] ?? ($req['item_class'] ?? '—')); ?>
                            </span>
                        </dd>
                        <dt class="col-sm-4 text-gray-600">Department</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars((string)($req['department_scope'] ?? '—')); ?></dd>
                        <dt class="col-sm-4 text-gray-600">Priority</dt>
                        <dd class="col-sm-8">
                            <span class="badge bg-<?php echo $priColors[(string)($req['priority'] ?? '')] ?? 'secondary'; ?>">
                                <?php echo htmlspecialchars((string)($req['priority'] ?? '—')); ?>
                            </span>
                        </dd>
                        <dt class="col-sm-4 text-gray-600">Description</dt>
                        <dd class="col-sm-8"><?php echo nl2br(htmlspecialchars((string)($req['description'] ?? '—'))); ?></dd>
                        <?php if (!empty($req['required_date'])): ?>
                        <dt class="col-sm-4 text-gray-600">Required by</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars((string)$req['required_date']); ?></dd>
                        <?php endif; ?>
                        <?php if (!empty($req['notes'])): ?>
                        <dt class="col-sm-4 text-gray-600">Notes</dt>
                        <dd class="col-sm-8"><?php echo nl2br(htmlspecialchars((string)$req['notes'])); ?></dd>
                        <?php endif; ?>
                    </dl>
                    <?php else: ?>
                    <?php if (!empty($payload)): ?>
                    <hr>
                    <h3 class="h6 text-gray-600">Submitted fields</h3>
                    <dl class="row mb-0">
                        <?php foreach ($payload as $key => $val): ?>
                        <dt class="col-sm-4 text-gray-600"><?php echo htmlspecialchars($fieldLabels[$key] ?? (string)$key); ?></dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars(is_scalar($val) ? (string)$val : json_encode($val)); ?></dd>
                        <?php endforeach; ?>
                    </dl>
                    <?php endif; ?>
                    <?php endif; ?>
                </div>
            </div>

            <?php if ($isReadyBoardRequest): ?>
            <div class="card border-0 shadow mt-4" id="bulkFulfillCard">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="fs-5 fw-bold mb-0">Allocate ready boards</h2>
                    <?php if ($reqStatus === 'Fulfilled'): ?>
                    <span class="badge bg-info">Already fulfilled · <?php echo count($fulfilledAssetTags); ?> assigned</span>
                    <?php endif; ?>
                </div>
                <div class="card-body">
                    <?php if (!empty($bulkErrors)): ?>
                    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($bulkErrors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
                    <?php endif; ?>

                    <?php if (!empty($fulfilledAssetTags)): ?>
                    <div class="alert alert-info">
                        <strong>Already allocated to this request:</strong>
                        <?php echo htmlspecialchars(implode(', ', $fulfilledAssetTags)); ?>
                    </div>
                    <?php endif; ?>

                    <p class="text-gray-600">Requested: <strong><?php echo $reqQty; ?></strong> ready board(s)
                        <?php if ($reqSiteCode): ?> → <strong><?php echo htmlspecialchars($reqSiteCode); ?></strong><?php endif; ?>
                        <?php if ($reqDepartment): ?> · <?php echo htmlspecialchars($reqDepartment); ?><?php endif; ?>.
                        Tick the ready boards to allocate, set the target location/status, then submit. This updates each asset's status, location, department, and project in one go.</p>

                    <form method="POST" action="" id="bulkFulfillForm">
                        <input type="hidden" name="action" value="bulk_fulfill">

                        <div class="row g-3 mb-3">
                            <div class="col-md-3">
                                <label class="form-label">Target status</label>
                                <select class="form-select" name="new_status" id="bulkNewStatus">
                                    <option value="Allocated" selected>Allocated</option>
                                    <option value="CheckedOut">Checked Out</option>
                                    <option value="InProject">In Project</option>
                                    <option value="Deployed">Deployed</option>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="new_department" id="bulkNewDept">
                                    <?php foreach (['RET', 'FAC', 'O&M', 'IT', 'General', 'Finance', 'HR', 'Procurement', 'Fleet'] as $d): ?>
                                    <option value="<?php echo $d; ?>" <?php echo $reqDepartment === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Target location (concession / site)</label>
                                <input type="text" class="form-control" name="new_location" id="bulkNewLocation"
                                    value="<?php echo htmlspecialchars($reqSiteCode); ?>"
                                    placeholder="Site code (e.g. SEH) or canonical location code (LSO-SEH)">
                            </div>
                            <div class="col-12">
                                <label class="form-label">Project / concession note (optional)</label>
                                <input type="text" class="form-control" name="project_note"
                                    value="<?php echo htmlspecialchars((string)($req['request_number'] ?? '') . ' · ' . $reqSiteCode); ?>">
                            </div>
                        </div>

                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <span class="text-gray-600">Available ready boards in <?php echo htmlspecialchars($countryLabel); ?>:
                                <strong id="availableCount"><?php echo count($readyBoardCandidates); ?></strong>
                            </span>
                            <div class="d-flex gap-2">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="selectAllAvailable">Select all available</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="selectRequestedQty">Select requested (<?php echo $reqQty; ?>)</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="clearSelection">Clear</button>
                            </div>
                        </div>

                        <div class="table-responsive" style="max-height: 50vh;">
                            <table class="table table-sm table-hover mb-0" id="readyBoardTable">
                                <thead>
                                    <tr><th style="width:40px;"></th><th>Tag</th><th>Name</th><th>Status</th><th>Location</th><th>Department</th><th>Project</th></tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($readyBoardCandidates)): ?>
                                    <tr><td colspan="7" class="text-center text-gray-500 py-3">No ready boards found for this country. Add ready board items in the catalog first.</td></tr>
                                    <?php else: ?>
                                    <?php foreach ($readyBoardCandidates as $c): ?>
                                    <tr data-status="<?php echo htmlspecialchars($c['status']); ?>"
                                        data-already="<?php echo $c['already_allocated_to_this'] ? '1' : '0'; ?>">
                                        <td class="text-center">
                                            <input type="checkbox" class="form-check-input rb-select" name="asset_ids[]"
                                                value="<?php echo htmlspecialchars($c['id']); ?>"
                                                <?php echo $c['already_allocated_to_this'] ? 'checked' : ''; ?>
                                                data-status="<?php echo htmlspecialchars($c['status']); ?>">
                                        </td>
                                        <td><code><?php echo htmlspecialchars($c['asset_tag'] ?: '—'); ?></code></td>
                                        <td><?php echo htmlspecialchars($c['name']); ?></td>
                                        <td>
                                            <span class="badge bg-<?php
                                                echo match($c['status']) {
                                                    'Available' => 'success',
                                                    'Allocated' => 'warning',
                                                    'CheckedOut' => 'info',
                                                    'InProject' => 'primary',
                                                    'Deployed' => 'dark',
                                                    default => 'secondary'
                                                };
                                            ?>"><?php echo htmlspecialchars($c['status']); ?></span>
                                            <?php if ($c['already_allocated_to_this']): ?>
                                                <span class="badge bg-info ms-1">This request</span>
                                            <?php endif; ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($c['location_name']); ?></td>
                                        <td><?php echo htmlspecialchars($c['allocated_department'] ?: '—'); ?></td>
                                        <td><?php echo htmlspecialchars($c['allocated_project'] ?: '—'); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>

                        <div class="mt-3 d-flex gap-2 align-items-center">
                            <button type="submit" class="btn btn-primary" id="bulkSubmitBtn">
                                <i class="fas fa-check-double me-2"></i>Allocate selected and mark fulfilled
                            </button>
                            <span class="text-gray-600 small" id="selectedCountLabel">0 selected</span>
                        </div>
                    </form>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
(function() {
    var form = document.getElementById('bulkFulfillForm');
    if (!form) return;

    function updateSelectedLabel() {
        var checked = form.querySelectorAll('.rb-select:checked').length;
        var label = document.getElementById('selectedCountLabel');
        if (label) label.textContent = checked + ' selected';
    }

    function availableCheckboxes() {
        return Array.from(form.querySelectorAll('.rb-select')).filter(function(cb) {
            return cb.dataset.status === 'Available' && cb.closest('tr').dataset.already !== '1';
        });
    }

    document.getElementById('selectAllAvailable').addEventListener('click', function() {
        availableCheckboxes().forEach(function(cb) { cb.checked = true; });
        updateSelectedLabel();
    });

    document.getElementById('selectRequestedQty').addEventListener('click', function() {
        var qty = <?php echo (int)$reqQty; ?>;
        form.querySelectorAll('.rb-select').forEach(function(cb) { cb.checked = false; });
        var avail = availableCheckboxes();
        for (var i = 0; i < qty && i < avail.length; i++) {
            avail[i].checked = true;
        }
        updateSelectedLabel();
    });

    document.getElementById('clearSelection').addEventListener('click', function() {
        form.querySelectorAll('.rb-select').forEach(function(cb) {
            if (cb.closest('tr').dataset.already !== '1') cb.checked = false;
        });
        updateSelectedLabel();
    });

    form.addEventListener('change', function(e) {
        if (e.target.classList.contains('rb-select')) {
            updateSelectedLabel();
        }
    });

    form.addEventListener('submit', function(e) {
        var checked = form.querySelectorAll('.rb-select:checked').length;
        if (checked === 0) {
            e.preventDefault();
            alert('Select at least one ready board to allocate.');
            return false;
        }
        return true;
    });

    updateSelectedLabel();
})();
</script>
