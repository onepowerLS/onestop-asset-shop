<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['flash_error'] = 'Admin access required.';
    header('Location: ' . base_url('index.php'));
    exit;
}

$page_title = 'Manage Locations';
$errors = [];
$editId = $_GET['edit'] ?? '';

$locations = am_firestore_get_collection('pr_master_locations', 2000);
$countries = am_firestore_get_collection('pr_master_countries', 500);
$countries = array_values(array_filter($countries, fn($c) => (int)($c['active'] ?? 1) === 1));

$countryById = [];
foreach ($countries as $c) {
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($cid !== '') $countryById[$cid] = $c;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $docId = trim($_POST['doc_id'] ?? '');
    $locCode = trim($_POST['location_code'] ?? '');
    $locName = trim($_POST['location_name'] ?? '');
    $locType = trim($_POST['location_type'] ?? 'Site');
    $countryId = trim($_POST['country_id'] ?? '');
    $parentId = trim($_POST['parent_location_id'] ?? '');

    if ($locName === '') $errors[] = 'Location name is required.';
    if ($locCode === '') $errors[] = 'Location code is required.';
    if ($countryId === '') $errors[] = 'Country is required.';

    if (empty($errors)) {
        $data = [
            'location_code' => $locCode,
            'location_name' => $locName,
            'location_type' => $locType,
            'country_id' => $countryId,
            'parent_location_id' => $parentId,
            'active' => 1,
        ];

        if ($action === 'update' && $docId !== '') {
            $result = am_firestore_update_document('pr_master_locations', $docId, $data);
        } else {
            $result = am_firestore_create_document('pr_master_locations', $data);
        }

        if ($result['ok']) {
            $_SESSION['flash_success'] = $action === 'update' ? 'Location updated.' : 'Location created.';
            header('Location: ' . base_url('admin/locations.php'));
            exit;
        } else {
            $errors[] = $result['error'] ?? 'Save failed.';
        }
    }
}

if ($_GET['delete'] ?? '') {
    $result = am_firestore_delete_document('pr_master_locations', $_GET['delete']);
    $_SESSION['flash_success'] = $result['ok'] ? 'Location deleted.' : 'Delete failed.';
    header('Location: ' . base_url('admin/locations.php'));
    exit;
}

$editLocation = null;
if ($editId) {
    foreach ($locations as $l) {
        if ((string)($l['id'] ?? '') === $editId) { $editLocation = $l; break; }
    }
}

$locationTypes = ['Country', 'Region', 'Site', 'Building', 'Room', 'Cabinet', 'Other'];

$byCountry = [];
foreach ($locations as $l) {
    $cid = (string)($l['country_id'] ?? '');
    $byCountry[$cid][] = $l;
}

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center py-4">
        <h1 class="h2">Manage Locations</h1>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($flash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-lg-4 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0"><?php echo $editLocation ? 'Edit' : 'Add'; ?> Location</h2></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="<?php echo $editLocation ? 'update' : 'create'; ?>">
                        <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($editId); ?>">

                        <div class="mb-3">
                            <label class="form-label">Country <span class="text-danger">*</span></label>
                            <select class="form-select" name="country_id" required>
                                <option value="">Select...</option>
                                <?php foreach ($countries as $c):
                                    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
                                ?>
                                <option value="<?php echo htmlspecialchars($cid); ?>"
                                    <?php echo (string)($editLocation['country_id'] ?? '') === $cid ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($c['country_name'] ?? ''); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Type</label>
                            <select class="form-select" name="location_type">
                                <?php foreach ($locationTypes as $t): ?>
                                <option value="<?php echo $t; ?>" <?php echo (string)($editLocation['location_type'] ?? 'Site') === $t ? 'selected' : ''; ?>><?php echo $t; ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Code <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="location_code" value="<?php echo htmlspecialchars($editLocation['location_code'] ?? ''); ?>" required placeholder="LSO-MAS-001">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Name <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="location_name" value="<?php echo htmlspecialchars($editLocation['location_name'] ?? ''); ?>" required>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Parent Location</label>
                            <select class="form-select" name="parent_location_id">
                                <option value="">None (top-level)</option>
                                <?php foreach ($locations as $l):
                                    $lid = (string)($l['location_id'] ?? $l['id'] ?? '');
                                ?>
                                <option value="<?php echo htmlspecialchars($lid); ?>"
                                    <?php echo (string)($editLocation['parent_location_id'] ?? '') === $lid ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars(($l['location_code'] ?? '') . ' - ' . ($l['location_name'] ?? '')); ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-2"></i><?php echo $editLocation ? 'Update' : 'Create'; ?></button>
                            <?php if ($editLocation): ?>
                            <a href="<?php echo base_url('admin/locations.php'); ?>" class="btn btn-gray-200">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-8 mb-4">
            <?php foreach ($byCountry as $cid => $locs):
                $cname = $countryById[$cid]['country_name'] ?? 'Unknown';
            ?>
            <div class="card border-0 shadow mb-4">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0"><?php echo htmlspecialchars($cname); ?> (<?php echo count($locs); ?>)</h2></div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($locs as $loc):
                                    $docId = (string)($loc['id'] ?? '');
                                ?>
                                <tr>
                                    <td><code><?php echo htmlspecialchars($loc['location_code'] ?? ''); ?></code></td>
                                    <td><?php echo htmlspecialchars($loc['location_name'] ?? ''); ?></td>
                                    <td><span class="badge bg-gray-200 text-gray-800"><?php echo htmlspecialchars($loc['location_type'] ?? ''); ?></span></td>
                                    <td>
                                        <a href="<?php echo base_url('admin/locations.php?edit=' . urlencode($docId)); ?>" class="btn btn-sm btn-outline-secondary"><i class="fas fa-edit"></i></a>
                                        <a href="<?php echo base_url('admin/locations.php?delete=' . urlencode($docId)); ?>" class="btn btn-sm btn-outline-danger" onclick="return confirm('Delete this location?')"><i class="fas fa-trash"></i></a>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
            <?php if (empty($byCountry)): ?>
            <div class="card border-0 shadow"><div class="card-body text-center text-gray-500 py-4">No locations configured yet.</div></div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
