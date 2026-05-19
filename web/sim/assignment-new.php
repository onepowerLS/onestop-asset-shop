<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/it_am.php';
require_login();

$simId = trim((string)($_GET['sim_id'] ?? ''));
if ($simId === '') {
    $_SESSION['flash_error'] = 'Missing sim_id.';
    header('Location: ' . base_url('sim/index.php'));
    exit;
}

$sim = am_firestore_get_document(AM_SIM_CARDS_COLLECTION, $simId);
if ($sim === null) {
    $_SESSION['flash_error'] = 'SIM not found.';
    header('Location: ' . base_url('sim/index.php'));
    exit;
}

$page_title = 'New SIM assignment';
$errors = [];
$assignmentType = trim((string)($_POST['assignment_type'] ?? 'team'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    am_require_can_mutate();
    $assignmentType = trim((string)($_POST['assignment_type'] ?? ''));

    if ($assignmentType === 'team' && !am_can_sim_team_assign()) {
        $errors[] = 'Your account cannot assign SIMs to teams (Finance capability required).';
    }
    if ($assignmentType === 'phone_asset' && !am_can_sim_phone_link()) {
        $errors[] = 'Your account cannot link SIMs to phone assets (IT capability required).';
    }

    $teamLabel = trim((string)($_POST['team_label'] ?? ''));
    $assetId = trim((string)($_POST['asset_id'] ?? ''));
    $notes = trim((string)($_POST['notes'] ?? ''));

    if (!in_array($assignmentType, am_sim_assignment_types(), true)) {
        $errors[] = 'Invalid assignment type.';
    }
    if ($assignmentType === 'team' && $teamLabel === '') {
        $errors[] = 'Team / function label is required.';
    }
    if ($assignmentType === 'phone_asset' && $assetId === '') {
        $errors[] = 'Phone asset id is required.';
    }

    if (empty($errors)) {
        $data = [
            'sim_id' => $simId,
            'assignment_type' => $assignmentType,
            'team_label' => $assignmentType === 'team' ? $teamLabel : '',
            'asset_id' => $assignmentType === 'phone_asset' ? $assetId : '',
            'site_label' => trim((string)($_POST['site_label'] ?? '')),
            'notes' => $notes,
            'valid_from' => date('c'),
            'valid_to' => '',
            'assigned_by' => (string)($_SESSION['user_id'] ?? ''),
            'created_at' => date('c'),
        ];

        $result = am_firestore_create_document(AM_SIM_ASSIGNMENTS_COLLECTION, $data);
        if ($result['ok']) {
            $_SESSION['flash_success'] = 'Assignment recorded.';
            header('Location: ' . base_url('sim/index.php'));
            exit;
        }
        $errors[] = (string)($result['error'] ?? 'Failed to save assignment.');
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-3 mb-3 border-bottom">
    <h1 class="h2 mb-0">Assign SIM</h1>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('sim/index.php'); ?>">Cancel</a>
</div>

<p class="small text-muted">SIM: <strong><?php echo htmlspecialchars((string)($sim['msisdn_normalized'] ?? '')); ?></strong></p>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
<?php endif; ?>

<div class="card border-0 shadow">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-12">
                <label class="form-label">Assignment type</label>
                <select name="assignment_type" class="form-select" id="atype">
                    <option value="team" <?php echo $assignmentType === 'team' ? 'selected' : ''; ?>>Team / function / cost pool</option>
                    <option value="phone_asset" <?php echo $assignmentType === 'phone_asset' ? 'selected' : ''; ?>>Phone handset (asset id)</option>
                    <option value="vehicle_tracker" <?php echo $assignmentType === 'vehicle_tracker' ? 'selected' : ''; ?>>Vehicle / tracker (informational)</option>
                    <option value="site_gateway" <?php echo $assignmentType === 'site_gateway' ? 'selected' : ''; ?>>Site gateway</option>
                    <option value="other" <?php echo $assignmentType === 'other' ? 'selected' : ''; ?>>Other</option>
                </select>
            </div>
            <div class="col-12" id="wrap-team">
                <label class="form-label">Team / function label</label>
                <input type="text" name="team_label" class="form-control" value="<?php echo htmlspecialchars((string)($_POST['team_label'] ?? '')); ?>"
                    placeholder="e.g. Reticulation, Facilities">
            </div>
            <div class="col-12" id="wrap-asset" style="display:none;">
                <label class="form-label">Asset document id (phone in catalog)</label>
                <input type="text" name="asset_id" class="form-control" value="<?php echo htmlspecialchars((string)($_POST['asset_id'] ?? '')); ?>"
                    placeholder="Firestore id from item detail">
            </div>
            <div class="col-12">
                <label class="form-label">Site label (optional)</label>
                <input type="text" name="site_label" class="form-control" value="<?php echo htmlspecialchars((string)($_POST['site_label'] ?? '')); ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="2"><?php echo htmlspecialchars((string)($_POST['notes'] ?? '')); ?></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Save assignment</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    function sync() {
        var t = document.getElementById('atype').value;
        document.getElementById('wrap-team').style.display = (t === 'team' || t === 'other') ? 'block' : 'none';
        document.getElementById('wrap-asset').style.display = (t === 'phone_asset') ? 'block' : 'none';
    }
    document.getElementById('atype').addEventListener('change', sync);
    sync();
})();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
