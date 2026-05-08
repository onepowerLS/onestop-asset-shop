<?php
/**
 * RET Material Request Archive — detail view.
 * Read-only. Shows items, requester, site, receiver, notes.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

$docId = trim($_GET['id'] ?? '');
if ($docId === '') {
    header('Location: ' . base_url('requests/archived-ret.php'));
    exit;
}

$req = am_firestore_get_document('am_core_archived_requests', $docId);
if (!$req) {
    $_SESSION['flash_error'] = 'Archived request not found.';
    header('Location: ' . base_url('requests/archived-ret.php'));
    exit;
}

$page_title = (string)($req['archived_request_number'] ?? 'RET archive');

$items = $req['items_requested_list'] ?? [];
if (!is_array($items)) $items = [];
$itemsRaw = (string)($req['items_requested_raw'] ?? '');

$ts = (string)($req['timestamp'] ?? '');
$tsDisplay = $ts !== '' ? date('Y-m-d H:i', strtotime($ts)) : '—';

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="<?php echo base_url('requests/archived-ret.php'); ?>">RET archives</a></li>
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
            <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
            <p class="mb-0 text-gray-600">
                <span class="badge bg-secondary"><?php echo htmlspecialchars($req['source_type'] ?? 'GoogleForm'); ?></span>
                <?php if (!empty($req['database_updated'])): ?>
                <span class="badge bg-success ms-1">Processed</span>
                <?php endif; ?>
            </p>
        </div>
        <a href="<?php echo base_url('requests/archived-ret.php'); ?>" class="btn btn-outline-secondary btn-sm">Back to list</a>
    </div>

    <div class="row g-4">
        <!-- Items requested -->
        <div class="col-12 col-lg-8">
            <div class="card border-0 shadow">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Items requested</h2></div>
                <div class="card-body">
                    <?php if (empty($items)): ?>
                    <p class="text-gray-500 mb-0">No items listed.</p>
                    <?php else: ?>
                    <ol class="mb-3">
                        <?php foreach ($items as $item): ?>
                        <li><?php echo htmlspecialchars($item); ?></li>
                        <?php endforeach; ?>
                    </ol>
                    <?php endif; ?>

                    <?php if ($itemsRaw !== '' && count($items) > 1): ?>
                    <details>
                        <summary class="small text-gray-500 cursor-pointer">Original submission text</summary>
                        <pre class="mt-2 p-3 bg-light rounded small text-gray-700 mb-0"><?php echo htmlspecialchars($itemsRaw); ?></pre>
                    </details>
                    <?php endif; ?>
                </div>
            </div>

            <?php if (!empty($req['notes'])): ?>
            <div class="card border-0 shadow mt-3">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Notes</h2></div>
                <div class="card-body">
                    <p class="mb-0"><?php echo htmlspecialchars($req['notes']); ?></p>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <!-- Sidebar cards -->
        <div class="col-12 col-lg-4">
            <!-- Requester -->
            <div class="card border-0 shadow mb-3">
                <div class="card-header"><h2 class="fs-6 fw-bold mb-0"><i class="fas fa-user me-2 text-primary"></i>Requester</h2></div>
                <div class="card-body">
                    <p class="mb-1"><strong><?php echo htmlspecialchars($req['requester_name'] ?? '—'); ?></strong></p>
                    <p class="mb-0 small text-gray-600"><?php echo htmlspecialchars($req['requester_email'] ?? '—'); ?></p>
                </div>
            </div>

            <!-- Site & dispatch -->
            <div class="card border-0 shadow mb-3">
                <div class="card-header"><h2 class="fs-6 fw-bold mb-0"><i class="fas fa-location-dot me-2 text-success"></i>Site &amp; dispatch</h2></div>
                <div class="card-body">
                    <p class="mb-1"><span class="badge bg-secondary"><?php echo htmlspecialchars($req['site_code'] ?? '—'); ?></span></p>
                    <p class="mb-1 small text-gray-600">Country: <strong><?php echo htmlspecialchars($req['site_country_code'] ?? 'LSO'); ?></strong></p>
                    <p class="mb-0 small text-gray-600">
                        <i class="fas fa-calendar me-1"></i>Dispatch: <strong><?php echo htmlspecialchars($req['estimated_dispatch_date'] ?? '—'); ?></strong>
                    </p>
                </div>
            </div>

            <!-- Receiver -->
            <div class="card border-0 shadow mb-3">
                <div class="card-header"><h2 class="fs-6 fw-bold mb-0"><i class="fas fa-user-check me-2 text-warning"></i>Receiver</h2></div>
                <div class="card-body">
                    <p class="mb-1"><strong><?php echo htmlspecialchars($req['receiver_name'] ?? '—'); ?></strong></p>
                    <p class="mb-0 small text-gray-600"><?php echo htmlspecialchars($req['receiver_email'] ?? '—'); ?></p>
                </div>
            </div>

            <!-- Info -->
            <div class="card border-0 shadow mb-3">
                <div class="card-header"><h2 class="fs-6 fw-bold mb-0"><i class="fas fa-info-circle me-2 text-info"></i>Record info</h2></div>
                <div class="card-body">
                    <p class="mb-1 small">Submitted: <strong><?php echo htmlspecialchars($tsDisplay); ?></strong></p>
                    <p class="mb-1 small">
                        Database updated:
                        <?php if (!empty($req['database_updated'])): ?>
                        <span class="badge bg-success">Yes</span>
                        <?php else: ?>
                        <span class="badge bg-secondary">No</span>
                        <?php endif; ?>
                    </p>
                    <p class="mb-0 small text-gray-500">Source: Google Form (RET Request Log)</p>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
