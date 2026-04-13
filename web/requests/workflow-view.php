<?php
/**
 * Detail view for one am_core_requests document.
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

$page_title = (string)($req['request_number'] ?? 'Workflow request');
$payload = $req['payload'] ?? [];
if (!is_array($payload)) {
    $payload = [];
}

$template = am_request_workflow_template((string)($req['workflow_type'] ?? ''));
$fieldLabels = [];
if ($template) {
    foreach ($template['fields'] ?? [] as $f) {
        $n = (string)($f['name'] ?? '');
        if ($n !== '') {
            $fieldLabels[$n] = (string)($f['label'] ?? $n);
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
                    <hr>
                    <h3 class="h6 text-gray-600">Submitted fields</h3>
                    <dl class="row mb-0">
                        <?php foreach ($payload as $key => $val): ?>
                        <dt class="col-sm-4 text-gray-600"><?php echo htmlspecialchars($fieldLabels[$key] ?? (string)$key); ?></dt>
                        <dd class="col-sm-8"><?php echo htmlspecialchars(is_scalar($val) ? (string)$val : json_encode($val)); ?></dd>
                        <?php endforeach; ?>
                    </dl>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
