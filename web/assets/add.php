<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/duplicate_assets.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_login();
am_ensure_country_scope_from_session();
am_require_can_mutate();

$page_title = 'Add New Item';
$errors = [];
$success = false;

$countries = am_firestore_get_collection('pr_master_countries', 500);
$categories = am_firestore_get_collection('pr_master_categories', 1000);
$locations = am_get_pr_sites();

$countries = array_values(array_filter($countries, fn($c) => (int)($c['active'] ?? 1) === 1));
$countries = am_countries_for_user_select($countries);
$categories = array_values(array_filter($categories, fn($c) => (int)($c['active'] ?? 1) === 1));

$preselectedClass = $_GET['item_class'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemClass = trim($_POST['item_class'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $categoryId = trim($_POST['category_id'] ?? '');
    $countryId = trim($_POST['country_id'] ?? '');
    $locationId = trim($_POST['location_id'] ?? '');
    $serialNumber = trim($_POST['serial_number'] ?? '');
    $manufacturer = trim($_POST['manufacturer'] ?? '');
    $model = trim($_POST['model'] ?? '');
    $purchaseDate = trim($_POST['purchase_date'] ?? '');
    $purchasePrice = trim($_POST['purchase_price'] ?? '');
    $salvageValue = trim($_POST['salvage_value'] ?? '');
    $warrantyExpiry = trim($_POST['warranty_expiry'] ?? '');
    $conditionStatus = trim($_POST['condition_status'] ?? 'New');
    $quantity = (int)($_POST['quantity'] ?? 1);
    $unitOfMeasure = trim($_POST['unit_of_measure'] ?? 'EA');
    $legacyTag = trim($_POST['legacy_tag'] ?? '');
    $notes = trim($_POST['notes'] ?? '');
    $vehicleType = trim($_POST['vehicle_type'] ?? '');
    $vehicleYear = trim($_POST['vehicle_year'] ?? '');
    $engineNumber = trim($_POST['engine_number'] ?? '');
    $transmissionType = trim($_POST['transmission_type'] ?? '');
    $fuelType = trim($_POST['fuel_type'] ?? '');
    $driveType = trim($_POST['drive_type'] ?? '');

    if ($itemClass === '' || !in_array($itemClass, ['FixedAsset', 'Material', 'Consumable', 'Inventory'])) {
        $errors[] = 'Please select a valid item classification.';
    }
    if ($name === '') {
        $errors[] = 'Item name is required.';
    }
    if ($countryId === '') {
        $errors[] = 'Country is required.';
    }
    if ($countryId !== '' && !am_user_may_access_country_id($countryId, $countries)) {
        $errors[] = 'You cannot create items for that country.';
    }

    // Class-specific validation
    if ($itemClass === 'FixedAsset') {
        if ($categoryId === '') {
            $errors[] = 'Category is required for Fixed Assets.';
        }
    } elseif (in_array($itemClass, ['Material', 'Consumable', 'Inventory'])) {
        if ($categoryId === '') {
            $errors[] = 'Category is required.';
        }
        if ($unitOfMeasure === '') {
            $errors[] = 'Unit of measure is required.';
        }
        if ($quantity < 1) {
            $errors[] = 'Quantity must be at least 1.';
        }
    }

    if (empty($errors)) {
        $countryCode = '';
        foreach ($countries as $c) {
            if ((string)($c['country_id'] ?? $c['id'] ?? '') === $countryId) {
                $countryCode = (string)($c['country_code'] ?? 'UNK');
                break;
            }
        }

        $existingAssets = am_firestore_get_collection('am_core_assets', 10000);
        $assetTag = am_generate_unique_asset_tag($itemClass, $countryCode, $existingAssets);

        am_require_asset_country_mutate($countryId, $countries);

        $data = [
            'name' => $name,
            'description' => $description,
            'item_class' => $itemClass,
            'category_id' => $categoryId,
            'country_id' => $countryId,
            'location_id' => $locationId,
            'serial_number' => $serialNumber,
            'manufacturer' => $manufacturer,
            'model' => $model,
            'purchase_date' => $purchaseDate,
            'purchase_price' => $purchasePrice !== '' ? (float)$purchasePrice : null,
            'salvage_value' => $salvageValue !== '' ? (float)$salvageValue : null,
            'warranty_expiry' => $warrantyExpiry,
            'condition_status' => $conditionStatus,
            'status' => 'Available',
            'quantity' => max(1, $quantity),
            'unit_of_measure' => $unitOfMeasure,
            'legacy_tag' => $legacyTag,
            'notes' => $notes,
            'asset_tag' => $assetTag,
            'qr_code_id' => '',
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'created_by' => $_SESSION['user_id'] ?? '',
            // Vehicle-specific fields (only meaningful for vehicle categories)
            'vehicle_type'       => trim($_POST['vehicle_type'] ?? ''),
            'vehicle_year'       => $vehicleYear !== '' ? (int)$vehicleYear : null,
            'engine_number'      => trim($_POST['engine_number'] ?? ''),
            'transmission_type'  => trim($_POST['transmission_type'] ?? ''),
            'fuel_type'          => trim($_POST['fuel_type'] ?? ''),
            'drive_type'         => trim($_POST['drive_type'] ?? ''),
        ];

        $result = am_firestore_create_document('am_core_assets', $data);
        if ($result['ok']) {
            // Keep stock levels in sync for stockable classes on create.
            $newAssetId = (string)($result['id'] ?? '');
            if (
                $newAssetId !== '' &&
                in_array($itemClass, ['Material', 'Consumable', 'Inventory'], true) &&
                $locationId !== '' &&
                $countryId !== ''
            ) {
                $locByAnyKey = [];
                foreach ($locations as $loc) {
                    $lid = (string)($loc['location_id'] ?? $loc['id'] ?? '');
                    $lcode = (string)($loc['location_code'] ?? '');
                    if ($lid !== '') {
                        $locByAnyKey[$lid] = $loc;
                    }
                    if ($lcode !== '' && $lcode !== $lid) {
                        $locByAnyKey[$lcode] = $loc;
                    }
                }
                $resolved = $locByAnyKey[$locationId] ?? [];
                $canonicalLoc = (string)($resolved['location_code'] ?? $locationId);
                am_firestore_create_document('am_core_inventory_levels', [
                    'asset_id' => $newAssetId,
                    'location_id' => $canonicalLoc,
                    'country_id' => $countryId,
                    'quantity_on_hand' => max(0, $quantity),
                    'quantity_allocated' => 0,
                    'created_at' => date('c'),
                    'updated_at' => date('c'),
                ]);
            }
            $_SESSION['flash_success'] = 'Item "' . htmlspecialchars($name) . '" created with tag ' . $assetTag;
            header('Location: ' . base_url('assets/view.php?id=' . urlencode($result['id'])));
            exit;
        } else {
            $errors[] = 'Failed to save: ' . ($result['error'] ?? 'Unknown error');
        }
    }
}

$itemClassOptions = [
    'FixedAsset' => ['label' => 'Fixed Asset', 'icon' => 'fa-building', 'color' => 'primary',
        'hint' => 'PP&E with useful life >1 year, capitalized and depreciated (vehicles, equipment, IT)'],
    'Material' => ['label' => 'Material', 'icon' => 'fa-cubes', 'color' => 'warning',
        'hint' => 'Construction/installation inputs expensed to project (wire, poles, panels)'],
    'Consumable' => ['label' => 'Consumable', 'icon' => 'fa-recycle', 'color' => 'info',
        'hint' => 'Operational supplies expensed on use (PPE, office supplies, maintenance)'],
    'Inventory' => ['label' => 'Inventory', 'icon' => 'fa-boxes-stacked', 'color' => 'success',
        'hint' => 'Finished goods held for deployment or sale (meters, ready boards, spare parts)'],
];

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('assets/index.php'); ?>">Catalog</a></li>
                    <li class="breadcrumb-item active">Add New Item</li>
                </ol>
            </nav>
            <h1 class="h2 mt-2">Add New Item</h1>
        </div>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $err): ?>
            <li><?php echo htmlspecialchars($err); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
    <?php endif; ?>

    <!-- Search before you add -->
    <div class="card border-0 shadow mb-4" id="catalogSearchCard">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="fs-5 fw-bold mb-0">Search catalog first</h2>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="catalogSearchToggle" aria-expanded="true">
                <i class="fas fa-chevron-up me-1"></i><span>Hide</span>
            </button>
        </div>
        <div class="card-body" id="catalogSearchBody">
            <p class="text-gray-600 mb-3">Before adding a new item, search the catalog to avoid duplicates. If you find a match, open it and edit its quantity or location instead of creating a new record.</p>
            <div class="row g-3 mb-2">
                <div class="col-md-8">
                    <label class="form-label">Search catalog</label>
                    <div class="input-group">
                        <span class="input-group-text"><i class="fas fa-search"></i></span>
                        <input type="text" id="catalogSearchInput" class="form-control" placeholder="Name, tag, manufacturer, model, notes, category…" autocomplete="off">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Filter by class</label>
                    <select id="catalogSearchClass" class="form-select">
                        <option value="">All classes</option>
                        <option value="Material">Material</option>
                        <option value="Consumable">Consumable</option>
                        <option value="Inventory">Inventory</option>
                        <option value="FixedAsset">Fixed Asset</option>
                    </select>
                </div>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" id="catalogSearchTable">
                    <thead>
                        <tr><th>Name</th><th>Tag</th><th>Class</th><th>Category</th><th>Location</th><th>On hand</th><th></th></tr>
                    </thead>
                    <tbody id="catalogSearchResults">
                        <tr><td colspan="7" class="text-center text-gray-500 py-3">Type at least 2 characters to search the catalog.</td></tr>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <form method="POST" action="" id="addItemForm">
        <!-- Step 1: Classification -->
        <div class="card border-0 shadow mb-4">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">1. Item Classification</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <?php foreach ($itemClassOptions as $classKey => $cfg): ?>
                    <div class="col-12 col-md-3">
                        <input type="radio" class="btn-check" name="item_class" id="class_<?php echo $classKey; ?>" value="<?php echo $classKey; ?>"
                            <?php echo ($preselectedClass === $classKey || ($_POST['item_class'] ?? '') === $classKey) ? 'checked' : ''; ?>
                            onchange="onClassChange()">
                        <label class="btn btn-outline-<?php echo $cfg['color']; ?> w-100 py-3 text-start" for="class_<?php echo $classKey; ?>">
                            <i class="fas <?php echo $cfg['icon']; ?> fa-lg me-2"></i>
                            <strong><?php echo $cfg['label']; ?></strong>
                            <br><small class="text-muted"><?php echo $cfg['hint']; ?></small>
                        </label>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- Step 2: Core Details -->
        <div class="card border-0 shadow mb-4">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">2. Item Details</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" id="itemNameInput" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
                        <div id="similarNameHint" class="form-text" style="display:none;"></div>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Legacy ID</label>
                        <input type="text" class="form-control" name="legacy_tag" value="<?php echo htmlspecialchars($_POST['legacy_tag'] ?? ''); ?>" placeholder="Old system UID">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category_id" id="categorySelect">
                            <option value="">Select category...</option>
                            <?php foreach ($categories as $cat):
                                $catId = (string)($cat['category_id'] ?? $cat['id'] ?? '');
                                $catClass = (string)($cat['item_class'] ?? '');
                                $catName = (string)($cat['category_name'] ?? '');
                            ?>
                            <option value="<?php echo htmlspecialchars($catId); ?>"
                                    data-item-class="<?php echo htmlspecialchars($catClass); ?>"
                                    <?php echo ($_POST['category_id'] ?? '') === $catId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($catName); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Country <span class="text-danger">*</span></label>
                        <select class="form-select" name="country_id" required>
                            <option value="">Select country...</option>
                            <?php foreach ($countries as $c):
                                $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
                            ?>
                            <option value="<?php echo htmlspecialchars($cid); ?>"
                                    <?php echo ($_POST['country_id'] ?? '') === $cid ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['country_name'] ?? ''); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Location</label>
                        <select class="form-select" name="location_id">
                            <option value="">Select location...</option>
                            <?php foreach ($locations as $loc):
                                $lid = (string)($loc['location_id'] ?? $loc['id'] ?? '');
                            ?>
                            <option value="<?php echo htmlspecialchars($lid); ?>"
                                    <?php echo ($_POST['location_id'] ?? '') === $lid ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($loc['location_name'] ?? '') . ($loc['location_code'] ? ' (' . $loc['location_code'] . ')' : '')); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Condition</label>
                        <select class="form-select" name="condition_status">
                            <?php foreach (['New', 'Good', 'Fair', 'Poor', 'Damaged'] as $cs): ?>
                            <option value="<?php echo $cs; ?>" <?php echo ($_POST['condition_status'] ?? 'New') === $cs ? 'selected' : ''; ?>><?php echo $cs; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3: Class-specific fields -->
        <div class="card border-0 shadow mb-4" id="fixedAssetFields" style="display:none;">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">3. Fixed Asset Details</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Serial Number</label>
                        <input type="text" class="form-control" name="serial_number" value="<?php echo htmlspecialchars($_POST['serial_number'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Manufacturer</label>
                        <input type="text" class="form-control" name="manufacturer" value="<?php echo htmlspecialchars($_POST['manufacturer'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Model</label>
                        <input type="text" class="form-control" name="model" value="<?php echo htmlspecialchars($_POST['model'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" class="form-control" name="purchase_date" value="<?php echo htmlspecialchars($_POST['purchase_date'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Purchase Price</label>
                        <input type="number" class="form-control" name="purchase_price" step="0.01" value="<?php echo htmlspecialchars($_POST['purchase_price'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Salvage Value</label>
                        <input type="number" class="form-control" name="salvage_value" step="0.01" value="<?php echo htmlspecialchars($_POST['salvage_value'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Warranty Expiry</label>
                        <input type="date" class="form-control" name="warranty_expiry" value="<?php echo htmlspecialchars($_POST['warranty_expiry'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Step 3b: Vehicle-specific fields (shown when a vehicle category is selected) -->
        <div class="card border-0 shadow mb-4" id="vehicleFields" style="display:none;">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">3b. Vehicle Details</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label">Vehicle Type</label>
                        <select class="form-select" name="vehicle_type">
                            <option value="">Select…</option>
                            <option value="4x4" <?php echo ($_POST['vehicle_type'] ?? '') === '4x4' ? 'selected' : ''; ?>>4x4 / SUV</option>
                            <option value="truck" <?php echo ($_POST['vehicle_type'] ?? '') === 'truck' ? 'selected' : ''; ?>>Truck</option>
                            <option value="trailer" <?php echo ($_POST['vehicle_type'] ?? '') === 'trailer' ? 'selected' : ''; ?>>Trailer</option>
                            <option value="equipment" <?php echo ($_POST['vehicle_type'] ?? '') === 'equipment' ? 'selected' : ''; ?>>Equipment</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Year</label>
                        <input type="number" class="form-control" name="vehicle_year" min="1980" max="2099" value="<?php echo htmlspecialchars($_POST['vehicle_year'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Engine Number</label>
                        <input type="text" class="form-control" name="engine_number" value="<?php echo htmlspecialchars($_POST['engine_number'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Transmission</label>
                        <select class="form-select" name="transmission_type">
                            <option value="">—</option>
                            <option value="MT" <?php echo ($_POST['transmission_type'] ?? '') === 'MT' ? 'selected' : ''; ?>>Manual (MT)</option>
                            <option value="AT" <?php echo ($_POST['transmission_type'] ?? '') === 'AT' ? 'selected' : ''; ?>>Automatic (AT)</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Fuel Type</label>
                        <select class="form-select" name="fuel_type">
                            <option value="">—</option>
                            <option value="Petrol" <?php echo ($_POST['fuel_type'] ?? '') === 'Petrol' ? 'selected' : ''; ?>>Petrol</option>
                            <option value="Diesel" <?php echo ($_POST['fuel_type'] ?? '') === 'Diesel' ? 'selected' : ''; ?>>Diesel</option>
                        </select>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Drive Type</label>
                        <select class="form-select" name="drive_type">
                            <option value="">—</option>
                            <option value="2WD" <?php echo ($_POST['drive_type'] ?? '') === '2WD' ? 'selected' : ''; ?>>2WD</option>
                            <option value="4WD" <?php echo ($_POST['drive_type'] ?? '') === '4WD' ? 'selected' : ''; ?>>4WD</option>
                            <option value="6WD" <?php echo ($_POST['drive_type'] ?? '') === '6WD' ? 'selected' : ''; ?>>6WD</option>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <div class="card border-0 shadow mb-4" id="quantityFields" style="display:none;">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">3. Quantity & Units</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" class="form-control" name="quantity" min="1" value="<?php echo htmlspecialchars($_POST['quantity'] ?? '1'); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Unit of Measure</label>
                        <select class="form-select" name="unit_of_measure">
                            <?php foreach (['EA' => 'Each (EA)', 'M' => 'Meters (M)', 'KG' => 'Kilograms (KG)', 'L' => 'Liters (L)', 'BOX' => 'Box', 'ROLL' => 'Roll', 'SET' => 'Set'] as $uom => $label): ?>
                            <option value="<?php echo $uom; ?>" <?php echo ($_POST['unit_of_measure'] ?? 'EA') === $uom ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Unit Cost</label>
                        <input type="number" class="form-control" name="purchase_price" step="0.01" value="<?php echo htmlspecialchars($_POST['purchase_price'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="card border-0 shadow mb-4">
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12">
                        <label class="form-label">Notes</label>
                        <textarea class="form-control" name="notes" rows="2"><?php echo htmlspecialchars($_POST['notes'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-2"></i>Create Item
            </button>
            <a href="<?php echo base_url('assets/index.php'); ?>" class="btn btn-gray-200">Cancel</a>
        </div>
    </form>
</div>

<script>
function onClassChange() {
    var selected = document.querySelector('input[name="item_class"]:checked');
    var cls = selected ? selected.value : '';

    document.getElementById('fixedAssetFields').style.display = cls === 'FixedAsset' ? '' : 'none';
    document.getElementById('quantityFields').style.display = (cls && cls !== 'FixedAsset') ? '' : 'none';

    var catSelect = document.getElementById('categorySelect');
    var options = catSelect.querySelectorAll('option[data-item-class]');
    options.forEach(function(opt) {
        opt.style.display = (!cls || opt.dataset.itemClass === cls) ? '' : 'none';
        if (opt.style.display === 'none' && opt.selected) {
            opt.selected = false;
            catSelect.value = '';
        }
    });
    updateVehicleFields();

    // Keep the search filter in sync with the selected class.
    var searchClass = document.getElementById('catalogSearchClass');
    if (searchClass) {
        searchClass.value = cls || '';
        runCatalogSearch();
    }
}
function updateVehicleFields() {
    var catVal = document.getElementById('categorySelect').value || '';
    var isVehicle = /^FA-VEH/.test(catVal);
    document.getElementById('vehicleFields').style.display = isVehicle ? '' : 'none';
}
document.addEventListener('DOMContentLoaded', function() {
    onClassChange();
    document.getElementById('categorySelect').addEventListener('change', updateVehicleFields);

    // Pre-fill search box from ?q= and trigger an initial search.
    var params = new URLSearchParams(window.location.search);
    var initialQ = params.get('q') || '';
    var searchInput = document.getElementById('catalogSearchInput');
    if (initialQ && searchInput) {
        searchInput.value = initialQ;
        runCatalogSearch();
    }
});

// ── Catalog search before add ───────────────────────────────────
var catalogSearchApiUrl = <?php echo json_encode(base_url('api/assets/search.php')); ?>;
var catalogSearchTimer = null;

function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function runCatalogSearch() {
    var input = document.getElementById('catalogSearchInput');
    var cls = document.getElementById('catalogSearchClass').value;
    var tbody = document.getElementById('catalogSearchResults');
    if (!input || !tbody) return;

    var q = input.value.trim();
    if (q.length < 2) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-gray-500 py-3">Type at least 2 characters to search the catalog.</td></tr>';
        return;
    }

    var url = catalogSearchApiUrl + '?q=' + encodeURIComponent(q) + '&limit=20';
    if (cls) url += '&item_class=' + encodeURIComponent(cls);

    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-gray-500 py-3">Searching…</td></tr>';

    fetch(url, { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data || !data.ok || !data.items || data.items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-gray-500 py-3">No matching items found. You can add a new one below.</td></tr>';
                return;
            }
            tbody.innerHTML = '';
            data.items.forEach(function(item) {
                var tr = document.createElement('tr');
                if (item.strong_match) {
                    tr.className = 'table-warning';
                }
                var onHand = item.quantity_on_hand !== undefined ? item.quantity_on_hand : item.quantity;
                tr.innerHTML =
                    '<td><strong>' + escapeHtml(item.name) + '</strong>'
                        + (item.strong_match ? ' <span class="badge bg-warning text-dark ms-1">Similar</span>' : '')
                        + '</td>'
                    + '<td><code class="text-muted">' + escapeHtml(item.asset_tag || '—') + '</code></td>'
                    + '<td><span class="badge bg-secondary">' + escapeHtml(item.item_class) + '</span></td>'
                    + '<td>' + escapeHtml(item.category_name || '—') + '</td>'
                    + '<td>' + escapeHtml(item.location_name || '—') + '</td>'
                    + '<td>' + (onHand !== null ? onHand : '—') + '</td>'
                    + '<td class="text-end">'
                        + '<a class="btn btn-sm btn-outline-secondary" href="' + escapeHtml(item.view_url) + '">View</a> '
                        + '<a class="btn btn-sm btn-outline-primary" href="' + escapeHtml(item.edit_url) + '">Edit</a>'
                        + '</td>';
                tbody.appendChild(tr);
            });
        })
        .catch(function() {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Search failed. Try again.</td></tr>';
        });
}

document.addEventListener('DOMContentLoaded', function() {
    var input = document.getElementById('catalogSearchInput');
    var cls = document.getElementById('catalogSearchClass');
    var toggle = document.getElementById('catalogSearchToggle');
    var body = document.getElementById('catalogSearchBody');

    if (input) {
        input.addEventListener('input', function() {
            if (catalogSearchTimer) clearTimeout(catalogSearchTimer);
            catalogSearchTimer = setTimeout(runCatalogSearch, 250);
        });
    }

    var nameInput = document.getElementById('itemNameInput');
    var nameHint = document.getElementById('similarNameHint');
    var nameTimer = null;
    if (nameInput && nameHint) {
        nameInput.addEventListener('input', function() {
            var v = nameInput.value.trim();
            if (nameTimer) clearTimeout(nameTimer);
            if (v.length < 3) {
                nameHint.style.display = 'none';
                nameHint.innerHTML = '';
                return;
            }
            nameTimer = setTimeout(function() {
                var cls = document.querySelector('input[name="item_class"]:checked');
                var clsVal = cls ? cls.value : '';
                var url = catalogSearchApiUrl + '?q=' + encodeURIComponent(v) + '&limit=5';
                if (clsVal) url += '&item_class=' + encodeURIComponent(clsVal);
                fetch(url, { credentials: 'same-origin' })
                    .then(function(r) { return r.json(); })
                    .then(function(data) {
                        var matches = (data && data.ok && data.items || []).filter(function(it) { return it.strong_match; });
                        if (matches.length === 0) {
                            nameHint.style.display = 'none';
                            nameHint.innerHTML = '';
                            return;
                        }
                        var links = matches.map(function(m) {
                            return '<a href="' + escapeHtml(m.view_url) + '" target="_blank">' + escapeHtml(m.name) + ' (' + escapeHtml(m.asset_tag) + ')</a>';
                        });
                        nameHint.style.display = '';
                        nameHint.className = 'form-text text-warning';
                        nameHint.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i>Similar item'
                            + (matches.length > 1 ? 's' : '')
                            + ' already in catalog: ' + links.join(', ') + '. Consider editing that record instead.';
                    })
                    .catch(function() {
                        nameHint.style.display = 'none';
                        nameHint.innerHTML = '';
                    });
            }, 400);
        });
    }
    if (cls) {
        cls.addEventListener('change', runCatalogSearch);
    }
    if (toggle && body) {
        toggle.addEventListener('click', function() {
            var expanded = body.style.display !== 'none';
            body.style.display = expanded ? 'none' : '';
            toggle.querySelector('span').textContent = expanded ? 'Show' : 'Hide';
            toggle.querySelector('i').className = expanded ? 'fas fa-chevron-down me-1' : 'fas fa-chevron-up me-1';
        });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
