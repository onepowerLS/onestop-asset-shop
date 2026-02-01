<?php
/**
 * Edit Asset
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/database.php';
require_login();

$page_title = 'Edit Asset';

$asset_id = isset($_GET['id']) ? intval($_GET['id']) : 0;
$asset = null;
$error = '';
$success = '';

// Load asset details
if ($asset_id) {
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
            $page_title = 'Edit Asset: ' . htmlspecialchars($asset['name']);
        }
    } catch (PDOException $e) {
        error_log("Error loading asset: " . $e->getMessage());
        $error = "Error loading asset details.";
    }
} else {
    $error = "No asset ID specified.";
}

// Get countries, locations, and categories for dropdowns
try {
    $countries = $pdo->query("SELECT country_id, country_name, country_code FROM countries ORDER BY country_name")->fetchAll();
    $locations = $pdo->query("SELECT location_id, location_name, country_id FROM locations ORDER BY location_name")->fetchAll();
    $categories = $pdo->query("SELECT category_id, category_name, category_type FROM categories ORDER BY category_type, category_name")->fetchAll();
} catch (PDOException $e) {
    error_log("Error loading dropdowns: " . $e->getMessage());
    $countries = [];
    $locations = [];
    $categories = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $asset) {
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $category_id = !empty($_POST['category_id']) ? intval($_POST['category_id']) : null;
    $serial_number = trim($_POST['serial_number'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $purchase_date = !empty($_POST['purchase_date']) ? $_POST['purchase_date'] : null;
    $purchase_price = !empty($_POST['purchase_price']) ? floatval($_POST['purchase_price']) : null;
    $current_value = !empty($_POST['current_value']) ? floatval($_POST['current_value']) : null;
    $warranty_expiry = !empty($_POST['warranty_expiry']) ? $_POST['warranty_expiry'] : null;
    $condition_status = $_POST['condition_status'] ?? 'Good';
    $status = $_POST['status'] ?? 'Available';
    $location_id = !empty($_POST['location_id']) ? intval($_POST['location_id']) : null;
    $country_id = !empty($_POST['country_id']) ? intval($_POST['country_id']) : null;
    $asset_tag = trim($_POST['asset_tag'] ?? '');
    $quantity = !empty($_POST['quantity']) ? intval($_POST['quantity']) : 1;
    $unit_of_measure = trim($_POST['unit_of_measure'] ?? 'EA');
    $notes = trim($_POST['notes'] ?? '');
    $asset_type = $_POST['asset_type'] ?? 'Non-Current';
    
    // Vehicle-specific fields
    $vehicle_year = !empty($_POST['vehicle_year']) ? intval($_POST['vehicle_year']) : null;
    $engine_number = trim($_POST['engine_number'] ?? '');
    $transmission_type = !empty($_POST['transmission_type']) ? $_POST['transmission_type'] : null;
    $fuel_type = !empty($_POST['fuel_type']) ? $_POST['fuel_type'] : null;
    $drive_type = !empty($_POST['drive_type']) ? $_POST['drive_type'] : null;
    
    // Validation
    if (empty($name)) {
        $error = 'Asset name is required.';
    } elseif (empty($country_id)) {
        $error = 'Country is required.';
    } else {
        try {
            // Check for duplicate serial number if provided and changed
            if (!empty($serial_number) && $serial_number !== $asset['serial_number']) {
                $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE serial_number = ? AND asset_id != ?");
                $stmt->execute([$serial_number, $asset_id]);
                if ($stmt->fetch()) {
                    $error = 'An asset with this serial number already exists.';
                }
            }
            
            // Check for duplicate asset tag if provided and changed
            if (empty($error) && !empty($asset_tag) && $asset_tag !== $asset['asset_tag']) {
                $stmt = $pdo->prepare("SELECT asset_id FROM assets WHERE asset_tag = ? AND asset_id != ?");
                $stmt->execute([$asset_tag, $asset_id]);
                if ($stmt->fetch()) {
                    $error = 'An asset with this tag number already exists.';
                }
            }
            
            if (empty($error)) {
                // Update asset
                $stmt = $pdo->prepare("
                    UPDATE assets SET
                        name = ?, description = ?, category_id = ?, serial_number = ?, manufacturer = ?, model = ?,
                        purchase_date = ?, purchase_price = ?, current_value = ?, warranty_expiry = ?,
                        condition_status = ?, status = ?, location_id = ?, country_id = ?, asset_tag = ?,
                        quantity = ?, unit_of_measure = ?, notes = ?, asset_type = ?,
                        vehicle_year = ?, engine_number = ?, transmission_type = ?, fuel_type = ?, drive_type = ?,
                        updated_at = NOW()
                    WHERE asset_id = ?
                ");
                
                $stmt->execute([
                    $name, $description, $category_id, $serial_number, $manufacturer, $model,
                    $purchase_date, $purchase_price, $current_value, $warranty_expiry,
                    $condition_status, $status, $location_id, $country_id, $asset_tag,
                    $quantity, $unit_of_measure, $notes, $asset_type,
                    $vehicle_year, $engine_number ?: null, $transmission_type, $fuel_type, $drive_type,
                    $asset_id
                ]);
                
                $success = "Asset updated successfully!";
                
                // Reload asset data
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
            }
        } catch (PDOException $e) {
            error_log("Error updating asset: " . $e->getMessage());
            $error = 'Failed to update asset. Please try again.';
        }
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
                    <?php if ($asset): ?>
                    <li class="breadcrumb-item"><a href="<?php echo base_url('assets/view.php?id=' . $asset_id); ?>"><?php echo htmlspecialchars($asset['name']); ?></a></li>
                    <?php endif; ?>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </nav>
            <h1 class="h2">Edit Asset</h1>
        </div>
        <div class="btn-toolbar mb-2 mb-md-0">
            <a href="<?php echo base_url('assets/index.php'); ?>" class="btn btn-sm btn-gray-800 d-inline-flex align-items-center me-2">
                <i class="fas fa-arrow-left me-2"></i>
                Back to Assets
            </a>
            <?php if ($asset): ?>
            <a href="<?php echo base_url('assets/view.php?id=' . $asset_id); ?>" class="btn btn-sm btn-secondary d-inline-flex align-items-center">
                <i class="fas fa-eye me-2"></i>
                View Asset
            </a>
            <?php endif; ?>
        </div>
    </div>

    <?php if ($error): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <i class="fas fa-exclamation-circle me-2"></i>
        <?php echo htmlspecialchars($error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($success): ?>
    <div class="alert alert-success alert-dismissible fade show" role="alert">
        <i class="fas fa-check-circle me-2"></i>
        <?php echo htmlspecialchars($success); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <?php if ($asset): ?>
    <div class="row">
        <div class="col-12">
            <div class="card border-0 shadow">
                <div class="card-body">
                    <form method="POST" id="editAssetForm">
                        <!-- Basic Information -->
                        <h5 class="mb-3"><i class="fas fa-info-circle me-2"></i>Basic Information</h5>
                        <div class="row mb-4">
                            <div class="col-md-6 mb-3">
                                <label for="name" class="form-label">Asset Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" id="name" name="name" value="<?php echo htmlspecialchars($asset['name']); ?>" required>
                            </div>
                            <div class="col-md-6 mb-3">
                                <label for="category_id" class="form-label">Category</label>
                                <select class="form-select" id="category_id" name="category_id">
                                    <option value="">Select Category</option>
                                    <?php foreach ($categories as $cat): ?>
                                    <option value="<?php echo $cat['category_id']; ?>" <?php echo ($asset['category_id'] == $cat['category_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($cat['category_name'] . ' (' . $cat['category_type'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>

                        <div class="row mb-4">
                            <div class="col-md-12 mb-3">
                                <label for="description" class="form-label">Description</label>
                                <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($asset['description'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <!-- Identification -->
                        <h5 class="mb-3 mt-4"><i class="fas fa-barcode me-2"></i>Identification</h5>
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <label for="serial_number" class="form-label">Serial Number</label>
                                <input type="text" class="form-control" id="serial_number" name="serial_number" value="<?php echo htmlspecialchars($asset['serial_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="asset_tag" class="form-label">Asset Tag</label>
                                <input type="text" class="form-control" id="asset_tag" name="asset_tag" value="<?php echo htmlspecialchars($asset['asset_tag'] ?? ''); ?>">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label for="quantity" class="form-label">Quantity</label>
                                <input type="number" class="form-control" id="quantity" name="quantity" value="<?php echo htmlspecialchars($asset['quantity'] ?? 1); ?>" min="1">
                            </div>
                            <div class="col-md-2 mb-3">
                                <label for="unit_of_measure" class="form-label">Unit</label>
                                <input type="text" class="form-control" id="unit_of_measure" name="unit_of_measure" value="<?php echo htmlspecialchars($asset['unit_of_measure'] ?? 'EA'); ?>">
                            </div>
                        </div>

                        <!-- Manufacturer Details -->
                        <h5 class="mb-3 mt-4"><i class="fas fa-industry me-2"></i>Manufacturer Details</h5>
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <label for="manufacturer" class="form-label">Manufacturer / Make</label>
                                <input type="text" class="form-control" id="manufacturer" name="manufacturer" value="<?php echo htmlspecialchars($asset['manufacturer'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="model" class="form-label">Model</label>
                                <input type="text" class="form-control" id="model" name="model" value="<?php echo htmlspecialchars($asset['model'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="vehicle_year" class="form-label">Model Year</label>
                                <input type="number" class="form-control" id="vehicle_year" name="vehicle_year" value="<?php echo htmlspecialchars($asset['vehicle_year'] ?? ''); ?>" min="1900" max="2100" placeholder="e.g. 2024">
                            </div>
                        </div>

                        <!-- Vehicle-Specific Fields (shown for all assets, but most relevant for vehicles) -->
                        <h5 class="mb-3 mt-4"><i class="fas fa-car me-2"></i>Vehicle Details</h5>
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <label for="engine_number" class="form-label">Engine Number</label>
                                <input type="text" class="form-control" id="engine_number" name="engine_number" value="<?php echo htmlspecialchars($asset['engine_number'] ?? ''); ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="transmission_type" class="form-label">Transmission</label>
                                <select class="form-select" id="transmission_type" name="transmission_type">
                                    <option value="">Not Specified</option>
                                    <option value="MT" <?php echo (($asset['transmission_type'] ?? '') === 'MT') ? 'selected' : ''; ?>>Manual (MT)</option>
                                    <option value="AT" <?php echo (($asset['transmission_type'] ?? '') === 'AT') ? 'selected' : ''; ?>>Automatic (AT)</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="fuel_type" class="form-label">Fuel Type</label>
                                <select class="form-select" id="fuel_type" name="fuel_type">
                                    <option value="">Not Specified</option>
                                    <option value="Petrol" <?php echo (($asset['fuel_type'] ?? '') === 'Petrol') ? 'selected' : ''; ?>>Petrol</option>
                                    <option value="Diesel" <?php echo (($asset['fuel_type'] ?? '') === 'Diesel') ? 'selected' : ''; ?>>Diesel</option>
                                </select>
                            </div>
                        </div>
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <label for="drive_type" class="form-label">Drive Type</label>
                                <select class="form-select" id="drive_type" name="drive_type">
                                    <option value="">Not Specified</option>
                                    <option value="2WD" <?php echo (($asset['drive_type'] ?? '') === '2WD') ? 'selected' : ''; ?>>2WD</option>
                                    <option value="4WD" <?php echo (($asset['drive_type'] ?? '') === '4WD') ? 'selected' : ''; ?>>4WD</option>
                                    <option value="6WD" <?php echo (($asset['drive_type'] ?? '') === '6WD') ? 'selected' : ''; ?>>6WD</option>
                                </select>
                            </div>
                        </div>

                        <!-- Financial Information -->
                        <h5 class="mb-3 mt-4"><i class="fas fa-dollar-sign me-2"></i>Financial Information</h5>
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <label for="purchase_date" class="form-label">Purchase Date</label>
                                <input type="date" class="form-control" id="purchase_date" name="purchase_date" value="<?php echo $asset['purchase_date'] ? date('Y-m-d', strtotime($asset['purchase_date'])) : ''; ?>">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="purchase_price" class="form-label">Purchase Price</label>
                                <input type="number" class="form-control" id="purchase_price" name="purchase_price" value="<?php echo htmlspecialchars($asset['purchase_price'] ?? ''); ?>" step="0.01" min="0">
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="current_value" class="form-label">Current Value</label>
                                <input type="number" class="form-control" id="current_value" name="current_value" value="<?php echo htmlspecialchars($asset['current_value'] ?? ''); ?>" step="0.01" min="0">
                            </div>
                        </div>

                        <!-- Location & Status -->
                        <h5 class="mb-3 mt-4"><i class="fas fa-map-marker-alt me-2"></i>Location & Status</h5>
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <label for="country_id" class="form-label">Country <span class="text-danger">*</span></label>
                                <select class="form-select" id="country_id" name="country_id" required>
                                    <option value="">Select Country</option>
                                    <?php foreach ($countries as $country): ?>
                                    <option value="<?php echo $country['country_id']; ?>" <?php echo ($asset['country_id'] == $country['country_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($country['country_name'] . ' (' . $country['country_code'] . ')'); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="location_id" class="form-label">Location</label>
                                <select class="form-select" id="location_id" name="location_id">
                                    <option value="">Select Location</option>
                                    <?php foreach ($locations as $loc): ?>
                                    <option value="<?php echo $loc['location_id']; ?>" data-country="<?php echo $loc['country_id']; ?>" <?php echo ($asset['location_id'] == $loc['location_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($loc['location_name']); ?>
                                    </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="status" class="form-label">Status</label>
                                <select class="form-select" id="status" name="status">
                                    <option value="Available" <?php echo ($asset['status'] === 'Available') ? 'selected' : ''; ?>>Available</option>
                                    <option value="Allocated" <?php echo ($asset['status'] === 'Allocated') ? 'selected' : ''; ?>>Allocated</option>
                                    <option value="CheckedOut" <?php echo ($asset['status'] === 'CheckedOut') ? 'selected' : ''; ?>>Checked Out</option>
                                    <option value="Missing" <?php echo ($asset['status'] === 'Missing') ? 'selected' : ''; ?>>Missing</option>
                                    <option value="WrittenOff" <?php echo ($asset['status'] === 'WrittenOff') ? 'selected' : ''; ?>>Written Off</option>
                                    <option value="Retired" <?php echo ($asset['status'] === 'Retired') ? 'selected' : ''; ?>>Retired</option>
                                </select>
                            </div>
                        </div>

                        <!-- Condition & Type -->
                        <h5 class="mb-3 mt-4"><i class="fas fa-clipboard-check me-2"></i>Condition & Type</h5>
                        <div class="row mb-4">
                            <div class="col-md-4 mb-3">
                                <label for="condition_status" class="form-label">Condition</label>
                                <select class="form-select" id="condition_status" name="condition_status">
                                    <option value="New" <?php echo ($asset['condition_status'] === 'New') ? 'selected' : ''; ?>>New</option>
                                    <option value="Good" <?php echo ($asset['condition_status'] === 'Good') ? 'selected' : ''; ?>>Good</option>
                                    <option value="Fair" <?php echo ($asset['condition_status'] === 'Fair') ? 'selected' : ''; ?>>Fair</option>
                                    <option value="Poor" <?php echo ($asset['condition_status'] === 'Poor') ? 'selected' : ''; ?>>Poor</option>
                                    <option value="Damaged" <?php echo ($asset['condition_status'] === 'Damaged') ? 'selected' : ''; ?>>Damaged</option>
                                    <option value="Retired" <?php echo ($asset['condition_status'] === 'Retired') ? 'selected' : ''; ?>>Retired</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="asset_type" class="form-label">Asset Type</label>
                                <select class="form-select" id="asset_type" name="asset_type">
                                    <option value="Current" <?php echo ($asset['asset_type'] === 'Current') ? 'selected' : ''; ?>>Current</option>
                                    <option value="Non-Current" <?php echo ($asset['asset_type'] === 'Non-Current') ? 'selected' : ''; ?>>Non-Current</option>
                                </select>
                            </div>
                            <div class="col-md-4 mb-3">
                                <label for="warranty_expiry" class="form-label">Warranty Expiry</label>
                                <input type="date" class="form-control" id="warranty_expiry" name="warranty_expiry" value="<?php echo $asset['warranty_expiry'] ? date('Y-m-d', strtotime($asset['warranty_expiry'])) : ''; ?>">
                            </div>
                        </div>

                        <!-- Additional Notes -->
                        <h5 class="mb-3 mt-4"><i class="fas fa-sticky-note me-2"></i>Additional Information</h5>
                        <div class="row mb-4">
                            <div class="col-md-12 mb-3">
                                <label for="notes" class="form-label">Notes</label>
                                <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($asset['notes'] ?? ''); ?></textarea>
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <a href="<?php echo base_url('assets/view.php?id=' . $asset_id); ?>" class="btn btn-secondary">Cancel</a>
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-2"></i>
                                Save Changes
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <!-- Odometer Readings Section (for vehicles) -->
    <?php 
    // Check if this is a vehicle (category name contains 'Vehicle')
    $isVehicle = ($asset['category_name'] ?? '') === 'Vehicles';
    if ($isVehicle): 
    ?>
    <div class="row mt-4">
        <div class="col-12">
            <div class="card border-0 shadow">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-tachometer-alt me-2"></i>Odometer Readings</h5>
                    <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#addOdometerModal">
                        <i class="fas fa-plus me-1"></i> Add Reading
                    </button>
                </div>
                <div class="card-body">
                    <div class="table-responsive">
                        <table class="table table-hover" id="odometerTable">
                            <thead>
                                <tr>
                                    <th>Date</th>
                                    <th>Reading (km)</th>
                                    <th>Distance Since Last</th>
                                    <th>Notes</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody id="odometerReadings">
                                <tr><td colspan="5" class="text-center text-muted">Loading...</td></tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <!-- Add Odometer Reading Modal -->
    <div class="modal fade" id="addOdometerModal" tabindex="-1">
        <div class="modal-dialog">
            <div class="modal-content">
                <div class="modal-header">
                    <h5 class="modal-title"><i class="fas fa-tachometer-alt me-2"></i>Add Odometer Reading</h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                </div>
                <div class="modal-body">
                    <form id="addOdometerForm">
                        <div class="mb-3">
                            <label for="reading_date" class="form-label">Date <span class="text-danger">*</span></label>
                            <input type="date" class="form-control" id="reading_date" name="reading_date" value="<?php echo date('Y-m-d'); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label for="reading_km" class="form-label">Odometer Reading (km) <span class="text-danger">*</span></label>
                            <input type="number" class="form-control" id="reading_km" name="reading_km" min="0" required>
                        </div>
                        <div class="mb-3">
                            <label for="odo_notes" class="form-label">Notes</label>
                            <input type="text" class="form-control" id="odo_notes" name="notes" placeholder="e.g., Service, Trip to site">
                        </div>
                    </form>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="button" class="btn btn-primary" id="saveOdometerBtn">
                        <i class="fas fa-save me-1"></i> Save Reading
                    </button>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <script>
    // Filter locations based on selected country
    document.getElementById('country_id').addEventListener('change', function() {
        const countryId = this.value;
        const locationSelect = document.getElementById('location_id');
        const options = locationSelect.querySelectorAll('option');
        
        options.forEach(option => {
            if (option.value === '') {
                option.style.display = 'block';
            } else {
                const optionCountryId = option.getAttribute('data-country');
                if (optionCountryId === countryId) {
                    option.style.display = 'block';
                } else {
                    option.style.display = 'none';
                    if (option.selected) {
                        option.selected = false;
                        locationSelect.value = '';
                    }
                }
            }
        });
    });

    <?php if ($isVehicle): ?>
    // Odometer readings functionality
    const assetId = <?php echo $asset_id; ?>;
    const odometerApiUrl = '<?php echo base_url('api/odometer/'); ?>';
    
    function loadOdometerReadings() {
        fetch(odometerApiUrl + '?asset_id=' + assetId)
            .then(response => response.json())
            .then(data => {
                const tbody = document.getElementById('odometerReadings');
                if (data.readings && data.readings.length > 0) {
                    let html = '';
                    let prevReading = null;
                    // Readings are sorted DESC, so reverse for distance calculation
                    const readings = [...data.readings].reverse();
                    readings.forEach((reading, index) => {
                        const distance = prevReading ? (reading.reading_km - prevReading.reading_km) : '-';
                        prevReading = reading;
                    });
                    // Display in DESC order (most recent first)
                    prevReading = null;
                    data.readings.forEach((reading, index) => {
                        const nextReading = data.readings[index + 1];
                        const distance = nextReading ? (reading.reading_km - nextReading.reading_km).toLocaleString() + ' km' : '-';
                        html += `
                            <tr>
                                <td>${new Date(reading.reading_date).toLocaleDateString()}</td>
                                <td><strong>${parseInt(reading.reading_km).toLocaleString()}</strong> km</td>
                                <td>${distance}</td>
                                <td>${reading.notes || '<span class="text-muted">-</span>'}</td>
                                <td>
                                    <button class="btn btn-sm btn-outline-danger" onclick="deleteOdometerReading(${reading.reading_id})">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </td>
                            </tr>
                        `;
                    });
                    tbody.innerHTML = html;
                } else {
                    tbody.innerHTML = '<tr><td colspan="5" class="text-center text-muted">No odometer readings recorded yet.</td></tr>';
                }
            })
            .catch(error => {
                console.error('Error loading odometer readings:', error);
                document.getElementById('odometerReadings').innerHTML = '<tr><td colspan="5" class="text-center text-danger">Error loading readings</td></tr>';
            });
    }
    
    function deleteOdometerReading(readingId) {
        if (!confirm('Are you sure you want to delete this reading?')) return;
        
        fetch(odometerApiUrl + '?id=' + readingId, { method: 'DELETE' })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    loadOdometerReadings();
                } else {
                    alert('Error: ' + (data.error || 'Failed to delete reading'));
                }
            })
            .catch(error => {
                console.error('Error deleting reading:', error);
                alert('Error deleting reading');
            });
    }
    
    document.getElementById('saveOdometerBtn').addEventListener('click', function() {
        const form = document.getElementById('addOdometerForm');
        const readingDate = document.getElementById('reading_date').value;
        const readingKm = document.getElementById('reading_km').value;
        const notes = document.getElementById('odo_notes').value;
        
        if (!readingDate || !readingKm) {
            alert('Please fill in date and odometer reading');
            return;
        }
        
        fetch(odometerApiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                asset_id: assetId,
                reading_date: readingDate,
                reading_km: parseInt(readingKm),
                notes: notes
            })
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                bootstrap.Modal.getInstance(document.getElementById('addOdometerModal')).hide();
                form.reset();
                document.getElementById('reading_date').value = new Date().toISOString().split('T')[0];
                loadOdometerReadings();
            } else {
                alert('Error: ' + (data.error || 'Failed to save reading'));
            }
        })
        .catch(error => {
            console.error('Error saving reading:', error);
            alert('Error saving reading');
        });
    });
    
    // Load readings on page load
    loadOdometerReadings();
    <?php endif; ?>
    </script>

    <?php else: ?>
    <div class="alert alert-warning" role="alert">
        <i class="fas fa-exclamation-triangle me-2"></i>
        Asset not found or invalid asset ID.
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
