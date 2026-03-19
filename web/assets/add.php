<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

$page_title = 'Add New Item';
$errors = [];
$success = false;

$countries = am_firestore_get_collection('pr_master_countries', 500);
$categories = am_firestore_get_collection('pr_master_categories', 1000);
$locations = am_firestore_get_collection('pr_master_locations', 2000);

$countries = array_values(array_filter($countries, fn($c) => (int)($c['active'] ?? 1) === 1));
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
    $notes = trim($_POST['notes'] ?? '');

    if ($itemClass === '' || !in_array($itemClass, ['FixedAsset', 'Material', 'Consumable', 'Inventory'])) {
        $errors[] = 'Please select a valid item classification.';
    }
    if ($name === '') {
        $errors[] = 'Item name is required.';
    }
    if ($countryId === '') {
        $errors[] = 'Country is required.';
    }

    if (empty($errors)) {
        $countryCode = '';
        foreach ($countries as $c) {
            if ((string)($c['country_id'] ?? $c['id'] ?? '') === $countryId) {
                $countryCode = (string)($c['country_code'] ?? 'UNK');
                break;
            }
        }

        $existingAssets = am_firestore_get_collection('am_core_assets', 2000);
        $assetTag = am_generate_asset_tag($itemClass, $countryCode, $existingAssets);

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
            'notes' => $notes,
            'asset_tag' => $assetTag,
            'qr_code_id' => '',
            'created_at' => date('c'),
            'updated_at' => date('c'),
            'created_by' => $_SESSION['user_id'] ?? '',
        ];

        $result = am_firestore_create_document('am_core_assets', $data);
        if ($result['ok']) {
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
                    <div class="col-12 col-md-6">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" required>
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
}
document.addEventListener('DOMContentLoaded', onClassChange);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
