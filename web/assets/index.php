<?php
/**
 * Assets Listing Page
 * 
 * Displays all assets with filtering, search, and QR code integration
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

$page_title = 'Assets';

// Get filter parameters
$countryFilter = $_GET['country'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$searchTerm = $_GET['search'] ?? '';

// Firestore collections (split sources of truth)
$assetsRaw = am_firestore_get_collection('am_core_assets', 2000);
$countries = am_firestore_get_collection('pr_master_countries', 500);
$categories = am_firestore_get_collection('pr_master_categories', 1000);
$locations = am_firestore_get_collection('pr_master_locations', 2000);
$allocations = am_firestore_get_collection('am_core_allocations', 2000);

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
$needle = strtolower(trim($searchTerm));
foreach ($assetsRaw as $asset) {
    $countryId = (string)($asset['country_id'] ?? '');
    $categoryId = (string)($asset['category_id'] ?? '');
    $locationId = (string)($asset['location_id'] ?? '');
    $assetId = (string)($asset['asset_id'] ?? $asset['id'] ?? '');
    $status = (string)($asset['status'] ?? '');

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
        $searchBlob = strtolower(implode(' ', [
            (string)($asset['name'] ?? ''),
            (string)($asset['description'] ?? ''),
            (string)($asset['serial_number'] ?? ''),
            (string)($asset['qr_code_id'] ?? ''),
            (string)($asset['asset_tag'] ?? ''),
        ]));
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
    $asset['category_name'] = (string)($category['category_name'] ?? '');
    $asset['category_type'] = (string)($category['category_type'] ?? '');
    $asset['location_name'] = (string)($location['location_name'] ?? '');
    $asset['location_code'] = (string)($location['location_code'] ?? '');
    $asset['allocation_count'] = (int)($allocationCounts[$assetId] ?? 0);

    $assets[] = $asset;
}

usort($assets, function ($a, $b) {
    $ai = (int)($a['asset_id'] ?? 0);
    $bi = (int)($b['asset_id'] ?? 0);
    return $bi <=> $ai;
});

// Filter options
$countries = array_values(array_filter($countries, fn($c) => (int)($c['active'] ?? 1) === 1));
$categories = array_values(array_filter($categories, fn($c) => (int)($c['active'] ?? 1) === 1));
$statuses = ['Available', 'Allocated', 'CheckedOut', 'Missing', 'WrittenOff', 'Retired'];

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4">
        <div class="d-block mb-4 mb-md-0">
            <h1 class="h2">Assets</h1>
            <p class="mb-0">Manage and track all assets across Lesotho, Zambia, and Benin</p>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="<?php echo base_url('assets/add.php'); ?>" class="btn btn-sm btn-gray-800 d-inline-flex align-items-center me-2">
                <i class="fas fa-plus me-2"></i>
                Add New Asset
            </a>
            <button class="btn btn-sm btn-primary d-inline-flex align-items-center" onclick="labelPrinter.generateLabel(prompt('Enter Asset ID:'))">
                <i class="fas fa-print me-2"></i>
                Print QR Label
            </button>
        </div>
    </div>

    <!-- Filters -->
    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <form method="GET" action="" class="row g-3">
                <div class="col-12 col-md-3">
                    <label class="form-label">Search</label>
                    <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($searchTerm); ?>" placeholder="Name, Serial, QR Code...">
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Country</label>
                    <select class="form-select" name="country">
                        <option value="">All Countries</option>
                        <?php foreach ($countries as $country): ?>
                        <option value="<?php echo $country['country_id']; ?>" <?php echo $countryFilter == $country['country_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($country['country_name']); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <label class="form-label">Status</label>
                    <select class="form-select" name="status">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $status): ?>
                        <option value="<?php echo $status; ?>" <?php echo $statusFilter === $status ? 'selected' : ''; ?>>
                            <?php echo $status; ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-3">
                    <label class="form-label">Category</label>
                    <select class="form-select" name="category">
                        <option value="">All Categories</option>
                        <?php foreach ($categories as $category): ?>
                        <option value="<?php echo $category['category_id']; ?>" <?php echo $categoryFilter == $category['category_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($category['category_name']); ?> (<?php echo $category['category_type']; ?>)
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
    <div class="card border-0 shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="assetsTable">
                    <thead>
                        <tr>
                            <th>QR Code</th>
                            <th>Asset Tag</th>
                            <th>Name</th>
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
                            <td colspan="8" class="text-center text-gray-500 py-4">
                                No assets found. <a href="<?php echo base_url('assets/add.php'); ?>">Add your first asset</a>
                            </td>
                        </tr>
                        <?php else: ?>
                        <?php foreach ($assets as $asset): ?>
                        <tr>
                            <td>
                                <?php if ($asset['qr_code_id']): ?>
                                    <code class="text-primary"><?php echo htmlspecialchars($asset['qr_code_id']); ?></code>
                                <?php else: ?>
                                    <button class="btn btn-sm btn-outline-primary" onclick="generateQR(<?php echo $asset['asset_id']; ?>)">
                                        <i class="fas fa-qrcode me-1"></i>Generate
                                    </button>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($asset['asset_tag'] ?? 'N/A'); ?></strong>
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
                                <?php if ($asset['category_name']): ?>
                                    <span class="badge bg-gray-200 text-gray-800">
                                        <?php echo htmlspecialchars($asset['category_name']); ?>
                                    </span>
                                    <?php if ($asset['category_type']): ?>
                                        <br><small class="text-gray-500"><?php echo $asset['category_type']; ?></small>
                                    <?php endif; ?>
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
                                    <a href="<?php echo base_url('assets/edit.php?id=' . $asset['asset_id']); ?>" class="btn btn-sm btn-outline-secondary" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php if ($asset['qr_code_id']): ?>
                                    <button class="btn btn-sm btn-outline-success" onclick="labelPrinter.generateLabel(<?php echo $asset['asset_id']; ?>)" title="Print Label">
                                        <i class="fas fa-print"></i>
                                    </button>
                                    <?php endif; ?>
                                </div>
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
// Initialize DataTables
$(document).ready(function() {
    $('#assetsTable').DataTable({
        pageLength: 25,
        order: [[1, 'desc']], // Sort by Asset Tag descending
        language: {
            search: "Search:",
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

<?php include __DIR__ . '/../includes/footer.php'; ?>
