<?php
/**
 * Dashboard / Home Page
 */
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/firestore.php';
require_login();

$page_title = 'Dashboard';

// Firestore-backed dashboard statistics
$assets = am_firestore_get_collection('am_core_assets', 1000);
$countries = am_firestore_get_collection('pr_master_countries', 500);
$transactions = am_firestore_get_collection('am_core_transactions', 1000);
$requests = am_firestore_get_collection('pr_master_requests', 1000);

$totalAssets = count($assets);

// Country counts
$countryMap = [];
foreach ($countries as $country) {
    $countryId = (string)($country['country_id'] ?? $country['id'] ?? '');
    $countryMap[$countryId] = [
        'country_name' => (string)($country['country_name'] ?? 'Unknown'),
        'country_code' => (string)($country['country_code'] ?? 'N/A'),
        'count' => 0,
    ];
}
foreach ($assets as $asset) {
    $countryId = (string)($asset['country_id'] ?? '');
    if ($countryId === '') {
        continue;
    }
    if (!isset($countryMap[$countryId])) {
        $countryMap[$countryId] = [
            'country_name' => 'Unknown',
            'country_code' => 'N/A',
            'count' => 0,
        ];
    }
    $countryMap[$countryId]['count']++;
}
$assetsByCountry = array_values($countryMap);
usort($assetsByCountry, fn($a, $b) => strcmp((string)$a['country_name'], (string)$b['country_name']));

// Status counts
$statusCounts = [];
foreach ($assets as $asset) {
    $status = (string)($asset['status'] ?? 'Unknown');
    $statusCounts[$status] = ($statusCounts[$status] ?? 0) + 1;
}
$assetsByStatus = [];
foreach ($statusCounts as $status => $count) {
    $assetsByStatus[] = ['status' => $status, 'count' => $count];
}
usort($assetsByStatus, fn($a, $b) => (int)$b['count'] <=> (int)$a['count']);

// Recent transactions (joined with asset details)
$assetById = [];
foreach ($assets as $asset) {
    $assetId = (string)($asset['asset_id'] ?? $asset['id'] ?? '');
    if ($assetId !== '') {
        $assetById[$assetId] = $asset;
    }
}
usort($transactions, function ($a, $b) {
    $ad = strtotime((string)($a['transaction_date'] ?? $a['created_at'] ?? '1970-01-01'));
    $bd = strtotime((string)($b['transaction_date'] ?? $b['created_at'] ?? '1970-01-01'));
    return $bd <=> $ad;
});
$recentTransactions = [];
foreach (array_slice($transactions, 0, 10) as $txn) {
    $assetId = (string)($txn['asset_id'] ?? '');
    $asset = $assetById[$assetId] ?? [];
    $recentTransactions[] = [
        'transaction_date' => (string)($txn['transaction_date'] ?? $txn['created_at'] ?? date('c')),
        'transaction_type' => (string)($txn['transaction_type'] ?? 'Unknown'),
        'asset_name' => (string)($asset['name'] ?? $txn['asset_name'] ?? 'Unknown asset'),
        'qr_code_id' => (string)($asset['qr_code_id'] ?? $txn['qr_code_id'] ?? ''),
        'device_type' => (string)($txn['device_type'] ?? 'Desktop'),
    ];
}

// Pending requests
$pendingRequests = 0;
foreach ($requests as $req) {
    $status = (string)($req['status'] ?? '');
    if ($status === 'Draft' || $status === 'Submitted') {
        $pendingRequests++;
    }
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
