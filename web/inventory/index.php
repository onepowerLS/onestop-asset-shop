<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

$page_title = 'Stock Levels';

$assets = am_firestore_get_collection('am_core_assets', 2000);
$countries = am_firestore_get_collection('pr_master_countries', 500);
$categories = am_firestore_get_collection('pr_master_categories', 1000);
$locations = am_get_pr_sites();
$inventoryLevels = am_firestore_get_collection('am_core_inventory_levels', 2000);

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
$locationById = [];
foreach ($locations as $l) {
    $lid = (string)($l['location_id'] ?? $l['id'] ?? '');
    if ($lid !== '') $locationById[$lid] = $l;
}
$assetById = [];
foreach ($assets as $a) {
    $aid = (string)($a['asset_id'] ?? $a['id'] ?? '');
    if ($aid !== '') $assetById[$aid] = $a;
}

$classFilter = $_GET['item_class'] ?? '';
$countryFilter = $_GET['country'] ?? '';
$lowStockOnly = isset($_GET['low_stock']);

$stockItems = [];
$reorderAlerts = 0;

if (!empty($inventoryLevels)) {
    foreach ($inventoryLevels as $inv) {
        $aid = (string)($inv['asset_id'] ?? '');
        $asset = $assetById[$aid] ?? [];
        $cls = (string)($asset['item_class'] ?? '');
        $cid = (string)($inv['country_id'] ?? $asset['country_id'] ?? '');

        if ($classFilter && $cls !== $classFilter) continue;
        if ($countryFilter && $cid !== $countryFilter) continue;

        $qoh = (int)($inv['quantity_on_hand'] ?? 0);
        $reorder = $inv['reorder_level'] ?? null;
        $isLow = $reorder !== null && $qoh <= (int)$reorder;
        if ($lowStockOnly && !$isLow) continue;
        if ($isLow) $reorderAlerts++;

        $stockItems[] = [
            'inv' => $inv,
            'asset' => $asset,
            'country' => $countryById[$cid] ?? [],
            'category' => $categoryById[(string)($asset['category_id'] ?? '')] ?? [],
            'location' => $locationById[(string)($inv['location_id'] ?? '')] ?? [],
            'is_low' => $isLow,
        ];
    }
} else {
    $trackable = array_filter($assets, fn($a) => in_array($a['item_class'] ?? '', ['Material', 'Consumable', 'Inventory']));
    foreach ($trackable as $asset) {
        $cls = (string)($asset['item_class'] ?? '');
        $cid = (string)($asset['country_id'] ?? '');
        if ($classFilter && $cls !== $classFilter) continue;
        if ($countryFilter && $cid !== $countryFilter) continue;

        $stockItems[] = [
            'inv' => ['quantity_on_hand' => (int)($asset['quantity'] ?? 0), 'quantity_allocated' => 0, 'reorder_level' => null],
            'asset' => $asset,
            'country' => $countryById[$cid] ?? [],
            'category' => $categoryById[(string)($asset['category_id'] ?? '')] ?? [],
            'location' => $locationById[(string)($asset['location_id'] ?? '')] ?? [],
            'is_low' => false,
        ];
    }
}

$classLabels = ['Material' => 'Materials', 'Consumable' => 'Consumables', 'Inventory' => 'Inventory'];
$classColors = ['Material' => 'warning', 'Consumable' => 'info', 'Inventory' => 'success'];

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4">
        <div>
            <h1 class="h2">Stock Levels</h1>
            <p class="mb-0">Inventory tracking for Materials, Consumables, and Inventory items</p>
        </div>
    </div>

    <?php if ($reorderAlerts > 0): ?>
    <div class="alert alert-warning d-flex align-items-center">
        <i class="fas fa-exclamation-triangle fa-lg me-3"></i>
        <div><strong><?php echo $reorderAlerts; ?> item(s)</strong> at or below reorder level.
            <?php if (!$lowStockOnly): ?>
            <a href="<?php echo base_url('inventory/index.php?low_stock=1'); ?>" class="alert-link">Show low-stock only</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label">Item Class</label>
                    <select class="form-select" name="item_class">
                        <option value="">All Stockable</option>
                        <?php foreach ($classLabels as $k => $v): ?>
                        <option value="<?php echo $k; ?>" <?php echo $classFilter === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Country</label>
                    <select class="form-select" name="country">
                        <option value="">All</option>
                        <?php foreach ($countries as $c):
                            $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
                        ?>
                        <option value="<?php echo htmlspecialchars($cid); ?>" <?php echo $countryFilter === $cid ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['country_name'] ?? ''); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" name="low_stock" id="lowStockCheck" value="1" <?php echo $lowStockOnly ? 'checked' : ''; ?>>
                        <label class="form-check-label" for="lowStockCheck">Low stock only</label>
                    </div>
                </div>
                <div class="col-12 col-md-3 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-filter me-2"></i>Filter</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Stock Table -->
    <div class="card border-0 shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="stockTable">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Class</th>
                            <th>Category</th>
                            <th>Location</th>
                            <th>Country</th>
                            <th class="text-end">On Hand</th>
                            <th class="text-end">Allocated</th>
                            <th class="text-end">Available</th>
                            <th class="text-end">Reorder Lvl</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($stockItems)): ?>
                        <tr><td colspan="9" class="text-center text-gray-500 py-4">No stock data found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($stockItems as $si):
                            $asset = $si['asset'];
                            $inv = $si['inv'];
                            $cls = (string)($asset['item_class'] ?? '');
                            $qoh = (int)($inv['quantity_on_hand'] ?? 0);
                            $alloc = (int)($inv['quantity_allocated'] ?? 0);
                            $avail = $qoh - $alloc;
                            $reorder = $inv['reorder_level'] ?? null;
                        ?>
                        <tr class="<?php echo $si['is_low'] ? 'table-warning' : ''; ?>">
                            <td>
                                <a href="<?php echo base_url('assets/view.php?id=' . urlencode($asset['id'] ?? '')); ?>">
                                    <?php echo htmlspecialchars($asset['name'] ?? ''); ?>
                                </a>
                                <br><small class="text-gray-500"><?php echo htmlspecialchars($asset['asset_tag'] ?? ''); ?></small>
                            </td>
                            <td><span class="badge bg-<?php echo $classColors[$cls] ?? 'secondary'; ?>"><?php echo htmlspecialchars($cls); ?></span></td>
                            <td><?php echo htmlspecialchars($si['category']['category_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($si['location']['location_name'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($si['country']['country_code'] ?? '—'); ?></td>
                            <td class="text-end fw-bold"><?php echo number_format($qoh); ?></td>
                            <td class="text-end"><?php echo number_format($alloc); ?></td>
                            <td class="text-end fw-bold <?php echo $avail <= 0 ? 'text-danger' : ''; ?>"><?php echo number_format($avail); ?></td>
                            <td class="text-end">
                                <?php if ($reorder !== null): ?>
                                <?php echo number_format((int)$reorder); ?>
                                <?php if ($si['is_low']): ?><i class="fas fa-exclamation-triangle text-warning ms-1"></i><?php endif; ?>
                                <?php else: ?>
                                —
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
</div>

<script>
$(document).ready(function() {
    $('#stockTable').DataTable({ pageLength: 25, order: [[0, 'asc']] });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
