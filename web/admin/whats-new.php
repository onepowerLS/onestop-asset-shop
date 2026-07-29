<?php
/**
 * Admin: manage What's New entries.
 * See docs/SYSTEM_SPECS.md for the policy on when to add entries.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/whats_new.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['flash_error'] = 'Admin access required.';
    header('Location: ' . base_url('index.php'));
    exit;
}

$page_title = "What's New";
$errors = [];
$editId = $_GET['edit'] ?? '';

$entries = am_whats_new_entries(false);

if (($_GET['seed'] ?? '') === '1') {
    // Run the in-process seed (same data as scripts/seed_whats_new.php).
    $seedEntries = [
        [
            'title' => 'Search catalog before adding an item',
            'summary' => 'Add Item now opens with a live catalog search panel so you can avoid creating duplicates, with a yellow "similar item already in catalog" warning on the Name field.',
            'details' => "<p>The Add Item form now starts with a <strong>Search catalog first</strong> panel.</p><ul><li>Type 2+ characters to search name, tag, manufacturer, model, notes, category, and location.</li><li>Each match has View and Edit buttons so you can open the existing record instead of creating a duplicate.</li><li>Filter results by class — the filter auto-syncs with the classification you pick below.</li></ul>",
            'category' => 'feature',
            'icon' => 'fa-magnifying-glass',
            'released_at' => '2026-06-30T15:50:00Z',
            'deep_link' => '/assets/add.php',
        ],
        [
            'title' => 'Assemble / produce now supports stockable outputs (pole boxes, ready boards)',
            'summary' => 'Produce stockable inventory items like pole boxes and ready boards from raw materials, not just fixed assets.',
            'details' => "<p><strong>Catalog → Assemble / produce</strong> has a new output type picker:</p><ul><li><strong>Fixed Asset</strong> — unchanged (powerhouses, tracker stations).</li><li><strong>Stockable item</strong> — for pole boxes, ready boards, and other produced inventory. Either create a new catalog item or add quantity to an existing one.</li></ul><p>Source materials are consumed from the assembly location's stock, and lineage (built_from) is recorded on the result.</p>",
            'category' => 'feature',
            'icon' => 'fa-wrench',
            'released_at' => '2026-06-24T16:20:00Z',
            'deep_link' => '/assets/assemble.php',
        ],
        [
            'title' => 'Stock levels no longer double-count items at HQ',
            'summary' => 'If an item had two inventory rows at the same location (legacy alias keys like site1 + LSO-HQ), Item detail, Stock Levels, and Edit now all show the correct single quantity.',
            'details' => "<p>Duplicate inventory rows caused by legacy location alias keys are now <strong>merged on read</strong> instead of summed. This fixes the doubled meter counts and keeps Item detail, Stock Levels, and Edit in sync.</p><p>If an item still shows wrong totals, open it and Save once — the Edit flow now merges/deletes the duplicate rows in Firestore.</p>",
            'category' => 'fix',
            'icon' => 'fa-boxes-stacked',
            'released_at' => '2026-06-24T16:20:00Z',
            'deep_link' => '/inventory/index.php',
        ],
        [
            'title' => 'Department + Project shown when an item is Allocated',
            'summary' => 'When Status is Allocated, CheckedOut, InProject, or Deployed, you can now record the department and the project/concession on the item.',
            'details' => "<p>On <strong>Catalog → Edit item</strong>, two new fields appear when Status is one of Allocated, CheckedOut, InProject, or Deployed:</p><ul><li><strong>Allocated to department</strong> — RET / FAC / O&amp;M / IS&amp;T / General / Finance / HR / Procurement / Fleet / A.M / P.M / EHS / Prod / M.E / E.E.</li><li><strong>Project / concession</strong> — free text.</li></ul>",
            'category' => 'feature',
            'icon' => 'fa-users',
            'released_at' => '2026-07-01T15:15:00Z',
            'deep_link' => '/assets/index.php',
        ],
        [
            'title' => 'Simpler Ready board request form',
            'summary' => 'Item Class picker removed (ready boards are always Inventory), Quantity field added, and the request list now shows Qty and Site columns.',
            'details' => "<p><strong>Requests → Ready board requests → New Request</strong> changes:</p><ul><li>Item Class picker removed — ready boards are always recorded as Inventory behind the scenes.</li><li>Quantity field added (required).</li><li>Concession / site, receiver name and email fields added.</li><li>Request list shows Qty and Site columns instead of free-text Description.</li></ul>",
            'category' => 'reconfigure',
            'icon' => 'fa-paper-plane',
            'released_at' => '2026-07-01T15:15:00Z',
            'deep_link' => '/requests/index.php',
        ],
        [
            'title' => 'Bulk ready board allocation against a request',
            'summary' => 'Open a ready board request, tick the ready boards to assign, set target status/location/department, and submit once to update every selected asset and mark the request fulfilled.',
            'details' => "<p>Open any Ready board request and use the <strong>Allocate ready boards</strong> panel:</p><ul><li>Tick the ready boards to assign (or click <em>Select all available</em> / <em>Select requested (N)</em>).</li><li>Set Target status, Department, and Target location (pre-filled with the request's site code).</li><li>Click <strong>Allocate selected and mark fulfilled</strong> — each selected ready board's status, location, department, and project is updated in one submission.</li></ul>",
            'category' => 'feature',
            'icon' => 'fa-check-double',
            'released_at' => '2026-07-01T15:15:00Z',
            'deep_link' => '/requests/index.php',
        ],
    ];
    $existingTitles = [];
    foreach ($entries as $e) {
        $t = strtolower(trim((string)($e['title'] ?? '')));
        if ($t !== '') {
            $existingTitles[$t] = true;
        }
    }
    $created = 0;
    foreach ($seedEntries as $entry) {
        $key = strtolower(trim($entry['title']));
        if (isset($existingTitles[$key])) {
            continue;
        }
        $data = array_merge($entry, ['active' => 1, 'created_at' => gmdate('c')]);
        $r = am_firestore_create_document(AM_WHATS_NEW_COLLECTION, $data);
        if ($r['ok']) {
            $created++;
        }
    }
    $_SESSION['flash_success'] = "Seeded {$created} new entries (existing titles skipped).";
    header('Location: ' . base_url('admin/whats-new.php'));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $docId = trim($_POST['doc_id'] ?? '');

    if ($action === 'delete' && $docId !== '') {
        $r = am_firestore_delete_document(AM_WHATS_NEW_COLLECTION, $docId);
        $_SESSION['flash_success'] = $r['ok'] ? 'Entry deleted.' : 'Delete failed.';
        header('Location: ' . base_url('admin/whats-new.php'));
        exit;
    }

    $data = am_whats_new_normalize_entry($_POST);
    if ($data['title'] === '') $errors[] = 'Title is required.';
    if ($data['summary'] === '') $errors[] = 'Summary is required.';

    if (empty($errors)) {
        if ($action === 'update' && $docId !== '') {
            $data['updated_at'] = gmdate('c');
            $r = am_firestore_update_document(AM_WHATS_NEW_COLLECTION, $docId, $data);
        } else {
            $data['created_at'] = gmdate('c');
            $r = am_firestore_create_document(AM_WHATS_NEW_COLLECTION, $data);
        }
        if ($r['ok']) {
            $_SESSION['flash_success'] = $action === 'update' ? 'Entry updated.' : 'Entry created.';
            header('Location: ' . base_url('admin/whats-new.php'));
            exit;
        }
        $errors[] = $r['error'] ?? 'Save failed.';
    }
}

$editEntry = null;
if ($editId) {
    foreach ($entries as $e) {
        if ((string)($e['id'] ?? '') === $editId) {
            $editEntry = $e;
            break;
        }
    }
}

$colors = am_whats_new_category_colors();
$labels = am_whats_new_category_labels();
$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center py-4">
        <div>
            <h1 class="h2">What's New</h1>
            <p class="mb-0 text-muted">Manage the popup primer shown at login. <a href="<?php echo base_url('docs/SYSTEM_SPECS.md'); ?>" target="_blank">Policy</a>.</p>
        </div>
        <div class="d-flex gap-2">
            <a href="<?php echo base_url('admin/whats-new.php?seed=1'); ?>" class="btn btn-sm btn-outline-success"
               onclick="return confirm('Seed recent feature entries from the seed script? Existing titles are skipped.')">
                <i class="fas fa-seedling me-1"></i>Seed recent features
            </a>
            <a href="<?php echo base_url('whats-new.php'); ?>" class="btn btn-sm btn-outline-secondary">View archive</a>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($flash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <div class="row">
        <div class="col-12 col-lg-5 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0"><?php echo $editEntry ? 'Edit' : 'Add'; ?> entry</h2></div>
                <div class="card-body">
                    <form method="POST" action="">
                        <input type="hidden" name="action" value="<?php echo $editEntry ? 'update' : 'create'; ?>">
                        <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($editId); ?>">
                        <div class="mb-3">
                            <label class="form-label">Title <span class="text-danger">*</span></label>
                            <input type="text" class="form-control" name="title" required
                                value="<?php echo htmlspecialchars($editEntry['title'] ?? ($_POST['title'] ?? '')); ?>">
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Summary <span class="text-danger">*</span></label>
                            <textarea class="form-control" name="summary" rows="2" required><?php echo htmlspecialchars($editEntry['summary'] ?? ($_POST['summary'] ?? '')); ?></textarea>
                            <div class="form-text">One or two sentences shown in the popup.</div>
                        </div>
                        <div class="mb-3">
                            <label class="form-label">Details (HTML allowed)</label>
                            <textarea class="form-control" name="details" rows="6"><?php echo htmlspecialchars($editEntry['details'] ?? ($_POST['details'] ?? '')); ?></textarea>
                            <div class="form-text">Longer explanation. &lt;p&gt;, &lt;ul&gt;, &lt;li&gt;, &lt;strong&gt; allowed.</div>
                        </div>
                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label">Category</label>
                                <select class="form-select" name="category">
                                    <?php foreach ($labels as $k => $v): ?>
                                    <option value="<?php echo $k; ?>" <?php echo (string)($editEntry['category'] ?? $_POST['category'] ?? 'feature') === $k ? 'selected' : ''; ?>><?php echo $v; ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">FontAwesome icon</label>
                                <input type="text" class="form-control" name="icon" value="<?php echo htmlspecialchars($editEntry['icon'] ?? ($_POST['icon'] ?? 'fa-star')); ?>" placeholder="fa-bullhorn">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Released at</label>
                                <input type="datetime-local" class="form-control" name="released_at"
                                    value="<?php echo htmlspecialchars(substr((string)($editEntry['released_at'] ?? ($_POST['released_at'] ?? '')), 0, 16)); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label">Deep link (optional)</label>
                                <input type="text" class="form-control" name="deep_link"
                                    value="<?php echo htmlspecialchars($editEntry['deep_link'] ?? ($_POST['deep_link'] ?? '')); ?>" placeholder="/assets/assemble.php">
                            </div>
                        </div>
                        <div class="form-check mb-3">
                            <input type="checkbox" class="form-check-input" name="active" id="wnActive"
                                <?php echo (int)($editEntry['active'] ?? 1) === 1 ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="wnActive">Active (show in popup and archive)</label>
                        </div>
                        <div class="d-flex gap-2">
                            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i><?php echo $editEntry ? 'Update' : 'Create'; ?></button>
                            <?php if ($editEntry): ?>
                            <a href="<?php echo base_url('admin/whats-new.php'); ?>" class="btn btn-gray-200">Cancel</a>
                            <?php endif; ?>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-12 col-lg-7 mb-4">
            <div class="card border-0 shadow">
                <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Entries <span class="badge bg-secondary"><?php echo count($entries); ?></span></h2></div>
                <div class="card-body p-0">
                    <?php if (empty($entries)): ?>
                    <p class="text-gray-500 text-center py-4 mb-0">No entries yet.</p>
                    <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover mb-0">
                            <thead><tr><th>Title</th><th>Category</th><th>Released</th><th>Active</th><th>Actions</th></tr></thead>
                            <tbody>
                                <?php foreach ($entries as $e):
                                    $id = (string)($e['id'] ?? '');
                                    $cat = (string)($e['category'] ?? 'feature');
                                ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($e['title'] ?? ''); ?></strong>
                                        <div class="small text-muted"><?php echo htmlspecialchars(substr((string)($e['summary'] ?? ''), 0, 80)); ?></div>
                                    </td>
                                    <td><span class="badge bg-<?php echo $colors[$cat] ?? 'secondary'; ?>"><?php echo htmlspecialchars($labels[$cat] ?? $cat); ?></span></td>
                                    <td><?php echo htmlspecialchars(substr((string)($e['released_at'] ?? ''), 0, 10)); ?></td>
                                    <td><?php echo (int)($e['active'] ?? 1) === 1 ? '<i class="fas fa-check text-success"></i>' : '<i class="fas fa-circle text-muted"></i>'; ?></td>
                                    <td>
                                        <a href="<?php echo base_url('admin/whats-new.php?edit=' . urlencode($id)); ?>" class="btn btn-sm btn-outline-secondary" title="Edit"><i class="fas fa-edit"></i></a>
                                        <form method="POST" action="" class="d-inline" onsubmit="return confirm('Delete this entry?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="doc_id" value="<?php echo htmlspecialchars($id); ?>">
                                            <button type="submit" class="btn btn-sm btn-outline-danger" title="Delete"><i class="fas fa-trash"></i></button>
                                        </form>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
