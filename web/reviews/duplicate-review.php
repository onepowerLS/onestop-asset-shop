<?php
/**
 * Country-aware duplicate review workspace (Managers, duplicate_review / AM ops capabilities).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/locale.php';
require_once __DIR__ . '/../config/duplicate_assets.php';
require_login();
am_ensure_country_scope_from_session();
am_require_duplicate_review_access();

$page_title = 'Duplicate review';
$message = '';
$error = '';

$countries = am_firestore_get_collection('pr_master_countries', 500);
$locations = am_get_pr_sites();
$locationById = [];
foreach ($locations as $l) {
    $lid = (string)($l['location_id'] ?? $l['id'] ?? '');
    if ($lid !== '') {
        $locationById[$lid] = $l;
    }
}

$assets = am_firestore_get_collection('am_core_assets', 10000);
$rawGroups = am_duplicate_find_groups($assets);
$dismissed = am_duplicate_load_dismissed_group_keys();
$groups = am_duplicate_filter_groups_not_dismissed($rawGroups, $dismissed);

$groups = array_values(array_filter($groups, function ($g) use ($countries, $locationById) {
    return am_duplicate_group_visible_in_country_scope($g, $countries, $locationById);
}));

$mergeRequests = [];
foreach (am_duplicate_merge_requests_pending() as $req) {
    if (am_duplicate_merge_request_visible_for_user($req, $countries)) {
        $mergeRequests[] = $req;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));

    if ($action === 'dismiss') {
        $gk = trim((string)($_POST['group_key'] ?? ''));
        $reason = trim((string)($_POST['reason'] ?? ''));
        $ids = $_POST['asset_ids'] ?? [];
        if (!is_array($ids)) {
            $ids = [];
        }
        $ids = array_map('strval', $ids);
        if ($gk === '' || $ids === []) {
            $error = 'Invalid dismissal.';
        } else {
            $uid = (string)($_SESSION['firebase_uid'] ?? $_SESSION['user_id'] ?? '');
            $r = am_duplicate_save_dismissal($gk, $ids, $uid, $reason);
            if ($r['ok']) {
                $message = 'Marked as not duplicate. This group will stay hidden until data changes.';
                $dismissed = am_duplicate_load_dismissed_group_keys();
                $groups = am_duplicate_filter_groups_not_dismissed($rawGroups, $dismissed);
                $groups = array_values(array_filter($groups, fn($g) => am_duplicate_group_visible_in_country_scope($g, $countries, $locationById)));
            } else {
                $error = (string)($r['error'] ?? 'Dismiss failed.');
            }
        }
    } elseif ($action === 'request_merge') {
        $keeper = trim((string)($_POST['keeper_id'] ?? ''));
        $loser = trim((string)($_POST['loser_id'] ?? ''));
        $notes = trim((string)($_POST['notes'] ?? ''));
        $mode = (string)($_POST['merge_mode'] ?? 'purgatory_30d');
        if (!in_array($mode, ['purgatory_30d', 'immediate'], true)) {
            $mode = 'purgatory_30d';
        }
        if ($keeper === '' || $loser === '' || $keeper === $loser) {
            $error = 'Select keeper and duplicate to remove.';
        } else {
            $uid = (string)($_SESSION['firebase_uid'] ?? $_SESSION['user_id'] ?? '');
            $r = am_duplicate_create_merge_request($keeper, $loser, $notes, $uid, $mode);
            if ($r['ok']) {
                $message = 'Merge request submitted. A Manager can execute it below.';
                $mergeRequests = [];
                foreach (am_duplicate_merge_requests_pending() as $req) {
                    if (am_duplicate_merge_request_visible_for_user($req, $countries)) {
                        $mergeRequests[] = $req;
                    }
                }
            } else {
                $error = (string)($r['error'] ?? 'Request failed.');
            }
        }
    } elseif ($action === 'execute_merge' || $action === 'execute_merge_direct') {
        am_require_duplicate_merge_execute();
        $reqId = trim((string)($_POST['request_id'] ?? ''));
        $keeper = '';
        $loser = '';
        $mode = 'purgatory_30d';
        if ($action === 'execute_merge' && $reqId !== '') {
            $req = null;
            foreach (am_duplicate_merge_requests_pending() as $r0) {
                if ((string)($r0['id'] ?? '') === $reqId) {
                    $req = $r0;
                    break;
                }
            }
            if (!$req) {
                $error = 'Request not found or already resolved.';
            } else {
                $keeper = (string)($req['keeper_id'] ?? '');
                $loser = (string)($req['loser_id'] ?? '');
                $mode = (string)($req['merge_mode'] ?? 'purgatory_30d');
            }
        } else {
            $keeper = trim((string)($_POST['keeper_id'] ?? ''));
            $loser = trim((string)($_POST['loser_id'] ?? ''));
            $mode = (string)($_POST['merge_mode'] ?? 'purgatory_30d');
        }
        if (!in_array($mode, ['purgatory_30d', 'immediate'], true)) {
            $mode = 'purgatory_30d';
        }
        if ($error === '' && ($keeper === '' || $loser === '' || $keeper === $loser)) {
            $error = 'Invalid merge targets.';
        }
        if ($error === '') {
            $r = am_duplicate_merge_loser_into_keeper($keeper, $loser, $mode);
            if ($r['ok']) {
                if ($reqId !== '') {
                    am_duplicate_complete_merge_request($reqId);
                }
                $message = 'Merge completed.';
                $assets = am_firestore_get_collection('am_core_assets', 10000);
                $rawGroups = am_duplicate_find_groups($assets);
                $groups = am_duplicate_filter_groups_not_dismissed($rawGroups, am_duplicate_load_dismissed_group_keys());
                $groups = array_values(array_filter($groups, fn($g) => am_duplicate_group_visible_in_country_scope($g, $countries, $locationById)));
                $mergeRequests = [];
                foreach (am_duplicate_merge_requests_pending() as $req) {
                    if (am_duplicate_merge_request_visible_for_user($req, $countries)) {
                        $mergeRequests[] = $req;
                    }
                }
            } else {
                $error = (string)($r['error'] ?? 'Merge failed.');
            }
        }
    } elseif ($action === 'cancel_request') {
        $reqId = trim((string)($_POST['request_id'] ?? ''));
        if ($reqId === '') {
            $error = 'Missing request.';
        } else {
            $r = am_duplicate_cancel_merge_request($reqId);
            if ($r['ok']) {
                $message = 'Request cancelled.';
                $mergeRequests = [];
                foreach (am_duplicate_merge_requests_pending() as $req) {
                    if (am_duplicate_merge_request_visible_for_user($req, $countries)) {
                        $mergeRequests[] = $req;
                    }
                }
            } else {
                $error = (string)($r['error'] ?? 'Cancel failed.');
            }
        }
    }
}

$canExecute = am_can_duplicate_merge_execute();

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo base_url('index.php'); ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Duplicate review</li>
        </ol>
    </nav>

    <h1 class="h2 mb-2">Duplicate review</h1>
    <p class="text-muted mb-4">
        Suspected duplicates share a <strong>tag</strong>, <strong>QR id</strong>, or <strong>legacy id</strong> (case-insensitive). Only records in <strong>your permitted countries</strong> appear here.
        Use <strong>Edit</strong> to fix tags before merging. <strong>Not a duplicate</strong> hides the group for everyone.
        <?php if (!$canExecute): ?>
        <span class="d-block mt-2"><strong>Managers</strong> can run merges; you can submit a <strong>merge request</strong> for approval.</span>
        <?php endif; ?>
    </p>

    <?php if ($message !== ''): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <?php if (!empty($mergeRequests)): ?>
    <div class="card border-0 shadow mb-4 border-start border-warning border-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <span><i class="fas fa-hourglass-half me-2"></i>Pending merge requests</span>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Keeper</th>
                            <th>Remove</th>
                            <th>Mode</th>
                            <th>Notes</th>
                            <th>Requested</th>
                            <th></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($mergeRequests as $req):
                            $rid = (string)($req['id'] ?? '');
                            $kid = (string)($req['keeper_id'] ?? '');
                            $lid = (string)($req['loser_id'] ?? '');
                        ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($kid); ?></code></td>
                            <td><code><?php echo htmlspecialchars($lid); ?></code></td>
                            <td><?php echo htmlspecialchars((string)($req['merge_mode'] ?? '')); ?></td>
                            <td><small><?php echo htmlspecialchars((string)($req['notes'] ?? '')); ?></small></td>
                            <td><small><?php echo htmlspecialchars((string)($req['created_at'] ?? '')); ?></small></td>
                            <td class="text-end">
                                <?php if ($canExecute && $rid !== ''): ?>
                                <form method="post" class="d-inline" onsubmit="return confirm('Execute merge and remove duplicate?');">
                                    <input type="hidden" name="action" value="execute_merge">
                                    <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($rid); ?>">
                                    <button type="submit" class="btn btn-sm btn-primary">Execute merge</button>
                                </form>
                                <form method="post" class="d-inline" onsubmit="return confirm('Cancel this request?');">
                                    <input type="hidden" name="action" value="cancel_request">
                                    <input type="hidden" name="request_id" value="<?php echo htmlspecialchars($rid); ?>">
                                    <button type="submit" class="btn btn-sm btn-outline-secondary">Cancel</button>
                                </form>
                                <?php else: ?>
                                <span class="text-muted small">Awaiting Manager</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <?php if (empty($groups)): ?>
    <div class="card border-0 shadow"><div class="card-body">No suspected duplicate groups in your country scope. <?php if ($canExecute): ?><a href="<?php echo base_url('admin/duplicate-assets.php'); ?>">Admin duplicate tool</a> scans all countries.<?php endif; ?></div></div>
    <?php else: ?>
    <?php foreach ($groups as $gi => $group):
        $gk = am_duplicate_group_key_from_assets($group);
        $rec = am_duplicate_pick_recommended_keeper($group);
        $recId = (string)($rec['id'] ?? $rec['asset_id'] ?? '');
        $ids = array_map(fn($a) => (string)($a['id'] ?? $a['asset_id'] ?? ''), $group);
    ?>
    <div class="card border-0 shadow mb-4">
        <div class="card-header d-flex flex-wrap justify-content-between align-items-center gap-2">
            <span>Group <?php echo (int)($gi + 1); ?> — <?php echo count($group); ?> records</span>
            <?php if ($recId !== ''): ?>
            <span class="badge bg-success">Suggested keeper: <?php echo htmlspecialchars($rec['name'] ?? $recId); ?> (score <?php echo (int)am_asset_data_richness_score($rec); ?>)</span>
            <?php endif; ?>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead>
                        <tr>
                            <th>Actions</th>
                            <th>Document ID</th>
                            <th>Name</th>
                            <th class="text-end">Score</th>
                            <th>Asset tag</th>
                            <th>QR</th>
                            <th>Legacy</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group as $a):
                            $id = (string)($a['id'] ?? $a['asset_id'] ?? '');
                            $score = am_asset_data_richness_score($a);
                        ?>
                        <tr>
                            <td>
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('assets/view.php?id=' . urlencode($id)); ?>">View</a>
                                <?php if (!am_is_auditor_readonly()): ?>
                                <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('assets/edit.php?id=' . urlencode($id)); ?>" target="_blank" rel="noopener">Edit</a>
                                <?php endif; ?>
                            </td>
                            <td><code><?php echo htmlspecialchars($id); ?></code></td>
                            <td><?php echo htmlspecialchars((string)($a['name'] ?? '')); ?></td>
                            <td class="text-end"><?php echo (int)$score; ?></td>
                            <td><small><?php echo htmlspecialchars((string)($a['asset_tag'] ?? '')); ?></small></td>
                            <td><small><?php echo htmlspecialchars((string)($a['qr_code_id'] ?? '')); ?></small></td>
                            <td><small><?php echo htmlspecialchars((string)($a['legacy_tag'] ?? '')); ?></small></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        <div class="card-footer bg-light">
            <div class="row g-3">
                <div class="col-lg-6">
                    <h3 class="h6">Not duplicates</h3>
                    <form method="post" class="border rounded p-3 bg-white" onsubmit="return confirm('Hide this group as a false positive?');">
                        <input type="hidden" name="action" value="dismiss">
                        <input type="hidden" name="group_key" value="<?php echo htmlspecialchars($gk); ?>">
                        <?php foreach ($ids as $iid): ?>
                        <input type="hidden" name="asset_ids[]" value="<?php echo htmlspecialchars($iid); ?>">
                        <?php endforeach; ?>
                        <label class="form-label small">Note (optional)</label>
                        <input type="text" name="reason" class="form-control form-control-sm mb-2" placeholder="e.g. different sites, same legacy code reused">
                        <button type="submit" class="btn btn-sm btn-outline-secondary">Mark as not duplicate</button>
                    </form>
                </div>
                <div class="col-lg-6">
                    <h3 class="h6">Merge into one record</h3>
                    <?php if ($canExecute): ?>
                    <form method="post" class="border rounded p-3 bg-white" onsubmit="return confirm('Merge duplicate into keeper? This deletes the loser document.');">
                        <input type="hidden" name="action" value="execute_merge_direct">
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label small">Keeper (survives)</label>
                                <input type="text" name="keeper_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($recId); ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small">Duplicate to remove</label>
                                <select name="loser_id" class="form-select form-select-sm" required>
                                    <option value="">— Select —</option>
                                    <?php foreach ($group as $a):
                                        $id = (string)($a['id'] ?? $a['asset_id'] ?? '');
                                        if ($id === $recId) {
                                            continue;
                                        }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($id . ' — ' . ($a['name'] ?? '')); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <select name="merge_mode" class="form-select form-select-sm">
                                    <option value="purgatory_30d">Purgatory snapshot 30 days</option>
                                    <option value="immediate">Immediate (no snapshot)</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-sm btn-danger">Merge now</button>
                            </div>
                        </div>
                    </form>
                    <?php else: ?>
                    <form method="post" class="border rounded p-3 bg-white">
                        <input type="hidden" name="action" value="request_merge">
                        <div class="row g-2">
                            <div class="col-12">
                                <label class="form-label small">Keeper (survives)</label>
                                <input type="text" name="keeper_id" class="form-control form-control-sm" value="<?php echo htmlspecialchars($recId); ?>" required>
                            </div>
                            <div class="col-12">
                                <label class="form-label small">Duplicate to remove</label>
                                <select name="loser_id" class="form-select form-select-sm" required>
                                    <option value="">— Select —</option>
                                    <?php foreach ($group as $a):
                                        $id = (string)($a['id'] ?? $a['asset_id'] ?? '');
                                        if ($id === $recId) {
                                            continue;
                                        }
                                    ?>
                                    <option value="<?php echo htmlspecialchars($id); ?>"><?php echo htmlspecialchars($id); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-12">
                                <select name="merge_mode" class="form-select form-select-sm">
                                    <option value="purgatory_30d">Purgatory 30 days</option>
                                    <option value="immediate">Immediate</option>
                                </select>
                            </div>
                            <div class="col-12">
                                <label class="form-label small">Notes for Manager</label>
                                <textarea name="notes" class="form-control form-control-sm" rows="2" placeholder="Why merge / any cautions"></textarea>
                            </div>
                            <div class="col-12">
                                <button type="submit" class="btn btn-sm btn-primary">Submit merge request</button>
                            </div>
                        </div>
                    </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
