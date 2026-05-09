<?php
/**
 * One-time import: vehicles from PR system into Firestore am_core_assets.
 * Requires Admin. After import, AM is the single source of truth for vehicles.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['flash_error'] = 'Admin access required.';
    header('Location: ' . base_url('index.php'));
    exit;
}

$page_title = 'Import Vehicles from PR';

// Hardcoded vehicle list from PR System (Vehicle.csv)
$prVehicles = [
    ['name' => 'Compressor',      'registration' => null,          'make' => null,           'model' => 'Air Compressor'],
    ['name' => 'Drill rig',       'registration' => null,          'make' => null,           'model' => 'Drill Rig'],
    ['name' => 'Hardbody 1',      'registration' => null,          'make' => 'Nissan',       'model' => 'Hardbody'],
    ['name' => 'Hardbody 2',      'registration' => null,          'make' => 'Nissan',       'model' => 'Hardbody'],
    ['name' => 'Hilux',           'registration' => null,          'make' => 'Toyota',       'model' => 'Hilux'],
    ['name' => 'Jeep 1',          'registration' => 'A992 BCF',    'make' => 'Jeep',         'model' => 'Wrangler'],
    ['name' => 'Jeep 2',          'registration' => null,          'make' => 'Jeep',         'model' => 'Wrangler'],
    ['name' => 'Jeep 3',          'registration' => null,          'make' => 'Jeep',         'model' => 'Wrangler'],
    ['name' => 'Mazda 1',         'registration' => null,          'make' => 'Mazda',        'model' => 'BT-50'],
    ['name' => 'Pajero',          'registration' => 'RLZ052J',     'make' => 'Mitsubishi',   'model' => 'Pajero'],
    ['name' => 'Raider',          'registration' => null,          'make' => 'Mitsubishi',   'model' => 'Raider'],
    ['name' => 'Ranger 1',        'registration' => 'A 838 BLF',   'make' => 'Ford',         'model' => 'Ranger'],
    ['name' => 'Ranger 2',        'registration' => 'A 374 BBV',   'make' => 'Ford',         'model' => 'Ranger'],
    ['name' => 'Ranger 3',        'registration' => null,          'make' => 'Ford',         'model' => 'Ranger'],
    ['name' => 'Surf 1',          'registration' => 'RCY461J',     'make' => 'Toyota',       'model' => 'Surf'],
    ['name' => 'Surf 2',          'registration' => 'RY019',       'make' => 'Toyota',       'model' => 'Surf'],
    ['name' => 'Telehandler',     'registration' => null,          'make' => 'JCB',          'model' => 'Telehandler'],
    ['name' => 'Tractors',        'registration' => null,          'make' => null,           'model' => 'Tractor'],
    ['name' => 'Trailer',         'registration' => null,          'make' => null,           'model' => 'Trailer'],
    ['name' => 'XTrail 1',        'registration' => 'RLK506J',     'make' => 'Nissan',       'model' => 'X-Trail'],
    ['name' => 'Xtrail 2',        'registration' => null,          'make' => 'Nissan',       'model' => 'X-Trail'],
    ['name' => '36',              'registration' => 'RLL415J',     'make' => null,           'model' => null],
];

// Pre-flight: find category + country
$categories = am_firestore_get_collection('pr_master_categories', 1000);
$vehicleCatId = null;
foreach ($categories as $c) {
    if ((string)($c['category_name'] ?? '') === 'Vehicles' || (string)($c['category_code'] ?? '') === 'FA-VEH') {
        $vehicleCatId = (string)($c['category_id'] ?? $c['id'] ?? '');
        break;
    }
}

$countries = am_firestore_get_collection('pr_master_countries', 500);
$lsoCountryId = null;
$lsoCountryCode = 'LSO';
foreach ($countries as $c) {
    if (strtoupper((string)($c['country_code'] ?? '')) === 'LSO') {
        $lsoCountryId = (string)($c['country_id'] ?? $c['id'] ?? '');
        break;
    }
}

$errors = [];
$results = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$vehicleCatId) {
        $errors[] = 'Vehicles category not found in pr_master_categories. Create it first.';
    }
    if (!$lsoCountryId) {
        $errors[] = 'Lesotho (LSO) not found in pr_master_countries.';
    }

    if (empty($errors)) {
        $existingAssets = am_firestore_get_collection('am_core_assets', 2000);

        // Check for existing vehicles by name + category
        $existingNames = [];
        foreach ($existingAssets as $ea) {
            if ((string)($ea['category_id'] ?? '') === $vehicleCatId) {
                $existingNames[] = strtolower((string)($ea['name'] ?? ''));
            }
        }

        foreach ($prVehicles as $v) {
            $name = $v['name'];
            if (in_array(strtolower($name), $existingNames, true)) {
                $results[] = ['name' => $name, 'status' => 'skipped', 'reason' => 'Already exists'];
                continue;
            }

            $assetTag = am_generate_asset_tag('FixedAsset', $lsoCountryCode, $existingAssets);

            $data = [
                'name'             => $name,
                'description'      => ($v['make'] ? $v['make'] . ' ' : '') . ($v['model'] ?? 'Vehicle') . ($v['registration'] ? ' — ' . $v['registration'] : ''),
                'item_class'       => 'FixedAsset',
                'category_id'      => $vehicleCatId,
                'country_id'       => $lsoCountryId,
                'location_id'      => '',
                'serial_number'    => $v['registration'] ?? '',
                'manufacturer'     => $v['make'] ?? '',
                'model'            => $v['model'] ?? '',
                'purchase_date'    => '',
                'purchase_price'   => null,
                'salvage_value'    => null,
                'warranty_expiry'  => '',
                'condition_status' => 'Good',
                'status'           => 'Available',
                'quantity'         => 1,
                'unit_of_measure'  => 'EA',
                'legacy_tag'       => $v['registration'] ?? '',
                'notes'            => 'Imported from PR System (one-time migration)',
                'asset_tag'        => $assetTag,
                'qr_code_id'       => '',
                'created_at'       => date('c'),
                'updated_at'       => date('c'),
                'created_by'       => $_SESSION['user_id'] ?? '',
            ];

            $result = am_firestore_create_document('am_core_assets', $data);
            if ($result['ok']) {
                $results[] = ['name' => $name, 'status' => 'imported', 'tag' => $assetTag, 'id' => $result['id']];
                // Add to existing assets so subsequent tags don't collide
                $existingAssets[] = array_merge($data, ['id' => $result['id'], 'asset_id' => $result['id']]);
            } else {
                $results[] = ['name' => $name, 'status' => 'error', 'reason' => $result['error'] ?? 'Unknown'];
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="<?php echo base_url('admin/migrate.php'); ?>">Admin</a></li>
            <li class="breadcrumb-item active">Import Vehicles</li>
        </ol>
    </nav>

    <h1 class="h2 mb-3">Import Vehicles from PR System</h1>

    <?php if (!$vehicleCatId): ?>
    <div class="alert alert-danger">Vehicles category not found in <code>pr_master_categories</code>. Import it from the <a href="<?php echo base_url('admin/migrate.php'); ?>">migration page</a> first.</div>
    <?php endif; ?>
    <?php if (!$lsoCountryId): ?>
    <div class="alert alert-danger">Lesotho (LSO) not found in <code>pr_master_countries</code>.</div>
    <?php endif; ?>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
    <div class="card border-0 shadow mb-4">
        <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Results</h2></div>
        <div class="card-body">
            <table class="table table-sm">
                <thead><tr><th>Vehicle</th><th>Status</th><th>Tag / Detail</th></tr></thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['name']); ?></td>
                        <td>
                            <?php if ($r['status'] === 'imported'): ?>
                            <span class="badge bg-success">Imported</span>
                            <?php elseif ($r['status'] === 'skipped'): ?>
                            <span class="badge bg-secondary">Skipped</span>
                            <?php else: ?>
                            <span class="badge bg-danger">Error</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($r['status'] === 'imported'): ?>
                            <code><?php echo htmlspecialchars($r['tag'] ?? ''); ?></code>
                            <a href="<?php echo base_url('assets/view.php?id=' . urlencode($r['id'] ?? '')); ?>" class="btn btn-sm btn-outline-primary ms-2">View</a>
                            <?php else: ?>
                            <small class="text-gray-500"><?php echo htmlspecialchars($r['reason'] ?? ''); ?></small>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($results)): ?>
    <div class="card border-0 shadow">
        <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Preview</h2></div>
        <div class="card-body">
            <p><?php echo count($prVehicles); ?> vehicles from PR Vehicle.csv will be imported as <strong>Fixed Assets</strong> in Lesotho (LSO) under the Vehicles category.</p>
            <table class="table table-sm">
                <thead><tr><th>Name</th><th>Registration</th><th>Make</th><th>Model</th></tr></thead>
                <tbody>
                    <?php foreach ($prVehicles as $v): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($v['name']); ?></td>
                        <td><code><?php echo htmlspecialchars($v['registration'] ?? '—'); ?></code></td>
                        <td><?php echo htmlspecialchars($v['make'] ?? '—'); ?></td>
                        <td><?php echo htmlspecialchars($v['model'] ?? '—'); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>

            <?php if ($vehicleCatId && $lsoCountryId): ?>
            <form method="post" class="mt-3">
                <button type="submit" class="btn btn-primary"><i class="fas fa-download me-2"></i>Import Vehicles</button>
            </form>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
