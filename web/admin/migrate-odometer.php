<?php
/**
 * MySQL → Firestore Odometer Readings Migration
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

$page_title = 'Migrate Odometer Readings: MySQL → Firestore';

// ---- Fetch odometer readings from MySQL ----
$mysqlReadings = [];
$mysqlError = null;
if ($pdo) {
    try {
        $stmt = $pdo->prepare("SELECT category_id FROM categories WHERE category_name = 'Vehicles'");
        $stmt->execute();
        $vc = $stmt->fetch();
        if ($vc) {
            $vcId = $vc['category_id'];
            $stmt = $pdo->prepare("
                SELECT
                    r.reading_id,
                    r.reading_km,
                    r.reading_date,
                    r.notes AS reading_notes,
                    r.created_at,
                    a.asset_id,
                    a.name AS vehicle_name,
                    a.asset_tag AS registration
                FROM odometer_readings r
                JOIN assets a ON r.asset_id = a.asset_id
                WHERE a.category_id = ?
                ORDER BY a.name, r.reading_date DESC
            ");
            $stmt->execute([$vcId]);
            $mysqlReadings = $stmt->fetchAll();
        }
    } catch (Exception $e) {
        $mysqlError = $e->getMessage();
    }
} else {
    $mysqlError = 'No MySQL connection. Check database.php / .env.';
}

// Build preview by vehicle
$preview = [];
foreach ($mysqlReadings as $r) {
    $vname = (string)($r['vehicle_name'] ?? '');
    $preview[$vname][] = [
        'reading_id'     => $r['reading_id'],
        'reading_km'     => (int)$r['reading_km'],
        'reading_date'   => (string)($r['reading_date'] ?? ''),
        'notes'          => (string)($r['reading_notes'] ?? ''),
        'asset_id'       => $r['asset_id'],
        'registration'   => (string)($r['registration'] ?? ''),
    ];
}

// ---- POST: run migration ----
$results = [];
$stats = ['imported' => 0, 'skipped' => 0, 'errors' => 0, 'no_vehicle' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$mysqlError && !empty($mysqlReadings)) {
    // Build Firestore vehicle name-to-ID lookup
    $fsAssets = am_firestore_get_collection('am_core_assets', 2000);
    $vehicleCatCodes = ['FA-VEH', 'FA-VEH-4X4', 'FA-VEH-TRUCK', 'FA-VEH-TRAILER', 'FA-VEH-EQUIP'];
    $nameToFirestoreId = [];
    foreach ($fsAssets as $a) {
        $cat = (string)($a['category_id'] ?? '');
        $cls = (string)($a['item_class'] ?? '');
        if ($cls === 'FixedAsset' && in_array($cat, $vehicleCatCodes, true)) {
            $aname = strtolower(trim((string)($a['name'] ?? '')));
            if ($aname !== '') {
                $nameToFirestoreId[$aname] = (string)($a['id'] ?? '');
            }
        }
    }

    // Existing readings in Firestore for dedup
    $existingReadings = am_firestore_get_collection('am_core_odometer_readings', 2000);
    $existingKeys = [];
    foreach ($existingReadings as $er) {
        $aid = (string)($er['asset_id'] ?? '');
        $km = (int)($er['reading_km'] ?? 0);
        $dt = (string)($er['reading_date'] ?? '');
        if ($aid !== '' && $km > 0 && $dt !== '') {
            $existingKeys[$aid . '|' . $km . '|' . $dt] = true;
        }
    }

    foreach ($preview as $vehicleName => $readings) {
        $fsId = $nameToFirestoreId[strtolower(trim($vehicleName))] ?? null;

        foreach ($readings as $r) {
            if (!$fsId) {
                $results[] = ['vehicle' => $vehicleName, 'status' => 'no_vehicle', 'detail' => 'Vehicle not found in Firestore'];
                $stats['no_vehicle']++;
                continue;
            }

            $key = $fsId . '|' . $r['reading_km'] . '|' . $r['reading_date'];
            if (isset($existingKeys[$key])) {
                $results[] = ['vehicle' => $vehicleName, 'status' => 'skipped', 'detail' => $r['reading_km'] . ' km @ ' . $r['reading_date'] . ' (already exists)'];
                $stats['skipped']++;
                continue;
            }

            $data = [
                'asset_id'     => $fsId,
                'vehicle_name' => $vehicleName,
                'reading_km'   => $r['reading_km'],
                'reading_date' => $r['reading_date'],
                'notes'        => $r['notes'],
                'created_at'   => (string)($r['reading_date'] ?? date('c')),
                'migrated_at'  => date('c'),
            ];

            $result = am_firestore_create_document('am_core_odometer_readings', $data);
            if ($result['ok']) {
                $results[] = ['vehicle' => $vehicleName, 'status' => 'imported', 'detail' => $r['reading_km'] . ' km @ ' . $r['reading_date']];
                $stats['imported']++;
                $existingKeys[$key] = true;
            } else {
                $results[] = ['vehicle' => $vehicleName, 'status' => 'error', 'detail' => $result['error'] ?? 'Unknown error'];
                $stats['errors']++;
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
            <li class="breadcrumb-item active">Migrate Odometer Readings</li>
        </ol>
    </nav>

    <h1 class="h2 mb-2">Migrate Odometer Readings: MySQL → Firestore</h1>
    <p class="text-gray-600 mb-4">Reads odometer readings from the MySQL <code>odometer_readings</code> table (joined to <code>assets</code> for vehicle context) and creates them in Firestore <code>am_core_odometer_readings</code>.</p>

    <?php if ($mysqlError): ?>
    <div class="alert alert-danger">
        <strong>MySQL connection failed:</strong> <?php echo htmlspecialchars($mysqlError); ?><br>
        The MySQL database must be accessible to read existing odometer readings.
    </div>
    <?php elseif (empty($mysqlReadings)): ?>
    <div class="alert alert-info">No odometer readings found in MySQL for vehicles. Nothing to migrate.</div>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
    <div class="card border-0 shadow mb-4">
        <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Migration Results</h2></div>
        <div class="card-body">
            <div class="mb-3">
                <span class="badge bg-success me-2"><?php echo $stats['imported']; ?> imported</span>
                <span class="badge bg-secondary me-2"><?php echo $stats['skipped']; ?> skipped</span>
                <?php if ($stats['no_vehicle']): ?><span class="badge bg-warning text-dark me-2"><?php echo $stats['no_vehicle']; ?> no vehicle match</span><?php endif; ?>
                <?php if ($stats['errors']): ?><span class="badge bg-danger me-2"><?php echo $stats['errors']; ?> errors</span><?php endif; ?>
            </div>
            <table class="table table-sm">
                <thead><tr><th>Vehicle</th><th>Status</th><th>Detail</th></tr></thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['vehicle']); ?></td>
                        <td>
                            <?php if ($r['status'] === 'imported'): ?><span class="badge bg-success">Imported</span>
                            <?php elseif ($r['status'] === 'skipped'): ?><span class="badge bg-secondary">Skipped</span>
                            <?php elseif ($r['status'] === 'no_vehicle'): ?><span class="badge bg-warning text-dark">No Match</span>
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

    <?php if (!$mysqlError && !empty($preview) && empty($results)): ?>
    <div class="card border-0 shadow">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="fs-5 fw-bold mb-0">Preview — <?php
                $totalReadings = 0;
                foreach ($preview as $rs) $totalReadings += count($rs);
                echo count($preview) . ' vehicles, ' . $totalReadings . ' readings';
            ?></h2>
        </div>
        <div class="card-body p-0">
            <?php foreach ($preview as $vehicleName => $readings): ?>
            <div class="border-bottom">
                <div class="px-3 py-2 bg-light fw-bold">
                    <?php echo htmlspecialchars($vehicleName); ?>
                    <?php if (!empty($readings[0]['registration'])): ?>
                        <code class="ms-2 small"><?php echo htmlspecialchars($readings[0]['registration']); ?></code>
                    <?php endif; ?>
                    <span class="badge bg-secondary ms-2"><?php echo count($readings); ?> readings</span>
                </div>
                <table class="table table-sm mb-0">
                    <thead><tr><th>Reading (km)</th><th>Date</th><th>Notes</th></tr></thead>
                    <tbody>
                        <?php foreach ($readings as $rd): ?>
                        <tr>
                            <td><?php echo number_format($rd['reading_km']); ?> km</td>
                            <td><?php echo htmlspecialchars($rd['reading_date']); ?></td>
                            <td class="text-gray-500"><?php echo htmlspecialchars($rd['notes'] ?: '—'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endforeach; ?>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <p class="mb-0 small text-gray-500">Readings are matched to Firestore vehicles by name and stored in <code>am_core_odometer_readings</code>.</p>
                <form method="post">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-rocket me-2"></i>Import All to Firestore</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
