<?php
/**
 * Assets Listing Page
 * 
 * Displays all assets with filtering, search, and QR code integration
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/locale.php';
require_once __DIR__ . '/../config/inventory_aggregate.php';
require_login();
am_ensure_country_scope_from_session();

$page_title = 'Assets';

// Get filter parameters
$countryFilter = $_GET['country'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$itemClassFilter = $_GET['item_class'] ?? '';
$searchTerm = $_GET['search'] ?? '';
$catalogView = (isset($_GET['catalog_view']) && $_GET['catalog_view'] === 'grouped') ? 'grouped' : 'flat';
$canCatalogGroup = ($itemClassFilter === '' || in_array($itemClassFilter, am_inventory_stockable_classes(), true));

$itemClassLabels = [
    'FixedAsset'  => 'Fixed Assets',
    'Material'    => 'Materials',
    'Consumable'  => 'Consumables',
    'Inventory'   => 'Inventory',
];
$page_title = $itemClassFilter && isset($itemClassLabels[$itemClassFilter])
    ? $itemClassLabels[$itemClassFilter]
    : 'All Items';

// Firestore collections (split sources of truth)
$assetsRaw = am_firestore_get_collection('am_core_assets', 10000);
$countries = am_firestore_get_collection('pr_master_countries', 500);
$categories = am_firestore_get_collection('pr_master_categories', 1000);
$locations = am_get_pr_sites();
$allocations = am_firestore_get_collection('am_core_allocations', 2000);

$countriesPick = am_countries_for_user_select(array_values(array_filter($countries, fn($c) => ((int)($c['active'] ?? 1)) !== 0)));
if ($countryFilter !== '' && !am_user_may_access_country_id($countryFilter, $countries)) {
    $countryFilter = '';
}

// Index lookups for joins
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
$locationById = [];
foreach ($locations as $l) {
    $lid = (string)($l['location_id'] ?? $l['id'] ?? '');
    if ($lid !== '') {
        $locationById[$lid] = $l;
    }
}
$allocationCounts = [];
foreach ($allocations as $al) {
    $aid = (string)($al['asset_id'] ?? '');
    if ($aid === '') {
        continue;
    }
    if ((string)($al['status'] ?? '') === 'Active') {
        $allocationCounts[$aid] = ($allocationCounts[$aid] ?? 0) + 1;
    }
}

// Join and filter in memory
$assets = [];
$needleRaw = trim($searchTerm);
$needle = $needleRaw === '' ? '' : (function_exists('mb_strtolower') ? mb_strtolower($needleRaw, 'UTF-8') : strtolower($needleRaw));
foreach ($assetsRaw as $asset) {
    if (!am_asset_passes_country_scope($asset, $countries, $locationById)) {
        continue;
    }
    $rawCountryCode = strtoupper(trim((string)($asset['country_code'] ?? '')));
    $countryId = am_resolve_asset_country_id($asset, $countries);
    $categoryId = (string)($asset['category_id'] ?? '');
    $locationId = (string)($asset['location_id'] ?? '');
    $assetId = (string)($asset['asset_id'] ?? $asset['id'] ?? '');
    $status = (string)($asset['status'] ?? '');

    $itemClass = (string)($asset['item_class'] ?? '');

    if ($itemClassFilter !== '' && $itemClassFilter !== $itemClass) {
        continue;
    }
    if ($countryFilter !== '' && $countryFilter !== $countryId) {
        continue;
    }
    if ($statusFilter !== '' && $statusFilter !== $status) {
        continue;
    }
    if ($categoryFilter !== '' && $categoryFilter !== $categoryId) {
        continue;
    }
    if ($needle !== '') {
        $cat = $categoryById[$categoryId] ?? [];
        $loc = $locationById[$locationId] ?? [];
        $blobParts = [
            (string)($asset['name'] ?? ''),
            (string)($asset['description'] ?? ''),
            (string)($asset['serial_number'] ?? ''),
            (string)($asset['qr_code_id'] ?? ''),
            (string)($asset['asset_tag'] ?? ''),
            (string)($asset['legacy_tag'] ?? ''),
            (string)($asset['manufacturer'] ?? ''),
            (string)($asset['model'] ?? ''),
            (string)($asset['notes'] ?? ''),
            (string)($asset['ugp_part_id'] ?? ''),
            (string)($asset['vehicle_type'] ?? ''),
            (string)($asset['engine_number'] ?? ''),
            (string)($asset['fuel_type'] ?? ''),
            (string)($cat['category_name'] ?? ''),
            (string)($loc['location_name'] ?? ''),
            (string)($loc['location_code'] ?? ''),
        ];
        $searchBlob = implode(' ', $blobParts);
        $searchBlob = function_exists('mb_strtolower') ? mb_strtolower($searchBlob, 'UTF-8') : strtolower($searchBlob);
        if (!str_contains($searchBlob, $needle)) {
            continue;
        }
    }

    $country = $countryById[$countryId] ?? [];
    $category = $categoryById[$categoryId] ?? [];
    $location = $locationById[$locationId] ?? [];

    $asset['asset_id'] = $assetId;
    $asset['country_name'] = (string)($country['country_name'] ?? '');
    $asset['country_code'] = (string)($country['country_code'] ?? '');
    if ($asset['country_code'] === '' && $asset['country_name'] === '' && $rawCountryCode !== '') {
        $asset['country_code'] = $rawCountryCode;
    }
    $asset['category_name'] = (string)($category['category_name'] ?? '');
    $asset['item_class'] = $itemClass;
    $asset['category_type'] = (string)($category['category_type'] ?? '');
    $asset['location_name'] = (string)($location['location_name'] ?? '');
    $asset['location_code'] = (string)($location['location_code'] ?? '');
    $asset['allocation_count'] = (int)($allocationCounts[$assetId] ?? 0);

    $assets[] = $asset;
}

usort($assets, function ($a, $b) {
    return strcmp((string)($b['id'] ?? ''), (string)($a['id'] ?? ''));
});

$catalogGrouped = null;
if ($catalogView === 'grouped' && $canCatalogGroup) {
    $catalogGrouped = am_inventory_aggregate_catalog_rows($assets, $countries);
}

// Filter options (master list for joins; pick list is $countriesPick)
$countries = array_values(array_filter($countries, fn($c) => ((int)($c['active'] ?? 1)) !== 0));
$categories = array_values(array_filter($categories, fn($c) => ((int)($c['active'] ?? 1)) !== 0));
$statuses = ['Available', 'Allocated', 'CheckedOut', 'InProject', 'Consumed', 'Deployed', 'Missing', 'WrittenOff', 'Retired'];
$itemClasses = ['FixedAsset' => 'Fixed Assets', 'Material' => 'Materials', 'Consumable' => 'Consumables', 'Inventory' => 'Inventory'];

$catalogFlatQs = array_filter($_GET, fn($v) => $v !== '' && $v !== null && $v !== []);
unset($catalogFlatQs['catalog_view']);
$catalogFlatUrl = base_url('assets/index.php' . ($catalogFlatQs ? '?' . http_build_query($catalogFlatQs) : ''));
$catalogGroupedQs = array_filter($_GET, fn($v) => $v !== '' && $v !== null && $v !== []);
$catalogGroupedQs['catalog_view'] = 'grouped';
$catalogGroupedUrl = base_url('assets/index.php?' . http_build_query($catalogGroupedQs));

$am_firestore_session_token_missing = is_logged_in() && trim((string)am_firestore_id_token()) === '';

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <?php if (!empty($am_firestore_session_token_missing)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars(am_ui('firestore_token_notice')); ?></div>
    <?php endif; ?>
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4" data-tutorial="tutorial-assets-header">
        <div class="d-block mb-4 mb-md-0">
            <h1 class="h2"><?php echo htmlspecialchars($page_title); ?></h1>
            <p class="mb-0"><?php echo htmlspecialchars(am_ui('assets_blurb', 'Manage and track items in your permitted countries.')); ?></p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <div class="btn-group me-2 mb-2" role="group" aria-label="Catalog view">
                <a href="<?php echo htmlspecialchars($catalogFlatUrl); ?>" class="btn btn-sm <?php echo $catalogView === 'flat' ? 'btn-primary' : 'btn-outline-primary'; ?>">Each record</a>
                <a href="<?php echo htmlspecialchars($catalogGroupedUrl); ?>" class="btn btn-sm <?php echo $catalogView === 'grouped' ? 'btn-primary' : 'btn-outline-primary'; ?>" title="Merge stockable lines by part + location">Grouped</a>
            </div>
            <?php if (!am_is_auditor_readonly()): ?>
            <a href="<?php echo base_url('assets/add.php' . ($itemClassFilter ? '?item_class=' . urlencode($itemClassFilter) : '')); ?>" class="btn btn-sm btn-gray-800 d-inline-flex align-items-center me-2" data-tutorial="tutorial-assets-add">
                <i class="fas fa-plus me-2"></i>
                Add New Item
            </a>
            <button class="btn btn-sm btn-primary d-inline-flex align-items-center" onclick="labelPrinter.generateLabel(prompt('Enter Asset ID:'))">
                <i class="fas fa-print me-2"></i>
                Print QR Label
            </button>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($catalogView === 'grouped' && !$canCatalogGroup): ?>
    <div class="alert alert-info py-2">Grouped catalog applies to <strong>Materials, Consumables, and Inventory</strong>. With a Fixed Asset filter, each record is listed separately.</div>
    <?php endif; ?>

    <!-- Filters -->
    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <?php if ($catalogView === 'grouped'): ?>
                <input type="hidden" name="catalog_view" value="grouped">
                <?php endif; ?>
                <div class="col-12 col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Name, manufacturer, model, notes, serial, tag, QR…">
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Classification</label>
                    <select class="form-select" name="item_class">
                        <option value="">All Classes</option>
                        <?php foreach ($itemClasses as $classKey => $classLabel): ?>
                        <option value="<?php echo $classKey; ?>" <?php echo $itemClassFilter === $classKey ? 'selected' : ''; ?>>
                            <?php echo $classLabel; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Category</label>
                    <select class="form-select" name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category):
                            $catId = (string)($category['category_id'] ?? $category['id'] ?? '');
                        ?>
                        <option value="<?php echo $catId; ?>" <?php echo $categoryFilter == $catId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['category_name'] ?? ''); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-1">
                    <label class="form-label">Country</label>
                    <select class="form-select" name="country">
                        <option value="">All</option>
                        <?php foreach ($countriesPick as $country):
                            $cntId = (string)($country['country_id'] ?? $country['id'] ?? '');
                        ?>
                        <option value="<?php echo $cntId; ?>" <?php echo $countryFilter == $cntId ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($country['country_code'] ?? $country['country_name'] ?? ''); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6 col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All</option>
                        <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                            <?php echo $status; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter me-2"></i>Filter
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Assets Table -->
    <div class="card border-0 shadow" data-tutorial="tutorial-assets-table">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="assetsTable">
                    <?php if ($catalogGrouped !== null): ?>
                    <thead>
                        <tr>
                            <th>Name</th>
                            <th class="text-end">Records</th>
                            <th class="text-end">Qty</th>
                            <th>UIDs / tags</th>
                            <th>Class</th>
                            <th>Category</th>
                            <th>Country</th>
                            <th>Location</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($catalogGrouped)): ?>
                        <tr>
                            <td colspan="9" class="text-center text-gray-500 py-4">
                                No items found.<?php if (!am_is_auditor_readonly()): ?> <a href="<?php echo base_url('assets/add.php'); ?>">Add your first item</a><?php endif; ?>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php
                        $gClassColors = ['FixedAsset' => 'primary', 'Material' => 'warning', 'Consumable' => 'info', 'Inventory' => 'success'];
                        $gClassLabels = ['FixedAsset' => 'Fixed Asset', 'Material' => 'Material', 'Consumable' => 'Consumable', 'Inventory' => 'Inventory'];
                        foreach ($catalogGrouped as $g):
                            $rep = (string)($g['representative_id'] ?? '');
                            $cls = (string)($g['cls'] ?? '');
                        ?>
                        <tr>
                            <td>
                                <?php if ($rep !== ''): ?>
                                <a href="<?php echo base_url('assets/view.php?id=' . urlencode($rep)); ?>" class="fw-semibold"><?php echo htmlspecialchars($g['name'] ?? ''); ?></a>
                                <?php else: ?>
                                <?php echo htmlspecialchars($g['name'] ?? ''); ?>
                                <?php endif; ?>
                                <?php if ((int)($g['line_count'] ?? 0) > 1): ?>
                                <span class="badge bg-secondary ms-1"><?php echo (int)$g['line_count']; ?> records</span>
                                <?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo (int)($g['line_count'] ?? 0); ?></td>
                            <td class="text-end fw-bold"><?php echo number_format((int)($g['qty_sum'] ?? 0)); ?></td>
                            <td><small class="text-muted"><?php echo htmlspecialchars($g['uid_summary'] ?? '—'); ?></small></td>
                            <td><span class="badge bg-<?php echo $gClassColors[$cls] ?? 'secondary'; ?>"><?php echo htmlspecialchars($gClassLabels[$cls] ?? $cls); ?></span></td>
                            <td>
                                <?php if (trim((string)($g['category_name'] ?? '')) !== ''): ?>
                                <span class="badge bg-gray-200 text-gray-800"><?php echo htmlspecialchars($g['category_name']); ?></span>
                                <?php else: ?>
                                <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td><span class="badge bg-info text-white"><?php echo htmlspecialchars($g['country_code'] ?: '—'); ?></span></td>
                            <td>
                                <?php if (trim((string)($g['location_name'] ?? '')) !== ''): ?>
                                <?php echo htmlspecialchars($g['location_name']); ?>
                                <?php else: ?>
                                <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($rep !== ''): ?>
                                <a href="<?php echo base_url('assets/view.php?id=' . urlencode($rep)); ?>" class="btn btn-sm btn-outline-primary" title="View"><i class="fas fa-eye"></i></a>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php else: ?>
                    <thead>
                        <tr>
                            <th>QR Code</th>
                            <th>Asset Tag</th>
                            <th>Legacy ID</th>
                            <th>Name</th>
                            <th>Class</th>
                            <th>Category</th>
                            <th>Country</th>
                            <th>Location</th>
                            <th>Status</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($assets)): ?>
                        <tr>
                            <td colspan="10" class="text-center text-gray-500 py-4">
                                No items found.<?php if (!am_is_auditor_readonly()): ?> <a href="<?php echo base_url('assets/add.php'); ?>">Add your first item</a><?php endif; ?>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($assets as $asset): ?>
                        <tr>
                            <td>
                                <?php if ($asset['qr_code_id']): ?>
                                    <code class="text-primary"><?php echo htmlspecialchars($asset['qr_code_id']); ?></code>
                                <?php elseif (!am_is_auditor_readonly()): ?>
                                    <button class="btn btn-sm btn-outline-primary" onclick="generateQR(<?php echo $asset['asset_id']; ?>)">
                                        <i class="fas fa-qrcode me-1"></i>Generate
                                    </button>
                                <?php else: ?>
                                    <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($asset['asset_tag'] ?? 'N/A'); ?></strong>
                            </td>
                            <td>
                                <?php if (($asset['legacy_tag'] ?? '') !== ''): ?>
                                    <code class="text-muted"><?php echo htmlspecialchars($asset['legacy_tag']); ?></code>
                                <?php else: ?>
                                    <span class="text-gray-400">&mdash;</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="<?php echo base_url('assets/view.php?id=' . $asset['asset_id']); ?>" class="text-primary">
                                    <?php echo htmlspecialchars($asset['name']); ?>
                                </a>
                                <?php if ($asset['serial_number']): ?>
                                    <br><small class="text-gray-500">SN: <?php echo htmlspecialchars($asset['serial_number']); ?></small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php
                                $classColors = ['FixedAsset' => 'primary', 'Material' => 'warning', 'Consumable' => 'info', 'Inventory' => 'success'];
                                $classLabels = ['FixedAsset' => 'Fixed Asset', 'Material' => 'Material', 'Consumable' => 'Consumable', 'Inventory' => 'Inventory'];
                                $cls = $asset['item_class'] ?? '';
                                ?>
                                <span class="badge bg-<?php echo $classColors[$cls] ?? 'secondary'; ?>">
                                    <?php echo htmlspecialchars($classLabels[$cls] ?? $cls ?: '—'); ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($asset['category_name']): ?>
                                    <span class="badge bg-gray-200 text-gray-800">
                                        <?php echo htmlspecialchars($asset['category_name']); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-info text-white">
                                    <?php echo htmlspecialchars($asset['country_code'] ?? 'N/A'); ?>
                                </span>
                                <br><small class="text-gray-500"><?php echo htmlspecialchars($asset['country_name'] ?? ''); ?></small>
                            </td>
                            <td>
                                <?php if ($asset['location_name']): ?>
                                    <?php echo htmlspecialchars($asset['location_name']); ?>
                                    <?php if ($asset['location_code']): ?>
                                        <br><small class="text-gray-500"><?php echo htmlspecialchars($asset['location_code']); ?></small>
                                    <?php endif; ?>
                                <?php else: ?>
                                    <span class="text-gray-400">—</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge bg-<?php 
                                    echo match($asset['status']) {
                                        'Available' => 'success',
                                        'Allocated' => 'warning',
                                        'CheckedOut' => 'info',
                                        'InProject' => 'primary',
                                        'Consumed' => 'secondary',
                                        'Deployed' => 'dark',
                                        'Missing' => 'danger',
                                        'WrittenOff' => 'secondary',
                                        'Retired' => 'dark',
                                        default => 'secondary'
                                    };
                                ?>">
                                    <?php echo htmlspecialchars($asset['status']); ?>
                                </span>
                                <?php if ($asset['allocation_count'] > 0): ?>
                                    <br><small class="text-gray-500"><?php echo $asset['allocation_count']; ?> allocation(s)</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <div class="btn-group" role="group">
                                    <a href="<?php echo base_url('assets/view.php?id=' . $asset['asset_id']); ?>" class="btn btn-sm btn-outline-primary" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (!am_is_auditor_readonly()): ?>
                                    <a href="<?php echo base_url('assets/edit.php?id=' . $asset['asset_id']); ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($asset['qr_code_id']): ?>
                                    <button class="btn btn-sm btn-outline-success" onclick="labelPrinter.generateLabel(<?php echo $asset['asset_id']; ?>)" title="Print Label">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <?php endif; ?>
                                    <?php endif; ?>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                    <?php endif; ?>
                </table>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>

<script>
// Initialize DataTables (server-side filters already applied; disable client "search" — it only saw visible columns and missed manufacturer/notes.)
$(document).ready(function() {
    var t = $('#assetsTable');
    if (t.find('tbody td[colspan]').length) return;
    var flat = <?php echo $catalogGrouped === null ? 'true' : 'false'; ?>;
    t.DataTable({
        pageLength: 25,
        order: flat ? [[1, 'desc']] : [[0, 'asc']],
        searching: false,
        language: {
            lengthMenu: "Show _MENU_ entries"
        }
    });
});

// Generate QR code for asset
async function generateQR(assetId) {
    try {
        const response = await fetch('<?php echo base_url('api/qr/generate.php'); ?>?asset_id=' + assetId);
        const result = await response.json();

        if (result.success) {
            alert('QR Code generated: ' + result.qr_code_id);
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to generate QR code'));
        }
    } catch (error) {
        alert('Error generating QR code: ' + error.message);
    }
}
</script>
