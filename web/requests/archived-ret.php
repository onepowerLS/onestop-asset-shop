<?php
/**
 * RET Material Request Archives — listing page.
 * View-only historical Google Form submissions (am_core_archived_requests).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/country_scope.php';
require_login();

$page_title = 'RET Material Request Archives';

$archives = am_firestore_get_collection('am_core_archived_requests', 2000);

// Site filter
$siteFilter = trim($_GET['site'] ?? '');
$filtered = [];
foreach ($archives as $a) {
    if ($siteFilter !== '' && strtoupper((string)($a['site_code'] ?? '')) !== strtoupper($siteFilter)) {
        continue;
    }
    $filtered[] = $a;
}

// Sort newest first
usort($filtered, function ($a, $b) {
    return strtotime((string)($b['timestamp'] ?? '1970-01-01'))
        <=> strtotime((string)($a['timestamp'] ?? '1970-01-01'));
});

// Collect unique sites for filter dropdown
$allSites = [];
foreach ($archives as $a) {
    $sc = strtoupper((string)($a['site_code'] ?? ''));
    if ($sc !== '') {
        $allSites[$sc] = ($allSites[$sc] ?? 0) + 1;
    }
}
ksort($allSites);

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$flashErr = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-2">
            <li class="breadcrumb-item"><a href="<?php echo base_url('requests/index.php'); ?>">Requests</a></li>
            <li class="breadcrumb-item active">RET archives</li>
        </ol>
    </nav>

    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2 mb-3">
        <div>
            <h1 class="h2">RET Material Request Archives</h1>
            <p class="mb-0 text-gray-600">Historical Google Form submissions (view-only) &middot; <?php echo count($filtered); ?> record<?php echo count($filtered) !== 1 ? 's' : ''; ?></p>
        </div>
        <a href="<?php echo base_url('requests/workflow-index.php'); ?>" class="btn btn-outline-secondary btn-sm">Active workflows</a>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($flash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if ($flashErr): ?>
    <div class="alert alert-danger alert-dismissible fade show"><?php echo htmlspecialchars($flashErr); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- Site filter -->
    <div class="d-flex align-items-center gap-3 mb-3">
        <form method="get" class="d-flex align-items-center gap-2">
            <label for="siteFilter" class="form-label mb-0 text-gray-600 small">Site:</label>
            <select id="siteFilter" name="site" class="form-select form-select-sm" style="width:auto;" onchange="this.form.submit()">
                <option value="">All sites</option>
                <?php foreach ($allSites as $sc => $n): ?>
                <option value="<?php echo htmlspecialchars($sc); ?>" <?php echo $siteFilter === $sc ? 'selected' : ''; ?>><?php echo htmlspecialchars($sc); ?> (<?php echo $n; ?>)</option>
                <?php endforeach; ?>
            </select>
        </form>
        <?php if ($siteFilter !== ''): ?>
        <a href="<?php echo base_url('requests/archived-ret.php'); ?>" class="btn btn-link btn-sm text-decoration-none">Clear filter</a>
        <?php endif; ?>
    </div>

    <div class="card border-0 shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="archivedRetTable">
                    <thead>
                        <tr>
                            <th>Request #</th>
                            <th>Requester</th>
                            <th>Items</th>
                            <th>Site</th>
                            <th>Dispatch date</th>
                            <th>Receiver</th>
                            <th class="text-end">View</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($filtered)): ?>
                        <tr><td colspan="7" class="text-center text-gray-500 py-4">No archived requests found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($filtered as $a):
                            $ts = $a['timestamp'] ?? '';
                            $tsDisplay = $ts !== '' ? date('Y-m-d', strtotime($ts)) : '—';
                            $itemsCount = count($a['items_requested_list'] ?? []);
                            $itemsSummary = (string)($a['items_requested_list'][0] ?? (string)($a['items_requested_raw'] ?? '—'));
                            if (mb_strlen($itemsSummary) > 60) {
                                $itemsSummary = mb_substr($itemsSummary, 0, 57) . '…';
                            }
                            if ($itemsCount > 1) {
                                $itemsSummary .= ' (+' . ($itemsCount - 1) . ' more)';
                            }
                        ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($a['archived_request_number'] ?? '—'); ?></code></td>
                            <td class="small">
                                <span class="d-block fw-semibold"><?php echo htmlspecialchars($a['requester_name'] ?? '—'); ?></span>
                                <span class="text-gray-500"><?php echo htmlspecialchars($a['requester_email'] ?? ''); ?></span>
                            </td>
                            <td class="small"><?php echo htmlspecialchars($itemsSummary); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars($a['site_code'] ?? '—'); ?></span></td>
                            <td class="small"><?php echo htmlspecialchars($a['estimated_dispatch_date'] ?? '—'); ?></td>
                            <td class="small"><?php echo htmlspecialchars($a['receiver_name'] ?? '—'); ?></td>
                            <td class="text-end">
                                <a href="<?php echo base_url('requests/archived-ret-view.php?id=' . urlencode($a['archived_request_number'])); ?>" class="btn btn-sm btn-outline-primary">View</a>
                            </td>
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
    $('#archivedRetTable').DataTable({ pageLength: 25, order: [[4, 'desc']], dom: "<'row'<'col-sm-12 col-md-6'l><'col-sm-12 col-md-6'f>>" + "<'row'<'col-sm-12'tr>>" + "<'row'<'col-sm-12 col-md-5'i><'col-sm-12 col-md-7'p>>" });
});
</script>
