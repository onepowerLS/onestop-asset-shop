<?php
/**
 * MySQL → Firestore Odometer Summary Migration
 *
 * FM owns detailed odometer history. AM stores only first/last readings
 * per vehicle directly on the am_core_assets document.
 *
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
                    a.asset_id,
                    a.name AS vehicle_name,
                    a.asset_tag AS registration
                FROM odometer_readings r
                JOIN assets a ON r.asset_id = a.asset_id
                WHERE a.category_id = ?
                ORDER BY a.name, r.reading_date ASC
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

// Build preview: first/last per vehicle
$preview = [];
foreach ($mysqlReadings as $r) {
    $vname = (string)($r['vehicle_name'] ?? '');
    if (!isset($preview[$vname])) {
        $preview[$vname] = [
            'registration'  => (string)($r['registration'] ?? ''),
            'readings'      => [],
        ];
    }
    $preview[$vname]['readings'][] = [
        'reading_km'   => (int)$r['reading_km'],
        'reading_date' => (string)($r['reading_date'] ?? ''),
    ];
}

// Compute first/last per vehicle
$previewSummary = [];
foreach ($preview as $vname => $data) {
    $readings = $data['readings'];
    usort($readings, fn($a, $b) => strcmp($a['reading_date'], $b['reading_date']));
    $first = $readings[0];
    $last  = $readings[count($readings) - 1];
    $previewSummary[$vname] = [
        'registration'   => $data['registration'],
        'total_readings' => count($readings),
        'first_km'       => $first['reading_km'],
        'first_date'     => $first['reading_date'],
        'last_km'        => $last['reading_km'],
        'last_date'      => $last['reading_date'],
    ];
}

// ---- POST: write first/last to vehicle documents ----
$results = [];
$stats = ['updated' => 0, 'skipped' => 0, 'errors' => 0, 'no_vehicle' => 0];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$mysqlError && !empty($previewSummary)) {
    $fsAssets = am_firestore_get_collection('am_core_assets', 2000);
    $vehicleCatCodes = ['FA-VEH', 'FA-VEH-4X4', 'FA-VEH-TRUCK', 'FA-VEH-TRAILER', 'FA-VEH-EQUIP'];
    $nameToFs = [];
    foreach ($fsAssets as $a) {
        $cat = (string)($a['category_id'] ?? '');
        $cls = (string)($a['item_class'] ?? '');
        if ($cls === 'FixedAsset' && in_array($cat, $vehicleCatCodes, true)) {
            $aname = strtolower(trim((string)($a['name'] ?? '')));
            if ($aname !== '') {
                $nameToFs[$aname] = ['id' => (string)($a['id'] ?? ''), 'name' => (string)($a['name'] ?? '')];
            }
        }
    }

    foreach ($previewSummary as $vehicleName => $s) {
        $fs = $nameToFs[strtolower(trim($vehicleName))] ?? null;
        if (!$fs) {
            $results[] = ['vehicle' => $vehicleName, 'status' => 'no_vehicle', 'detail' => 'Vehicle not found in Firestore'];
            $stats['no_vehicle']++;
            continue;
        }

        // Check if already has odometer data
        $existing = $fsAssets;
        $alreadyHas = false;
        foreach ($existing as $ea) {
            if ((string)($ea['id'] ?? '') === $fs['id']) {
                if (!empty($ea['odometer_first_km']) || !empty($ea['odometer_last_km'])) {
                    $alreadyHas = true;
                }
                break;
            }
        }

        if ($alreadyHas) {
            $results[] = ['vehicle' => $vehicleName, 'status' => 'skipped', 'detail' => 'Already has odometer summary'];
            $stats['skipped']++;
            continue;
        }

        $update = [
            'odometer_first_km'   => $s['first_km'],
            'odometer_first_date' => $s['first_date'],
            'odometer_last_km'    => $s['last_km'],
            'odometer_last_date'  => $s['last_date'],
            'updated_at'          => date('c'),
        ];

        $result = am_firestore_update_document('am_core_assets', $fs['id'], $update);
        if ($result['ok']) {
            $results[] = ['vehicle' => $vehicleName, 'status' => 'updated', 'detail' =>
                number_format($s['first_km']) . ' km → ' . number_format($s['last_km']) . ' km (' . $s['total_readings'] . ' readings)'];
            $stats['updated']++;
        } else {
            $results[] = ['vehicle' => $vehicleName, 'status' => 'error', 'detail' => $result['error'] ?? 'Unknown error'];
            $stats['errors']++;
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
    <p class="text-gray-600 mb-4">
        Computes <strong>first</strong> (oldest) and <strong>last</strong> (newest) odometer readings per vehicle from MySQL
        and stores them directly on the vehicle's <code>am_core_assets</code> document.
        Detailed reading history stays in FM.
    </p>

    <?php if ($mysqlError): ?>
    <div class="alert alert-danger">
        <strong>MySQL connection failed:</strong> <?php echo htmlspecialchars($mysqlError); ?>
    </div>
    <?php elseif (empty($previewSummary)): ?>
    <div class="alert alert-info">No odometer readings found in MySQL for vehicles. Nothing to migrate.</div>
    <?php endif; ?>

    <?php if (!empty($results)): ?>
    <div class="card border-0 shadow mb-4">
        <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Migration Results</h2></div>
        <div class="card-body">
            <div class="mb-3">
                <span class="badge bg-success me-2"><?php echo $stats['updated']; ?> updated</span>
                <span class="badge bg-secondary me-2"><?php echo $stats['skipped']; ?> skipped</span>
                <?php if ($stats['no_vehicle']): ?><span class="badge bg-warning text-dark me-2"><?php echo $stats['no_vehicle']; ?> no match</span><?php endif; ?>
                <?php if ($stats['errors']): ?><span class="badge bg-danger me-2"><?php echo $stats['errors']; ?> errors</span><?php endif; ?>
            </div>
            <table class="table table-sm">
                <thead><tr><th>Vehicle</th><th>Status</th><th>Detail</th></tr></thead>
                <tbody>
                    <?php foreach ($results as $r): ?>
                    <tr>
                        <td><?php echo htmlspecialchars($r['vehicle']); ?></td>
                        <td>
                            <?php if ($r['status'] === 'updated'): ?><span class="badge bg-success">Updated</span>
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

    <?php if (!$mysqlError && !empty($previewSummary) && empty($results)): ?>
    <div class="card border-0 shadow">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="fs-5 fw-bold mb-0">Preview — <?php echo count($previewSummary); ?> vehicles</h2>
            <span class="small text-gray-500">Fields written to <code>am_core_assets</code>: odometer_first_km, odometer_first_date, odometer_last_km, odometer_last_date</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-sm mb-0">
                    <thead>
                        <tr>
                            <th>Vehicle</th>
                            <th>Registration</th>
                            <th>Readings</th>
                            <th>First</th>
                            <th>Last</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewSummary as $vehicleName => $s): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars($vehicleName); ?></strong></td>
                            <td><code><?php echo htmlspecialchars($s['registration'] ?: '—'); ?></code></td>
                            <td><span class="badge bg-secondary"><?php echo $s['total_readings']; ?></span></td>
                            <td><?php echo number_format($s['first_km']); ?> km<br><small class="text-gray-500"><?php echo htmlspecialchars($s['first_date']); ?></small></td>
                            <td><?php echo number_format($s['last_km']); ?> km<br><small class="text-gray-500"><?php echo htmlspecialchars($s['last_date']); ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <div class="card-footer d-flex justify-content-between align-items-center">
                <p class="mb-0 small text-gray-500">
                    Each vehicle gets <code>odometer_first_km</code>, <code>odometer_first_date</code>,
                    <code>odometer_last_km</code>, <code>odometer_last_date</code> written to its Firestore document.
                </p>
                <form method="post">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-rocket me-2"></i>Write Summaries to Firestore</button>
                </form>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
