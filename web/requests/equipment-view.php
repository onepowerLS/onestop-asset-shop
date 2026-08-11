<?php
/**
 * Detail view for IT equipment requests (workflow_type = it_equipment_request).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/request_workflows.php';
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

$wfType = (string)($req['workflow_type'] ?? '');
if ($wfType !== 'it_equipment_request') {
    header('Location: ' . base_url('requests/workflow-view.php?id=' . urlencode($docId)));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    am_require_can_mutate();
    $action = $_POST['action'] ?? '';
    if ($action === 'update_status') {
        $newStatus = trim($_POST['new_status'] ?? '');
        if (in_array($newStatus, ['Approved', 'Rejected', 'Fulfilled', 'Cancelled'], true)) {
            $update = ['status' => $newStatus];
            if ($newStatus === 'Fulfilled') {
                $update['fulfilled_date'] = date('c');
            }
            $updateResult = am_firestore_update_document('am_core_requests', $docId, $update);
            if ($updateResult['ok']) {
                $_SESSION['flash_success'] = 'Status updated to ' . $newStatus . '.';
            } else {
                $_SESSION['flash_error'] = 'Status update failed: ' . ($updateResult['error'] ?? 'unknown error');
            }
        }
        header('Location: ' . base_url('requests/equipment-view.php?id=' . urlencode($docId)));
        exit;
    }
}

$payload = $req['payload'] ?? [];
if (!is_array($payload)) {
    $payload = [];
}
$lastNotification = is_array($req['last_notification'] ?? null) ? $req['last_notification'] : [];

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
$canProcess = am_is_manager_role();

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="<?php echo base_url('requests/workflow-index.php'); ?>">Service workflows</a></li>
            <li class="breadcrumb-item active"><?php echo htmlspecialchars((string)($req['request_number'] ?? 'IT equipment request')); ?></li>
        </ol>
    </nav>

    <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($flash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div>
            <h1 class="h2"><?php echo htmlspecialchars((string)($req['workflow_label'] ?? 'IT Equipment Request')); ?></h1>
            <p class="mb-0 text-gray-600">
                <strong><?php echo htmlspecialchars((string)($req['request_number'] ?? '')); ?></strong>
                · <span class="badge bg-secondary"><?php echo htmlspecialchars($status); ?></span>
            </p>
            <?php if (!empty($lastNotification)): ?>
            <p class="small mb-0 mt-2 text-gray-600"><i class="fas fa-envelope me-1"></i>
                <?php echo htmlspecialchars(match ((string)($lastNotification['delivery_status'] ?? '')) {
                    'sent' => 'Email sent',
                    'failed_retrying' => 'Email delayed — retrying',
                    'skipped_missing_recipient' => 'Email not sent — requester email missing',
                    default => 'Email status pending',
                }); ?>
                <?php if (!empty($lastNotification['recipient'])): ?> to <?php echo htmlspecialchars((string)$lastNotification['recipient']); ?><?php endif; ?>
                · <?php echo htmlspecialchars((string)($lastNotification['status'] ?? '')); ?>
            </p>
            <?php endif; ?>
        </div>
        <a href="<?php echo base_url('requests/workflow-index.php'); ?>" class="btn btn-outline-secondary btn-sm">Back to list</a>
    </div>

    <div class="row g-4">
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Request details</h2></div>
                <div class="card-body">
                    <dl class="row mb-0">
                        <dt class="col-sm-4 text-gray-600">Summary</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars((string)($req['summary'] ?? '')); ?></dd>

                        <dt class="col-sm-4 text-gray-600">Request type</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars((string)($payload['equipment_request_type'] ?? '—')); ?></dd>

                        <dt class="col-sm-4 text-gray-600">Category</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars((string)($payload['equipment_category'] ?? '—')); ?></dd>

                        <dt class="col-sm-4 text-gray-600">Country</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars($countryLabel); ?></dd>

                        <dt class="col-sm-4 text-gray-600">Destination site</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars((string)($payload['site_code'] ?? '—')); ?></dd>

                        <dt class="col-sm-4 text-gray-600">Receiver</dt>
                        <dd class="col-sm-8">
                            <?php echo htmlspecialchars((string)($payload['receiver_name'] ?? '—')); ?><br>
                            <span class="text-gray-500 small"><?php echo htmlspecialchars((string)($payload['receiver_email'] ?? '—')); ?></span>
                        </dd>

                        <dt class="col-sm-4 text-gray-600">Submitted by</dt>
                        <dd class="col-sm-8">
                            <?php echo htmlspecialchars((string)($payload['submitter_name'] ?? '—')); ?><br>
                            <span class="text-gray-500 small"><?php echo htmlspecialchars((string)($payload['submitter_email'] ?? '—')); ?></span>
                        </dd>

                        <dt class="col-sm-4 text-gray-600">IS&amp;T ticket</dt>
                        <dd class="col-sm-8"><code><?php echo htmlspecialchars((string)($payload['ist_ticket_id'] ?? '—')); ?></code></dd>

                        <dt class="col-sm-4 text-gray-600">Requested</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars(substr((string)($req['requested_date'] ?? ''), 0, 19)); ?></dd>

                        <?php if (!empty($req['fulfilled_date'])): ?>
                        <dt class="col-sm-4 text-gray-600">Fulfilled</dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars(substr((string)$req['fulfilled_date'], 0, 19)); ?></dd>
                        <?php endif; ?>
                    </dl>

                    <?php if (!empty($payload['justification'])): ?>
                    <hr>
                    <h3 class="h6 text-gray-600">Justification</h3>
                    <p class="mb-0"><?php echo nl2br(htmlspecialchars((string)$payload['justification'])); ?></p>
                    <?php endif; ?>

                    <?php if (!empty($payload['specifications'])): ?>
                    <hr>
                    <h3 class="h6 text-gray-600">Specifications</h3>
                    <p class="mb-0"><?php echo nl2br(htmlspecialchars((string)$payload['specifications'])); ?></p>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-4">
            <div class="card border-0 shadow">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Status</h2></div>
                <div class="card-body">
                    <?php if ($canProcess): ?>
                    <div class="d-flex flex-wrap gap-2 mb-3">
                        <?php if ($status === 'Submitted' || $status === 'Approved'): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Approve this request?')">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="new_status" value="Approved">
                            <button type="submit" class="btn btn-sm btn-success" <?php echo $status === 'Approved' ? 'disabled' : ''; ?>><i class="fas fa-check me-1"></i>Approve</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($status === 'Submitted' || $status === 'Approved'): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Reject this request?')">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="new_status" value="Rejected">
                            <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-times me-1"></i>Reject</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($status === 'Approved'): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Mark this request as fulfilled?')">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="new_status" value="Fulfilled">
                            <button type="submit" class="btn btn-sm btn-info"><i class="fas fa-check-double me-1"></i>Mark fulfilled</button>
                        </form>
                        <?php endif; ?>
                        <?php if ($status === 'Submitted' || $status === 'Approved'): ?>
                        <form method="post" class="d-inline" onsubmit="return confirm('Cancel this request?')">
                            <input type="hidden" name="action" value="update_status">
                            <input type="hidden" name="new_status" value="Cancelled">
                            <button type="submit" class="btn btn-sm btn-outline-secondary"><i class="fas fa-ban me-1"></i>Cancel</button>
                        </form>
                        <?php endif; ?>
                    </div>
                    <?php endif; ?>
                    <p class="mb-0 small text-gray-500">Current status: <strong><?php echo htmlspecialchars($status); ?></strong></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
