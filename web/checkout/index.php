<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

$page_title = 'Check-Out / Check-In';
$errors = [];
$success = '';

$assets = am_firestore_get_collection('am_core_assets', 2000);
$employees = am_firestore_get_collection('pr_master_employees', 2000);
if (empty($employees)) {
    $employees = am_firestore_get_collection('am_core_employees', 2000);
}
$locations = am_get_pr_sites();
$allocations = am_firestore_get_collection('am_core_allocations', 2000);

$assetById = [];
foreach ($assets as $a) {
    $aid = (string)($a['asset_id'] ?? $a['id'] ?? '');
    if ($aid !== '') $assetById[$aid] = $a;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['checkout_action'] ?? '';
    $assetDocId = trim($_POST['asset_id'] ?? '');
    $employeeId = trim($_POST['employee_id'] ?? '');
    $locationId = trim($_POST['location_id'] ?? '');
    $notes = trim($_POST['notes'] ?? '');

    if ($assetDocId === '') $errors[] = 'Please select an item.';

    if ($action === 'checkout') {
        if ($employeeId === '') $errors[] = 'Please select an employee.';

        if (empty($errors)) {
            $allocData = [
                'asset_id' => $assetDocId,
                'employee_id' => $employeeId,
                'allocated_by' => $_SESSION['user_id'] ?? '',
                'allocation_date' => date('c'),
                'expected_return_date' => $_POST['expected_return'] ?? '',
                'status' => 'Active',
                'notes' => $notes,
            ];
            $allocResult = am_firestore_create_document('am_core_allocations', $allocData);

            $txnData = [
                'transaction_type' => 'CheckOut',
                'asset_id' => $assetDocId,
                'quantity' => 1,
                'to_location_id' => $locationId,
                'employee_id' => $employeeId,
                'performed_by' => $_SESSION['user_id'] ?? '',
                'device_type' => 'Desktop',
                'notes' => $notes,
                'transaction_date' => date('c'),
            ];
            am_firestore_create_document('am_core_transactions', $txnData);

            am_firestore_update_document('am_core_assets', $assetDocId, [
                'status' => 'CheckedOut',
                'updated_at' => date('c'),
            ]);

            if ($allocResult['ok']) {
                $success = 'Item checked out successfully.';
            } else {
                $errors[] = 'Check-out failed: ' . ($allocResult['error'] ?? 'Unknown error');
            }
        }
    } elseif ($action === 'checkin') {
        $allocationId = trim($_POST['allocation_id'] ?? '');

        if (empty($errors)) {
            if ($allocationId !== '') {
                am_firestore_update_document('am_core_allocations', $allocationId, [
                    'status' => 'Returned',
                    'actual_return_date' => date('c'),
                ]);
            }

            $txnData = [
                'transaction_type' => 'CheckIn',
                'asset_id' => $assetDocId,
                'quantity' => 1,
                'to_location_id' => $locationId,
                'performed_by' => $_SESSION['user_id'] ?? '',
                'device_type' => 'Desktop',
                'notes' => $notes,
                'transaction_date' => date('c'),
            ];
            am_firestore_create_document('am_core_transactions', $txnData);

            $hasOtherActive = false;
            foreach ($allocations as $alloc) {
                if ((string)($alloc['asset_id'] ?? '') === $assetDocId
                    && (string)($alloc['status'] ?? '') === 'Active'
                    && (string)($alloc['id'] ?? '') !== $allocationId) {
                    $hasOtherActive = true;
                    break;
                }
            }

            am_firestore_update_document('am_core_assets', $assetDocId, [
                'status' => $hasOtherActive ? 'Allocated' : 'Available',
                'location_id' => $locationId,
                'updated_at' => date('c'),
            ]);

            $success = 'Item checked in successfully.';
        }
    }
}

$activeAllocs = [];
foreach ($allocations as $alloc) {
    if ((string)($alloc['status'] ?? '') === 'Active') {
        $aid = (string)($alloc['asset_id'] ?? '');
        $alloc['_asset'] = $assetById[$aid] ?? [];
        $activeAllocs[] = $alloc;
    }
}

usort($activeAllocs, function ($a, $b) {
    return strtotime((string)($b['allocation_date'] ?? '1970-01-01'))
        <=> strtotime((string)($a['allocation_date'] ?? '1970-01-01'));
});

$availableAssets = array_filter($assets, fn($a) => in_array($a['status'] ?? '', ['Available', 'Good']));

$employeeById = [];
foreach ($employees as $e) {
    $eid = (string)($e['employee_id'] ?? $e['id'] ?? '');
    if ($eid !== '') $employeeById[$eid] = $e;
}

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center py-4">
        <div>
            <h1 class="h2">Check-Out / Check-In</h1>
            <p class="mb-0">Manage item allocations to employees</p>
        </div>
    </div>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($success); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row">
        <!-- Check-Out Form -->
        <div class="col-12 col-lg-6 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header bg-primary text-white"><h2 class="fs-5 fw-bold mb-0"><i class="fas fa-sign-out-alt me-2"></i>Check-Out Item</h2></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="checkout_action" value="checkout">
                        <div class="mb-3">
                            <label class="form-label">Item</label>
                            <select class="form-select" name="asset_id" required>
                                <option value="">Select item...</option>
                                <?php foreach ($availableAssets as $a):
                                    $aid = (string)($a['asset_id'] ?? $a['id'] ?? '');
                                ?>
                                <option value="<?php echo htmlspecialchars($aid); ?>">
                                    <?php echo htmlspecialchars(($a['asset_tag'] ?? '') . ' — ' . ($a['name'] ?? '')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Employee</label>
                            <select class="form-select" name="employee_id" required>
                                <option value="">Select employee...</option>
                                <?php foreach ($employees as $e):
                                    $eid = (string)($e['employee_id'] ?? $e['id'] ?? '');
                                ?>
                                <option value="<?php echo htmlspecialchars($eid); ?>">
                                    <?php echo htmlspecialchars(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? '')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Expected Return Date</label>
                            <input type="date" class="form-control" name="expected_return">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-primary w-100"><i class="fas fa-sign-out-alt me-2"></i>Check Out</button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Check-In Form -->
        <div class="col-12 col-lg-6 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header bg-success text-white"><h2 class="fs-5 fw-bold mb-0"><i class="fas fa-sign-in-alt me-2"></i>Check-In Item</h2></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="checkout_action" value="checkin">
                        <div class="mb-3">
                            <label class="form-label">Select Active Allocation</label>
                            <select class="form-select" name="allocation_id" id="allocSelect" onchange="onAllocChange(this)">
                                <option value="">Select allocation to return...</option>
                                <?php foreach ($activeAllocs as $alloc):
                                    $allocId = (string)($alloc['id'] ?? '');
                                    $aid = (string)($alloc['asset_id'] ?? '');
                                    $eid = (string)($alloc['employee_id'] ?? '');
                                    $emp = $employeeById[$eid] ?? [];
                                    $aname = ($alloc['_asset']['name'] ?? 'Unknown');
                                ?>
                                <option value="<?php echo htmlspecialchars($allocId); ?>" data-asset-id="<?php echo htmlspecialchars($aid); ?>">
                                    <?php echo htmlspecialchars($aname . ' → ' . ($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? $eid)); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="asset_id" id="checkinAssetId">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Return to Location</label>
                            <select class="form-select" name="location_id">
                                <option value="">Select location...</option>
                                <?php foreach ($locations as $loc):
                                    $lid = (string)($loc['location_id'] ?? $loc['id'] ?? '');
                                ?>
                                <option value="<?php echo htmlspecialchars($lid); ?>">
                                    <?php echo htmlspecialchars($loc['location_name'] ?? ''); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Notes</label>
                            <textarea class="form-control" name="notes" rows="2"></textarea>
                        </div>
                        <button type="submit" class="btn btn-success w-100"><i class="fas fa-sign-in-alt me-2"></i>Check In</button>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Active Allocations -->
    <div class="card border-0 shadow">
        <div class="card-header">
            <h2 class="fs-5 fw-bold mb-0">Active Allocations (<?php echo count($activeAllocs); ?>)</h2>
        </div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="allocTable">
                    <thead>
                        <tr><th>Item</th><th>Employee</th><th>Checked Out</th><th>Expected Return</th><th>Notes</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($activeAllocs)): ?>
                        <tr><td colspan="5" class="text-center text-gray-500 py-4">No active allocations.</td></tr>
                        <?php else: ?>
                        <?php foreach ($activeAllocs as $alloc):
                            $asset = $alloc['_asset'];
                            $eid = (string)($alloc['employee_id'] ?? '');
                            $emp = $employeeById[$eid] ?? [];
                        ?>
                        <tr>
                            <td>
                                <a href="<?php echo base_url('assets/view.php?id=' . urlencode($alloc['asset_id'] ?? '')); ?>">
                                    <?php echo htmlspecialchars($asset['name'] ?? 'Unknown'); ?>
                                </a>
                                <br><small class="text-gray-500"><?php echo htmlspecialchars($asset['asset_tag'] ?? ''); ?></small>
                            </td>
                            <td><?php echo htmlspecialchars(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? $eid)); ?></td>
                            <td><?php echo htmlspecialchars(substr((string)($alloc['allocation_date'] ?? ''), 0, 10)); ?></td>
                            <td><?php echo htmlspecialchars($alloc['expected_return_date'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(substr((string)($alloc['notes'] ?? ''), 0, 60)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function onAllocChange(select) {
    var opt = select.options[select.selectedIndex];
    document.getElementById('checkinAssetId').value = opt ? (opt.dataset.assetId || '') : '';
}
$(document).ready(function() {
    $('#allocTable').DataTable({ pageLength: 25, order: [[2, 'desc']] });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
