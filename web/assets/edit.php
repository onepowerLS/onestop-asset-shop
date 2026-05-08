<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/duplicate_assets.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_login();
am_ensure_country_scope_from_session();
am_require_can_mutate();

$assetId = $_GET['id'] ?? '';
if ($assetId === '') {
    header('Location: ' . base_url('assets/index.php'));
    exit;
}

$asset = am_firestore_get_document('am_core_assets', $assetId);
if (!$asset) {
    $_SESSION['flash_error'] = 'Item not found.';
    header('Location: ' . base_url('assets/index.php'));
    exit;
}

$countries = am_firestore_get_collection('pr_master_countries', 500);
am_require_asset_visible($asset, $countries);

$page_title = 'Edit: ' . ($asset['name'] ?? 'Item');
$errors = [];

$categories = am_firestore_get_collection('pr_master_categories', 1000);
$locations = am_get_pr_sites();
$countries = array_values(array_filter($countries, fn($c) => (int)($c['active'] ?? 1) === 1));
$countries = am_countries_for_user_select($countries);
$categories = array_values(array_filter($categories, fn($c) => (int)($c['active'] ?? 1) === 1));

$vals = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $asset;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $itemClass = trim($_POST['item_class'] ?? '');
    $name = trim($_POST['name'] ?? '');
    $countryId = trim($_POST['country_id'] ?? '');

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
        $errors[] = 'You cannot assign items to that country.';
    }

    if (empty($errors)) {
        $purchasePrice = trim($_POST['purchase_price'] ?? '');
        $salvageValue = trim($_POST['salvage_value'] ?? '');

        $assetTag = trim($_POST['asset_tag'] ?? (string)($asset['asset_tag'] ?? ''));
        $qrCodeId = trim($_POST['qr_code_id'] ?? (string)($asset['qr_code_id'] ?? ''));
        if ($assetTag === '') {
            $errors[] = 'Asset tag cannot be empty.';
        } else {
            $peerAssets = am_firestore_get_collection('am_core_assets', 8000);
            $errTag = am_duplicate_uid_field_unique_among_assets($assetId, 'asset_tag', $assetTag, $peerAssets);
            if ($errTag !== null) {
                $errors[] = $errTag;
            }
            $errQr = am_duplicate_uid_field_unique_among_assets($assetId, 'qr_code_id', $qrCodeId, $peerAssets);
            if ($errQr !== null) {
                $errors[] = $errQr;
            }
        }

        $data = [
            'name' => $name,
            'description' => trim($_POST['description'] ?? ''),
            'item_class' => $itemClass,
            'category_id' => trim($_POST['category_id'] ?? ''),
            'country_id' => $countryId,
            'location_id' => trim($_POST['location_id'] ?? ''),
            'serial_number' => trim($_POST['serial_number'] ?? ''),
            'manufacturer' => trim($_POST['manufacturer'] ?? ''),
            'model' => trim($_POST['model'] ?? ''),
            'purchase_date' => trim($_POST['purchase_date'] ?? ''),
            'purchase_price' => $purchasePrice !== '' ? (float)$purchasePrice : null,
            'salvage_value' => $salvageValue !== '' ? (float)$salvageValue : null,
            'warranty_expiry' => trim($_POST['warranty_expiry'] ?? ''),
            'condition_status' => trim($_POST['condition_status'] ?? 'Good'),
            'status' => trim($_POST['status'] ?? ($asset['status'] ?? 'Available')),
            'quantity' => max(1, (int)($_POST['quantity'] ?? 1)),
            'unit_of_measure' => trim($_POST['unit_of_measure'] ?? 'EA'),
            'asset_tag' => $assetTag,
            'qr_code_id' => $qrCodeId,
            'legacy_tag' => trim($_POST['legacy_tag'] ?? ($asset['legacy_tag'] ?? '')),
            'notes' => trim($_POST['notes'] ?? ''),
            'updated_at' => date('c'),
        ];

        am_require_asset_country_mutate($countryId, $countries);

        if (empty($errors)) {
            $result = am_firestore_update_document('am_core_assets', $assetId, $data);
            if ($result['ok']) {
                $_SESSION['flash_success'] = 'Item updated successfully.';
                header('Location: ' . base_url('assets/view.php?id=' . urlencode($assetId)));
                exit;
            }
            $errors[] = 'Failed to save: ' . ($result['error'] ?? 'Unknown error');
        }
    }
}

$cls = (string)($vals['item_class'] ?? '');

$statusOptions = ['Available', 'Allocated', 'CheckedOut', 'InProject', 'Consumed', 'Deployed', 'Missing', 'WrittenOff', 'Retired'];
$itemClassOptions = [
    'FixedAsset' => ['label' => 'Fixed Asset', 'icon' => 'fa-building', 'color' => 'primary'],
    'Material' => ['label' => 'Material', 'icon' => 'fa-cubes', 'color' => 'warning'],
    'Consumable' => ['label' => 'Consumable', 'icon' => 'fa-recycle', 'color' => 'info'],
    'Inventory' => ['label' => 'Inventory', 'icon' => 'fa-boxes-stacked', 'color' => 'success'],
];

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center py-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('assets/index.php'); ?>">Catalog</a></li>
                    <li class="breadcrumb-item"><a href="<?php echo base_url('assets/view.php?id=' . urlencode($assetId)); ?>"><?php echo htmlspecialchars($asset['asset_tag'] ?? $assetId); ?></a></li>
                    <li class="breadcrumb-item active">Edit</li>
                </ol>
            </nav>
            <h1 class="h2 mt-2">Edit: <?php echo htmlspecialchars($asset['name'] ?? ''); ?></h1>
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

    <form method="POST" action="" id="editItemForm">
        <!-- Unique identifiers (fix false-positive duplicate groups) -->
        <div class="card border-0 shadow mb-4">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Identifiers &amp; tags</h2></div>
            <div class="card-body">
                <p class="small text-gray-600 mb-3">
                    <strong>Asset tag</strong> and <strong>QR id</strong> must each be unique in the catalog (case-insensitive).
                    If duplicate review grouped different physical items, give each item its own tag here, or use <strong>Mark as not duplicate</strong> on the review page.
                </p>
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Document ID</label>
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($assetId); ?>" readonly>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Asset tag <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="asset_tag" required
                            value="<?php echo htmlspecialchars((string)($vals['asset_tag'] ?? '')); ?>"
                            autocomplete="off" spellcheck="false">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">QR code id</label>
                        <input type="text" class="form-control" name="qr_code_id"
                            value="<?php echo htmlspecialchars((string)($vals['qr_code_id'] ?? '')); ?>"
                            placeholder="Optional; must be unique if set" autocomplete="off" spellcheck="false">
                    </div>
                </div>
            </div>
        </div>

        <!-- Classification & Status -->
        <div class="card border-0 shadow mb-4">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Classification & Status</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-8">
                        <label class="form-label">Classification</label>
                        <div class="d-flex gap-2 flex-wrap">
                            <?php foreach ($itemClassOptions as $classKey => $cfg): ?>
                            <div>
                                <input type="radio" class="btn-check" name="item_class" id="class_<?php echo $classKey; ?>" value="<?php echo $classKey; ?>"
                                    <?php echo $cls === $classKey ? 'checked' : ''; ?> onchange="onClassChange()">
                                <label class="btn btn-outline-<?php echo $cfg['color']; ?>" for="class_<?php echo $classKey; ?>">
                                    <i class="fas <?php echo $cfg['icon']; ?> me-1"></i><?php echo $cfg['label']; ?>
                                </label>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Status</label>
                        <select class="form-select" name="status">
                            <?php foreach ($statusOptions as $s): ?>
                            <option value="<?php echo $s; ?>" <?php echo (string)($vals['status'] ?? '') === $s ? 'selected' : ''; ?>><?php echo $s; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Core Details -->
        <div class="card border-0 shadow mb-4">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Item Details</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" name="name" value="<?php echo htmlspecialchars($vals['name'] ?? ''); ?>" required>
                    </div>
                    <div class="col-12 col-md-2">
                        <label class="form-label">Legacy ID</label>
                        <input type="text" class="form-control" name="legacy_tag" value="<?php echo htmlspecialchars($vals['legacy_tag'] ?? ''); ?>" placeholder="Old system UID">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Category</label>
                        <select class="form-select" name="category_id" id="categorySelect">
                            <option value="">Select category...</option>
                            <?php foreach ($categories as $cat):
                                $catId = (string)($cat['category_id'] ?? $cat['id'] ?? '');
                                $catClass = (string)($cat['item_class'] ?? '');
                            ?>
                            <option value="<?php echo htmlspecialchars($catId); ?>"
                                    data-item-class="<?php echo htmlspecialchars($catClass); ?>"
                                    <?php echo (string)($vals['category_id'] ?? '') === $catId ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($cat['category_name'] ?? ''); ?>
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
                                    <?php echo (string)($vals['country_id'] ?? '') === $cid ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($c['country_name'] ?? ''); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-6">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="3"><?php echo htmlspecialchars($vals['description'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Location</label>
                        <select class="form-select" name="location_id">
                            <option value="">Select location...</option>
                            <?php foreach ($locations as $loc):
                                $lid = (string)($loc['location_id'] ?? $loc['id'] ?? '');
                            ?>
                            <option value="<?php echo htmlspecialchars($lid); ?>"
                                    <?php echo (string)($vals['location_id'] ?? '') === $lid ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($loc['location_name'] ?? ''); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Condition</label>
                        <select class="form-select" name="condition_status">
                            <?php foreach (['New', 'Good', 'Fair', 'Poor', 'Damaged', 'Retired'] as $cs): ?>
                            <option value="<?php echo $cs; ?>" <?php echo (string)($vals['condition_status'] ?? '') === $cs ? 'selected' : ''; ?>><?php echo $cs; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>
        </div>

        <!-- Fixed Asset fields -->
        <div class="card border-0 shadow mb-4" id="fixedAssetFields" style="display:none;">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Fixed Asset Details</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label class="form-label">Serial Number</label>
                        <input type="text" class="form-control" name="serial_number" value="<?php echo htmlspecialchars($vals['serial_number'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Manufacturer</label>
                        <input type="text" class="form-control" name="manufacturer" value="<?php echo htmlspecialchars($vals['manufacturer'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-4">
                        <label class="form-label">Model</label>
                        <input type="text" class="form-control" name="model" value="<?php echo htmlspecialchars($vals['model'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Purchase Date</label>
                        <input type="date" class="form-control" name="purchase_date" value="<?php echo htmlspecialchars($vals['purchase_date'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Purchase Price</label>
                        <input type="number" class="form-control" name="purchase_price" step="0.01" value="<?php echo htmlspecialchars($vals['purchase_price'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Salvage Value</label>
                        <input type="number" class="form-control" name="salvage_value" step="0.01" value="<?php echo htmlspecialchars($vals['salvage_value'] ?? ''); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Warranty Expiry</label>
                        <input type="date" class="form-control" name="warranty_expiry" value="<?php echo htmlspecialchars($vals['warranty_expiry'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Quantity fields -->
        <div class="card border-0 shadow mb-4" id="quantityFields" style="display:none;">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Quantity & Units</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-12 col-md-3">
                        <label class="form-label">Quantity</label>
                        <input type="number" class="form-control" name="quantity" min="1" value="<?php echo htmlspecialchars($vals['quantity'] ?? '1'); ?>">
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Unit of Measure</label>
                        <select class="form-select" name="unit_of_measure">
                            <?php foreach (['EA' => 'Each (EA)', 'M' => 'Meters (M)', 'KG' => 'Kilograms (KG)', 'L' => 'Liters (L)', 'BOX' => 'Box', 'ROLL' => 'Roll', 'SET' => 'Set'] as $uom => $label): ?>
                            <option value="<?php echo $uom; ?>" <?php echo (string)($vals['unit_of_measure'] ?? 'EA') === $uom ? 'selected' : ''; ?>><?php echo $label; ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-12 col-md-3">
                        <label class="form-label">Unit Cost</label>
                        <input type="number" class="form-control" name="purchase_price" step="0.01" value="<?php echo htmlspecialchars($vals['purchase_price'] ?? ''); ?>">
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="card border-0 shadow mb-4">
            <div class="card-body">
                <label class="form-label">Notes</label>
                <textarea class="form-control" name="notes" rows="2"><?php echo htmlspecialchars($vals['notes'] ?? ''); ?></textarea>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i>Save Changes</button>
            <a href="<?php echo base_url('assets/view.php?id=' . urlencode($assetId)); ?>" class="btn btn-gray-200">Cancel</a>
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
    catSelect.querySelectorAll('option[data-item-class]').forEach(function(opt) {
        opt.style.display = (!cls || opt.dataset.itemClass === cls) ? '' : 'none';
        if (opt.style.display === 'none' && opt.selected) { opt.selected = false; catSelect.value = ''; }
    });
}
document.addEventListener('DOMContentLoaded', onClassChange);
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
