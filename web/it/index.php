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

$page_title = 'Dashboard';

$tickets = am_firestore_get_collection(AM_IT_TICKETS_COLLECTION, 3000);

$byStatus = ['Open' => 0, 'InProgress' => 0, 'Resolved' => 0, 'Closed' => 0, 'Cancelled' => 0];
$byQueue = ['it' => 0, 'am_operations' => 0];
$openTotal = 0;
foreach ($tickets as $t) {
    $st = (string)($t['status'] ?? 'Open');
    if (isset($byStatus[$st])) {
        $byStatus[$st]++;
    }
    $q = (string)($t['queue'] ?? 'it');
    if (isset($byQueue[$q])) {
        $byQueue[$q]++;
    }
    if (!in_array($st, ['Resolved', 'Closed', 'Cancelled'], true)) {
        $openTotal++;
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-3 mb-3 border-bottom">
    <h1 class="h2 mb-0">IT &amp; operations support</h1>
</div>

<div class="row g-3 mb-4">
    <div class="col-md-3">
        <div class="card border-0 shadow h-100">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small">Open tickets</h6>
                <p class="h2 mb-0 text-primary"><?php echo (int)$openTotal; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow h-100">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small">IT queue</h6>
                <p class="h2 mb-0"><?php echo (int)$byQueue['it']; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow h-100">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small">AM operations queue</h6>
                <p class="h2 mb-0"><?php echo (int)$byQueue['am_operations']; ?></p>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="card border-0 shadow h-100">
            <div class="card-body">
                <h6 class="text-muted text-uppercase small">In progress</h6>
                <p class="h2 mb-0"><?php echo (int)$byStatus['InProgress']; ?></p>
            </div>
        </div>
    </div>
</div>

<div class="row g-4">
    <div class="col-lg-6">
        <div class="card border-0 shadow">
            <div class="card-header bg-white border-bottom py-3">
                <strong>Quick actions</strong>
            </div>
            <div class="card-body">
                <a class="btn btn-primary me-2 mb-2" href="<?php echo htmlspecialchars(am_it_url('ticket-new.php')); ?>">New ticket</a>
                <a class="btn btn-outline-secondary mb-2" href="<?php echo htmlspecialchars(am_it_url('tickets.php')); ?>">Browse all</a>
            </div>
        </div>
    </div>
    <div class="col-lg-6">
        <div class="card border-0 shadow">
            <div class="card-header bg-white border-bottom py-3">
                <strong>Queues</strong>
            </div>
            <div class="card-body small text-muted">
                <p class="mb-2"><strong>IT</strong> — computers, phones, tablets, printers, networks, peripherals, software/UX.</p>
                <p class="mb-0"><strong>AM operations</strong> — equipment and site issues that are not IT and not vehicles (vehicles stay in Fleet Management).</p>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
