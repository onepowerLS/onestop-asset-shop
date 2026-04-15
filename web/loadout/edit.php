<?php
/**
 * Create or edit a load-out manifest.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/loadout_manifests.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_login();
am_ensure_country_scope_from_session();
am_require_can_mutate();

$docId = trim($_GET['id'] ?? '');
$isNew = ($docId === '');

$manifest = null;
$countries = am_firestore_get_collection('pr_master_countries', 500);
if (!$isNew) {
    $manifest = am_firestore_get_document(AM_LOADOUT_COLLECTION, $docId);
    if (!$manifest) {
        $_SESSION['flash_error'] = 'Manifest not found.';
        header('Location: ' . base_url('loadout/index.php'));
        exit;
    }
    if (!am_record_in_country_scope($manifest, $countries)) {
        $_SESSION['flash_error'] = 'Manifest is outside your country scope.';
        header('Location: ' . base_url('loadout/index.php'));
        exit;
    }
}

$page_title = $isNew ? 'New load-out manifest' : 'Edit manifest';

$sites = am_get_pr_sites();
$locationById = [];
foreach ($sites as $l) {
    $lid = (string)($l['location_id'] ?? $l['id'] ?? '');
    if ($lid !== '') {
        $locationById[$lid] = $l;
    }
}
$assets = am_firestore_get_collection('am_core_assets', 2000);
$assets = array_values(array_filter($assets, fn($a) => am_asset_passes_country_scope($a, $countries, $locationById)));
$assetById = [];
foreach ($assets as $a) {
    $aid = (string)($a['asset_id'] ?? $a['id'] ?? '');
    if ($aid !== '') {
        $assetById[$aid] = $a;
    }
}
$statuses = am_loadout_statuses();

$errors = [];
$countries = array_values(array_filter($countries, fn($c) => (int)($c['active'] ?? 1) === 1));
$countries = am_countries_for_user_select($countries);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['form_action'] ?? 'save';
    if ($action === 'delete' && !$isNew) {
        $st = (string)($manifest['status'] ?? '');
        if ($st !== 'Draft') {
            $errors[] = 'Only Draft manifests can be deleted.';
        } else {
            $del = am_firestore_delete_document(AM_LOADOUT_COLLECTION, $docId);
            if ($del['ok']) {
                $_SESSION['flash_success'] = 'Manifest deleted.';
                header('Location: ' . base_url('loadout/index.php'));
                exit;
            }
            $errors[] = $del['error'] ?? 'Delete failed.';
        }
    } else {
        $title = trim((string)($_POST['title'] ?? ''));
        $status = trim((string)($_POST['status'] ?? 'Draft'));
        if (!in_array($status, $statuses, true)) {
            $status = 'Draft';
        }
        $originLabel = trim((string)($_POST['origin_label'] ?? 'HQ / Warehouse'));
        if ($originLabel === '') {
            $originLabel = 'HQ / Warehouse';
        }
        $destinationSiteId = trim((string)($_POST['destination_site_id'] ?? ''));
        $countryId = trim((string)($_POST['country_id'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $tripId = trim((string)($_POST['trip_id'] ?? ''));
        $tripLabel = trim((string)($_POST['trip_label'] ?? ''));

        if ($countryId !== '' && !am_user_may_access_country_id($countryId, $countries)) {
            $errors[] = 'You cannot assign this manifest to that country.';
        }

        $rawLines = $_POST['lines'] ?? [];
        $lineRows = [];
        if (is_array($rawLines)) {
            foreach ($rawLines as $row) {
                if (is_array($row)) {
                    $lineRows[] = $row;
                }
            }
        }
        $lines = am_loadout_normalize_lines($lineRows, $assetById);

        if (in_array($status, ['Packed', 'Shipped', 'Delivered'], true) && $destinationSiteId === '') {
            $errors[] = 'Select a destination site for status "' . $status . '".';
        }
        if (in_array($status, ['Packed', 'Shipped', 'Delivered'], true) && empty($lines)) {
            $errors[] = 'Add at least one line item for status "' . $status . '".';
        }

        $destinationLabel = '';
        foreach ($sites as $s) {
            $sid = (string)($s['id'] ?? '');
            if ($sid === $destinationSiteId) {
                $destinationLabel = (string)($s['location_name'] ?? '') . ' (' . (string)($s['country_code'] ?? '') . ')';
                break;
            }
        }

        if (empty($errors)) {
            $uid = (string)($_SESSION['user_id'] ?? '');
            $now = date('c');

            if ($isNew) {
                $all = am_firestore_get_collection(AM_LOADOUT_COLLECTION, 2000);
                $manifestNumber = am_next_loadout_manifest_number($all);
                $data = [
                    'manifest_number'           => $manifestNumber,
                    'title'                     => $title,
                    'status'                    => $status,
                    'origin_label'              => $originLabel,
                    'destination_site_id'       => $destinationSiteId,
                    'destination_site_label'    => $destinationLabel,
                    'country_id'                => $countryId,
                    'notes'                     => $notes,
                    'trip_id'                   => $tripId,
                    'trip_label'                => $tripLabel,
                    'lines'                     => $lines,
                    'source_system'             => 'am.1pwrafrica.com',
                    'created_at'                => $now,
                    'updated_at'                => $now,
                    'created_by'                => $uid,
                    'updated_by'                => $uid,
                ];
                $result = am_firestore_create_document(AM_LOADOUT_COLLECTION, $data);
                if ($result['ok']) {
                    $_SESSION['flash_success'] = 'Manifest ' . $manifestNumber . ' created.';
                    header('Location: ' . base_url('loadout/view.php?id=' . urlencode($result['id'] ?? '')));
                    exit;
                }
                $errors[] = $result['error'] ?? 'Could not save.';
            } else {
                $data = [
                    'title'                  => $title,
                    'status'                 => $status,
                    'origin_label'           => $originLabel,
                    'destination_site_id'    => $destinationSiteId,
                    'destination_site_label' => $destinationLabel,
                    'country_id'             => $countryId,
                    'notes'                  => $notes,
                    'trip_id'                => $tripId,
                    'trip_label'             => $tripLabel,
                    'lines'                  => $lines,
                    'updated_at'             => $now,
                    'updated_by'             => $uid,
                ];
                $result = am_firestore_update_document(AM_LOADOUT_COLLECTION, $docId, $data);
                if ($result['ok']) {
                    $_SESSION['flash_success'] = 'Manifest updated.';
                    header('Location: ' . base_url('loadout/view.php?id=' . urlencode($docId)));
                    exit;
                }
                $errors[] = $result['error'] ?? 'Could not save.';
            }
        }
    }
}

// Defaults for form
$fv = [
    'title' => (string)($manifest['title'] ?? ''),
    'status' => (string)($manifest['status'] ?? 'Draft'),
    'origin_label' => (string)($manifest['origin_label'] ?? 'HQ / Warehouse'),
    'destination_site_id' => (string)($manifest['destination_site_id'] ?? ''),
    'country_id' => (string)($manifest['country_id'] ?? ''),
    'notes' => (string)($manifest['notes'] ?? ''),
    'trip_id' => (string)($manifest['trip_id'] ?? ''),
    'trip_label' => (string)($manifest['trip_label'] ?? ''),
    'lines' => $manifest['lines'] ?? [['asset_id' => '', 'quantity' => 1, 'notes' => '']],
];
if (!is_array($fv['lines']) || empty($fv['lines'])) {
    $fv['lines'] = [['asset_id' => '', 'quantity' => 1, 'notes' => '']];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors) && ($_POST['form_action'] ?? '') === 'save') {
    $rawLines = $_POST['lines'] ?? [];
    $postLines = [];
    if (is_array($rawLines)) {
        foreach ($rawLines as $row) {
            if (is_array($row)) {
                $postLines[] = $row;
            }
        }
    }
    if (empty($postLines)) {
        $postLines = [['asset_id' => '', 'quantity' => 1, 'notes' => '']];
    }
    $fv = [
        'title' => trim((string)($_POST['title'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? 'Draft')),
        'origin_label' => trim((string)($_POST['origin_label'] ?? 'HQ / Warehouse')),
        'destination_site_id' => trim((string)($_POST['destination_site_id'] ?? '')),
        'country_id' => trim((string)($_POST['country_id'] ?? '')),
        'notes' => trim((string)($_POST['notes'] ?? '')),
        'trip_id' => trim((string)($_POST['trip_id'] ?? '')),
        'trip_label' => trim((string)($_POST['trip_label'] ?? '')),
        'lines' => $postLines,
    ];
}

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="mb-4">
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-2">
                <li class="breadcrumb-item"><a href="<?php echo base_url('loadout/index.php'); ?>">Load-out manifests</a></li>
                <li class="breadcrumb-item active"><?php echo $isNew ? 'New' : 'Edit'; ?></li>
            </ol>
        </nav>
        <h1 class="h3"><?php echo htmlspecialchars($page_title); ?></h1>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
    <?php endif; ?>

    <form method="post" class="card border-0 shadow">
        <div class="card-body">
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Title</label>
                    <input type="text" name="title" class="form-control" value="<?php echo htmlspecialchars($fv['title']); ?>" placeholder="e.g. Site X — switchgear">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Status</label>
                    <select name="status" class="form-select">
                        <?php foreach ($statuses as $s): ?>
                        <option value="<?php echo htmlspecialchars($s); ?>" <?php echo $fv['status'] === $s ? 'selected' : ''; ?>><?php echo htmlspecialchars($s); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Country (optional)</label>
                    <select name="country_id" class="form-select">
                        <option value="">—</option>
                        <?php foreach ($countries as $c): ?>
                        <?php $cid = (string)($c['country_id'] ?? $c['id'] ?? ''); ?>
                        <option value="<?php echo htmlspecialchars($cid); ?>" <?php echo $fv['country_id'] === $cid ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string)($c['country_name'] ?? '') . ' (' . (string)($c['country_code'] ?? '') . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="row g-3 mt-1">
                <div class="col-md-4">
                    <label class="form-label">Origin label</label>
                    <input type="text" name="origin_label" class="form-control" value="<?php echo htmlspecialchars($fv['origin_label']); ?>">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Destination site</label>
                    <select name="destination_site_id" class="form-select">
                        <option value="">— select —</option>
                        <?php foreach ($sites as $s): ?>
                        <?php
                            $sid = (string)($s['id'] ?? '');
                            $label = (string)($s['location_name'] ?? '') . ' — ' . (string)($s['location_code'] ?? '');
                        ?>
                        <option value="<?php echo htmlspecialchars($sid); ?>" <?php echo $fv['destination_site_id'] === $sid ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <hr class="my-4">
            <h2 class="h6 text-uppercase text-muted">Fleet / trip (optional)</h2>
            <p class="small text-muted">Fleet Management can set these via API. You can paste a trip ID here so the manifest appears when filtering trips in <code>fm.1pwrafrica.com</code>.</p>
            <div class="row g-3">
                <div class="col-md-4">
                    <label class="form-label">Trip ID</label>
                    <input type="text" name="trip_id" class="form-control" value="<?php echo htmlspecialchars($fv['trip_id']); ?>" placeholder="Firestore doc id from FM">
                </div>
                <div class="col-md-8">
                    <label class="form-label">Trip label</label>
                    <input type="text" name="trip_label" class="form-control" value="<?php echo htmlspecialchars($fv['trip_label']); ?>" placeholder="Optional display name">
                </div>
            </div>

            <hr class="my-4">
            <h2 class="h6 text-uppercase text-muted">Line items</h2>
            <p class="small text-muted">Enter asset document IDs (from the catalog) and quantities.</p>

            <div class="table-responsive">
                <table class="table table-sm" id="lines-table">
                    <thead>
                        <tr>
                            <th style="width:40%">Asset ID</th>
                            <th style="width:12%">Qty</th>
                            <th>Line notes</th>
                            <th style="width:48px"></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($fv['lines'] as $idx => $line): ?>
                        <?php if (!is_array($line)) { continue; } ?>
                        <tr class="line-row">
                            <td>
                                <input type="text" name="lines[<?php echo (int)$idx; ?>][asset_id]" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string)($line['asset_id'] ?? '')); ?>" list="asset-id-list" placeholder="Asset doc id">
                            </td>
                            <td>
                                <input type="number" name="lines[<?php echo (int)$idx; ?>][quantity]" class="form-control form-control-sm" min="1" value="<?php echo (int)($line['quantity'] ?? 1); ?>">
                            </td>
                            <td>
                                <input type="text" name="lines[<?php echo (int)$idx; ?>][notes]" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string)($line['notes'] ?? '')); ?>">
                            </td>
                            <td>
                                <button type="button" class="btn btn-sm btn-outline-danger btn-remove-line" title="Remove">&times;</button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <datalist id="asset-id-list">
                <?php foreach ($assets as $a): ?>
                <?php
                    $aid = (string)($a['asset_id'] ?? $a['id'] ?? '');
                    if ($aid === '') { continue; }
                    $nm = (string)($a['name'] ?? '');
                    $tag = (string)($a['asset_tag'] ?? '');
                ?>
                <option value="<?php echo htmlspecialchars($aid); ?>"><?php echo htmlspecialchars($nm . ' ' . $tag); ?></option>
                <?php endforeach; ?>
            </datalist>
            <button type="button" class="btn btn-sm btn-outline-secondary" id="btn-add-line"><i class="fas fa-plus me-1"></i> Add line</button>

            <div class="mt-4">
                <label class="form-label">Manifest notes</label>
                <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars($fv['notes']); ?></textarea>
            </div>
        </div>
        <div class="card-footer bg-white d-flex flex-wrap gap-2 justify-content-between">
            <div>
                <button type="submit" name="form_action" value="save" class="btn btn-primary">Save</button>
                <a href="<?php echo base_url('loadout/index.php'); ?>" class="btn btn-outline-secondary">Cancel</a>
            </div>
            <?php if (!$isNew && (string)($manifest['status'] ?? '') === 'Draft'): ?>
            <button type="submit" name="form_action" value="delete" class="btn btn-outline-danger" onclick="return confirm('Delete this draft manifest?');">Delete draft</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<script>
(function () {
    var table = document.getElementById('lines-table');
    if (!table) return;
    var tbody = table.querySelector('tbody');
    document.getElementById('btn-add-line').addEventListener('click', function () {
        var idx = tbody.querySelectorAll('tr').length;
        var tr = document.createElement('tr');
        tr.className = 'line-row';
        tr.innerHTML = '<td><input type="text" name="lines[' + idx + '][asset_id]" class="form-control form-control-sm" list="asset-id-list" placeholder="Asset doc id"></td>' +
            '<td><input type="number" name="lines[' + idx + '][quantity]" class="form-control form-control-sm" min="1" value="1"></td>' +
            '<td><input type="text" name="lines[' + idx + '][notes]" class="form-control form-control-sm"></td>' +
            '<td><button type="button" class="btn btn-sm btn-outline-danger btn-remove-line">&times;</button></td>';
        tbody.appendChild(tr);
    });
    table.addEventListener('click', function (e) {
        if (e.target.classList.contains('btn-remove-line')) {
            var rows = tbody.querySelectorAll('tr');
            if (rows.length <= 1) return;
            e.target.closest('tr').remove();
        }
    });
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
