<?php
/**
 * MySQL → Firestore vehicle migration with FM-aligned categorization.
 * Requires Admin. One-time operation.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/database.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['flash_error'] = 'Admin access required.';
    header('Location: ' . base_url('index.php'));
    exit;
}

$page_title = 'Migrate Vehicles: MySQL → Firestore';

// ---- Vehicle type classification ----
function classify_vehicle_type(string $name, string $make, string $model): array {
    $blob = strtolower($name . ' ' . $make . ' ' . $model);
    $nameLower = strtolower($name);

    $suvKeywords   = ['jeep', 'pajero', 'raider', 'surf', 'xtrail', 'x-trail', 'x trail',
                      'hilux', 'ranger', 'mazda', 'hardbody', 'land cruiser', 'landcruiser',
                      'land rover', 'rav4', 'rav 4', 'cr-v', 'crv', 'forester', 'prado',
                      '4runner', '4 runner', 'patrol', 'navara', 'd-max', 'dmax', 'bt-50',
                      'suv', '4x4', '4wd', 'double cab', 'pickup', 'bakkie'];
    $truckKeywords  = ['truck', 'lorry', 'canter', 'fuso', 'hino', 'actros', 'fh', 'fm',
                       'cargo', 'tipper', 'dump', 'tanker', 'rigid', 'artic'];
    $equipKeywords  = ['compressor', 'drill rig', 'drillrig', 'telehandler', 'tractors',
                       'tractor', 'trailer', 'generator', 'bowser', 'forklift', 'fork lift',
                       'crane', 'excavator', 'grader', 'dozer', 'bulldozer', 'loader',
                       'backhoe', 'roller', 'compactor', 'skid steer', 'skidsteer',
                       'concrete mixer', 'tower light', 'welder', 'pump', 'crusher',
                       'screener', 'equipment', 'plant', 'harrow', 'plough'];

    foreach ($equipKeywords as $kw) {
        if (str_contains($blob, $kw)) return ['equipment', 'FA-VEH-EQUIP'];
    }
    foreach ($truckKeywords as $kw) {
        if (str_contains($blob, $kw)) return ['truck', 'FA-VEH-TRUCK'];
    }
    foreach ($suvKeywords as $kw) {
        if (str_contains($blob, $kw)) return ['4x4', 'FA-VEH-4X4'];
    }
    return ['4x4', 'FA-VEH-4X4']; // default fallback
}

// ---- Seed vehicle subcategories in Firestore ----
function seed_vehicle_categories(): array {
    $cats = am_firestore_get_collection('pr_master_categories', 1000);
    $byCode = [];
    foreach ($cats as $c) {
        $code = (string)($c['category_code'] ?? '');
        if ($code !== '') $byCode[$code] = $c;
    }

    $needed = [
        'FA-VEH-4X4'   => 'Vehicles - 4x4 / SUV',
        'FA-VEH-TRUCK' => 'Vehicles - Truck',
        'FA-VEH-EQUIP' => 'Vehicles - Equipment',
    ];

    $seeded = [];
    foreach ($needed as $code => $name) {
        if (isset($byCode[$code])) {
            $seeded[$code] = $byCode[$code]['id'] ?? $code;
            continue;
        }
        $result = am_firestore_create_document('pr_master_categories', [
            'category_code'     => $code,
            'category_name'     => $name,
            'item_class'        => 'FixedAsset',
            'department_scope'  => 'General',
            'useful_life_years' => 4,
            'depreciation_method' => 'DecliningBalance',
            'reorder_enabled'   => 0,
            'active'            => 1,
        ]);
        if ($result['ok']) {
            $seeded[$code] = $result['id'];
        }
    }
    return $seeded;
}

// ---- Fetch vehicles from MySQL ----
$mysqlVehicles = [];
$mysqlError = null;
if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_name = 'Vehicles'");
        $stmt->execute();
        $vc = $stmt->fetch();
        if ($vc) {
            $vcId = $vc['category_id'];
            $stmt = $pdo->prepare("
                SELECT asset_id, name, asset_tag AS registration_number, serial_number AS vin_number,
                       manufacturer, model, vehicle_year, engine_number, transmission_type,
                       fuel_type, drive_type, purchase_date, purchase_price, status,
                       condition_status, notes, country_id, location_id, qr_code_id
                FROM assets
                WHERE category_id = ?
                ORDER BY name
            ");
            $stmt->execute([$vcId]);
            $mysqlVehicles = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $mysqlError = $e->getMessage();
    }
} else {
    $mysqlError = 'No MySQL connection. Check database.php / .env.';
}

// ---- POST: run migration ----
$results = [];
$stats = ['imported' => 0, 'skipped' => 0, 'errors' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$mysqlError && !empty($mysqlVehicles)) {
    $catIds = seed_vehicle_categories();
    $countries = am_firestore_get_collection('pr_master_countries', 500);
    $countryById = [];
    foreach ($countries as $c) {
        $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
        if ($cid !== '') $countryById[$cid] = $c;
    }
    $locations = am_get_pr_sites();
    $locById = [];
    foreach ($locations as $l) {
        $lid = (string)($l['location_id'] ?? $l['id'] ?? '');
        if ($lid !== '') $locById[$lid] = $l;
    }

    // Existing Firestore vehicles for dedup + tag generation
    $existingAssets = am_firestore_get_collection('am_core_assets', 2000);
    $existingVehicleNames = [];
    $vehicleCatCodes = ['FA-VEH', 'FA-VEH-4X4', 'FA-VEH-TRUCK', 'FA-VEH-EQUIP'];
    foreach ($existingAssets as $ea) {
        $ecid = (string)($ea['category_id'] ?? '');
        if (in_array($ecid, $vehicleCatCodes, true)) {
            $existingVehicleNames[] = strtolower((string)($ea['name'] ?? ''));
        }
    }

    $overrides = $_POST['override_type'] ?? [];
    $overrideCat = $_POST['override_cat'] ?? [];

    foreach ($mysqlVehicles as $mv) {
        $name = (string)($mv['name'] ?? '');
        $assetId = $mv['asset_id'];

        if (in_array(strtolower($name), $existingVehicleNames, true)) {
            $results[] = ['name' => $name, 'status' => 'skipped', 'detail' => 'Already in Firestore'];
            $stats['skipped']++;
            continue;
        }

        // Determine type/category — allow override from form
        if (isset($overrides[$assetId]) && isset($overrideCat[$assetId])) {
            $vtype = $overrides[$assetId];
            $catCode = $overrideCat[$assetId];
        } else {
            [$vtype, $catCode] = classify_vehicle_type($name,
                (string)($mv['manufacturer'] ?? ''), (string)($mv['model'] ?? ''));
        }

        // Resolve country
        $cid = (string)($mv['country_id'] ?? '');
        $country = $countryById[$cid] ?? [];
        $countryCode = (string)($country['country_code'] ?? 'LSO');

        // Resolve location
        $lid = (string)($mv['location_id'] ?? '');
        $loc = $locById[$lid] ?? [];
        $locName = (string)($loc['location_name'] ?? '');
        $locCode = (string)($loc['location_code'] ?? '');

        // Generate asset tag
        $assetTag = am_generate_asset_tag('FixedAsset', $countryCode, $existingAssets);

        // Map MySQL fields to Firestore
        $data = [
            'name'               => $name,
            'description'        => (string)($mv['manufacturer'] ?? '') . ' ' . (string)($mv['model'] ?? '') . ($mv['registration_number'] ? ' — ' . $mv['registration_number'] : ''),
            'item_class'         => 'FixedAsset',
            'category_id'        => $catCode,
            'country_id'         => $cid,
            'location_id'        => $lid,
            'location_name'      => $locName,
            'location_code'      => $locCode,
            'serial_number'      => (string)($mv['vin_number'] ?? ''),
            'manufacturer'       => (string)($mv['manufacturer'] ?? ''),
            'model'              => (string)($mv['model'] ?? ''),
            'purchase_date'      => (string)($mv['purchase_date'] ?? ''),
            'purchase_price'     => $mv['purchase_price'] ? (float)$mv['purchase_price'] : null,
            'salvage_value'      => null,
            'warranty_expiry'    => '',
            'condition_status'   => (string)($mv['condition_status'] ?? 'Good'),
            'status'             => (string)($mv['status'] ?? 'Available'),
            'quantity'           => 1,
            'unit_of_measure'    => 'EA',
            'asset_tag'          => $assetTag,
            'legacy_tag'         => (string)($mv['registration_number'] ?? ''),
            'qr_code_id'         => (string)($mv['qr_code_id'] ?? ''),
            'notes'              => 'Migrated from MySQL — ' . (string)($mv['notes'] ?? ''),
            'vehicle_type'       => $vtype,
            'vehicle_year'       => $mv['vehicle_year'] ? (int)$mv['vehicle_year'] : null,
            'engine_number'      => (string)($mv['engine_number'] ?? ''),
            'transmission_type'  => (string)($mv['transmission_type'] ?? ''),
            'fuel_type'          => (string)($mv['fuel_type'] ?? ''),
            'drive_type'         => (string)($mv['drive_type'] ?? ''),
            'created_at'         => date('c'),
            'updated_at'         => date('c'),
            'created_by'         => $_SESSION['user_id'] ?? '',
        ];

        $result = am_firestore_create_document('am_core_assets', $data);
        if ($result['ok']) {
            $results[] = ['name' => $name, 'status' => 'imported', 'detail' => $assetTag];
            $stats['imported']++;
            $existingVehicleNames[] = strtolower($name);
            $existingAssets[] = array_merge($data, ['id' => $result['id'], 'asset_id' => $result['id']]);
        } else {
            $results[] = ['name' => $name, 'status' => 'error', 'detail' => $result['error'] ?? 'Unknown error'];
            $stats['errors']++;
        }
    }
}

// ---- For preview: classify each vehicle ----
$preview = [];
foreach ($mysqlVehicles as $mv) {
    [$vtype, $catCode] = classify_vehicle_type(
        (string)($mv['name'] ?? ''),
        (string)($mv['manufacturer'] ?? ''),
        (string)($mv['model'] ?? '')
    );
    $preview[] = [
        'asset_id'             => $mv['asset_id'],
        'name'                 => (string)($mv['name'] ?? ''),
        'registration_number'  => (string)($mv['registration_number'] ?? ''),
        'make'                 => (string)($mv['manufacturer'] ?? ''),
        'model'                => (string)($mv['model'] ?? ''),
        'year'                 => $mv['vehicle_year'] ?? '',
        'vin'                  => (string)($mv['vin_number'] ?? ''),
        'status'               => (string)($mv['status'] ?? ''),
        'detected_type'        => $vtype,
        'detected_cat'         => $catCode,
    ];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-3">
            <li class="breadcrumb-item"><a href="<?php echo base_url('admin/migrate.php'); ?>">Admin</a></li>
            <li class="breadcrumb-item active">Migrate Vehicles</li>
        </ol>
    </nav>

    <h1 class="h2 mb-2">Migrate Vehicles: MySQL → Firestore</h1>
    <p class="text-gray-600 mb-4">Reads vehicles from the MySQL <code>assets</code> table and creates them in Firestore <code>am_core_assets</code> with FM-aligned subcategories (4x4/SUV, Truck, Equipment).</p>

    <?php if ($mysqlError): ?>
    <div class="alert alert-danger">
        <strong>MySQL connection failed:</strong> <?php echo htmlspecialchars($mysqlError); ?><br>
        The MySQL database must be accessible to read existing vehicle data.
    </div>
    <?php elseif (empty($mysqlVehicles)): ?>
    <div class="alert alert-info">No vehicles found in MySQL <code>assets</code> table (Vehicles category). Nothing to migrate.</div>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
    <div class="card border-0 shadow mb-4">
        <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Migration Results</h2></div>
        <div class="card-body">
            <div class="mb-3">
                <span class="badge bg-success me-2"><?php echo $stats['imported']; ?> imported</span>
                <span class="badge bg-secondary me-2"><?php echo $stats['skipped']; ?> skipped</span>
                <?php if ($stats['errors']): ?><span class="badge bg-danger me-2"><?php echo $stats['errors']; ?> errors</span><?php endif; ?>
            </div>
            <table class="table table-sm">
                <thead><tr><th>Vehicle</th><th>Status</th><th>Detail</th></tr></thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['name']); ?></td>
                        <td>
                            <?php if ($r['status'] === 'imported'): ?><span class="badge bg-success">Imported</span>
                            <?php elseif ($r['status'] === 'skipped'): ?><span class="badge bg-secondary">Skipped</span>
                            <?php else: ?><span class="badge bg-danger">Error</span><?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars($r['detail']); ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <?php if (!$mysqlError && !empty($mysqlVehicles) && empty($results)): ?>
    <div class="card border-0 shadow">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="fs-5 fw-bold mb-0">Preview — <?php echo count($preview); ?> vehicles</h2>
            <span class="small text-gray-500">Adjust types before importing</span>
        </div>
        <div class="card-body p-0">
            <form method="post">
                <div class="table-responsive">
                    <table class="table table-sm mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Registration</th>
                                <th>Make / Model</th>
                                <th>Year</th>
                                <th>VIN</th>
                                <th>Status</th>
                                <th>Detected Type</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($preview as $pv): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($pv['name']); ?></strong></td>
                                <td><code><?php echo htmlspecialchars($pv['registration_number'] ?: '—'); ?></code></td>
                                <td><?php echo htmlspecialchars(trim($pv['make'] . ' ' . $pv['model']) ?: '—'); ?></td>
                                <td><?php echo htmlspecialchars($pv['year'] ?: '—'); ?></td>
                                <td><small><code><?php echo htmlspecialchars($pv['vin'] ?: '—'); ?></code></small></td>
                                <td><span class="badge bg-<?php echo $pv['status'] === 'Available' ? 'success' : 'secondary'; ?>"><?php echo htmlspecialchars($pv['status']); ?></span></td>
                                <td>
                                    <input type="hidden" name="override_type[<?php echo $pv['asset_id']; ?>]" value="<?php echo htmlspecialchars($pv['detected_type']); ?>">
                                    <select class="form-select form-select-sm" name="override_cat[<?php echo $pv['asset_id']; ?>]" style="width:auto;">
                                        <option value="FA-VEH-4X4" <?php echo $pv['detected_cat'] === 'FA-VEH-4X4' ? 'selected' : ''; ?>>4x4 / SUV</option>
                                        <option value="FA-VEH-TRUCK" <?php echo $pv['detected_cat'] === 'FA-VEH-TRUCK' ? 'selected' : ''; ?>>Truck</option>
                                        <option value="FA-VEH-EQUIP" <?php echo $pv['detected_cat'] === 'FA-VEH-EQUIP' ? 'selected' : ''; ?>>Equipment</option>
                                    </select>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer d-flex justify-content-between align-items-center">
                    <p class="mb-0 small text-gray-500">Categories FA-VEH-4X4, FA-VEH-TRUCK, FA-VEH-EQUIP will be seeded into <code>pr_master_categories</code> if missing.</p>
                    <button type="submit" class="btn btn-primary"><i class="fas fa-rocket me-2"></i>Import All to Firestore</button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
