<?php
/**
 * Assets Listing Page
 * 
 * Displays all assets with filtering, search, and QR code integration
 * Pagination: 100 assets per page
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$page_title = 'Assets';

// Pagination settings
$records_per_page = 100;
$current_page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$offset = ($current_page - 1) * $records_per_page;

// Get filter parameters
$countryFilter = $_GET['country'] ?? '';
$statusFilter = $_GET['status'] ?? '';
$categoryFilter = $_GET['category'] ?? '';
$searchTerm = $_GET['search'] ?? '';

// Build base query
$baseQuery = "
    SELECT 
        a.*,
        c.country_name,
        c.country_code,
        cat.category_name,
        cat.category_type,
        l.location_name,
        l.location_code,
        (SELECT COUNT(*) FROM allocations al WHERE al.asset_id = a.asset_id AND al.status = 'Active') as allocation_count
    FROM assets a
    LEFT JOIN countries c ON a.country_id = c.country_id
    LEFT JOIN categories cat ON a.category_id = cat.category_id
    LEFT JOIN locations l ON a.location_id = l.location_id
    WHERE 1=1
";

$countQuery = "SELECT COUNT(*) as total FROM assets a WHERE 1=1";

$params = [];
$countParams = [];

if ($countryFilter) {
    $baseQuery .= " AND a.country_id = ?";
    $countQuery .= " AND a.country_id = ?";
    $params[] = $countryFilter;
    $countParams[] = $countryFilter;
}

if ($statusFilter) {
    $baseQuery .= " AND a.status = ?";
    $countQuery .= " AND a.status = ?";
    $params[] = $statusFilter;
    $countParams[] = $statusFilter;
}

if ($categoryFilter) {
    $baseQuery .= " AND a.category_id = ?";
    $countQuery .= " AND a.category_id = ?";
    $params[] = $categoryFilter;
    $countParams[] = $categoryFilter;
}

if ($searchTerm) {
    $baseQuery .= " AND (a.name LIKE ? OR a.description LIKE ? OR a.serial_number LIKE ? OR a.qr_code_id LIKE ? OR a.asset_tag LIKE ?)";
    $countQuery .= " AND (a.name LIKE ? OR a.description LIKE ? OR a.serial_number LIKE ? OR a.qr_code_id LIKE ? OR a.asset_tag LIKE ?)";
    $searchParam = "%{$searchTerm}%";
    $params = array_merge($params, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
    $countParams = array_merge($countParams, [$searchParam, $searchParam, $searchParam, $searchParam, $searchParam]);
}

$baseQuery .= " ORDER BY a.asset_id DESC LIMIT ? OFFSET ?";
$params[] = $records_per_page;
$params[] = $offset;

// Get total count
$countStmt = $pdo->prepare($countQuery);
$countStmt->execute($countParams);
$total_records = (int)$countStmt->fetch()['total'];
$total_pages = (int)ceil($total_records / $records_per_page);

// Get assets
$stmt = $pdo->prepare($baseQuery);
$stmt->execute($params);
$assets = $stmt->fetchAll();

// Get filter options
$countries = $pdo->query("SELECT country_id, country_name, country_code FROM countries WHERE active = 1 ORDER BY country_name")->fetchAll();
$categories = $pdo->query("SELECT category_id, category_name, category_type FROM categories WHERE active = 1 ORDER BY category_name")->fetchAll();
$statuses = ['Available', 'Allocated', 'CheckedOut', 'Missing', 'WrittenOff', 'Retired'];

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4">
        <div class="d-block mb-4 mb-md-0">
            <h1 class="h2">Assets</h1>
            <p class="mb-0">Manage and track all assets across Lesotho, Zambia, and Benin</p>
            <small class="text-gray-500">Showing <?php echo number_format($total_records); ?> total assets</small>
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
                <input type="hidden" name="page" value="1">
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
                <table class="table table-hover">
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
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteAsset(<?php echo $asset['asset_id']; ?>, '<?php echo htmlspecialchars(addslashes($asset['name'])); ?>')" title="Delete">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
            <nav aria-label="Assets pagination" class="mt-4">
                <ul class="pagination justify-content-center">
                    <?php
                    // Build pagination URL
                    $paginationParams = $_GET;
                    unset($paginationParams['page']);
                    $paginationBase = '?' . http_build_query($paginationParams) . ($paginationParams ? '&' : '') . 'page=';
                    ?>
                    
                    <!-- Previous -->
                    <li class="page-item <?php echo (int)$current_page <= 1 ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo (int)$current_page > 1 ? $paginationBase . ((int)$current_page - 1) : '#'; ?>">
                            <i class="fas fa-chevron-left"></i>
                        </a>
                    </li>
                    
                    <!-- Page Numbers -->
                    <?php
                    $start_page = max(1, (int)$current_page - 2);
                    $end_page = min((int)$total_pages, (int)$current_page + 2);
                    
                    if ($start_page > 1): ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo $paginationBase . '1'; ?>">1</a>
                        </li>
                        <?php if ($start_page > 2): ?>
                            <li class="page-item disabled">
                                <span class="page-link">...</span>
                            </li>
                        <?php endif; ?>
                    <?php endif; ?>
                    
                    <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                        <li class="page-item <?php echo $i == $current_page ? 'active' : ''; ?>">
                            <a class="page-link" href="<?php echo $paginationBase . $i; ?>"><?php echo $i; ?></a>
                        </li>
                    <?php endfor; ?>
                    
                    <?php if ($end_page < $total_pages): ?>
                        <?php if ($end_page < $total_pages - 1): ?>
                            <li class="page-item disabled">
                                <span class="page-link">...</span>
                            </li>
                        <?php endif; ?>
                        <li class="page-item">
                            <a class="page-link" href="<?php echo $paginationBase . $total_pages; ?>"><?php echo $total_pages; ?></a>
                        </li>
                    <?php endif; ?>
                    
                    <!-- Next -->
                    <li class="page-item <?php echo (int)$current_page >= (int)$total_pages ? 'disabled' : ''; ?>">
                        <a class="page-link" href="<?php echo (int)$current_page < (int)$total_pages ? $paginationBase . ((int)$current_page + 1) : '#'; ?>">
                            <i class="fas fa-chevron-right"></i>
                        </a>
                    </li>
                </ul>
                
                <div class="text-center mt-2">
                    <small class="text-gray-500">
                        Showing <?php echo number_format($offset + 1); ?> - <?php echo number_format(min($offset + $records_per_page, $total_records)); ?> 
                        of <?php echo number_format($total_records); ?> assets
                        (Page <?php echo $current_page; ?> of <?php echo $total_pages; ?>)
                    </small>
                </div>
            </nav>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
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

// Delete asset
async function deleteAsset(assetId, assetName) {
    if (!confirm('Are you sure you want to delete "' + assetName + '"?\n\nThis action cannot be undone.')) {
        return;
    }
    
    try {
        const response = await fetch('<?php echo base_url('api/assets/delete.php'); ?>?id=' + assetId, {
            method: 'DELETE'
        });
        const result = await response.json();
        
        if (result.success) {
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed to delete asset'));
        }
    } catch (error) {
        alert('Error deleting asset: ' + error.message);
    }
}
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
