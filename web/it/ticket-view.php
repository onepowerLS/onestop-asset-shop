<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/it_am.php';
require_login();

$am_sidebar_file = __DIR__ . '/../includes/sidebar-it.php';
$app_name_display = 'IT Helpdesk';
$am_brand_suffix = 'IT';
$am_nav_home = am_it_path('index.php');

$id = trim((string)($_GET['id'] ?? ''));
if ($id === '') {
    $_SESSION['flash_error'] = 'Missing ticket id.';
    header('Location: ' . am_it_url('tickets.php'));
    exit;
}

$ticket = am_firestore_get_document(AM_IT_TICKETS_COLLECTION, $id);
if ($ticket === null || empty($ticket['ticket_number'])) {
    $_SESSION['flash_error'] = 'Ticket not found.';
    header('Location: ' . am_it_url('tickets.php'));
    exit;
}

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);
$flashErr = $_SESSION['flash_error'] ?? '';
unset($_SESSION['flash_error']);

$uid = (string)($_SESSION['user_id'] ?? '');
$canManage = am_can_it_queue_manage() || am_can_am_ops_queue_manage();
$isRequester = ((string)($ticket['requester_uid'] ?? '') === $uid);

$page_title = (string)($ticket['ticket_number'] ?? 'Ticket');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    am_require_can_mutate();
    $action = (string)($_POST['action'] ?? '');

    if ($action === 'add_comment') {
        $body = trim((string)($_POST['comment_body'] ?? ''));
        if ($body === '') {
            $_SESSION['flash_error'] = 'Comment cannot be empty.';
        } else {
            $comments = $ticket['comments'] ?? [];
            if (!is_array($comments)) {
                $comments = [];
            }
            $comments[] = [
                'author_uid' => $uid,
                'author_name' => (string)($_SESSION['username'] ?? ''),
                'body' => $body,
                'created_at' => date('c'),
            ];
            $res = am_firestore_update_document(AM_IT_TICKETS_COLLECTION, $id, [
                'comments' => $comments,
                'updated_at' => date('c'),
            ]);
            $_SESSION['flash_success'] = $res['ok'] ? 'Comment added.' : ('Failed: ' . ($res['error'] ?? ''));
        }
        header('Location: ' . am_it_url('ticket-view.php') . '?id=' . rawurlencode($id));
        exit;
    }

    if ($action === 'update_ticket' && $canManage) {
        $newStatus = trim((string)($_POST['status'] ?? ''));
        $assignee = trim((string)($_POST['assignee_name'] ?? ''));
        if (in_array($newStatus, am_it_ticket_statuses(), true)) {
            $update = [
                'status' => $newStatus,
                'updated_at' => date('c'),
            ];
            if ($assignee !== '') {
                $update['assignee_name'] = $assignee;
            }
            $res = am_firestore_update_document(AM_IT_TICKETS_COLLECTION, $id, $update);
            $_SESSION['flash_success'] = $res['ok'] ? 'Ticket updated.' : ('Failed: ' . ($res['error'] ?? ''));
        }
        header('Location: ' . am_it_url('ticket-view.php') . '?id=' . rawurlencode($id));
        exit;
    }

    header('Location: ' . am_it_url('ticket-view.php') . '?id=' . rawurlencode($id));
    exit;
}

$comments = $ticket['comments'] ?? [];
if (!is_array($comments)) {
    $comments = [];
}

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-3 mb-3 border-bottom">
    <div>
        <h1 class="h2 mb-0"><?php echo htmlspecialchars((string)($ticket['ticket_number'] ?? '')); ?></h1>
        <p class="text-muted small mb-0"><?php echo htmlspecialchars((string)($ticket['title'] ?? '')); ?></p>
    </div>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars(am_it_url('tickets.php')); ?>">All tickets</a>
</div>

<?php if ($flash !== ''): ?><div class="alert alert-success"><?php echo htmlspecialchars($flash); ?></div><?php endif; ?>
<?php if ($flashErr !== ''): ?><div class="alert alert-danger"><?php echo htmlspecialchars($flashErr); ?></div><?php endif; ?>

<div class="row g-4">
    <div class="col-lg-8">
        <div class="card border-0 shadow mb-3">
            <div class="card-header bg-white"><strong>Description</strong></div>
            <div class="card-body">
                <p class="mb-0" style="white-space:pre-wrap;"><?php echo htmlspecialchars((string)($ticket['description'] ?? '')); ?></p>
            </div>
        </div>

        <div class="card border-0 shadow">
            <div class="card-header bg-white"><strong>Activity</strong></div>
            <div class="card-body">
                <?php if (empty($comments)): ?>
                    <p class="text-muted small mb-0">No comments yet.</p>
                <?php else: ?>
                    <?php foreach ($comments as $c): ?>
                        <div class="border-bottom pb-3 mb-3">
                            <div class="small text-muted"><?php echo htmlspecialchars((string)($c['created_at'] ?? '')); ?>
                                — <?php echo htmlspecialchars((string)($c['author_name'] ?? '')); ?></div>
                            <div style="white-space:pre-wrap;"><?php echo htmlspecialchars((string)($c['body'] ?? '')); ?></div>
                        </div>
                    <?php endforeach; ?>
                <?php endif; ?>

                <?php if (($isRequester || $canManage) && !am_is_auditor_readonly()): ?>
                    <form method="post" class="mt-3">
                        <input type="hidden" name="action" value="add_comment">
                        <label class="form-label">Add comment</label>
                        <textarea name="comment_body" class="form-control mb-2" rows="3" required></textarea>
                        <button type="submit" class="btn btn-primary btn-sm">Post</button>
                    </form>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="col-lg-4">
        <div class="card border-0 shadow mb-3">
            <div class="card-header bg-white"><strong>Details</strong></div>
            <ul class="list-group list-group-flush">
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Queue</span>
                    <span><?php echo htmlspecialchars((string)($ticket['queue'] ?? '')); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Status</span>
                    <span><?php echo htmlspecialchars((string)($ticket['status'] ?? '')); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Priority</span>
                    <span><?php echo htmlspecialchars((string)($ticket['priority'] ?? '')); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Requester</span>
                    <span><?php echo htmlspecialchars((string)($ticket['requester_name'] ?? '')); ?></span>
                </li>
                <li class="list-group-item d-flex justify-content-between">
                    <span class="text-muted">Assignee</span>
                    <span><?php echo htmlspecialchars((string)($ticket['assignee_name'] ?? '—')); ?></span>
                </li>
                <li class="list-group-item">
                    <span class="text-muted d-block">Vehicle-related</span>
                    <?php echo !empty($ticket['vehicle_related']) ? 'Yes' : 'No'; ?>
                </li>
            </ul>
        </div>

        <?php if ($canManage): ?>
            <div class="card border-0 shadow">
                <div class="card-header bg-white"><strong>Manage</strong></div>
                <div class="card-body">
                    <form method="post">
                        <input type="hidden" name="action" value="update_ticket">
                        <div class="mb-2">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select form-select-sm">
                                <?php foreach (am_it_ticket_statuses() as $st): ?>
                                    <option value="<?php echo htmlspecialchars($st); ?>" <?php echo ($ticket['status'] ?? '') === $st ? 'selected' : ''; ?>><?php echo htmlspecialchars($st); ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-2">
                            <label class="form-label">Assignee (display name)</label>
                            <input type="text" name="assignee_name" class="form-control form-control-sm" value="<?php echo htmlspecialchars((string)($ticket['assignee_name'] ?? '')); ?>" placeholder="Optional">
                        </div>
                        <button type="submit" class="btn btn-sm btn-primary">Save</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
