<?php
/**
 * Admin-only migration page: imports ETL-generated JSON into Firestore.
 *
 * Reads JSON files from migration/output/ and writes documents
 * to the corresponding Firestore collections in batches.
 */
require_once __DIR__ . '/../config/app.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['firebase_id_token'])) {
    header('Location: /login.php'); exit;
}
$amRole = $_SESSION['am_role'] ?? 'Viewer';
if ($amRole !== 'Admin') {
    echo 'Admin access required.'; exit;
}

require_once __DIR__ . '/../config/firestore.php';

$migrationDir = realpath(__DIR__ . '/../../migration/output');
$collections = [
    'pr_master_countries'  => ['file' => 'pr_master_countries.json',  'id_field' => 'country_code'],
    'pr_master_locations'  => ['file' => 'pr_master_locations.json',  'id_field' => 'location_code'],
    'pr_master_categories' => ['file' => 'pr_master_categories.json', 'id_field' => 'category_code'],
    'am_core_assets'       => ['file' => 'am_core_assets.json',       'id_field' => null],
    'pr_master_requests'   => ['file' => 'pr_master_requests.json',   'id_field' => 'request_number'],
];

$results = [];
$action = $_POST['action'] ?? '';
$targetCollection = $_POST['collection'] ?? '';

if ($action === 'import' && $targetCollection && isset($collections[$targetCollection])) {
    $spec = $collections[$targetCollection];
    $jsonPath = $migrationDir . '/' . $spec['file'];
    if (!file_exists($jsonPath)) {
        $results[] = ['error' => "File not found: {$spec['file']}"];
    } else {
        $data = json_decode(file_get_contents($jsonPath), true);
        if (!is_array($data)) {
            $results[] = ['error' => "Invalid JSON in {$spec['file']}"];
        } else {
            $ok = 0; $fail = 0; $errors = [];
            foreach ($data as $i => $record) {
                $docId = $spec['id_field'] ? ($record[$spec['id_field']] ?? null) : null;
                $result = am_firestore_create_document($targetCollection, $record, $docId);
                if ($result['ok']) {
                    $ok++;
                } else {
                    $fail++;
                    if (count($errors) < 10) {
                        $errors[] = "Row {$i}: " . ($result['error'] ?? 'unknown');
                    }
                }
                if (($i + 1) % 50 === 0) {
                    usleep(200000);
                }
            }
            $results[] = [
                'collection' => $targetCollection,
                'total' => count($data),
                'ok' => $ok,
                'fail' => $fail,
                'errors' => $errors,
            ];
        }
    }
}

if ($action === 'preview' && $targetCollection && isset($collections[$targetCollection])) {
    $spec = $collections[$targetCollection];
    $jsonPath = $migrationDir . '/' . $spec['file'];
    $previewData = [];
    if (file_exists($jsonPath)) {
        $all = json_decode(file_get_contents($jsonPath), true);
        $previewData = is_array($all) ? array_slice($all, 0, 20) : [];
    }
}

$fileCounts = [];
foreach ($collections as $coll => $spec) {
    $path = $migrationDir . '/' . $spec['file'];
    if (file_exists($path)) {
        $data = json_decode(file_get_contents($path), true);
        $fileCounts[$coll] = is_array($data) ? count($data) : 0;
    } else {
        $fileCounts[$coll] = -1;
    }
}

$pageTitle = 'Data Migration';
include __DIR__ . '/../includes/header.php';
include __DIR__ . '/../includes/sidebar.php';
?>

<main class="content">
    <?php include __DIR__ . '/../includes/topbar.php'; ?>

    <div class="py-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2 class="h4">Data Migration</h2>
            <span class="badge bg-danger">Admin Only</span>
        </div>

        <?php if (!empty($results)): foreach ($results as $r): ?>
            <?php if (isset($r['error'])): ?>
                <div class="alert alert-danger"><?= htmlspecialchars($r['error']) ?></div>
            <?php else: ?>
                <div class="alert <?= $r['fail'] === 0 ? 'alert-success' : 'alert-warning' ?>">
                    <strong><?= htmlspecialchars($r['collection']) ?>:</strong>
                    <?= $r['ok'] ?> created, <?= $r['fail'] ?> failed (of <?= $r['total'] ?> total)
                    <?php if (!empty($r['errors'])): ?>
                        <ul class="mb-0 mt-2">
                            <?php foreach ($r['errors'] as $e): ?>
                                <li><small><?= htmlspecialchars($e) ?></small></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php endforeach; endif; ?>

        <div class="alert alert-info">
            <strong>Migration source:</strong> <code>migration/output/</code><br>
            Run <code>python3 migration/etl.py</code> to regenerate JSON from Dropbox Excel sources.
        </div>

        <div class="card border-0 shadow-sm">
            <div class="card-body">
                <h5>Import Order</h5>
                <p class="text-muted small">
                    Import in order: Countries → Locations → Categories → Assets → Requests.
                    Reference data must exist before assets can resolve their category/location/country codes.
                </p>

                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th>#</th>
                            <th>Collection</th>
                            <th>Source File</th>
                            <th>Records</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php $seq = 0; foreach ($collections as $coll => $spec): $seq++; ?>
                        <tr>
                            <td><?= $seq ?></td>
                            <td><code><?= htmlspecialchars($coll) ?></code></td>
                            <td><code><?= htmlspecialchars($spec['file']) ?></code></td>
                            <td>
                                <?php if ($fileCounts[$coll] < 0): ?>
                                    <span class="badge bg-danger">Not Found</span>
                                <?php else: ?>
                                    <?= number_format($fileCounts[$coll]) ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($fileCounts[$coll] >= 0): ?>
                                <form method="post" style="display:inline;" class="me-1">
                                    <input type="hidden" name="action" value="preview">
                                    <input type="hidden" name="collection" value="<?= htmlspecialchars($coll) ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Preview</button>
                                </form>
                                <form method="post" style="display:inline;"
                                      onsubmit="return confirm('Import <?= $fileCounts[$coll] ?> records into <?= htmlspecialchars($coll) ?>?');">
                                    <input type="hidden" name="action" value="import">
                                    <input type="hidden" name="collection" value="<?= htmlspecialchars($coll) ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Import</button>
                                </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>

        <?php if (!empty($previewData)): ?>
        <div class="card border-0 shadow-sm mt-4">
            <div class="card-header">
                <h5 class="mb-0">Preview: <?= htmlspecialchars($targetCollection) ?> (first 20 records)</h5>
            </div>
            <div class="card-body" style="max-height:500px; overflow:auto;">
                <table class="table table-sm table-bordered" style="font-size:0.8rem;">
                    <thead>
                        <tr>
                            <?php foreach (array_keys($previewData[0]) as $col): ?>
                                <th><?= htmlspecialchars($col) ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($previewData as $row): ?>
                        <tr>
                            <?php foreach ($row as $val): ?>
                                <td><?= htmlspecialchars(is_array($val) ? json_encode($val) : (string)$val) ?></td>
                            <?php endforeach; ?>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
