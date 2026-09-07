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

$page_title = 'New ticket';
$errors = [];

$tickets = am_firestore_get_collection(AM_IT_TICKETS_COLLECTION, 3000);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    am_require_can_mutate();
    $title = trim((string)($_POST['title'] ?? ''));
    $description = trim((string)($_POST['description'] ?? ''));
    $queue = trim((string)($_POST['queue'] ?? 'it'));
    $priority = trim((string)($_POST['priority'] ?? 'Normal'));
    $vehicleRelated = !empty($_POST['vehicle_related']);

    if ($title === '') {
        $errors[] = 'Title is required.';
    }
    if ($description === '') {
        $errors[] = 'Description is required.';
    }
    if (!in_array($queue, am_it_ticket_queues(), true)) {
        $errors[] = 'Invalid queue.';
    }
    if (!in_array($priority, am_it_ticket_priorities(), true)) {
        $errors[] = 'Invalid priority.';
    }

    if (empty($errors)) {
        $num = am_it_next_ticket_number($tickets);
        $uid = (string)($_SESSION['user_id'] ?? '');
        $name = (string)($_SESSION['username'] ?? '');
        $data = [
            'ticket_number' => $num,
            'queue' => $queue,
            'title' => $title,
            'description' => $description,
            'status' => 'Open',
            'priority' => $priority,
            'requester_uid' => $uid,
            'requester_name' => $name,
            'assignee_uid' => '',
            'assignee_name' => '',
            'vehicle_related' => $vehicleRelated,
            'source' => 'web',
            'legacy_import_id' => '',
            'linked_asset_ids' => [],
            'linked_sim_id' => '',
            'comments' => [],
            'created_at' => date('c'),
            'updated_at' => date('c'),
        ];

        $result = am_firestore_create_document(AM_IT_TICKETS_COLLECTION, $data);
        if ($result['ok']) {
            $newId = (string)($result['id'] ?? '');
            $_SESSION['flash_success'] = 'Ticket ' . $num . ' created.';
            header('Location: ' . am_it_url('ticket-view.php') . '?id=' . rawurlencode($newId));
            exit;
        }
        $errors[] = (string)($result['error'] ?? 'Could not create ticket.');
    }
}

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-3 mb-3 border-bottom">
    <h1 class="h2 mb-0">New ticket</h1>
    <a class="btn btn-sm btn-outline-secondary" href="<?php echo htmlspecialchars(am_it_url('tickets.php')); ?>">Back to list</a>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
<?php endif; ?>

<div class="card border-0 shadow">
    <div class="card-body">
        <form method="post" class="row g-3">
            <div class="col-md-6">
                <label class="form-label">Queue</label>
                <select name="queue" class="form-select" required>
                    <option value="it">IT (hardware, software, networks, devices)</option>
                    <option value="am_operations">AM operations (non-IT, non-vehicle equipment)</option>
                </select>
            </div>
            <div class="col-md-6">
                <label class="form-label">Priority</label>
                <select name="priority" class="form-select">
                    <?php foreach (am_it_ticket_priorities() as $p): ?>
                        <option value="<?php echo htmlspecialchars($p); ?>" <?php echo $p === 'Normal' ? 'selected' : ''; ?>><?php echo htmlspecialchars($p); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-12">
                <label class="form-label">Title</label>
                <input type="text" name="title" class="form-control" required maxlength="200" value="<?php echo htmlspecialchars((string)($_POST['title'] ?? '')); ?>">
            </div>
            <div class="col-12">
                <label class="form-label">Description</label>
                <textarea name="description" class="form-control" rows="6" required><?php echo htmlspecialchars((string)($_POST['description'] ?? '')); ?></textarea>
            </div>
            <div class="col-12">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" name="vehicle_related" value="1" id="veh">
                    <label class="form-check-label" for="veh">This is vehicle-related (we will still log it; Fleet Management handles vehicles)</label>
                </div>
            </div>
            <div class="col-12">
                <button type="submit" class="btn btn-primary">Submit ticket</button>
            </div>
        </form>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
