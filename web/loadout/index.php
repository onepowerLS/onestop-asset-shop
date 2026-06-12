<?php
/**
 * Load-out manifests — list (packing lists for HQ → site).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/loadout_manifests.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_login();
am_ensure_country_scope_from_session();

$page_title = 'Load-out manifests';
$statusFilter = trim($_GET['status'] ?? '');
$tripFilter = trim($_GET['trip'] ?? '');

$manifests = am_firestore_get_collection(AM_LOADOUT_COLLECTION, 2000);
$countries = am_firestore_get_collection('pr_master_countries', 500);
$manifests = array_values(array_filter($manifests, fn($m) => am_record_in_country_scope($m, $countries)));

if ($statusFilter !== '') {
    $manifests = array_values(array_filter($manifests, fn($m) => (string)($m['status'] ?? '') === $statusFilter));
}
if ($tripFilter !== '') {
    $manifests = array_values(array_filter($manifests, function ($m) use ($tripFilter) {
        $tid = (string)($m['trip_id'] ?? '');
        return $tid !== '' && stripos($tid, $tripFilter) !== false;
    }));
}

usort($manifests, function ($a, $b) {
    return strcmp((string)($b['updated_at'] ?? $b['created_at'] ?? ''), (string)($a['updated_at'] ?? $a['created_at'] ?? ''));
});

$statuses = am_loadout_statuses();

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$flashErr = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <?php if ($flash !== ''): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($flash); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($flashErr !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert"><?php echo htmlspecialchars($flashErr); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 mb-4">
        <div data-tutorial="tutorial-loadout-header">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('index.php'); ?>">Home</a></li>
                    <li class="breadcrumb-item active">Load-out manifests</li>
                </ol>
            </nav>
            <h1 class="h3 mb-1">Load-out manifests</h1>
            <p class="text-muted mb-0" data-tutorial="tutorial-loadout-fleet">Packing lists for items leaving HQ toward a field site. Link a manifest to a Fleet / trip in <code>fm.1pwrafrica.com</code> via the trip ID (API or Firestore).</p>
        </div>
        <?php if (!am_is_auditor_readonly()): ?>
        <a href="<?php echo base_url('loadout/edit.php'); ?>" class="btn btn-primary">
            <i class="fas fa-plus me-1"></i> New manifest
        </a>
        <?php endif; ?>
    </div>

    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <form method="get" class="row g-2 align-items-end">
                <div class="col-md-3">
                    <label class="form-label small text-muted">Status</label>
                    <select name="status" class="form-select form-select-sm">
                        <option value="">All</option>
                        <?php foreach ($statuses as $s): ?>
                            <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $statusFilter === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label small text-muted">Trip ID contains</label>
                    <input type="text" name="trip" class="form-control form-control-sm" value="<?php echo htmlspecialchars($tripFilter); ?>" placeholder="e.g. FM trip document id">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-sm btn-secondary">Filter</button>
                    <a href="<?php echo base_url('loadout/index.php'); ?>" class="btn btn-sm btn-outline-secondary">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Manifest #</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Destination</th>
                        <th>Trip</th>
                        <th>Updated</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($manifests)): ?>
                    <tr><td colspan="7" class="text-muted py-4 text-center">No manifests found.</td></tr>
                    <?php else: ?>
                    <?php foreach ($manifests as $m): ?>
                    <?php
                        $mid = (string)($m['id'] ?? '');
                        $num = (string)($m['manifest_number'] ?? $mid);
                        $title = (string)($m['title'] ?? '');
                        $st = (string)($m['status'] ?? '');
                        $dest = (string)($m['destination_site_label'] ?? '');
                        if ($dest === '') {
                            $dest = '—';
                        }
                        $tripId = trim((string)($m['trip_id'] ?? ''));
                        $tripCell = $tripId !== '' ? htmlspecialchars($tripId) : '<span class="text-muted">—</span>';
                        $upd = (string)($m['updated_at'] ?? $m['created_at'] ?? '');
                    ?>
                    <tr>
                        <td><code><?php echo htmlspecialchars($num); ?></code></td>
                        <td><?php echo $title !== '' ? htmlspecialchars($title) : '<span class="text-muted">(no title)</span>'; ?></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($st); ?></span></td>
                        <td><?php echo htmlspecialchars($dest); ?></td>
                        <td><?php echo $tripCell; ?></td>
                        <td class="small text-muted"><?php echo $upd !== '' ? htmlspecialchars($upd) : '—'; ?></td>
                        <td class="text-end">
                            <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('loadout/view.php?id=' . urlencode($mid)); ?>">View</a>
                            <?php if (!am_is_auditor_readonly()): ?>
                            <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('loadout/edit.php?id=' . urlencode($mid)); ?>">Edit</a>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
