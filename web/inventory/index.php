<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/inventory_aggregate.php';
require_once __DIR__ . '/../config/inventory_levels.php';
require_login();
am_ensure_country_scope_from_session();

$page_title = 'Stock Levels';

$assets = am_firestore_get_collection('am_core_assets', 4000);
$countries = am_firestore_get_collection('pr_master_countries', 500);
$categories = am_firestore_get_collection('pr_master_categories', 1000);
$locations = am_get_pr_sites();
$inventoryLevels = am_firestore_get_collection('am_core_inventory_levels', 4000);

$locationById = am_build_location_index($locations);

$countryById = [];
foreach ($countries as $c) {
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($cid !== '') {
        $countryById[$cid] = $c;
    }
}
$categoryById = [];
foreach ($categories as $c) {
    $cid = (string)($c['category_id'] ?? $c['id'] ?? '');
    if ($cid !== '') {
        $categoryById[$cid] = $c;
    }
}
$assetById = [];
foreach ($assets as $a) {
    $aid = (string)($a['asset_id'] ?? $a['id'] ?? '');
    if ($aid !== '') {
        $assetById[$aid] = $a;
    }
}

$inventoryLevels = am_inventory_dedupe_all_levels($inventoryLevels, $locationById, $assetById);

$classFilter = $_GET['item_class'] ?? '';
$countryFilter = $_GET['country'] ?? '';
$lowStockOnly = isset($_GET['low_stock']);
$stockView = isset($_GET['each_tag']) ? 'each_tag' : 'rollup';

$stockItems = [];
$reorderAlerts = 0;
$hasInvByAsset = [];

if (!empty($inventoryLevels)) {
    foreach ($inventoryLevels as $inv) {
        $aid = (string)($inv['asset_id'] ?? '');
        $asset = $assetById[$aid] ?? [];
        if ($asset !== [] && !am_asset_passes_country_scope($asset, $countries, $locationById)) {
            continue;
        }
        if ($aid !== '') {
            $hasInvByAsset[$aid] = true;
        }

        // Normalize inventory location_id so stock rows are grouped/displayed consistently.
        $rawLocId = (string)($inv['location_id'] ?? '');
        $locResolved = $locationById[$rawLocId] ?? [];
        $canonLocId = (string)($locResolved['location_code'] ?? $rawLocId);
        $invNorm = $inv;
        $invNorm['location_id'] = $canonLocId;

        $cls = (string)($asset['item_class'] ?? '');
        $cid = (string)($invNorm['country_id'] ?? '');
        if ($cid === '' && $asset) {
            $cid = am_resolve_asset_country_id($asset, $countries);
        }

        if ($classFilter && $cls !== $classFilter) {
            continue;
        }
        if ($countryFilter && $cid !== $countryFilter) {
            continue;
        }

        $qoh = (int)($invNorm['quantity_on_hand'] ?? 0);
        $reorder = $invNorm['reorder_level'] ?? null;
        $isLow = $reorder !== null && $qoh <= (int)$reorder;
        if ($lowStockOnly && !$isLow) {
            continue;
        }
        if ($isLow) {
            $reorderAlerts++;
        }

        $stockItems[] = [
            'inv' => $invNorm,
            'asset' => $asset,
            'country' => $countryById[$cid] ?? [],
            'category' => $categoryById[(string)($asset['category_id'] ?? '')] ?? [],
            'location' => $locationById[$canonLocId] ?? [],
            'is_low' => $isLow,
            'country_id_resolved' => $cid,
        ];
    }
}

// Backfill stockable assets without inventory rows so Item Detail and Stock Levels remain aligned.
$trackable = array_filter($assets, fn($a) => in_array($a['item_class'] ?? '', ['Material', 'Consumable', 'Inventory'], true));
foreach ($trackable as $asset) {
    $aid = (string)($asset['asset_id'] ?? $asset['id'] ?? '');
    if ($aid !== '' && isset($hasInvByAsset[$aid])) {
        continue;
    }
    if (!am_asset_passes_country_scope($asset, $countries, $locationById)) {
        continue;
    }
    $cls = (string)($asset['item_class'] ?? '');
    $cid = am_resolve_asset_country_id($asset, $countries);
    if ($classFilter && $cls !== $classFilter) {
        continue;
    }
    if ($countryFilter && $cid !== $countryFilter) {
        continue;
    }

    $assetLocRaw = (string)($asset['location_id'] ?? '');
    $assetLocResolved = $locationById[$assetLocRaw] ?? [];
    $assetLocCanonical = (string)($assetLocResolved['location_code'] ?? $assetLocRaw);
    $fallbackInv = [
        'quantity_on_hand' => (int)($asset['quantity'] ?? 0),
        'quantity_allocated' => 0,
        'reorder_level' => null,
        'location_id' => $assetLocCanonical,
        'country_id' => $cid,
    ];

    $stockItems[] = [
        'inv' => $fallbackInv,
        'asset' => $asset,
        'country' => $countryById[$cid] ?? [],
        'category' => $categoryById[(string)($asset['category_id'] ?? '')] ?? [],
        'location' => $locationById[$assetLocCanonical] ?? [],
        'is_low' => false,
        'country_id_resolved' => $cid,
    ];
}

if ($stockView === 'rollup') {
    $aggregated = am_inventory_aggregate_stock_items($stockItems);
    $reorderAlerts = 0;
    foreach ($aggregated as $block) {
        if (($block['type'] ?? '') === 'group' && !empty($block['is_low'])) {
            $reorderAlerts++;
        }
        if (($block['type'] ?? '') === 'single') {
            foreach ($block['rows'] as $r) {
                if (!empty($r['is_low'])) {
                    $reorderAlerts++;
                }
            }
        }
    }
} else {
    $aggregated = null;
}

$classLabels = ['Material' => 'Materials', 'Consumable' => 'Consumables', 'Inventory' => 'Inventory'];
$classColors = ['Material' => 'warning', 'Consumable' => 'info', 'Inventory' => 'success'];

$rollupQuery = array_filter($_GET, fn($v) => $v !== '' && $v !== null && $v !== []);
unset($rollupQuery['each_tag']);
$rollupUrl = base_url('inventory/index.php' . ($rollupQuery ? '?' . http_build_query($rollupQuery) : ''));
$eachTagQuery = array_filter($_GET, fn($v) => $v !== '' && $v !== null && $v !== []);
$eachTagQuery['each_tag'] = '1';
$eachTagUrl = base_url('inventory/index.php?' . http_build_query($eachTagQuery));

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4" data-tutorial="tutorial-inventory-header">
        <div>
            <h1 class="h2">Stock Levels</h1>
            <p class="mb-0">Materials, consumables, and inventory — <strong>rollup</strong> merges same item at the same location (e.g. bulk washers, tagged ready boards) into one line with total qty and a UID summary.</p>
        </div>
        <div class="btn-group" role="group">
            <a href="<?php echo htmlspecialchars($rollupUrl); ?>" class="btn btn-sm <?php echo $stockView === 'rollup' ? 'btn-primary' : 'btn-outline-primary'; ?>">Rollup</a>
            <a href="<?php echo htmlspecialchars($eachTagUrl); ?>" class="btn btn-sm <?php echo $stockView === 'each_tag' ? 'btn-primary' : 'btn-outline-primary'; ?>">Each tag</a>
        </div>
    </div>

    <?php if ($reorderAlerts > 0): ?>
    <div class="alert alert-warning d-flex align-items-center">
        <i class="fas fa-exclamation-triangle fa-lg me-3"></i>
        <div><strong><?php echo (int)$reorderAlerts; ?> item(s)</strong> at or below reorder level.
            <?php if (!$lowStockOnly): ?>
            <a href="<?php echo htmlspecialchars(base_url('inventory/index.php?' . http_build_query(array_merge($_GET, ['low_stock' => '1'])))); ?>" class="alert-link">Show low-stock only</a>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <?php if ($stockView === 'each_tag'): ?>
                <input type="hidden" name="each_tag" value="1">
                <?php endif; ?>
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
                            <?php if ($stockView === 'rollup'): ?>
                            <th class="text-end">Lines</th>
                            <?php endif; ?>
                            <th>Class</th>
                            <th>Category</th>
                            <th>Location</th>
                            <th>Country</th>
                            <?php if ($stockView === 'rollup'): ?>
                            <th>UIDs / tags</th>
                            <?php endif; ?>
                            <th class="text-end">On Hand</th>
                            <th class="text-end">Allocated</th>
                            <th class="text-end">Available</th>
                            <th class="text-end">Reorder Lvl</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($stockView === 'each_tag'): ?>
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
                                $aid = (string)($asset['id'] ?? $asset['asset_id'] ?? '');
                            ?>
                            <tr class="<?php echo $si['is_low'] ? 'table-warning' : ''; ?>">
                                <td>
                                    <?php if ($aid !== ''): ?>
                                    <a href="<?php echo base_url('assets/view.php?id=' . urlencode($aid)); ?>">
                                        <?php echo htmlspecialchars($asset['name'] ?? ''); ?>
                                    </a>
                                    <?php else: ?>
                                    <?php echo htmlspecialchars($asset['name'] ?? '—'); ?>
                                    <?php endif; ?>
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
                        <?php else: ?>
                            <?php if (empty($aggregated)): ?>
                            <tr><td colspan="11" class="text-center text-gray-500 py-4">No stock data found.</td></tr>
                            <?php else: ?>
                            <?php foreach ($aggregated as $block):
                                if (($block['type'] ?? '') === 'single'):
                                    $si = $block['rows'][0];
                                    $asset = $si['asset'];
                                    $inv = $si['inv'];
                                    $cls = (string)($asset['item_class'] ?? '');
                                    $qoh = (int)($inv['quantity_on_hand'] ?? 0);
                                    $alloc = (int)($inv['quantity_allocated'] ?? 0);
                                    $avail = $qoh - $alloc;
                                    $reorder = $inv['reorder_level'] ?? null;
                                    $aid = (string)($asset['id'] ?? $asset['asset_id'] ?? '');
                            ?>
                            <tr class="<?php echo $si['is_low'] ? 'table-warning' : ''; ?>">
                                <td>
                                    <?php if ($aid !== ''): ?>
                                    <a href="<?php echo base_url('assets/view.php?id=' . urlencode($aid)); ?>">
                                        <?php echo htmlspecialchars($asset['name'] ?? ''); ?>
                                    </a>
                                    <?php else: ?>
                                    <?php echo htmlspecialchars($asset['name'] ?? '—'); ?>
                                    <?php endif; ?>
                                    <?php if ($asset !== [] && am_inventory_aggregate_eligible($asset)): ?>
                                    <span class="badge bg-secondary ms-1">1 line</span>
                                    <?php endif; ?>
                                    <br><small class="text-gray-500"><?php echo htmlspecialchars($asset['asset_tag'] ?? ''); ?></small>
                                </td>
                                <td class="text-end">1</td>
                                <td><span class="badge bg-<?php echo $classColors[$cls] ?? 'secondary'; ?>"><?php echo htmlspecialchars($cls); ?></span></td>
                                <td><?php echo htmlspecialchars($si['category']['category_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($si['location']['location_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($si['country']['country_code'] ?? '—'); ?></td>
                                <td><small class="text-muted"><?php echo htmlspecialchars(am_inventory_format_uid_range([$asset])); ?></small></td>
                                <td class="text-end fw-bold"><?php echo number_format($qoh); ?></td>
                                <td class="text-end"><?php echo number_format($alloc); ?></td>
                                <td class="text-end fw-bold <?php echo $avail <= 0 ? 'text-danger' : ''; ?>"><?php echo number_format($avail); ?></td>
                                <td class="text-end">
                                    <?php if ($reorder !== null): ?>
                                    <?php echo number_format((int)$reorder); ?>
                                    <?php if ($si['is_low']): ?><i class="fas fa-exclamation-triangle text-warning ms-1"></i><?php endif; ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                            <?php else: ?>
                            <?php
                                $qoh = (int)($block['qoh'] ?? 0);
                                $alloc = (int)($block['alloc'] ?? 0);
                                $avail = $qoh - $alloc;
                                $reorder = $block['reorder'] ?? null;
                                $rep = (string)($block['representative_id'] ?? '');
                                $cls = (string)($block['cls'] ?? '');
                            ?>
                            <tr class="<?php echo !empty($block['is_low']) ? 'table-warning' : ''; ?>">
                                <td>
                                    <?php if ($rep !== ''): ?>
                                    <a href="<?php echo base_url('assets/view.php?id=' . urlencode($rep)); ?>">
                                        <strong><?php echo htmlspecialchars($block['name'] ?? ''); ?></strong>
                                    </a>
                                    <?php else: ?>
                                    <strong><?php echo htmlspecialchars($block['name'] ?? ''); ?></strong>
                                    <?php endif; ?>
                                    <span class="badge bg-primary ms-1"><?php echo (int)($block['line_count'] ?? 0); ?> lines</span>
                                </td>
                                <td class="text-end"><?php echo (int)($block['line_count'] ?? 0); ?></td>
                                <td><span class="badge bg-<?php echo $classColors[$cls] ?? 'secondary'; ?>"><?php echo htmlspecialchars($cls); ?></span></td>
                                <td><?php echo htmlspecialchars($block['category']['category_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($block['location']['location_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($block['country']['country_code'] ?? '—'); ?></td>
                                <td><small class="text-muted" title="<?php echo htmlspecialchars($block['uid_summary'] ?? ''); ?>"><?php echo htmlspecialchars($block['uid_summary'] ?? '—'); ?></small></td>
                                <td class="text-end fw-bold"><?php echo number_format($qoh); ?></td>
                                <td class="text-end"><?php echo number_format($alloc); ?></td>
                                <td class="text-end fw-bold <?php echo $avail <= 0 ? 'text-danger' : ''; ?>"><?php echo number_format($avail); ?></td>
                                <td class="text-end">
                                    <?php if ($reorder !== null): ?>
                                    <?php echo number_format((int)$reorder); ?>
                                    <?php if (!empty($block['is_low'])): ?><i class="fas fa-exclamation-triangle text-warning ms-1"></i><?php endif; ?>
                                    <?php else: ?>—<?php endif; ?>
                                </td>
                            </tr>
                            <?php endif; ?>
                            <?php endforeach; ?>
                            <?php endif; ?>
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
