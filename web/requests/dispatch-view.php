<?php
/**
 * Detail view for inventory dispatch requests.
 * Reads am_core_requests — renders line items, destination, receiver cards.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
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

// Redirect non-dispatch types to generic view
$wfType = (string)($req['workflow_type'] ?? '');
if ($wfType !== 'inventory_dispatch') {
    header('Location: ' . base_url('requests/workflow-view.php?id=' . urlencode($docId)));
    exit;
}

// ── Status update (POST) ────────────────────────────────────
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
            am_firestore_update_document('am_core_requests', $docId, $update);
            $_SESSION['flash_success'] = 'Status updated to ' . $newStatus . '.';
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
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="Approved">
                <button type="submit" class="btn btn-sm btn-success"><i class="fas fa-check me-1"></i>Approve</button>
            </form>
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="Rejected">
                <button type="submit" class="btn btn-sm btn-danger"><i class="fas fa-times me-1"></i>Reject</button>
            </form>
            <?php elseif ($canProcess && $status === 'Approved'): ?>
            <form method="post" class="d-inline">
                <input type="hidden" name="action" value="update_status">
                <input type="hidden" name="new_status" value="Fulfilled">
                <button type="submit" class="btn btn-sm btn-info"><i class="fas fa-check-double me-1"></i>Mark fulfilled</button>
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
                            <tr><th>#</th><th>Item</th><th>Tag</th><th>Class</th><th class="text-end">Qty</th><th>Unit</th></tr>
                        </thead>
                        <tbody>
                            <?php if (empty($lineItems)): ?>
                            <tr><td colspan="6" class="text-center text-gray-500 py-4">No line items.</td></tr>
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
                    <hr class="my-2">
                    <p class="mb-0 small text-gray-500">Request #: <?php echo htmlspecialchars($req['request_number'] ?? ''); ?></p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
