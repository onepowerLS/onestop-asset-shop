<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['flash_error'] = 'Admin access required.';
    header('Location: ' . base_url('index.php'));
    exit;
}

$page_title = 'Manage Categories';
$errors = [];
$editId = $_GET['edit'] ?? '';

$categories = am_firestore_get_collection('pr_master_categories', 1000);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $catId = trim($_POST['category_doc_id'] ?? '');
    $catCode = trim($_POST['category_code'] ?? '');
    $catName = trim($_POST['category_name'] ?? '');
    $itemClass = trim($_POST['item_class'] ?? '');
    $deptScope = trim($_POST['department_scope'] ?? 'All');
    $description = trim($_POST['description'] ?? '');
    $usefulLife = trim($_POST['useful_life_years'] ?? '');
    $depMethod = trim($_POST['depreciation_method'] ?? 'None');
    $reorder = isset($_POST['reorder_enabled']) ? 1 : 0;

    if ($catName === '') $errors[] = 'Category name is required.';
    if ($catCode === '') $errors[] = 'Category code is required.';
    if (!in_array($itemClass, ['FixedAsset', 'Material', 'Consumable', 'Inventory'])) $errors[] = 'Valid item class is required.';

    if (empty($errors)) {
        $data = [
            'category_code' => $catCode,
            'category_name' => $catName,
            'item_class' => $itemClass,
            'department_scope' => $deptScope,
            'description' => $description,
            'useful_life_years' => $usefulLife !== '' ? (int)$usefulLife : null,
            'depreciation_method' => $depMethod,
            'reorder_enabled' => $reorder,
            'active' => 1,
        ];

        if ($action === 'update' && $catId !== '') {
            $result = am_firestore_update_document('pr_master_categories', $catId, $data);
        } else {
            $result = am_firestore_create_document('pr_master_categories', $data);
        }

        if ($result['ok']) {
            $_SESSION['flash_success'] = $action === 'update' ? 'Category updated.' : 'Category created.';
            header('Location: ' . base_url('admin/categories.php'));
            exit;
        } else {
            $errors[] = $result['error'] ?? 'Save failed.';
        }
    }
}

if ($_GET['delete'] ?? '') {
    $result = am_firestore_delete_document('pr_master_categories', $_GET['delete']);
    $_SESSION['flash_success'] = $result['ok'] ? 'Category deleted.' : 'Delete failed.';
    header('Location: ' . base_url('admin/categories.php'));
    exit;
}

// Seed additional consumable categories (idempotent — skips existing codes).
if (($_GET['seed_consumables'] ?? '') === '1' && ($_SESSION['role'] ?? '') === 'Admin') {
    $newCategories = [
        ['code' => 'CON-MNT', 'name' => 'Maintenance Supplies', 'dept' => 'O&M', 'desc' => 'Lubricants, fasteners, tape, cable ties, sealant, adhesives'],
        ['code' => 'CON-LUB', 'name' => 'Lubricants & Fluids', 'dept' => 'O&M', 'desc' => 'Engine oil, grease, hydraulic fluid, coolant, brake fluid'],
        ['code' => 'CON-FUE', 'name' => 'Fuel', 'dept' => 'General', 'desc' => 'Petrol, diesel, gas for generators and vehicles'],
        ['code' => 'CON-PRN', 'name' => 'Print & Stationery', 'dept' => 'General', 'desc' => 'Paper, toner, ink, pens, notebooks, printer consumables'],
        ['code' => 'CON-ITC', 'name' => 'IT Consumables', 'dept' => 'General', 'desc' => 'Toner, ink, cables, adapters, storage media, batteries'],
        ['code' => 'CON-MSC', 'name' => 'Miscellaneous Consumables', 'dept' => 'General', 'desc' => 'General consumables not covered by other categories'],
        ['code' => 'CON-SAF', 'name' => 'Safety & First Aid', 'dept' => 'General', 'desc' => 'First aid kits, fire extinguisher refills, signage, spotters'],
        ['code' => 'CON-FOD', 'name' => 'Food & Catering', 'dept' => 'General', 'desc' => 'Site rations, water, catering supplies for field work'],
    ];
    $existingByCode = [];
    foreach ($categories as $cat) {
        $code = strtoupper(trim((string)($cat['category_code'] ?? '')));
        if ($code !== '') {
            $existingByCode[$code] = $cat;
        }
    }
    $created = 0;
    $skipped = 0;
    foreach ($newCategories as $new) {
        $code = strtoupper($new['code']);
        if (isset($existingByCode[$code])) {
            $skipped++;
            continue;
        }
        $data = [
            'category_code' => $new['code'],
            'category_name' => $new['name'],
            'item_class' => 'Consumable',
            'department_scope' => $new['dept'],
            'description' => $new['desc'],
            'useful_life_years' => null,
            'depreciation_method' => 'None',
            'reorder_enabled' => 1,
            'active' => 1,
            'created_at' => date('c'),
        ];
        $r = am_firestore_create_document('pr_master_categories', $data);
        if ($r['ok']) {
            $created++;
        }
    }
    $_SESSION['flash_success'] = "Seeded consumable categories: {$created} created, {$skipped} already existed.";
    header('Location: ' . base_url('admin/categories.php'));
    exit;
}

$editCategory = null;
if ($editId) {
    foreach ($categories as $c) {
        if ((string)($c['id'] ?? '') === $editId) {
            $editCategory = $c;
            break;
        }
    }
}

$grouped = ['FixedAsset' => [], 'Material' => [], 'Consumable' => [], 'Inventory' => []];
foreach ($categories as $c) {
    $cls = (string)($c['item_class'] ?? '');
    if (isset($grouped[$cls])) {
        $grouped[$cls][] = $c;
    }
}

$classLabels = ['FixedAsset' => 'Fixed Assets', 'Material' => 'Materials', 'Consumable' => 'Consumables', 'Inventory' => 'Inventory'];
$classColors = ['FixedAsset' => 'primary', 'Material' => 'warning', 'Consumable' => 'info', 'Inventory' => 'success'];

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center py-4">
        <h1 class="h2">Manage Categories</h1>
        <a href="<?php echo base_url('admin/categories.php?seed_consumables=1'); ?>" class="btn btn-outline-success btn-sm"
           onclick="return confirm('Add missing consumable categories (Maintenance, Lubricants, Fuel, Print, IT, Safety, Food, Misc)? Existing codes are skipped.')">
            <i class="fas fa-seedling me-1"></i>Seed consumable categories
        </a>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($flash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row">
        <!-- Form -->
        <div class="col-12 col-lg-4 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0"><?php echo $editCategory ? 'Edit' : 'Add'; ?> Category</h2></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="<?php echo $editCategory ? 'update' : 'create'; ?>">
                        <input type="hidden" name="category_doc_id" value="<?php echo htmlspecialchars($editId); ?>">

                        <div class="mb-3">
                            <label class="form-label">Item Class <span class="text-danger">*</span></label>
                            <select class="form-select" name="item_class" required>
                                <?php foreach ($classLabels as $k => $v): ?>
                                <option value="<?php echo $k; ?>" <?php echo (string)($editCategory['item_class'] ?? ($_POST['item_class'] ?? '')) === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="category_code" value="<?php echo htmlspecialchars($editCategory['category_code'] ?? ($_POST['category_code'] ?? '')); ?>" required placeholder="FA-VEH">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="category_name" value="<?php echo htmlspecialchars($editCategory['category_name'] ?? ($_POST['category_name'] ?? '')); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Department Scope</label>
                            <select class="form-select" name="department_scope">
                                <?php foreach (['All', 'RET', 'FAC', 'O&M', 'General'] as $d): ?>
                                <option value="<?php echo $d; ?>" <?php echo (string)($editCategory['department_scope'] ?? 'All') === $d ? 'selected' : ''; ?>><?php echo $d; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Useful Life (years)</label>
                            <input type="number" class="form-control" name="useful_life_years" value="<?php echo htmlspecialchars($editCategory['useful_life_years'] ?? ''); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Depreciation Method</label>
                            <select class="form-select" name="depreciation_method">
                                <?php foreach (['None', 'StraightLine', 'DecliningBalance', 'UnitsOfProduction'] as $m): ?>
                                <option value="<?php echo $m; ?>" <?php echo (string)($editCategory['depreciation_method'] ?? 'None') === $m ? 'selected' : ''; ?>><?php echo $m; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3 form-check">
                            <input type="checkbox" class="form-check-input" name="reorder_enabled" id="reorderEnabled"
                                <?php echo (int)($editCategory['reorder_enabled'] ?? 0) ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="reorderEnabled">Enable reorder-point tracking</label>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Description</label>
                            <textarea class="form-control" name="description" rows="2"><?php echo htmlspecialchars($editCategory['description'] ?? ''); ?></textarea>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?php echo $editCategory ? 'Update' : 'Create'; ?></button>
                            <?php if ($editCategory): ?>
                            <a href="<?php echo base_url('admin/categories.php'); ?>" class="btn btn-gray-200">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <!-- Category List -->
        <div class="col-12 col-lg-8 mb-4">
            <?php foreach ($grouped as $cls => $cats): ?>
            <div class="card border-0 shadow mb-4">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h2 class="fs-5 fw-bold mb-0">
                        <span class="badge bg-<?php echo $classColors[$cls]; ?> me-2"><?php echo count($cats); ?></span>
                        <?php echo $classLabels[$cls]; ?>
                    </h2>
                </div>
                <div class="card-body p-0">
                    <?php if (empty($cats)): ?>
                    <p class="text-gray-500 text-center py-3 mb-0">No categories in this class.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Code</th><th>Name</th><th>Dept</th><th>Life</th><th>Reorder</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($cats as $cat):
                                    $docId = (string)($cat['id'] ?? '');
                                ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($cat['category_code'] ?? ''); ?></code></td>
                                    <td><?php echo htmlspecialchars($cat['category_name'] ?? ''); ?></td>
                                    <td><span class="badge bg-gray-200 text-gray-800"><?php echo htmlspecialchars($cat['department_scope'] ?? 'All'); ?></span></td>
                                    <td><?php echo $cat['useful_life_years'] ? $cat['useful_life_years'] . 'yr' : '—'; ?></td>
                                    <td><?php echo (int)($cat['reorder_enabled'] ?? 0) ? '<i class="fas fa-check text-success"></i>' : '—'; ?></td>
                                    <td>
                                        <a href="<?php echo base_url('admin/categories.php?edit=' . urlencode($docId)); ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                                        <a href="<?php echo base_url('admin/categories.php?delete=' . urlencode($docId)); ?>" class="btn btn-sm btn-outline-danger" title="Delete" onclick="return confirm('Delete this category?')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
