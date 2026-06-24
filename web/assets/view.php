<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/inventory_levels.php';
require_login();
am_ensure_country_scope_from_session();

$assetId = $_GET['id'] ?? '';
$qrCode = $_GET['qr'] ?? '';

$countries = am_firestore_get_collection('pr_master_countries', 500);
$categories = am_firestore_get_collection('pr_master_categories', 1000);
$locations = am_get_pr_sites();

$countryById = [];
foreach ($countries as $c) {
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($cid !== '') $countryById[$cid] = $c;
}
$categoryById = [];
foreach ($categories as $c) {
    $cid = (string)($c['category_id'] ?? $c['id'] ?? '');
    if ($cid !== '') $categoryById[$cid] = $c;
}
$locationById = am_build_location_index($locations);

$asset = null;

if ($assetId !== '') {
    $asset = am_firestore_get_document('am_core_assets', $assetId);
} elseif ($qrCode !== '') {
    $allAssets = am_firestore_get_collection('am_core_assets', 2000);
    foreach ($allAssets as $a) {
        if ((string)($a['qr_code_id'] ?? '') !== $qrCode) {
            continue;
        }
        if (!am_asset_passes_country_scope($a, $countries, $locationById)) {
            continue;
        }
        $asset = $a;
        $assetId = (string)($a['id'] ?? '');
        break;
    }
}

if (!$asset) {
    $_SESSION['flash_error'] = 'Item not found.';
    header('Location: ' . base_url('assets/index.php'));
    exit;
}

am_require_asset_visible($asset, $countries);

$page_title = (string)($asset['name'] ?? 'Item Detail');

$allocations = am_firestore_get_collection('am_core_allocations', 2000);
$transactions = am_firestore_get_collection('am_core_transactions', 2000);
$inventoryLevels = am_firestore_get_collection('am_core_inventory_levels', 5000);

$itemAllocations = array_filter($allocations, fn($a) => (string)($a['asset_id'] ?? '') === $assetId);
$itemTransactions = array_filter($transactions, fn($t) => (string)($t['asset_id'] ?? '') === $assetId);
usort($itemTransactions, function ($a, $b) {
    return strtotime((string)($b['transaction_date'] ?? $b['created_at'] ?? '1970-01-01'))
        <=> strtotime((string)($a['transaction_date'] ?? $a['created_at'] ?? '1970-01-01'));
});

$classColors = ['FixedAsset' => 'primary', 'Material' => 'warning', 'Consumable' => 'info', 'Inventory' => 'success'];
$classLabels = ['FixedAsset' => 'Fixed Asset', 'Material' => 'Material', 'Consumable' => 'Consumable', 'Inventory' => 'Inventory'];
$cls = (string)($asset['item_class'] ?? '');
$isStockable = in_array($cls, ['Material', 'Consumable', 'Inventory'], true);

$stockQohAll = 0;
$stockAllocAll = 0;
$stockQohHere = 0;
$stockAllocHere = 0;
$stockRowCountAll = 0;
$stockRowCountHere = 0;
$assetLocRaw = (string)($asset['location_id'] ?? '');
$assetLocCanonical = am_canonical_location_code($assetLocRaw, $locationById);

$itemInventoryRows = am_inventory_rows_for_asset($assetId, $inventoryLevels, $locationById, $asset);
foreach ($itemInventoryRows as $inv) {
    $stockRowCountAll++;
    $qoh = (int)($inv['quantity_on_hand'] ?? 0);
    $alloc = (int)($inv['quantity_allocated'] ?? 0);
    $stockQohAll += $qoh;
    $stockAllocAll += $alloc;

    $invLocCanonical = am_canonical_location_code((string)($inv['location_id'] ?? ''), $locationById);
    if ($assetLocCanonical !== '' && $invLocCanonical === $assetLocCanonical) {
        $stockRowCountHere++;
        $stockQohHere += $qoh;
        $stockAllocHere += $alloc;
    }
}

$useCurrentLocationRows = $isStockable && $stockRowCountHere > 0;
$hasInventoryRows = $stockRowCountAll > 0;
$effectiveQoh = $isStockable
    ? ($useCurrentLocationRows ? $stockQohHere : ($hasInventoryRows ? $stockQohAll : (int)($asset['quantity'] ?? 1)))
    : (int)($asset['quantity'] ?? 1);
$effectiveAlloc = $isStockable
    ? ($useCurrentLocationRows ? $stockAllocHere : $stockAllocAll)
    : 0;
$effectiveAvail = max(0, $effectiveQoh - $effectiveAlloc);

$resolvedCountryId = am_resolve_asset_country_id($asset, $countries);
$country = $countryById[$resolvedCountryId] ?? [];
$category = $categoryById[(string)($asset['category_id'] ?? '')] ?? [];
$location = $locationById[(string)($asset['location_id'] ?? '')] ?? [];

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$flashError = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('assets/index.php'); ?>">Catalog</a></li>
                    <?php if ($cls): ?>
                    <li class="breadcrumb-item"><a href="<?php echo base_url('assets/index.php?item_class=' . urlencode($cls)); ?>"><?php echo htmlspecialchars($classLabels[$cls] ?? $cls); ?></a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($asset['asset_tag'] ?? $assetId); ?></li>
                </ol>
            </nav>
            <h1 class="h2 mt-2">
                <?php echo htmlspecialchars($asset['name'] ?? ''); ?>
                <span class="badge bg-<?php echo $classColors[$cls] ?? 'secondary'; ?> ms-2"><?php echo htmlspecialchars($classLabels[$cls] ?? $cls); ?></span>
            </h1>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <?php if (!am_is_auditor_readonly()): ?>
            <a href="<?php echo base_url('assets/edit.php?id=' . urlencode($assetId)); ?>" class="btn btn-sm btn-gray-800 d-inline-flex align-items-center me-2">
                <i class="fas fa-edit me-2"></i>Edit
            </a>
            <?php endif; ?>
            <?php if (am_is_manager_role()): ?>
            <form method="POST" action="<?php echo base_url('assets/delete.php'); ?>" class="d-inline-flex align-items-center me-2" id="deleteAssetForm">
                <input type="hidden" name="asset_id" value="<?php echo htmlspecialchars($assetId); ?>">
                <input type="hidden" name="delete_reason" id="deleteReasonField" value="">
                <button type="submit" class="btn btn-sm btn-danger d-inline-flex align-items-center">
                    <i class="fas fa-trash me-2"></i>Delete
                </button>
            </form>
            <?php endif; ?>
            <a href="<?php echo base_url('assets/index.php'); ?>" class="btn btn-sm btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to List
            </a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show">
        <?php echo htmlspecialchars($flash); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($flashError): ?>
    <div class="alert alert-danger alert-dismissible fade show">
        <?php echo htmlspecialchars($flashError); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <div class="row">
        <!-- Main Details -->
        <div class="col-12 col-lg-8 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Item Details</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Asset Tag</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($asset['asset_tag'] ?? 'N/A'); ?></p>
                        </div>
                        <?php if (($asset['legacy_tag'] ?? '') !== ''): ?>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Legacy ID</small>
                            <p class="fw-bold mb-0"><code><?php echo htmlspecialchars($asset['legacy_tag']); ?></code></p>
                        </div>
                        <?php endif; ?>
                        <?php if (trim((string)($asset['ugp_part_id'] ?? '')) !== ''): ?>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">UGP part ID</small>
                            <p class="fw-bold mb-0"><code><?php echo htmlspecialchars((string)$asset['ugp_part_id']); ?></code></p>
                        </div>
                        <?php endif; ?>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">QR Code</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($asset['qr_code_id'] ?: 'Not assigned'); ?></p>
                        </div>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Status</small>
                            <p class="mb-0">
                                <span class="badge bg-<?php
                                    echo match($asset['status'] ?? '') {
                                        'Available' => 'success', 'Allocated' => 'warning', 'CheckedOut' => 'info',
                                        'InProject' => 'primary', 'Consumed' => 'secondary', 'Deployed' => 'dark',
                                        'Missing' => 'danger', default => 'secondary'
                                    };
                                ?>"><?php echo htmlspecialchars($asset['status'] ?? 'Unknown'); ?></span>
                            </p>
                        </div>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Category</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($category['category_name'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Country</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars(($country['country_name'] ?? '') . ' (' . ($country['country_code'] ?? '') . ')'); ?></p>
                        </div>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Location</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($location['location_name'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Condition</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($asset['condition_status'] ?? 'N/A'); ?></p>
                        </div>
                        <?php if ($cls !== 'FixedAsset'): ?>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500"><?php echo $isStockable ? 'On Hand' : 'Quantity'; ?></small>
                            <p class="fw-bold mb-0"><?php echo (int)$effectiveQoh; ?> <?php echo htmlspecialchars($asset['unit_of_measure'] ?? 'EA'); ?></p>
                        </div>
                        <?php if ($isStockable): ?>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Allocated</small>
                            <p class="fw-bold mb-0"><?php echo (int)$effectiveAlloc; ?> <?php echo htmlspecialchars($asset['unit_of_measure'] ?? 'EA'); ?></p>
                        </div>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Available</small>
                            <p class="fw-bold mb-0"><?php echo (int)$effectiveAvail; ?> <?php echo htmlspecialchars($asset['unit_of_measure'] ?? 'EA'); ?></p>
                        </div>
                        <?php endif; ?>
                        <?php endif; ?>
                        <?php if ($asset['description'] ?? ''): ?>
                        <div class="col-12">
                            <small class="text-gray-500">Description</small>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($asset['description'])); ?></p>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <?php if ($cls === 'FixedAsset'): ?>
            <div class="card border-0 shadow mt-4">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Asset Specifics</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Serial Number</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($asset['serial_number'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Manufacturer</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($asset['manufacturer'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Model</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($asset['model'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-6 col-md-3">
                            <small class="text-gray-500">Purchase Date</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($asset['purchase_date'] ?? 'N/A'); ?></p>
                        </div>
                        <div class="col-6 col-md-3">
                            <small class="text-gray-500">Purchase Price</small>
                            <p class="fw-bold mb-0"><?php echo $asset['purchase_price'] !== null ? number_format((float)$asset['purchase_price'], 2) : 'N/A'; ?></p>
                        </div>
                        <div class="col-6 col-md-3">
                            <small class="text-gray-500">Salvage Value</small>
                            <p class="fw-bold mb-0"><?php echo $asset['salvage_value'] !== null ? number_format((float)$asset['salvage_value'], 2) : 'N/A'; ?></p>
                        </div>
                        <div class="col-6 col-md-3">
                            <small class="text-gray-500">Warranty Expiry</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars($asset['warranty_expiry'] ?? 'N/A'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php
            $catId = (string)($asset['category_id'] ?? '');
            $isVehicle = (bool)preg_match('/^FA-VEH/', $catId);
            if ($cls === 'FixedAsset' && $isVehicle):
            ?>
            <div class="card border-0 shadow mt-4">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0"><i class="fas fa-car me-2 text-primary"></i>Vehicle Details</h2></div>
                <div class="card-body">
                    <div class="row g-3">
                        <div class="col-6 col-md-2">
                            <small class="text-gray-500">Vehicle Type</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars((string)($asset['vehicle_type'] ?? '—')); ?></p>
                        </div>
                        <div class="col-6 col-md-2">
                            <small class="text-gray-500">Year</small>
                            <p class="fw-bold mb-0"><?php echo !empty($asset['vehicle_year']) ? htmlspecialchars((string)$asset['vehicle_year']) : '—'; ?></p>
                        </div>
                        <div class="col-6 col-md-3">
                            <small class="text-gray-500">Engine Number</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars((string)($asset['engine_number'] ?? '—')); ?></p>
                        </div>
                        <div class="col-6 col-md-2">
                            <small class="text-gray-500">Transmission</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars((string)($asset['transmission_type'] ?? '—')); ?></p>
                        </div>
                        <div class="col-6 col-md-2">
                            <small class="text-gray-500">Fuel Type</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars((string)($asset['fuel_type'] ?? '—')); ?></p>
                        </div>
                        <div class="col-6 col-md-1">
                            <small class="text-gray-500">Drive</small>
                            <p class="fw-bold mb-0"><?php echo htmlspecialchars((string)($asset['drive_type'] ?? '—')); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php
            $hasOdo = !empty($asset['odometer_first_km']) || !empty($asset['odometer_last_km']);
            if ($cls === 'FixedAsset' && $isVehicle && $hasOdo):
            ?>
            <div class="card border-0 shadow mt-4">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0"><i class="fas fa-tachometer-alt me-2 text-success"></i>Odometer</h2></div>
                <div class="card-body">
                    <p class="small text-gray-500 mb-3">Detailed readings are managed in FM. AM stores first/last summary.</p>
                    <div class="row g-3">
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">First Reading</small>
                            <p class="fw-bold mb-0"><?php echo number_format((int)($asset['odometer_first_km'] ?? 0)); ?> km</p>
                            <small class="text-gray-400"><?php echo htmlspecialchars((string)($asset['odometer_first_date'] ?? '—')); ?></small>
                        </div>
                        <div class="col-6 col-md-4">
                            <small class="text-gray-500">Last Reading</small>
                            <p class="fw-bold mb-0"><?php echo number_format((int)($asset['odometer_last_km'] ?? 0)); ?> km</p>
                            <small class="text-gray-400"><?php echo htmlspecialchars((string)($asset['odometer_last_date'] ?? '—')); ?></small>
                        </div>
                        <div class="col-6 col-md-2">
                            <small class="text-gray-500">Total Distance</small>
                            <p class="fw-bold mb-0">
                                <?php
                                $firstKm = (int)($asset['odometer_first_km'] ?? 0);
                                $lastKm = (int)($asset['odometer_last_km'] ?? 0);
                                echo ($lastKm > $firstKm) ? number_format($lastKm - $firstKm) . ' km' : '—';
                                ?>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
            <?php
            $builtFrom = $asset['built_from'] ?? [];
            if ($cls === 'FixedAsset' && is_array($builtFrom) && !empty($builtFrom)):
            ?>
            <div class="card border-0 shadow mt-4">
                <div class="card-header">
                    <h2 class="fs-5 fw-bold mb-0">
                        <i class="fas fa-microchip me-2 text-purple"></i>Assembly Lineage
                    </h2>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead>
                                <tr><th>Material</th><th>Tag</th><th class="text-end">Qty Consumed</th><th>Unit</th></tr>
                            </thead>
                            <tbody>
                                <?php foreach ($builtFrom as $component):
                                    $compId = (string)($component['asset_id'] ?? '');
                                ?>
                                <tr>
                                    <td>
                                        <?php if ($compId !== ''): ?>
                                        <a href="<?php echo base_url('assets/view.php?id=' . urlencode($compId)); ?>">
                                            <?php echo htmlspecialchars($component['name'] ?? 'Unknown'); ?>
                                        </a>
                                        <?php else: ?>
                                            <?php echo htmlspecialchars($component['name'] ?? 'Unknown'); ?>
                                        <?php endif; ?>
                                    </td>
                                    <td><code class="text-muted"><?php echo htmlspecialchars($component['asset_tag'] ?? '—'); ?></code></td>
                                    <td class="text-end"><?php echo (int)($component['quantity'] ?? 0); ?></td>
                                    <td><?php echo htmlspecialchars($component['unit'] ?? 'EA'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <?php if (!empty($asset['assembled_at']) || !empty($asset['assembled_by'])): ?>
                <div class="card-footer">
                    <small class="text-gray-500">
                        Assembled <?php echo htmlspecialchars(substr((string)($asset['assembled_at'] ?? ''), 0, 10)); ?>
                        <?php if (!empty($asset['assembled_by'])): ?>
                            by <?php echo htmlspecialchars($asset['assembled_by']); ?>
                        <?php endif; ?>
                    </small>
                </div>
                <?php endif; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Sidebar -->
        <div class="col-12 col-lg-4 mb-4">
            <?php if ($asset['notes'] ?? ''): ?>
            <div class="card border-0 shadow mb-4">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Notes</h2></div>
                <div class="card-body">
                    <p class="mb-0"><?php echo nl2br(htmlspecialchars($asset['notes'])); ?></p>
                </div>
            </div>
            <?php endif; ?>

            <div class="card border-0 shadow mb-4">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Timestamps</h2></div>
                <div class="card-body">
                    <small class="text-gray-500">Created</small>
                    <p class="fw-bold mb-2"><?php echo htmlspecialchars($asset['created_at'] ?? 'N/A'); ?></p>
                    <small class="text-gray-500">Last Updated</small>
                    <p class="fw-bold mb-0"><?php echo htmlspecialchars($asset['updated_at'] ?? 'N/A'); ?></p>
                </div>
            </div>

            <!-- Active Allocations -->
            <?php
            $activeAllocs = array_filter($itemAllocations, fn($a) => (string)($a['status'] ?? '') === 'Active');
            if (!empty($activeAllocs)):
            ?>
            <div class="card border-0 shadow mb-4">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Active Allocations (<?php echo count($activeAllocs); ?>)</h2></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm mb-0">
                            <thead><tr><th>Employee</th><th>Since</th></tr></thead>
                            <tbody>
                                <?php foreach ($activeAllocs as $alloc): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($alloc['employee_id'] ?? 'N/A'); ?></td>
                                    <td><?php echo htmlspecialchars(substr((string)($alloc['allocation_date'] ?? ''), 0, 10)); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Transaction History -->
    <div class="card border-0 shadow">
        <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Transaction History</h2></div>
        <div class="card-body">
            <?php if (empty($itemTransactions)): ?>
            <p class="text-gray-500 text-center py-3 mb-0">No transactions recorded for this item.</p>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead><tr><th>Date</th><th>Type</th><th>Qty</th><th>From</th><th>To</th><th>Device</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach (array_slice($itemTransactions, 0, 50) as $txn): ?>
                        <tr>
                            <td><?php echo date('M d, Y H:i', strtotime((string)($txn['transaction_date'] ?? $txn['created_at'] ?? ''))); ?></td>
                            <td><span class="badge bg-primary"><?php echo htmlspecialchars($txn['transaction_type'] ?? ''); ?></span></td>
                            <td><?php echo (int)($txn['quantity'] ?? 1); ?></td>
                            <td><?php echo htmlspecialchars(($locationById[(string)($txn['from_location_id'] ?? '')] ?? [])['location_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars(($locationById[(string)($txn['to_location_id'] ?? '')] ?? [])['location_name'] ?? '—'); ?></td>
                            <td><span class="badge bg-gray-200 text-gray-800"><?php echo htmlspecialchars($txn['device_type'] ?? 'Desktop'); ?></span></td>
                            <td><?php echo htmlspecialchars(substr((string)($txn['notes'] ?? ''), 0, 60)); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(function() {
    var form = document.getElementById('deleteAssetForm');
    if (!form) return;
    form.addEventListener('submit', function(e) {
        var ok = confirm('Delete this item from active catalog? A full snapshot will be archived.');
        if (!ok) {
            e.preventDefault();
            return;
        }
        var reason = prompt('Reason for deleting this item (optional):', 'No longer needed');
        if (reason === null) {
            e.preventDefault();
            return;
        }
        var reasonField = document.getElementById('deleteReasonField');
        if (reasonField) {
            reasonField.value = reason.trim();
        }
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
