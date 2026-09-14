<?php
/**
 * Shared part definitions (cross-country identity). No photo gallery in this phase.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/locale.php';
require_once __DIR__ . '/../config/part_definitions.php';
require_login();
am_require_can_mutate();

$page_title = 'Part definitions';
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    if ($action === 'create') {
        $res = am_part_definition_create([
            'name' => trim((string)($_POST['name'] ?? '')),
            'description' => trim((string)($_POST['description'] ?? '')),
            'manufacturer' => trim((string)($_POST['manufacturer'] ?? '')),
            'model' => trim((string)($_POST['model'] ?? '')),
            'technical_specification' => trim((string)($_POST['technical_specification'] ?? '')),
            'unit_of_measure' => trim((string)($_POST['unit_of_measure'] ?? 'EA')),
            'classification' => trim((string)($_POST['classification'] ?? 'needs_classification')),
            'am_only_reason' => trim((string)($_POST['am_only_reason'] ?? '')),
            'ugp_part_id' => '',
            'forecast_ready' => false,
            'active' => true,
        ]);
        if ($res['ok']) {
            $message = 'Definition created: ' . $res['id'];
        } else {
            $error = (string)($res['error'] ?? 'Create failed');
        }
    }
}

$defs = am_firestore_get_collection(AM_PART_DEFINITIONS_COLLECTION, 2000);
usort($defs, static fn($a, $b) => strcasecmp((string)($a['name'] ?? ''), (string)($b['name'] ?? '')));
$q = trim((string)($_GET['q'] ?? ''));
if ($q !== '') {
    $defs = am_part_definition_search($defs, $q, 100);
}

include __DIR__ . '/../includes/header.php';
?>
<div class="py-4">
    <h1 class="h2">Part definitions</h1><p><a class="btn btn-primary" href="reconciliation.php">Open RET–AM reconciliation workshop</a></p>
    <p class="text-muted">Shared product identity reused across countries. Country stock stays on catalog items. UGP approval still uses the MAS mapping workflow.</p>
    <?php if ($message): ?><div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div><?php endif; ?>
    <?php if ($error): ?><div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div><?php endif; ?>

    <div class="card border-0 shadow mb-4">
        <div class="card-header"><strong>New definition</strong></div>
        <div class="card-body">
            <form method="post" class="row g-2">
                <input type="hidden" name="action" value="create">
                <div class="col-md-6">
                    <label class="form-label">Name</label>
                    <input name="name" class="form-control" required>
                </div>
                <div class="col-md-3">
                    <label class="form-label">Unit</label>
                    <input name="unit_of_measure" class="form-control" value="EA">
                </div>
                <div class="col-md-3">
                    <label class="form-label">Classification</label>
                    <select name="classification" class="form-select" id="defClass">
                        <?php foreach (['needs_classification', 'am_only'] as $c): ?>
                        <option value="<?php echo htmlspecialchars($c); ?>"><?php echo htmlspecialchars($c); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label">Manufacturer</label>
                    <input name="manufacturer" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Model</label>
                    <input name="model" class="form-control">
                </div>
                <div class="col-md-4">
                    <label class="form-label">UGP part id (only if already approved)</label>
                    <p>Publish verified UGP identity in the joint workshop.</p>
                </div>
                <div class="col-12">
                    <label class="form-label">Description / technical specification</label>
                    <textarea name="technical_specification" class="form-control" rows="2"></textarea>
                </div>
                <div class="col-md-6">
                    <label class="form-label">AM-only reason (required if am_only)</label>
                    <input name="am_only_reason" class="form-control">
                </div>
                <div class="col-md-6 d-flex align-items-end">
                    <div class="form-check">
                        <input class="form-check-input" type="checkbox" disabled id="fr">
                        <label class="form-check-label" for="fr">Forecast-ready (requires classification + unit; UGP link when ugp_linked)</label>
                    </div>
                </div>
                <div class="col-12">
                    <button type="submit" class="btn btn-primary btn-sm">Create definition</button>
                    <a class="btn btn-outline-secondary btn-sm" href="<?php echo base_url('admin/catalogue-tasks.php'); ?>">Catalogue tasks</a>
                </div>
            </form>
        </div>
    </div>

    <form method="get" class="mb-3">
        <div class="input-group" style="max-width:28rem;">
            <input type="search" name="q" value="<?php echo htmlspecialchars($q); ?>" class="form-control" placeholder="Search definitions…">
            <button class="btn btn-outline-primary" type="submit">Search</button>
        </div>
    </form>

    <div class="card border-0 shadow">
        <div class="card-body table-responsive">
            <table class="table table-sm table-hover">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Class</th>
                        <th>Unit</th>
                        <th>UGP</th>
                        <th>Forecast</th>
                        <th>Id</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if ($defs === []): ?>
                    <tr><td colspan="6" class="text-muted">No definitions yet.</td></tr>
                    <?php else: foreach ($defs as $d): ?>
                    <tr>
                        <td><?php echo htmlspecialchars((string)($d['name'] ?? '')); ?><?php if(!empty($d['canonical_approved'])):?><br><a href="../assets/reference-photo.php?definition=<?=rawurlencode($d['id'])?>">Shared photos</a><?php endif?></td>
                        <td><code><?php echo htmlspecialchars((string)($d['classification'] ?? '')); ?></code></td>
                        <td><?php echo htmlspecialchars((string)($d['unit_of_measure'] ?? '')); ?></td>
                        <td><?php echo htmlspecialchars((string)($d['ugp_part_id'] ?? '—')); ?></td>
                        <td><?php echo !empty($d['forecast_ready']) ? 'yes' : 'no'; ?></td>
                        <td><small><code><?php echo htmlspecialchars((string)($d['id'] ?? '')); ?></code></small></td>
                    </tr>
                    <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
