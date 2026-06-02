<?php
/**
 * List template-driven AM workflow requests (am_core_requests).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/request_workflows.php';
require_login();

$page_title = 'Service workflows';
$templates = am_request_workflow_templates();

$requests = am_firestore_get_collection('am_core_requests', 2000);
$countries = am_firestore_get_collection('pr_master_countries', 500);
$countryById = [];
foreach ($countries as $c) {
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($cid !== '') {
        $countryById[$cid] = $c;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    am_require_can_mutate();
    $action = $_POST['action'] ?? '';
    if ($action === 'update_status') {
        $docId = trim($_POST['doc_id'] ?? '');
        $newStatus = trim($_POST['new_status'] ?? '');
        if ($docId !== '' && in_array($newStatus, ['Approved', 'Rejected', 'Fulfilled', 'Cancelled'], true)) {
            $existing = am_firestore_get_document('am_core_requests', $docId);
            $wfType = (string)($existing['workflow_type'] ?? '');
            if ($wfType === 'inventory_dispatch') {
                $_SESSION['flash_error'] = 'Process inventory dispatch statuses from the dispatch detail page so stock apportionment is applied.';
                header('Location: ' . base_url('requests/dispatch-view.php?id=' . urlencode($docId)));
                exit;
            }
            $update = ['status' => $newStatus];
            if ($newStatus === 'Fulfilled') {
                $update['fulfilled_date'] = date('c');
            }
            am_firestore_update_document('am_core_requests', $docId, $update);
            $_SESSION['flash_success'] = 'Updated to ' . $newStatus . '.';
        }
        header('Location: ' . base_url('requests/workflow-index.php'));
        exit;
    }
}

$statusFilter = $_GET['status'] ?? '';
$typeFilter = $_GET['type'] ?? '';
$filtered = [];
foreach ($requests as $req) {
    if ($statusFilter !== '' && (string)($req['status'] ?? '') !== $statusFilter) {
        continue;
    }
    if ($typeFilter !== '' && (string)($req['workflow_type'] ?? '') !== $typeFilter) {
        continue;
    }
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

$canProcess = in_array($_SESSION['role'] ?? '', ['Admin', 'Manager'], true);

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$flashErr = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 py-4" data-tutorial="tutorial-workflow-header">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('requests/index.php'); ?>">Requests</a></li>
                    <li class="breadcrumb-item active">Service workflows</li>
                </ol>
            </nav>
            <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
            <p class="mb-0 text-gray-600">Replaces ad-hoc Google Forms with tracked requests in Asset Management.</p>
        </div>
        <?php if (!am_is_auditor_readonly()): ?>
        <div class="dropdown">
            <button class="btn btn-sm btn-gray-800 dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                <i class="fas fa-plus me-1"></i> New workflow
            </button>
            <ul class="dropdown-menu dropdown-menu-end">
                <?php foreach ($templates as $key => $info): ?>
                <li>
                    <a class="dropdown-item" href="<?php echo base_url('requests/workflow-new.php?type=' . urlencode($key)); ?>">
                        <?php echo htmlspecialchars($info['label'] ?? $key); ?>
                    </a>
                </li>
                <?php endforeach; ?>
            </ul>
        </div>
        <?php endif; ?>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($flash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($flashErr); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="row mb-4">
        <?php
        $statusConfig = [
            'Submitted' => ['color' => 'primary', 'icon' => 'fa-paper-plane'],
            'Approved'  => ['color' => 'success', 'icon' => 'fa-check'],
            'Rejected'  => ['color' => 'danger', 'icon' => 'fa-times'],
            'Fulfilled' => ['color' => 'info', 'icon' => 'fa-check-double'],
            'Cancelled' => ['color' => 'secondary', 'icon' => 'fa-ban'],
        ];
        foreach ($statusConfig as $status => $cfg):
        ?>
        <div class="col">
            <a href="<?php echo base_url('requests/workflow-index.php?status=' . urlencode($status)); ?>" class="text-decoration-none">
                <div class="card border-0 shadow text-center py-3 <?php echo $statusFilter === $status ? 'border-' . $cfg['color'] : ''; ?>">
                    <div class="card-body py-2">
                        <i class="fas <?php echo $cfg['icon']; ?> text-<?php echo $cfg['color']; ?> fa-lg"></i>
                        <h3 class="fw-extrabold mb-0 mt-1"><?php echo (int)($statusCounts[$status] ?? 0); ?></h3>
                        <small class="text-gray-500"><?php echo htmlspecialchars($status); ?></small>
                    </div>
                </div>
            </a>
        </div>
        <?php endforeach; ?>
    </div>

    <?php if ($typeFilter !== '' || $statusFilter !== ''): ?>
    <p class="small mb-3">
        <a href="<?php echo base_url('requests/workflow-index.php'); ?>">Clear filters</a>
    </p>
    <?php endif; ?>

    <div class="card border-0 shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="wfTable">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Workflow</th>
                            <th>Summary</th>
                            <th>Country</th>
                            <th>Status</th>
                            <th>Date</th>
                            <th></th>
                            <?php if ($canProcess): ?><th class="text-end">Actions</th><?php endif; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($filtered)): ?>
                        <tr><td colspan="<?php echo $canProcess ? 8 : 7; ?>" class="text-center text-gray-500 py-4">No workflow requests yet.</td></tr>
                        <?php else: ?>
                        <?php foreach ($filtered as $req):
                            $docId = (string)($req['id'] ?? '');
                            $status = (string)($req['status'] ?? '');
                            $statColors = ['Submitted' => 'primary', 'Approved' => 'success', 'Rejected' => 'danger', 'Fulfilled' => 'info', 'Cancelled' => 'secondary'];
                            $cid = (string)($req['requested_for_country'] ?? '');
                        ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($req['request_number'] ?? ''); ?></strong></td>
                            <td><?php echo htmlspecialchars($req['workflow_label'] ?? $req['workflow_type'] ?? ''); ?></td>
                            <td><?php echo htmlspecialchars((string)($req['summary'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars(($countryById[$cid] ?? [])['country_code'] ?? '—'); ?></td>
                            <td><span class="badge bg-<?php echo $statColors[$status] ?? 'secondary'; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                            <td><?php echo htmlspecialchars(substr((string)($req['requested_date'] ?? ''), 0, 10)); ?></td>
                            <td><?php $isDisp = ($req['workflow_type'] ?? '') === 'inventory_dispatch'; ?>
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url($isDisp ? 'requests/dispatch-view.php' : 'requests/workflow-view.php'); ?>?id=<?php echo urlencode($docId); ?>">View</a></td>
                            <?php if ($canProcess): ?>
                            <td class="text-end">
                                <?php if ($isDisp): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('requests/dispatch-view.php?id=' . urlencode($docId)); ?>" title="Open dispatch">
                                    <i class="fas fa-arrow-up-right-from-square"></i>
                                </a>
                                <?php elseif ($status === 'Submitted'): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($docId); ?>">
                                    <input type="hidden" name="new_status" value="Approved">
                                    <button type="submit" class="btn btn-sm btn-outline-success" title="Approve"><i class="fas fa-check"></i></button>
                                </form>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($docId); ?>">
                                    <input type="hidden" name="new_status" value="Rejected">
                                    <button type="submit" class="btn btn-sm btn-outline-danger" title="Reject"><i class="fas fa-times"></i></button>
                                </form>
                                <?php elseif ($status === 'Approved'): ?>
                                <form method="post" class="d-inline">
                                    <input type="hidden" name="action" value="update_status">
                                    <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($docId); ?>">
                                    <input type="hidden" name="new_status" value="Fulfilled">
                                    <button type="submit" class="btn btn-sm btn-outline-info" title="Fulfilled"><i class="fas fa-check-double"></i></button>
                                </form>
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
    var t = $('#wfTable');
    if (t.find('tbody td[colspan]').length) return;
    t.DataTable({ pageLength: 25, order: [[5, 'desc']] });
});
</script>
