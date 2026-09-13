<?php
/**
 * Catalogue stewardship queue — classify, verify UGP match, unit/spec conflicts.
 * Photo capture tasks are listed but not actionable until Phase 3 storage lands.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/locale.php';
require_once __DIR__ . '/../config/part_definitions.php';
require_login();
am_ensure_country_scope_from_session();
am_require_can_mutate();

$page_title = 'Catalogue tasks';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $taskId = trim((string)($_POST['task_id'] ?? ''));
    if ($action === 'resolve' && $taskId !== '') {
        $res = am_firestore_update_document(AM_CATALOGUE_TASKS_COLLECTION, $taskId, [
            'status' => 'resolved',
            'resolved_at' => date('c'),
            'resolved_by' => (string)($_SESSION['user_id'] ?? ''),
            'updated_at' => date('c'),
        ]);
        $message = $res['ok'] ? 'Task resolved.' : ('Failed: ' . ($res['error'] ?? 'unknown'));
        if (!$res['ok']) {
            $error = $message;
            $message = '';
        }
    } elseif ($action === 'create') {
        $res = am_catalogue_task_create([
            'task_type' => trim((string)($_POST['task_type'] ?? 'classify_item')),
            'reason' => trim((string)($_POST['reason'] ?? '')),
            'asset_id' => trim((string)($_POST['asset_id'] ?? '')),
            'definition_id' => trim((string)($_POST['definition_id'] ?? '')),
            'country_id' => trim((string)($_POST['country_id'] ?? '')),
            'owner_name' => trim((string)($_POST['owner_name'] ?? '')),
            'due_at' => trim((string)($_POST['due_at'] ?? '')),
            'site_impact' => trim((string)($_POST['site_impact'] ?? '')),
        ]);
        if ($res['ok']) {
            $message = 'Task created.';
        } else {
            $error = (string)($res['error'] ?? 'Create failed');
        }
    }
}

$tasks = am_firestore_get_collection(AM_CATALOGUE_TASKS_COLLECTION, 1000);
usort($tasks, static function ($a, $b) {
    return strcmp((string)($b['created_at'] ?? ''), (string)($a['created_at'] ?? ''));
});
$open = array_values(array_filter($tasks, static fn($t) => in_array((string)($t['status'] ?? ''), ['open', 'in_progress'], true)));

include __DIR__ . '/../includes/header.php';
?>
<div class="py-4">
    <h1 class="h2">Catalogue tasks</h1>
    <p class="text-muted">Named-owner queue for classification and mapping stewardship. Photos deferred until storage is confirmed.</p>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card border-0 shadow mb-4">
        <div class="card-header"><strong>Create task</strong></div>
        <div class="card-body">
            <form method="post" class="row g-2">
                <input type="hidden" name="action" value="create">
                <div class="col-md-3">
                    <label class="form-label">Type</label>
                    <select name="task_type" class="form-select">
                        <?php foreach (am_catalogue_task_types() as $tt): if ($tt === 'capture_reference_image') continue; ?>
                        <option value="<?php echo htmlspecialchars($tt); ?>"><?php echo htmlspecialchars($tt); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Asset id (optional)</label>
                    <input name="asset_id" class="form-control" placeholder="Firestore asset id">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Owner name</label>
                    <input name="owner_name" class="form-control">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Due</label>
                    <input type="date" name="due_at" class="form-control">
                </div>
                <div class="col-12">
                    <label class="form-label">Reason (actionable)</label>
                    <input name="reason" class="form-control" required placeholder="e.g. Classify ABC conductor for LSO forecast coverage">
                </div>
                <div class="col-12">
                    <button class="btn btn-primary btn-sm" type="submit">Create</button>
                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo base_url('admin/part-definitions.php'); ?>">Part definitions</a>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow">
        <div class="card-header"><strong>Open / in progress (<?php echo count($open); ?>)</strong></div>
        <div class="card-body table-responsive">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>Created</th>
                        <th>Type</th>
                        <th>Reason</th>
                        <th>Asset</th>
                        <th>Owner</th>
                        <th>Due</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($open === []): ?>
                    <tr><td colspan="7" class="text-muted">No open catalogue tasks.</td></tr>
                    <?php else: foreach ($open as $t): ?>
                    <tr>
                        <td><small><?php echo htmlspecialchars(substr((string)($t['created_at'] ?? ''), 0, 10)); ?></small></td>
                        <td><code><?php echo htmlspecialchars((string)($t['task_type'] ?? '')); ?></code></td>
                        <td><?php echo htmlspecialchars((string)($t['reason'] ?? '')); ?></td>
                        <td>
                            <?php if (!empty($t['asset_id'])): ?>
                            <a href="<?php echo base_url('assets/view.php?id=' . urlencode((string)$t['asset_id'])); ?>"><?php echo htmlspecialchars((string)$t['asset_id']); ?></a>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td><?php echo htmlspecialchars((string)($t['owner_name'] ?? '—')); ?></td>
                        <td><?php echo htmlspecialchars((string)($t['due_at'] ?? '—')); ?></td>
                        <td>
                            <form method="post" class="d-inline" onsubmit="return confirm('Mark resolved?');">
                                <input type="hidden" name="action" value="resolve">
                                <input type="hidden" name="task_id" value="<?php echo htmlspecialchars((string)($t['id'] ?? '')); ?>">
                                <button class="btn btn-sm btn-success" type="submit">Resolve</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
