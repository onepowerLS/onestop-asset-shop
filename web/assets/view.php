<?php
/**
 * View Asset Details
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$page_title = 'Asset Details';

$asset_id = null;
$asset = null;
$error = '';

// Get asset ID from query string (either ?id=X or ?qr=QR_CODE)
if (isset($_GET['id'])) {
    $asset_id = intval($_GET['id']);
} elseif (isset($_GET['qr'])) {
    $qr_code = trim($_GET['qr']);
    $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE qr_code_id = ?");
    $stmt->execute([$qr_code]);
    $result = $stmt->fetch();
    if ($result) {
        $asset_id = $result['asset_id'];
    } else {
        $error = "Asset with QR code '$qr_code' not found.";
    }
} else {
    $error = "No asset specified.";
}

// Load asset details
if ($asset_id && empty($error)) {
    try {
        $stmt = $pdo->prepare("
            SELECT 
                a.*,
                c.country_name, c.country_code,
                l.location_name, l.location_code,
                cat.category_name, cat.category_type
            FROM assets a
            LEFT JOIN countries c ON a.country_id = c.country_id
            LEFT JOIN locations l ON a.location_id = l.location_id
            LEFT JOIN categories cat ON a.category_id = cat.category_id
            WHERE a.asset_id = ?
        ");
        $stmt->execute([$asset_id]);
        $asset = $stmt->fetch();
        
        if (!$asset) {
            $error = "Asset not found.";
        } else {
            $page_title = 'Asset: ' . htmlspecialchars($asset['name']);
        }
    } catch (PDOException $e) {
        error_log("Error loading asset: " . $e->getMessage());
        $error = "Error loading asset details.";
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4">
        <div class="d-block mb-4 mb-md-0">
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('index.php'); ?>">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo base_url('assets/index.php'); ?>">Assets</a></li>
                    <li class="breadcrumb-item active">Asset Details</li>
                </ol>
            </nav>
            <h1 class="h2">Asset Details</h1>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="<?php echo base_url('assets/index.php'); ?>" class="btn btn-sm btn-gray-800 d-inline-flex align-items-center me-2">
                <i class="fas fa-arrow-left me-2"></i>
                Back to Assets
            </a>
            <?php if ($asset): ?>
            <a href="<?php echo base_url('assets/edit.php?id=' . $asset_id); ?>" class="btn btn-sm btn-primary d-inline-flex align-items-center">
                <i class="fas fa-edit me-2"></i>
                Edit Asset
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars($error); ?>
    </div>
    <?php elseif ($asset): ?>
    
    <div class="row">
        <!-- Main Details -->
        <div class="col-12 col-lg-8 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-box me-2"></i>Asset Information</h5>
                </div>
                <div class="card-body">
                    <div class="row mb-3">
                        <div class="col-md-6">
                            <strong>Asset Name:</strong>
                            <p class="mb-0"><?php echo htmlspecialchars($asset['name']); ?></p>
                        </div>
                        <div class="col-md-6">
                            <strong>QR Code ID:</strong>
                            <p class="mb-0"><code><?php echo htmlspecialchars($asset['qr_code_id'] ?? 'N/A'); ?></code></p>
                        </div>
                    </div>
                    
                    <?php if ($asset['description']): ?>
                    <div class="row mb-3">
                        <div class="col-12">
                            <strong>Description:</strong>
                            <p class="mb-0"><?php echo nl2br(htmlspecialchars($asset['description'])); ?></p>
                        </div>
                    </div>
                    <?php endif; ?>
                    
                    <div class="row mb-3">
                        <div class="col-md-4">
                            <strong>Category:</strong>
                            <p class="mb-0">
                                <?php if ($asset['category_name']): ?>
                                    <?php echo htmlspecialchars($asset['category_name']); ?>
                                    <span class="badge bg-secondary ms-2"><?php echo htmlspecialchars($asset['category_type'] ?? ''); ?></span>
                                <?php else: ?>
                                    <span class="text-muted">Not assigned</span>
                                <?php endif; ?>
                            </p>
                        </div>
                        <div class="col-md-4">
                            <strong>Status:</strong>
                            <p class="mb-0">
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
                            </p>
                        </div>
                        <div class="col-md-4">
                            <strong>Condition:</strong>
                            <p class="mb-0">
                                <span class="badge bg-<?php 
                                    echo match($asset['condition_status']) {
                                        'New' => 'success',
                                        'Good' => 'primary',
                                        'Fair' => 'warning',
                                        'Poor' => 'warning',
                                        'Damaged' => 'danger',
                                        'Retired' => 'dark',
                                        default => 'secondary'
                                    };
                                ?>">
                                    <?php echo htmlspecialchars($asset['condition_status']); ?>
                                </span>
                            </p>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Sidebar Info -->
        <div class="col-12 col-lg-4 mb-4">
            <!-- Location & Country -->
            <div class="card border-0 shadow mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-map-marker-alt me-2"></i>Location</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2">
                        <strong>Country:</strong><br>
                        <?php echo htmlspecialchars($asset['country_name'] ?? 'N/A'); ?>
                        <span class="badge bg-gray-200 text-gray-800 ms-2"><?php echo htmlspecialchars($asset['country_code'] ?? ''); ?></span>
                    </p>
                    <p class="mb-0">
                        <strong>Location:</strong><br>
                        <?php if ($asset['location_name']): ?>
                            <?php echo htmlspecialchars($asset['location_name']); ?>
                            <span class="badge bg-gray-200 text-gray-800 ms-2"><?php echo htmlspecialchars($asset['location_code'] ?? ''); ?></span>
                        <?php else: ?>
                            <span class="text-muted">Not specified</span>
                        <?php endif; ?>
                    </p>
                </div>
            </div>

            <!-- Identification -->
            <div class="card border-0 shadow mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-barcode me-2"></i>Identification</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2">
                        <strong>Serial Number:</strong><br>
                        <?php echo $asset['serial_number'] ? htmlspecialchars($asset['serial_number']) : '<span class="text-muted">Not provided</span>'; ?>
                    </p>
                    <p class="mb-2">
                        <strong>Asset Tag:</strong><br>
                        <?php echo $asset['asset_tag'] ? htmlspecialchars($asset['asset_tag']) : '<span class="text-muted">Not assigned</span>'; ?>
                    </p>
                    <p class="mb-0">
                        <strong>Quantity:</strong><br>
                        <?php echo htmlspecialchars($asset['quantity'] ?? 1); ?> <?php echo htmlspecialchars($asset['unit_of_measure'] ?? 'EA'); ?>
                    </p>
                </div>
            </div>

            <!-- Manufacturer Details -->
            <?php if ($asset['manufacturer'] || $asset['model']): ?>
            <div class="card border-0 shadow mb-4">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-industry me-2"></i>Manufacturer</h5>
                </div>
                <div class="card-body">
                    <?php if ($asset['manufacturer']): ?>
                    <p class="mb-2">
                        <strong>Manufacturer:</strong><br>
                        <?php echo htmlspecialchars($asset['manufacturer']); ?>
                    </p>
                    <?php endif; ?>
                    <?php if ($asset['model']): ?>
                    <p class="mb-0">
                        <strong>Model:</strong><br>
                        <?php echo htmlspecialchars($asset['model']); ?>
                    </p>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Financial & Dates -->
    <div class="row">
        <div class="col-12 col-lg-6 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-dollar-sign me-2"></i>Financial Information</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <strong>Purchase Date:</strong><br>
                            <?php echo $asset['purchase_date'] ? date('M d, Y', strtotime($asset['purchase_date'])) : '<span class="text-muted">Not specified</span>'; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Purchase Price:</strong><br>
                            <?php echo $asset['purchase_price'] ? '$' . number_format($asset['purchase_price'], 2) : '<span class="text-muted">Not specified</span>'; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Current Value:</strong><br>
                            <?php echo $asset['current_value'] ? '$' . number_format($asset['current_value'], 2) : '<span class="text-muted">Not specified</span>'; ?>
                        </div>
                        <div class="col-md-6 mb-3">
                            <strong>Warranty Expiry:</strong><br>
                            <?php echo $asset['warranty_expiry'] ? date('M d, Y', strtotime($asset['warranty_expiry'])) : '<span class="text-muted">Not specified</span>'; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-6 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Additional Information</h5>
                </div>
                <div class="card-body">
                    <p class="mb-2">
                        <strong>Asset Type:</strong><br>
                        <span class="badge bg-secondary"><?php echo htmlspecialchars($asset['asset_type'] ?? 'Non-Current'); ?></span>
                    </p>
                    <?php if ($asset['notes']): ?>
                    <p class="mb-0">
                        <strong>Notes:</strong><br>
                        <?php echo nl2br(htmlspecialchars($asset['notes'])); ?>
                    </p>
                    <?php endif; ?>
                    <hr>
                    <p class="mb-0 text-muted small">
                        <strong>Created:</strong> <?php echo date('M d, Y H:i', strtotime($asset['created_at'])); ?><br>
                        <strong>Last Updated:</strong> <?php echo date('M d, Y H:i', strtotime($asset['updated_at'])); ?>
                    </p>
                </div>
            </div>
        </div>
    </div>

    <?php else: ?>
    <div class="alert alert-warning" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Asset not found or invalid asset ID.
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
