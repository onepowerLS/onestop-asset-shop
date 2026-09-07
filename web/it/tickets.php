<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/it_am.php';
require_login();

$am_sidebar_file = __DIR__ . '/../includes/sidebar-it.php';
$app_name_display = 'IT Helpdesk';
$am_brand_suffix = 'IT';
$am_nav_home = am_it_path('index.php');

$page_title = 'Tickets';

$queueFilter = $_GET['queue'] ?? '';
$statusFilter = $_GET['status'] ?? '';

$tickets = am_firestore_get_collection(AM_IT_TICKETS_COLLECTION, 3000);
$filtered = [];
foreach ($tickets as $t) {
    if ($queueFilter !== '' && (string)($t['queue'] ?? '') !== $queueFilter) {
        continue;
    }
    if ($statusFilter !== '' && (string)($t['status'] ?? '') !== $statusFilter) {
        continue;
    }
    $filtered[] = $t;
}

usort($filtered, function ($a, $b) {
    return strtotime((string)($b['created_at'] ?? '')) <=> strtotime((string)($a['created_at'] ?? ''));
});

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-3 mb-3 border-bottom">
    <h1 class="h2 mb-0">Tickets</h1>
    <a class="btn btn-sm btn-primary" href="<?php echo htmlspecialchars(am_it_url('ticket-new.php')); ?>">New ticket</a>
</div>

<form method="get" class="row g-2 mb-3 align-items-end">
    <div class="col-auto">
        <label class="form-label small text-muted mb-0">Queue</label>
        <select name="queue" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All</option>
            <option value="it" <?php echo $queueFilter === 'it' ? 'selected' : ''; ?>>IT</option>
            <option value="am_operations" <?php echo $queueFilter === 'am_operations' ? 'selected' : ''; ?>>AM operations</option>
        </select>
    </div>
    <div class="col-auto">
        <label class="form-label small text-muted mb-0">Status</label>
        <select name="status" class="form-select form-select-sm" onchange="this.form.submit()">
            <option value="">All</option>
            <?php foreach (am_it_ticket_statuses() as $st): ?>
                <option value="<?php echo htmlspecialchars($st); ?>" <?php echo $statusFilter === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<div class="card border-0 shadow">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0" id="itTicketsTable">
                <thead class="thead-light">
                    <tr>
                        <th>Ticket</th>
                        <th>Queue</th>
                        <th>Title</th>
                        <th>Status</th>
                        <th>Priority</th>
                        <th>Updated</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filtered as $t): ?>
                        <?php
                        $id = (string)($t['id'] ?? '');
                        $num = (string)($t['ticket_number'] ?? $id);
                        $viewUrl = am_it_url('ticket-view.php') . '?id=' . rawurlencode($id);
                        ?>
                        <tr>
                            <td><a href="<?php echo htmlspecialchars($viewUrl); ?>"><?php echo htmlspecialchars($num); ?></a></td>
                            <td><?php echo htmlspecialchars((string)($t['queue'] ?? '')); ?></td>
                            <td><?php
                            $ti = (string)($t['title'] ?? '');
                            echo htmlspecialchars(function_exists('mb_substr') ? mb_substr($ti, 0, 80) . (strlen($ti) > 80 ? '…' : '') : (strlen($ti) > 80 ? substr($ti, 0, 77) . '…' : $ti));
                        ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars((string)($t['status'] ?? '')); ?></span></td>
                            <td><?php echo htmlspecialchars((string)($t['priority'] ?? '')); ?></td>
                            <td class="small text-muted"><?php echo htmlspecialchars(substr((string)($t['updated_at'] ?? $t['created_at'] ?? ''), 0, 16)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(function () {
    if ($.fn.DataTable) {
        $('#itTicketsTable').DataTable({ order: [[0, 'desc']], pageLength: 25 });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
