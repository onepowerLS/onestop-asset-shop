<?php
/**
 * Submit a template-driven AM workflow (e.g. ready boards).
 * ?type=ready_board
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/request_workflows.php';
require_login();

$type = preg_replace('/[^a-z0-9_]/', '', strtolower(trim($_GET['type'] ?? 'ready_board')));
if ($type === 'inventory_dispatch') {
    header('Location: ' . base_url('requests/dispatch-new.php'));
    exit;
}
$template = am_request_workflow_template($type);
if (!$template) {
    $_SESSION['flash_error'] = 'Unknown workflow type.';
    header('Location: ' . base_url('requests/workflow-index.php'));
    exit;
}

$page_title = 'New: ' . $template['label'];
$errors = [];
$countries = am_firestore_get_collection('pr_master_countries', 500);

$countryId = '';
$cc = strtoupper(trim($template['country_code'] ?? ''));
foreach ($countries as $c) {
    if (strtoupper((string)($c['country_code'] ?? '')) === $cc) {
        $countryId = (string)($c['country_id'] ?? $c['id'] ?? '');
        break;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    am_require_can_mutate();
    $payload = [];
    foreach ($template['fields'] as $field) {
        $name = (string)($field['name'] ?? '');
        if ($name === '') {
            continue;
        }
        $raw = trim((string)($_POST[$name] ?? ''));
        if (!empty($field['required']) && $raw === '') {
            $errors[] = ($field['label'] ?? $name) . ' is required.';
            continue;
        }
        if ($field['type'] === 'number' && $raw !== '') {
            $n = (int)$raw;
            $min = isset($field['min']) ? (int)$field['min'] : null;
            if ($min !== null && $n < $min) {
                $errors[] = ($field['label'] ?? $name) . ' must be at least ' . $min . '.';
            }
            $payload[$name] = $n;
        } elseif ($field['type'] === 'email' && $raw !== '' && !filter_var($raw, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Invalid email for ' . ($field['label'] ?? $name) . '.';
        } else {
            $payload[$name] = $raw;
        }
    }

    if (empty($errors)) {
        $all = am_firestore_get_collection('am_core_requests', 2000);
        $seq = count($all) + 1;
        $reqNum = 'AMW-' . date('Y') . '-' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);
        $summary = am_workflow_summary_line($type, $payload);

        $data = [
            'request_number'       => $reqNum,
            'workflow_type'      => $type,
            'workflow_label'     => $template['label'],
            'status'             => 'Submitted',
            'requested_by'       => (string)($_SESSION['user_id'] ?? ''),
            'requested_for_country'=> $countryId,
            'summary'              => $summary,
            'requested_date'       => date('c'),
            'payload'              => $payload,
        ];

        $result = am_firestore_create_document('am_core_requests', $data);
        if ($result['ok']) {
            $_SESSION['flash_success'] = 'Submitted ' . $reqNum . '.';
            header('Location: ' . base_url('requests/workflow-view.php?id=' . urlencode($result['id'] ?? '')));
            exit;
        }
        $errors[] = $result['error'] ?? 'Could not save request.';
    }
}

$defaults = [
    'submitter_email' => (string)($_SESSION['email'] ?? ''),
    'submitter_name'  => (string)($_SESSION['username'] ?? ''),
];

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 py-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('requests/workflow-index.php'); ?>">Service workflows</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($template['label']); ?></li>
                </ol>
            </nav>
            <h1 class="h2"><?php echo htmlspecialchars($template['label']); ?></h1>
            <p class="mb-0 text-gray-600"><?php echo htmlspecialchars($template['description'] ?? ''); ?></p>
        </div>
        <a href="<?php echo base_url('requests/workflow-index.php'); ?>" class="btn btn-outline-secondary btn-sm">Back to list</a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if (am_is_auditor_readonly()): ?>
    <div class="alert alert-warning">Read-only accounts cannot submit workflows.</div>
    <?php else: ?>
    <div class="card border-0 shadow">
        <div class="card-body">
            <form method="post" action="" class="row g-3">
                <?php foreach ($template['fields'] as $field):
                    $fname = (string)($field['name'] ?? '');
                    $fid = 'wf_' . preg_replace('/[^a-z0-9_]/i', '_', $fname);
                    $val = $_POST[$fname] ?? ($defaults[$fname] ?? '');
                    $req = !empty($field['required']);
                    ?>
                <div class="col-12 <?php echo ($field['type'] ?? '') === 'date' ? 'col-md-4' : 'col-md-6'; ?>">
                    <label class="form-label" for="<?php echo htmlspecialchars($fid); ?>"><?php echo htmlspecialchars($field['label'] ?? $fname); ?><?php if ($req): ?> <span class="text-danger">*</span><?php endif; ?></label>
                    <?php if (($field['type'] ?? '') === 'select'): ?>
                    <select name="<?php echo htmlspecialchars($fname); ?>" id="<?php echo htmlspecialchars($fid); ?>" class="form-select" <?php echo $req ? 'required' : ''; ?>>
                        <option value="">Select…</option>
                        <?php foreach ($field['options'] ?? [] as $opt): $opt = (string)$opt; ?>
                        <option value="<?php echo htmlspecialchars($opt); ?>" <?php echo (string)$val === $opt ? 'selected' : ''; ?>><?php echo htmlspecialchars($opt); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php elseif (($field['type'] ?? '') === 'number'): ?>
                    <input type="number" name="<?php echo htmlspecialchars($fname); ?>" id="<?php echo htmlspecialchars($fid); ?>" class="form-control"
                        value="<?php echo htmlspecialchars((string)$val); ?>"
                        <?php echo isset($field['min']) ? ' min="' . (int)$field['min'] . '"' : ''; ?>
                        <?php echo $req ? 'required' : ''; ?>>
                    <?php elseif (($field['type'] ?? '') === 'date'): ?>
                    <input type="date" name="<?php echo htmlspecialchars($fname); ?>" id="<?php echo htmlspecialchars($fid); ?>" class="form-control"
                        value="<?php echo htmlspecialchars((string)$val); ?>" <?php echo $req ? 'required' : ''; ?>>
                    <?php elseif (($field['type'] ?? '') === 'email'): ?>
                    <input type="email" name="<?php echo htmlspecialchars($fname); ?>" id="<?php echo htmlspecialchars($fid); ?>" class="form-control"
                        value="<?php echo htmlspecialchars((string)$val); ?>" <?php echo $req ? 'required' : ''; ?> autocomplete="email">
                    <?php else: ?>
                    <input type="text" name="<?php echo htmlspecialchars($fname); ?>" id="<?php echo htmlspecialchars($fid); ?>" class="form-control"
                        value="<?php echo htmlspecialchars((string)$val); ?>" <?php echo $req ? 'required' : ''; ?>>
                    <?php endif; ?>
                    <?php if (!empty($field['help'])): ?>
                    <div class="form-text"><?php echo htmlspecialchars($field['help']); ?></div>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <div class="col-12 mt-2">
                    <button type="submit" class="btn btn-primary"><i class="fas fa-paper-plane me-2"></i>Submit</button>
                    <a href="<?php echo base_url('requests/workflow-index.php'); ?>" class="btn btn-gray-200">Cancel</a>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
