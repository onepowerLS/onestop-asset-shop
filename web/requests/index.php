<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_login();

$page_title = 'Ready board requests';
$errors = [];
$showForm = isset($_GET['new']) || !empty($errors);

$requests = am_firestore_get_collection('am_core_requests', 2000);
$requests = array_values(array_filter($requests, fn($r) => ($r['workflow_type'] ?? '') === 'ready_board'));
$countries = am_firestore_get_collection('pr_master_countries', 500);
$locations = am_get_pr_sites();
$employees = am_firestore_get_collection('pr_master_employees', 2000);
if (empty($employees)) $employees = am_firestore_get_collection('am_core_employees', 2000);

$countryById = [];
foreach ($countries as $c) {
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($cid !== '') $countryById[$cid] = $c;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    am_require_can_mutate();
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        // Ready boards are always Inventory class — requesters no longer pick the class.
        $itemClass = 'Inventory';
        $deptScope = trim($_POST['department_scope'] ?? 'General');
        $description = trim($_POST['description'] ?? '');
        $countryId = trim($_POST['country_id'] ?? '');
        $priority = trim($_POST['priority'] ?? 'Normal');
        $quantity = max(1, (int)($_POST['quantity'] ?? 1));
        $siteCode = trim($_POST['site_code'] ?? '');
        $receiverName = trim($_POST['receiver_name'] ?? '');
        $receiverEmail = trim($_POST['receiver_email'] ?? '');

        if ($description === '') $errors[] = 'Description is required.';
        if ($countryId === '') $errors[] = 'Country is required.';
        if ($quantity < 1) $errors[] = 'Quantity must be at least 1.';

        if (empty($errors)) {
            $reqNum = 'REQ-' . date('Y') . '-' . str_pad((string)(count($requests) + 1), 4, '0', STR_PAD_LEFT);

            $payload = [
                'item_class' => $itemClass,
                'department_scope' => $deptScope,
                'description' => $description,
                'priority' => $priority,
                'quantity' => $quantity,
                'site_code' => $siteCode,
                'receiver_name' => $receiverName,
                'receiver_email' => $receiverEmail,
                'required_date' => $_POST['required_date'] ?? '',
                'notes' => trim($_POST['notes'] ?? ''),
                'location_id' => trim($_POST['location_id'] ?? ''),
            ];

            $summary = 'Ready boards ×' . $quantity . ($siteCode ? ' → ' . $siteCode : '') . ' — ' . substr($description, 0, 80);

            $data = [
                'request_number' => $reqNum,
                'workflow_type' => 'ready_board',
                'workflow_label' => 'Ready board request',
                'item_class' => $itemClass,
                'department_scope' => $deptScope,
                'requested_by' => $_SESSION['user_id'] ?? '',
                'requested_for_country' => $countryId,
                'requested_for_location' => trim($_POST['location_id'] ?? ''),
                'priority' => $priority,
                'status' => 'Submitted',
                'description' => $description,
                'summary' => $summary,
                'quantity' => $quantity,
                'site_code' => $siteCode,
                'receiver_name' => $receiverName,
                'receiver_email' => $receiverEmail,
                'requested_date' => date('c'),
                'required_date' => $_POST['required_date'] ?? '',
                'notes' => trim($_POST['notes'] ?? ''),
                'payload' => $payload,
            ];

            $result = am_firestore_create_document('am_core_requests', $data);
            if ($result['ok']) {
                $_SESSION['flash_success'] = 'Request ' . $reqNum . ' submitted.';
                header('Location: ' . base_url('requests/index.php'));
                exit;
            } else {
                $errors[] = $result['error'] ?? 'Failed to submit request.';
            }
        }
        $showForm = true;
    } elseif ($action === 'update_status') {
        $docId = trim($_POST['doc_id'] ?? '');
        $newStatus = trim($_POST['new_status'] ?? '');

        if ($docId !== '' && in_array($newStatus, ['Approved', 'Rejected', 'Fulfilled', 'Cancelled'])) {
            $updateData = ['status' => $newStatus];
            if ($newStatus === 'Fulfilled') {
                $updateData['fulfilled_date'] = date('c');
            }
            am_firestore_update_document('am_core_requests', $docId, $updateData);
            $_SESSION['flash_success'] = 'Request status updated to ' . $newStatus . '.';
        }
        header('Location: ' . base_url('requests/index.php'));
        exit;
    }
}

if (am_is_auditor_readonly()) {
    $showForm = false;
}

$statusFilter = $_GET['status'] ?? '';
$filtered = [];
foreach ($requests as $req) {
    if ($statusFilter !== '' && (string)($req['status'] ?? '') !== $statusFilter) continue;
    $filtered[] = $req;
}

usort($filtered, function ($a, $b) {
    return strtotime((string)($b['requested_date'] ?? '1970-01-01'))
        <=> strtotime((string)($a['requested_date'] ?? '1970-01-01'));
});

$statusCounts = [];
foreach ($requests as $r) {
    $s = (string)($r['status'] ?? 'Unknown');
    $statusCounts[$s] = ($statusCounts[$s] ?? 0) + 1;
}

$isAdmin = ($_SESSION['role'] ?? '') === 'Admin' || ($_SESSION['role'] ?? '') === 'Manager';
$classLabels = ['FixedAsset' => 'Fixed Asset', 'Material' => 'Material', 'Consumable' => 'Consumable', 'Inventory' => 'Inventory'];
$classColors = ['FixedAsset' => 'primary', 'Material' => 'warning', 'Consumable' => 'info', 'Inventory' => 'success'];

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center py-4" data-tutorial="tutorial-requests-header">
        <div>
            <h1 class="h2">Ready board requests</h1>
            <p class="mb-0"><?php echo count($filtered); ?> ready board requests</p>
            <p class="small text-muted mb-0 mt-1">
                For ready boards and other AM service requests, use
                <a href="<?php echo base_url('requests/workflow-index.php'); ?>">Service workflows</a>
                (replaces the <a href="https://docs.google.com/forms/d/1F-Hfa_HdRidRd3BOPEiG-6f4Zha-AWdTdGFG8-6iTUI/viewform" target="_blank" rel="noopener">legacy Google Form</a>).
            </p>
        </div>
        <?php if (!am_is_auditor_readonly()): ?>
        <a href="<?php echo base_url('requests/index.php?new=1'); ?>" class="btn btn-sm btn-gray-800">
            <i class="fas fa-plus me-2"></i>New Request
        </a>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($flash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <!-- Status Summary -->
    <div class="row mb-4">
        <?php
        $statusConfig = [
            'Submitted' => ['color' => 'primary', 'icon' => 'fa-paper-plane'],
            'Approved' => ['color' => 'success', 'icon' => 'fa-check'],
            'Rejected' => ['color' => 'danger', 'icon' => 'fa-times'],
            'Fulfilled' => ['color' => 'info', 'icon' => 'fa-check-double'],
            'Cancelled' => ['color' => 'secondary', 'icon' => 'fa-ban'],
        ];
        foreach ($statusConfig as $status => $cfg):
        ?>
        <div class="col">
            <a href="<?php echo base_url('requests/index.php?status=' . urlencode($status)); ?>" class="text-decoration-none">
                <div class="card border-0 shadow text-center py-3 <?php echo $statusFilter === $status ? 'border-' . $cfg['color'] : ''; ?>">
                    <div class="card-body py-2">
                        <i class="fas <?php echo $cfg['icon']; ?> text-<?php echo $cfg['color']; ?> fa-lg"></i>
                        <h3 class="fw-extrabold mb-0 mt-1"><?php echo (int)($statusCounts[$status] ?? 0); ?></h3>
                        <small class="text-gray-500"><?php echo $status; ?></small>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($showForm): ?>
    <!-- New Request Form -->
    <div class="card border-0 shadow mb-4">
        <div class="card-header"><h2 class="fs-5 fw-bold mb-0">New Request</h2></div>
        <div class="card-body">
            <form method="POST" action="">
                <input type="hidden" name="action" value="create">
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label">Quantity <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" name="quantity" min="1" value="<?php echo htmlspecialchars($_POST['quantity'] ?? '1'); ?>" required>
                        <div class="form-text">Number of ready boards needed.</div>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Concession / site</label>
                        <input type="text" class="form-control" name="site_code" value="<?php echo htmlspecialchars($_POST['site_code'] ?? ''); ?>" placeholder="e.g. SEH, MAT, HQ">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Department</label>
                        <select class="form-select" name="department_scope">
                            <?php foreach (['General', 'RET', 'FAC', 'O&M', 'IT'] as $d): ?>
                            <option value="<?php echo $d; ?>" <?php echo ($_POST['department_scope'] ?? 'General') === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Country <span class="text-danger">*</span></label>
                        <select class="form-select" name="country_id" required>
                            <option value="">Select...</option>
                            <?php foreach ($countries as $c):
                                $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
                            ?>
                            <option value="<?php echo htmlspecialchars($cid); ?>" <?php echo ($_POST['country_id'] ?? '') === $cid ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['country_name'] ?? ''); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Priority</label>
                        <select class="form-select" name="priority">
                            <?php foreach (['Low', 'Normal', 'High', 'Urgent'] as $p): ?>
                            <option value="<?php echo $p; ?>" <?php echo ($_POST['priority'] ?? 'Normal') === $p ? 'selected' : ''; ?>><?php echo $p; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-9">
                        <label class="form-label">Description <span class="text-danger">*</span></label>
                        <textarea class="form-control" name="description" rows="2" required placeholder="Describe what you need..."><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Required By</label>
                        <input type="date" class="form-control" name="required_date" value="<?php echo htmlspecialchars($_POST['required_date'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Receiver name</label>
                        <input type="text" class="form-control" name="receiver_name" value="<?php echo htmlspecialchars($_POST['receiver_name'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Receiver email</label>
                        <input type="text" class="form-control" name="receiver_email" value="<?php echo htmlspecialchars($_POST['receiver_email'] ?? ''); ?>">
                    </div>
                    <div class="col-12">
                        <label class="form-label">Additional Notes</label>
                        <input type="text" class="form-control" name="notes" value="<?php echo htmlspecialchars($_POST['notes'] ?? ''); ?>">
                    </div>
                </div>
                <div class="mt-3 d-flex gap-2">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Submit Request</button>
                    <a href="<?php echo base_url('requests/index.php'); ?>" class="btn btn-gray-200">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <!-- Requests Table -->
    <div class="card border-0 shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="requestsTable">
                    <thead>
                        <tr><th>Request #</th><th>Qty</th><th>Site</th><th>Country</th><th>Priority</th><th>Status</th><th>Date</th><?php if ($isAdmin): ?><th>Actions</th><?php endif; ?></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($filtered)): ?>
                        <tr><td colspan="<?php echo $isAdmin ? 9 : 8; ?>" class="text-center text-gray-500 py-4">No requests found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($filtered as $req):
                            $docId = (string)($req['id'] ?? '');
                            $status = (string)($req['status'] ?? '');
                            $priColors = ['Low' => 'secondary', 'Normal' => 'primary', 'High' => 'warning', 'Urgent' => 'danger'];
                            $statColors = ['Draft' => 'secondary', 'Submitted' => 'primary', 'Approved' => 'success', 'Rejected' => 'danger', 'Fulfilled' => 'info', 'Cancelled' => 'secondary'];
                        ?>
                        <tr>
                            <td><a href="<?php echo base_url('requests/workflow-view.php?id=' . urlencode($docId)); ?>" class="fw-bold"><?php echo htmlspecialchars($req['request_number'] ?? ''); ?></a></td>
                            <td><?php echo (int)($req['quantity'] ?? 0); ?></td>
                            <td><?php echo htmlspecialchars((string)($req['site_code'] ?? '—')); ?></td>
                            <td><?php echo htmlspecialchars(($countryById[(string)($req['requested_for_country'] ?? '')] ?? [])['country_code'] ?? '—'); ?></td>
                            <td><span class="badge bg-<?php echo $priColors[$req['priority'] ?? ''] ?? 'secondary'; ?>"><?php echo htmlspecialchars($req['priority'] ?? ''); ?></span></td>
                            <td><span class="badge bg-<?php echo $statColors[$status] ?? 'secondary'; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                            <td><?php echo htmlspecialchars(substr((string)($req['requested_date'] ?? ''), 0, 10)); ?></td>
                            <?php if ($isAdmin): ?>
                            <td>
                                <?php if ($status === 'Submitted'): ?>
                                <form method="POST" action="" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($docId); ?>">
                                    <input type="hidden" name="new_status" value="Approved">
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Approve"><i class="fas fa-check"></i></button>
                                </form>
                                <form method="POST" action="" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($docId); ?>">
                                    <input type="hidden" name="new_status" value="Rejected">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Reject"><i class="fas fa-times"></i></button>
                                </form>
                                <?php elseif ($status === 'Approved'): ?>
                                <a class="btn btn-sm btn-outline-info" href="<?php echo base_url('requests/workflow-view.php?id=' . urlencode($docId)); ?>" title="Fulfill"><i class="fas fa-check-double"></i></a>
                                <?php endif; ?>
                            </td>
                            <?php endif; ?>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
$(document).ready(function() {
    var t = $('#requestsTable');
    if (t.find('tbody td[colspan]').length) return;
    t.DataTable({ pageLength: 25, order: [[7, 'desc']] });
});
</script>
