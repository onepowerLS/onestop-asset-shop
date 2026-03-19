<?php
/**
 * Reports hub -- generate and download asset registers, transaction logs,
 * and stock reports as CSV or PDF.
 */
require_once __DIR__ . '/../config/app.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['firebase_id_token'])) {
    header('Location: /login.php'); exit;
}

require_once __DIR__ . '/../config/firestore.php';

$countries = am_firestore_get_collection('pr_master_countries', 100);
$pageTitle = 'Reports';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="py-4">
        <h2 class="h4 mb-4">Reports &amp; Export</h2>

        <div class="row g-4">
            <!-- Asset Register -->
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5><i class="fas fa-clipboard-list text-primary me-2"></i>Asset Register</h5>
                        <p class="text-muted small">Complete item register filtered by class and country.</p>
                        <form action="<?= base_url('reports/export.php') ?>" method="get">
                            <input type="hidden" name="report" value="asset_register">
                            <div class="mb-2">
                                <select name="item_class" class="form-select form-select-sm">
                                    <option value="">All Classes</option>
                                    <option value="FixedAsset">Fixed Assets</option>
                                    <option value="Material">Materials</option>
                                    <option value="Consumable">Consumables</option>
                                    <option value="Inventory">Inventory</option>
                                </select>
                            </div>
                            <div class="mb-2">
                                <select name="country_code" class="form-select form-select-sm">
                                    <option value="">All Countries</option>
                                    <?php foreach ($countries as $c): ?>
                                        <option value="<?= htmlspecialchars($c['country_code'] ?? '') ?>">
                                            <?= htmlspecialchars($c['country_name'] ?? $c['country_code'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" name="format" value="csv" class="btn btn-sm btn-outline-success flex-fill">
                                    <i class="fas fa-file-csv me-1"></i> CSV
                                </button>
                                <button type="submit" name="format" value="pdf" class="btn btn-sm btn-outline-danger flex-fill">
                                    <i class="fas fa-file-pdf me-1"></i> PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Transaction Log -->
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5><i class="fas fa-exchange-alt text-warning me-2"></i>Transaction Log</h5>
                        <p class="text-muted small">Audit trail of all item movements and actions.</p>
                        <form action="<?= base_url('reports/export.php') ?>" method="get">
                            <input type="hidden" name="report" value="transactions">
                            <div class="mb-2">
                                <select name="transaction_type" class="form-select form-select-sm">
                                    <option value="">All Types</option>
                                    <?php foreach (['CheckOut','CheckIn','StockTake','Transfer','Allocation','Return','WriteOff','Consume','Deploy'] as $t): ?>
                                        <option value="<?= $t ?>"><?= $t ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2 row g-1">
                                <div class="col-6">
                                    <input type="date" name="date_from" class="form-control form-control-sm" placeholder="From">
                                </div>
                                <div class="col-6">
                                    <input type="date" name="date_to" class="form-control form-control-sm" placeholder="To">
                                </div>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" name="format" value="csv" class="btn btn-sm btn-outline-success flex-fill">
                                    <i class="fas fa-file-csv me-1"></i> CSV
                                </button>
                                <button type="submit" name="format" value="pdf" class="btn btn-sm btn-outline-danger flex-fill">
                                    <i class="fas fa-file-pdf me-1"></i> PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Stock Report -->
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5><i class="fas fa-boxes-stacked text-success me-2"></i>Stock Report</h5>
                        <p class="text-muted small">Current stock levels with reorder status for Materials, Consumables, and Inventory.</p>
                        <form action="<?= base_url('reports/export.php') ?>" method="get">
                            <input type="hidden" name="report" value="stock">
                            <div class="mb-2">
                                <select name="country_code" class="form-select form-select-sm">
                                    <option value="">All Countries</option>
                                    <?php foreach ($countries as $c): ?>
                                        <option value="<?= htmlspecialchars($c['country_code'] ?? '') ?>">
                                            <?= htmlspecialchars($c['country_name'] ?? $c['country_code'] ?? '') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="mb-2">
                                <label class="form-check form-check-inline">
                                    <input type="checkbox" name="low_stock_only" value="1" class="form-check-input">
                                    <span class="form-check-label small">Low stock only</span>
                                </label>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" name="format" value="csv" class="btn btn-sm btn-outline-success flex-fill">
                                    <i class="fas fa-file-csv me-1"></i> CSV
                                </button>
                                <button type="submit" name="format" value="pdf" class="btn btn-sm btn-outline-danger flex-fill">
                                    <i class="fas fa-file-pdf me-1"></i> PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Allocation Report -->
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5><i class="fas fa-user-check text-info me-2"></i>Allocation Report</h5>
                        <p class="text-muted small">Active and historical item allocations by employee.</p>
                        <form action="<?= base_url('reports/export.php') ?>" method="get">
                            <input type="hidden" name="report" value="allocations">
                            <div class="mb-2">
                                <select name="alloc_status" class="form-select form-select-sm">
                                    <option value="">All</option>
                                    <option value="Active">Active Only</option>
                                    <option value="Returned">Returned Only</option>
                                </select>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" name="format" value="csv" class="btn btn-sm btn-outline-success flex-fill">
                                    <i class="fas fa-file-csv me-1"></i> CSV
                                </button>
                                <button type="submit" name="format" value="pdf" class="btn btn-sm btn-outline-danger flex-fill">
                                    <i class="fas fa-file-pdf me-1"></i> PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- QR Coverage -->
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5><i class="fas fa-qrcode text-dark me-2"></i>QR Coverage</h5>
                        <p class="text-muted small">Items with and without QR code assignments.</p>
                        <form action="<?= base_url('reports/export.php') ?>" method="get">
                            <input type="hidden" name="report" value="qr_coverage">
                            <div class="mb-2">
                                <select name="qr_filter" class="form-select form-select-sm">
                                    <option value="">All Items</option>
                                    <option value="assigned">With QR Code</option>
                                    <option value="missing">Without QR Code</option>
                                </select>
                            </div>
                            <div class="d-flex gap-2">
                                <button type="submit" name="format" value="csv" class="btn btn-sm btn-outline-success flex-fill">
                                    <i class="fas fa-file-csv me-1"></i> CSV
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Classification Summary -->
            <div class="col-md-6 col-lg-4">
                <div class="card border-0 shadow-sm h-100">
                    <div class="card-body">
                        <h5><i class="fas fa-chart-pie text-secondary me-2"></i>Classification Summary</h5>
                        <p class="text-muted small">Counts and values by item class, category, country, and status.</p>
                        <form action="<?= base_url('reports/export.php') ?>" method="get">
                            <input type="hidden" name="report" value="summary">
                            <div class="d-flex gap-2">
                                <button type="submit" name="format" value="csv" class="btn btn-sm btn-outline-success flex-fill">
                                    <i class="fas fa-file-csv me-1"></i> CSV
                                </button>
                                <button type="submit" name="format" value="pdf" class="btn btn-sm btn-outline-danger flex-fill">
                                    <i class="fas fa-file-pdf me-1"></i> PDF
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
</main>

<?php include __DIR__ . '/../includes/footer.php'; ?>
