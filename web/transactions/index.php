<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

$page_title = 'Transaction History';

$transactions = am_firestore_get_collection('am_core_transactions', 2000);
$assets = am_firestore_get_collection('am_core_assets', 2000);
$locations = am_get_pr_sites();

$assetById = [];
foreach ($assets as $a) {
    $aid = (string)($a['asset_id'] ?? $a['id'] ?? '');
    if ($aid !== '') $assetById[$aid] = $a;
}
$locationById = [];
foreach ($locations as $l) {
    $lid = (string)($l['location_id'] ?? $l['id'] ?? '');
    if ($lid !== '') $locationById[$lid] = $l;
}

$typeFilter = $_GET['type'] ?? '';
$searchTerm = strtolower(trim($_GET['search'] ?? ''));

$filtered = [];
foreach ($transactions as $txn) {
    if ($typeFilter !== '' && (string)($txn['transaction_type'] ?? '') !== $typeFilter) continue;

    if ($searchTerm !== '') {
        $aid = (string)($txn['asset_id'] ?? '');
        $asset = $assetById[$aid] ?? [];
        $blob = strtolower(implode(' ', [
            $txn['transaction_type'] ?? '',
            $asset['name'] ?? '',
            $asset['asset_tag'] ?? '',
            $txn['notes'] ?? '',
            $txn['qr_code_scanned'] ?? '',
        ]));
        if (!str_contains($blob, $searchTerm)) continue;
    }

    $filtered[] = $txn;
}

usort($filtered, function ($a, $b) {
    $ad = strtotime((string)($a['transaction_date'] ?? $a['created_at'] ?? '1970-01-01'));
    $bd = strtotime((string)($b['transaction_date'] ?? $b['created_at'] ?? '1970-01-01'));
    return $bd <=> $ad;
});

$txnTypes = ['CheckOut', 'CheckIn', 'StockIngestion', 'StockTake', 'Transfer', 'Allocation', 'Return', 'WriteOff', 'QRScan', 'Consume', 'Deploy'];

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center py-4">
        <div>
            <h1 class="h2">Transaction History</h1>
            <p class="mb-0"><?php echo count($filtered); ?> transactions</p>
        </div>
    </div>

    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-12 col-md-4">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" placeholder="Asset name, tag, notes...">
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Transaction Type</label>
                    <select class="form-select" name="type">
                        <option value="">All Types</option>
                        <?php foreach ($txnTypes as $t): ?>
                        <option value="<?php echo $t; ?>" <?php echo $typeFilter === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="txnTable">
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Item</th>
                            <th>Qty</th>
                            <th>From</th>
                            <th>To</th>
                            <th>Device</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($filtered)): ?>
                        <tr><td colspan="8" class="text-center text-gray-500 py-4">No transactions found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($filtered as $txn):
                            $aid = (string)($txn['asset_id'] ?? '');
                            $asset = $assetById[$aid] ?? [];
                            $typeColors = [
                                'CheckOut' => 'info', 'CheckIn' => 'success', 'StockIngestion' => 'primary',
                                'Transfer' => 'warning', 'WriteOff' => 'danger', 'Consume' => 'secondary',
                                'Deploy' => 'dark', 'Allocation' => 'warning', 'Return' => 'success',
                            ];
                            $ttype = (string)($txn['transaction_type'] ?? '');
                        ?>
                        <tr>
                            <td><?php echo date('M d, Y H:i', strtotime((string)($txn['transaction_date'] ?? $txn['created_at'] ?? ''))); ?></td>
                            <td><span class="badge bg-<?php echo $typeColors[$ttype] ?? 'primary'; ?>"><?php echo htmlspecialchars($ttype); ?></span></td>
                            <td>
                                <a href="<?php echo base_url('assets/view.php?id=' . urlencode($aid)); ?>">
                                    <?php echo htmlspecialchars($asset['name'] ?? $txn['asset_name'] ?? 'Unknown'); ?>
                                </a>
                            </td>
                            <td><?php echo (int)($txn['quantity'] ?? 1); ?></td>
                            <td><?php echo htmlspecialchars(($locationById[(string)($txn['from_location_id'] ?? '')] ?? [])['location_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(($locationById[(string)($txn['to_location_id'] ?? '')] ?? [])['location_name'] ?? '—'); ?></td>
                            <td><span class="badge bg-gray-200 text-gray-800"><?php echo htmlspecialchars($txn['device_type'] ?? 'Desktop'); ?></span></td>
                            <td><?php echo htmlspecialchars(substr((string)($txn['notes'] ?? ''), 0, 80)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#txnTable').DataTable({ pageLength: 25, order: [[0, 'desc']] });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
