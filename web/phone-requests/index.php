<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/it_am.php';
require_login();

$page_title = 'Phone requests';

$rows = am_firestore_get_collection(AM_PHONE_REQUESTS_COLLECTION, 2000);
$countries = am_firestore_get_collection('pr_master_countries', 500);

$errors = [];
$showForm = isset($_GET['new']);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    am_require_can_mutate();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'create') {
        $justification = trim((string)($_POST['justification'] ?? ''));
        $countryId = trim((string)($_POST['country_id'] ?? ''));
        if ($justification === '') {
            $errors[] = 'Justification is required.';
        }
        if ($countryId === '') {
            $errors[] = 'Country is required.';
        }
        if (empty($errors)) {
            $num = am_phone_request_next_number($rows);
            $data = [
                'request_number' => $num,
                'justification' => $justification,
                'country_id' => $countryId,
                'status' => 'Submitted',
                'requested_by' => (string)($_SESSION['user_id'] ?? ''),
                'requested_by_name' => (string)($_SESSION['username'] ?? ''),
                'requested_at' => date('c'),
                'notes' => trim((string)($_POST['notes'] ?? '')),
                'fulfilled_notes' => '',
            ];
            $result = am_firestore_create_document(AM_PHONE_REQUESTS_COLLECTION, $data);
            if ($result['ok']) {
                $_SESSION['flash_success'] = 'Request ' . $num . ' submitted.';
                header('Location: ' . base_url('phone-requests/index.php'));
                exit;
            }
            $errors[] = (string)($result['error'] ?? 'Failed to submit.');
        }
        $showForm = true;
    } elseif ($action === 'update_status' && am_is_manager_role()) {
        $docId = trim((string)($_POST['doc_id'] ?? ''));
        $newStatus = trim((string)($_POST['new_status'] ?? ''));
        $fulfilled = trim((string)($_POST['fulfilled_notes'] ?? ''));
        if ($docId !== '' && in_array($newStatus, am_phone_request_statuses(), true)) {
            $upd = ['status' => $newStatus];
            if ($fulfilled !== '') {
                $upd['fulfilled_notes'] = $fulfilled;
            }
            am_firestore_update_document(AM_PHONE_REQUESTS_COLLECTION, $docId, $upd);
            $_SESSION['flash_success'] = 'Request updated.';
        }
        header('Location: ' . base_url('phone-requests/index.php'));
        exit;
    }
}

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

usort($rows, function ($a, $b) {
    return strtotime((string)($b['requested_at'] ?? '')) <=> strtotime((string)($a['requested_at'] ?? ''));
});

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-3 mb-3 border-bottom">
    <h1 class="h2 mb-0">Phone requests</h1>
    <?php if (!am_is_auditor_readonly()): ?>
        <a class="btn btn-sm btn-primary" href="<?php echo base_url('phone-requests/index.php?new=1'); ?>">New request</a>
    <?php endif; ?>
</div>

<?php if ($flash !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>

<?php if ($showForm && !am_is_auditor_readonly()): ?>
    <div class="card border-0 shadow mb-4">
        <div class="card-header bg-white"><strong>New phone request</strong></div>
        <div class="card-body">
            <?php if (!empty($errors)): ?><div class="alert alert-danger"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div><?php endif; ?>
            <form method="post" class="row g-3">
                <input type="hidden" name="action" value="create">
                <div class="col-md-6">
                    <label class="form-label">Country</label>
                    <select name="country_id" class="form-select" required>
                        <option value="">—</option>
                        <?php foreach ($countries as $c): ?>
                            <?php $cid = (string)($c['country_id'] ?? $c['id'] ?? ''); ?>
                            <option value="<?php echo htmlspecialchars($cid); ?>"><?php echo htmlspecialchars((string)($c['country_name'] ?? $cid)); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12">
                    <label class="form-label">What do you need?</label>
                    <textarea name="justification" class="form-control" rows="4" required></textarea>
                </div>
                <div class="col-12">
                    <label class="form-label">Notes (optional)</label>
                    <textarea name="notes" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary">Submit</button>
                    <a class="btn btn-outline-secondary" href="<?php echo base_url('phone-requests/index.php'); ?>">Cancel</a>
                </div>
            </form>
        </div>
    </div>
<?php endif; ?>

<div class="card border-0 shadow">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover mb-0">
                <thead>
                    <tr>
                        <th>Request</th>
                        <th>Country</th>
                        <th>Status</th>
                        <th>Requested</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($rows as $r): ?>
                        <?php
                        $rid = (string)($r['id'] ?? '');
                        $cc = '';
                        foreach ($countries as $c) {
                            if ((string)($c['country_id'] ?? $c['id'] ?? '') === (string)($r['country_id'] ?? '')) {
                                $cc = (string)($c['country_name'] ?? '');
                                break;
                            }
                        }
                        ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($r['request_number'] ?? $rid)); ?></td>
                            <td><?php echo htmlspecialchars($cc !== '' ? $cc : (string)($r['country_id'] ?? '')); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars((string)($r['status'] ?? '')); ?></span></td>
                            <td class="small"><?php echo htmlspecialchars(substr((string)($r['requested_at'] ?? ''), 0, 16)); ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" type="button" data-bs-toggle="collapse" data-bs-target="#pr<?php echo htmlspecialchars(md5($rid)); ?>">View</button>
                            </td>
                        </tr>
                        <tr class="collapse" id="pr<?php echo htmlspecialchars(md5($rid)); ?>">
                            <td colspan="5" class="bg-light small">
                                <p class="mb-1"><strong>Justification</strong><br><?php echo nl2br(htmlspecialchars((string)($r['justification'] ?? ''))); ?></p>
                                <?php if (!empty($r['notes'])): ?>
                                    <p class="mb-1"><strong>Notes</strong><br><?php echo nl2br(htmlspecialchars((string)$r['notes'])); ?></p>
                                <?php endif; ?>
                                <?php if (am_is_manager_role() && !am_is_auditor_readonly()): ?>
                                    <form method="post" class="row g-2 align-items-end mt-2">
                                        <input type="hidden" name="action" value="update_status">
                                        <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($rid); ?>">
                                        <div class="col-auto">
                                            <select name="new_status" class="form-select form-select-sm">
                                                <?php foreach (am_phone_request_statuses() as $st): ?>
                                                    <option value="<?php echo htmlspecialchars($st); ?>" <?php echo (($r['status'] ?? '') === $st) ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="col-md-4">
                                            <input type="text" name="fulfilled_notes" class="form-control form-control-sm" placeholder="Fulfillment notes" value="<?php echo htmlspecialchars((string)($r['fulfilled_notes'] ?? '')); ?>">
                                        </div>
                                        <div class="col-auto">
                                            <button type="submit" class="btn btn-sm btn-primary">Save</button>
                                        </div>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
