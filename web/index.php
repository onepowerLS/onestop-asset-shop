<?php
/**
 * Dashboard / Home Page
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/database.php';
require_login();

$page_title = 'Dashboard';

// Get dashboard statistics
try {
    // Total assets
    $totalAssets = $pdo->query("SELECT COUNT(*) as count FROM assets")->fetch()['count'];
    
    // Assets by country
    $assetsByCountry = $pdo->query("
        SELECT c.country_name, c.country_code, COUNT(a.asset_id) as count
        FROM countries c
        LEFT JOIN assets a ON c.country_id = a.country_id
        GROUP BY c.country_id, c.country_name, c.country_code
        ORDER BY c.country_name
    ")->fetchAll();
    
    // Assets by status
    $assetsByStatus = $pdo->query("
        SELECT status, COUNT(*) as count
        FROM assets
        GROUP BY status
        ORDER BY count DESC
    ")->fetchAll();
    
    // Recent transactions
    $recentTransactions = $pdo->query("
        SELECT t.*, a.name as asset_name, a.qr_code_id
        FROM transactions t
        JOIN assets a ON t.asset_id = a.asset_id
        ORDER BY t.transaction_date DESC
        LIMIT 10
    ")->fetchAll();
    
    // Pending requests
    $pendingRequests = $pdo->query("
        SELECT COUNT(*) as count
        FROM requests
        WHERE status IN ('Draft', 'Submitted')
    ")->fetch()['count'];
    
} catch (PDOException $e) {
    error_log("Dashboard query error: " . $e->getMessage());
    $totalAssets = 0;
    $assetsByCountry = [];
    $assetsByStatus = [];
    $recentTransactions = [];
    $pendingRequests = 0;
}

include __DIR__ . '/includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4">
        <div class="d-block mb-4 mb-md-0">
            <h1 class="h2">Dashboard</h1>
            <p class="mb-0">Welcome to OneStop Asset Shop - Consolidated Asset Management</p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="<?php echo base_url('assets/add.php'); ?>" class="btn btn-sm btn-gray-800 d-inline-flex align-items-center">
                <i class="fas fa-plus me-2"></i>
                Add New Asset
            </a>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="row mb-4">
        <div class="col-12 col-sm-6 col-xl-3 mb-4">
            <div class="card border-0 shadow">
                <div class="card-body">
                    <div class="row d-block d-xl-flex align-items-center">
                        <div class="col-12 col-xl-5 text-xl-center border flex-column align-items-center justify-content-center py-3">
                            <div class="icon-shape icon-shape-primary rounded me-4 me-sm-0">
                                <i class="fas fa-box fa-2x"></i>
                            </div>
                        </div>
                        <div class="col-12 col-xl-7 py-3">
                            <div class="d-block">
                                <h2 class="h5 fw-normal text-gray-600 mb-0">Total Assets</h2>
                                <h3 class="fw-extrabold mb-2"><?php echo number_format($totalAssets); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3 mb-4">
            <div class="card border-0 shadow">
                <div class="card-body">
                    <div class="row d-block d-xl-flex align-items-center">
                        <div class="col-12 col-xl-5 text-xl-center border flex-column align-items-center justify-content-center py-3">
                            <div class="icon-shape icon-shape-secondary rounded me-4 me-sm-0">
                                <i class="fas fa-clipboard-list fa-2x"></i>
                            </div>
                        </div>
                        <div class="col-12 col-xl-7 py-3">
                            <div class="d-block">
                                <h2 class="h5 fw-normal text-gray-600 mb-0">Pending Requests</h2>
                                <h3 class="fw-extrabold mb-2"><?php echo number_format($pendingRequests); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3 mb-4">
            <div class="card border-0 shadow">
                <div class="card-body">
                    <div class="row d-block d-xl-flex align-items-center">
                        <div class="col-12 col-xl-5 text-xl-center border flex-column align-items-center justify-content-center py-3">
                            <div class="icon-shape icon-shape-tertiary rounded me-4 me-sm-0">
                                <i class="fas fa-globe fa-2x"></i>
                            </div>
                        </div>
                        <div class="col-12 col-xl-7 py-3">
                            <div class="d-block">
                                <h2 class="h5 fw-normal text-gray-600 mb-0">Countries</h2>
                                <h3 class="fw-extrabold mb-2"><?php echo count($assetsByCountry); ?></h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-sm-6 col-xl-3 mb-4">
            <div class="card border-0 shadow">
                <div class="card-body">
                    <div class="row d-block d-xl-flex align-items-center">
                        <div class="col-12 col-xl-5 text-xl-center border flex-column align-items-center justify-content-center py-3">
                            <div class="icon-shape icon-shape-success rounded me-4 me-sm-0">
                                <i class="fas fa-check-circle fa-2x"></i>
                            </div>
                        </div>
                        <div class="col-12 col-xl-7 py-3">
                            <div class="d-block">
                                <h2 class="h5 fw-normal text-gray-600 mb-0">Available</h2>
                                <h3 class="fw-extrabold mb-2">
                                    <?php 
                                    $available = array_filter($assetsByStatus, fn($s) => $s['status'] === 'Available');
                                    echo number_format($available ? reset($available)['count'] : 0);
                                    ?>
                                </h3>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Assets by Country -->
    <div class="row mb-4">
        <div class="col-12 col-lg-6 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h2 class="fs-5 fw-bold mb-0">Assets by Country</h2>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Country</th>
                                    <th class="text-end">Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assetsByCountry as $country): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($country['country_name']); ?></strong>
                                        <span class="badge bg-gray-200 text-gray-800 ms-2"><?php echo htmlspecialchars($country['country_code']); ?></span>
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-bold"><?php echo number_format($country['count']); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assets by Status -->
        <div class="col-12 col-lg-6 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h2 class="fs-5 fw-bold mb-0">Assets by Status</h2>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Status</th>
                                    <th class="text-end">Count</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assetsByStatus as $status): ?>
                                <tr>
                                    <td>
                                        <span class="badge bg-<?php 
                                            echo match($status['status']) {
                                                'Available' => 'success',
                                                'Allocated' => 'warning',
                                                'CheckedOut' => 'info',
                                                'Missing' => 'danger',
                                                default => 'secondary'
                                            };
                                        ?>">
                                            <?php echo htmlspecialchars($status['status']); ?>
                                        </span>
                                    </td>
                                    <td class="text-end">
                                        <span class="fw-bold"><?php echo number_format($status['count']); ?></span>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Recent Transactions -->
    <div class="row">
        <div class="col-12 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header">
                    <div class="row align-items-center">
                        <div class="col">
                            <h2 class="fs-5 fw-bold mb-0">Recent Transactions</h2>
                        </div>
                        <div class="col text-end">
                            <a href="<?php echo base_url('transactions/index.php'); ?>" class="btn btn-sm btn-primary">View All</a>
                        </div>
                    </div>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Type</th>
                                    <th>Asset</th>
                                    <th>QR Code</th>
                                    <th>Device</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($recentTransactions)): ?>
                                <tr>
                                    <td colspan="5" class="text-center text-gray-500">No recent transactions</td>
                                </tr>
                                <?php else: ?>
                                <?php foreach ($recentTransactions as $txn): ?>
                                <tr>
                                    <td><?php echo date('M d, Y H:i', strtotime($txn['transaction_date'])); ?></td>
                                    <td>
                                        <span class="badge bg-primary"><?php echo htmlspecialchars($txn['transaction_type']); ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($txn['asset_name']); ?></td>
                                    <td><code><?php echo htmlspecialchars($txn['qr_code_id'] ?? 'N/A'); ?></code></td>
                                    <td>
                                        <span class="badge bg-gray-200 text-gray-800">
                                            <?php echo htmlspecialchars($txn['device_type'] ?? 'Desktop'); ?>
                                        </span>
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
    </div>
</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
