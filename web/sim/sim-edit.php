<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/it_am.php';
require_login();

if (!am_can_sim_team_assign() && !am_can_sim_phone_link()) {
    $_SESSION['flash_error'] = 'You do not have permission to edit SIM records.';
    header('Location: ' . base_url('sim/index.php'));
    exit;
}

$id = trim((string)($_GET['id'] ?? ''));
$existing = $id !== '' ? am_firestore_get_document(AM_SIM_CARDS_COLLECTION, $id) : null;

$page_title = $id === '' ? 'Register SIM' : 'Edit SIM';
$errors = [];

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$flashErr = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    am_require_can_mutate();
    $msisdnRaw = trim((string)($_POST['msisdn'] ?? ''));
    $norm = am_normalize_msisdn($msisdnRaw);
    if ($norm === '') {
        $errors[] = 'Phone / SIM number is required.';
    }

    $data = [
        'msisdn_normalized' => $norm,
        'msisdn_display' => $msisdnRaw,
        'contact_value' => trim((string)($_POST['contact_value'] ?? '')),
        'pool' => trim((string)($_POST['pool'] ?? '')),
        'sim_location' => trim((string)($_POST['sim_location'] ?? '')),
        'person_assigned' => trim((string)($_POST['person_assigned'] ?? '')),
        'status' => trim((string)($_POST['status'] ?? 'Active')),
        'locate_status' => trim((string)($_POST['locate_status'] ?? '')),
        'notes' => trim((string)($_POST['notes'] ?? '')),
        'updated_at' => date('c'),
        'updated_by' => (string)($_SESSION['user_id'] ?? ''),
    ];

    if (!in_array($data['status'], am_sim_statuses(), true)) {
        $errors[] = 'Invalid status.';
    }

    if (empty($errors)) {
        if ($id === '') {
            $data['created_at'] = date('c');
            $data['created_by'] = (string)($_SESSION['user_id'] ?? '');
            $result = am_firestore_create_document(AM_SIM_CARDS_COLLECTION, $data);
        } else {
            $result = am_firestore_update_document(AM_SIM_CARDS_COLLECTION, $id, $data);
        }
        if ($result['ok']) {
            $_SESSION['flash_success'] = $id === '' ? 'SIM registered.' : 'SIM updated.';
            $rid = $id !== '' ? $id : (string)($result['id'] ?? '');
            header('Location: ' . base_url('sim/sim-edit.php?id=' . rawurlencode($rid)));
            exit;
        }
        $errors[] = (string)($result['error'] ?? 'Save failed.');
    }
}

$row = $existing ?? [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($errors)) {
    $row = array_merge($row, $_POST);
}

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-3 mb-3 border-bottom">
    <h1 class="h2 mb-0"><?php echo htmlspecialchars($page_title); ?></h1>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('sim/index.php'); ?>">Back to list</a>
</div>

<?php if ($flash !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
<?php if ($flashErr !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($flashErr); ?></div><?php endif; ?>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
<?php endif; ?>

<div class="card border-0 shadow">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">MSISDN / number</label>
                <input type="text" name="msisdn" class="form-control" required
                    value="<?php echo htmlspecialchars((string)($row['msisdn_display'] ?? $row['msisdn_normalized'] ?? '')); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Status</label>
                <select name="status" class="form-select">
                    <?php foreach (am_sim_statuses() as $st): ?>
                        <option value="<?php echo htmlspecialchars($st); ?>" <?php echo (($row['status'] ?? 'Active') === $st) ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Pool / workbook tab</label>
                <input type="text" name="pool" class="form-control" placeholder="e.g. Reticulation, HQ"
                    value="<?php echo htmlspecialchars((string)($row['pool'] ?? '')); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Contact value (plan / top-up notes)</label>
                <input type="text" name="contact_value" class="form-control"
                    value="<?php echo htmlspecialchars((string)($row['contact_value'] ?? '')); ?>">
            </div>
            <div class="col-12">
                <label class="form-label">SIM location / site label</label>
                <input type="text" name="sim_location" class="form-control"
                    value="<?php echo htmlspecialchars((string)($row['sim_location'] ?? '')); ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Person / team / assignment</label>
                <input type="text" name="person_assigned" class="form-control"
                    value="<?php echo htmlspecialchars((string)($row['person_assigned'] ?? '')); ?>">
            </div>
            <div class="col-md-6">
                <label class="form-label">Locate status</label>
                <input type="text" name="locate_status" class="form-control"
                    value="<?php echo htmlspecialchars((string)($row['locate_status'] ?? '')); ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Notes</label>
                <textarea name="notes" class="form-control" rows="3"><?php echo htmlspecialchars((string)($row['notes'] ?? '')); ?></textarea>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Save</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
