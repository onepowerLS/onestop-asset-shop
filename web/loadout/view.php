<?php
/**
 * Read-only manifest view (print-friendly packing list).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/loadout_manifests.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_login();
am_ensure_country_scope_from_session();

$docId = trim($_GET['id'] ?? '');
if ($docId === '') {
    header('Location: ' . base_url('loadout/index.php'));
    exit;
}

$m = am_firestore_get_document(AM_LOADOUT_COLLECTION, $docId);
if (!$m) {
    $_SESSION['flash_error'] = 'Manifest not found.';
    header('Location: ' . base_url('loadout/index.php'));
    exit;
}

$countries = am_firestore_get_collection('pr_master_countries', 500);
if (!am_record_in_country_scope($m, $countries)) {
    $_SESSION['flash_error'] = 'Manifest is outside your country scope.';
    header('Location: ' . base_url('loadout/index.php'));
    exit;
}

$page_title = (string)($m['manifest_number'] ?? 'Manifest');
$lines = $m['lines'] ?? [];
if (!is_array($lines)) {
    $lines = [];
}

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4 loadout-print-area">
    <?php if ($flash !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show no-print" role="alert"><?php echo htmlspecialchars($flash); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4 no-print">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('loadout/index.php'); ?>">Load-out manifests</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($page_title); ?></li>
                </ol>
            </nav>
            <h1 class="h3 mb-0"><code><?php echo htmlspecialchars((string)($m['manifest_number'] ?? '')); ?></code></h1>
            <?php if (!empty($m['title'])): ?>
            <p class="text-muted mb-0"><?php echo htmlspecialchars((string)$m['title']); ?></p>
            <?php endif; ?>
        </div>
        <div class="d-flex gap-2">
            <button type="button" class="btn btn-outline-secondary" onclick="window.print();"><i class="fas fa-print me-1"></i> Print</button>
            <?php if (!am_is_auditor_readonly()): ?>
            <a href="<?php echo base_url('loadout/edit.php?id=' . urlencode($docId)); ?>" class="btn btn-primary">Edit</a>
            <?php endif; ?>
        </div>
    </div>

    <div class="card border-0 shadow">
        <div class="card-body">
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="small text-muted text-uppercase">Status</div>
                    <div><span class="badge bg-secondary"><?php echo htmlspecialchars((string)($m['status'] ?? '')); ?></span></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted text-uppercase">Last updated</div>
                    <div><?php echo htmlspecialchars((string)($m['updated_at'] ?? $m['created_at'] ?? '—')); ?></div>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="small text-muted text-uppercase">Origin</div>
                    <div><?php echo htmlspecialchars((string)($m['origin_label'] ?? '—')); ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted text-uppercase">Destination</div>
                    <div><?php echo htmlspecialchars((string)($m['destination_site_label'] ?? ($m['destination_site_id'] ?? '—'))); ?></div>
                </div>
            </div>
            <div class="row mb-3">
                <div class="col-md-6">
                    <div class="small text-muted text-uppercase">Trip ID (FM)</div>
                    <div><?php $tid = trim((string)($m['trip_id'] ?? '')); echo $tid !== '' ? '<code>' . htmlspecialchars($tid) . '</code>' : '<span class="text-muted">—</span>'; ?></div>
                </div>
                <div class="col-md-6">
                    <div class="small text-muted text-uppercase">Trip label</div>
                    <div><?php echo htmlspecialchars((string)($m['trip_label'] ?? '—')); ?></div>
                </div>
            </div>
            <?php if (trim((string)($m['notes'] ?? '')) !== ''): ?>
            <div class="mb-4">
                <div class="small text-muted text-uppercase">Notes</div>
                <div class="border rounded p-2 bg-light"><?php echo nl2br(htmlspecialchars((string)$m['notes'])); ?></div>
            </div>
            <?php endif; ?>

            <h2 class="h6 text-uppercase text-muted mb-3">Packing list</h2>
            <div class="table-responsive">
                <table class="table table-bordered align-middle">
                    <thead class="table-light">
                        <tr>
                            <th>#</th>
                            <th>Asset ID</th>
                            <th>Description (snapshot)</th>
                            <th>Tag / QR</th>
                            <th class="text-end">Qty</th>
                            <th>Operation</th>
                            <th>Stop #</th>
                            <th>Stop ID</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($lines)): ?>
                        <tr><td colspan="9" class="text-muted">No line items.</td></tr>
                        <?php else: ?>
                        <?php foreach ($lines as $line): ?>
                        <?php if (!is_array($line)) { continue; } ?>
                        <tr>
                            <td><?php echo (int)($line['line_no'] ?? 0); ?></td>
                            <td><code><?php echo htmlspecialchars((string)($line['asset_id'] ?? '')); ?></code></td>
                            <td><?php echo htmlspecialchars((string)($line['name_snapshot'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($line['tag_snapshot'] ?? '')); ?></td>
                            <td class="text-end"><?php echo (int)($line['quantity'] ?? 1); ?></td>
                            <td><?php echo htmlspecialchars((string)($line['operation'] ?? 'carry')); ?></td>
                            <td><?php echo htmlspecialchars((string)($line['stop_number'] ?? '')); ?></td>
                            <td><code><?php echo htmlspecialchars((string)($line['stop_id'] ?? '')); ?></code></td>
                            <td><?php echo htmlspecialchars((string)($line['notes'] ?? '')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<style media="print">
    .no-print, #sidebarMenu, #topbar, .sidebar, nav, footer, .btn { display: none !important; }
    .loadout-print-area { padding: 0 !important; }
    .card { box-shadow: none !important; border: none !important; }
</style>

<?php include __DIR__ . '/../includes/footer.php'; ?>
