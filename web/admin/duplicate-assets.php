<?php
/**
 * Admin: find assets sharing the same UID (asset_tag, qr_code_id, or legacy_tag) and merge duplicates.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/duplicate_assets.php';
require_once __DIR__ . '/../config/authz.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['flash_error'] = 'Admin access required.';
    header('Location: ' . base_url('index.php'));
    exit;
}

$page_title = 'Duplicate assets';
$message = '';
$error = '';

$assets = am_firestore_get_collection('am_core_assets', 10000);
$groups = am_duplicate_find_groups($assets);
$groups = am_duplicate_filter_groups_not_dismissed($groups, am_duplicate_load_dismissed_group_keys());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keeper = trim((string)($_POST['keeper_id'] ?? ''));
    $loser = trim((string)($_POST['loser_id'] ?? ''));
    $mode = (string)($_POST['merge_mode'] ?? 'purgatory_30d');
    if (!in_array($mode, ['purgatory_30d', 'immediate'], true)) {
        $mode = 'purgatory_30d';
    }
    if ($keeper === '' || $loser === '' || $keeper === $loser) {
        $error = 'Select a keeper and a different duplicate to remove.';
    } else {
        $r = am_duplicate_merge_loser_into_keeper($keeper, $loser, $mode);
        if ($r['ok']) {
            $message = $mode === 'purgatory_30d'
                ? 'Duplicate merged. A snapshot is in purgatory for 30 days, then purged by the scheduled job.'
                : 'Duplicate removed; inventory and allocations were repointed to the keeper.';
            $assets = am_firestore_get_collection('am_core_assets', 10000);
            $groups = am_duplicate_find_groups($assets);
            $groups = am_duplicate_filter_groups_not_dismissed($groups, am_duplicate_load_dismissed_group_keys());
        } else {
            $error = (string)($r['error'] ?? 'Merge failed.');
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb">
            <li class="breadcrumb-item"><a href="<?php echo base_url('index.php'); ?>">Dashboard</a></li>
            <li class="breadcrumb-item active">Duplicate assets</li>
        </ol>
    </nav>

    <h1 class="h2 mb-3">Duplicate assets (shared UID)</h1>
    <p class="text-muted">Two records are treated as duplicates if they share the same non-empty value on <strong>asset tag</strong>, <strong>QR code id</strong>, or <strong>legacy tag</strong> (after trim and case-fold). The <strong>keeper</strong> is the row with richer field data; the other row is removed after repointing stock and allocations.</p>

    <?php if ($message !== ''): ?>
    <div class="alert alert-success"><?php echo htmlspecialchars($message); ?></div>
    <?php endif; ?>
    <?php if ($error !== ''): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars($error); ?></div>
    <?php endif; ?>

    <div class="alert alert-secondary small">
        <strong>Transactions</strong> are immutable in Firestore rules and are not rewritten. Historical rows may still reference the deleted id.
        Run <code>web/cron/purge-asset-purgatory.php</code> daily (with <code>CRON_SECRET</code>) to delete expired purgatory snapshots.
    </div>

    <?php if (empty($groups)): ?>
    <div class="card border-0 shadow mb-4"><div class="card-body">No duplicate UID groups detected in the current catalog.</div></div>
    <?php else: ?>
    <?php foreach ($groups as $gi => $group):
        $rec = am_duplicate_pick_recommended_keeper($group);
        $recId = (string)($rec['id'] ?? $rec['asset_id'] ?? '');
    ?>
    <div class="card border-0 shadow mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
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
                            <th>Document ID</th>
                            <th>Name</th>
                            <th class="text-end">Score</th>
                            <th>Asset tag</th>
                            <th>QR code</th>
                            <th>Legacy</th>
                            <th>Merge</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($group as $a):
                            $id = (string)($a['id'] ?? $a['asset_id'] ?? '');
                            $score = am_asset_data_richness_score($a);
                            $isRec = $recId !== '' && $id === $recId;
                        ?>
                        <tr class="<?php echo $isRec ? 'table-success' : ''; ?>">
                            <td><code><?php echo htmlspecialchars($id); ?></code></td>
                            <td>
                                <a href="<?php echo base_url('assets/view.php?id=' . urlencode($id)); ?>"><?php echo htmlspecialchars((string)($a['name'] ?? '')); ?></a>
                                <?php if ($isRec): ?><span class="badge bg-success ms-1">keeper</span><?php endif; ?>
                            </td>
                            <td class="text-end"><?php echo (int)$score; ?></td>
                            <td><small><?php echo htmlspecialchars((string)($a['asset_tag'] ?? '')); ?></small></td>
                            <td><small><?php echo htmlspecialchars((string)($a['qr_code_id'] ?? '')); ?></small></td>
                            <td><small><?php echo htmlspecialchars((string)($a['legacy_tag'] ?? '')); ?></small></td>
                            <td>
                                <?php if ($id !== $recId && $recId !== ''): ?>
                                <form method="post" class="d-flex flex-wrap gap-1 align-items-center" onsubmit="return confirm('Merge this record into the keeper and delete it?');">
                                    <input type="hidden" name="keeper_id" value="<?php echo htmlspecialchars($recId); ?>">
                                    <input type="hidden" name="loser_id" value="<?php echo htmlspecialchars($id); ?>">
                                    <select name="merge_mode" class="form-select form-select-sm" style="width:auto;">
                                        <option value="purgatory_30d">Purgatory 30 days</option>
                                        <option value="immediate">Delete immediately (no snapshot)</option>
                                    </select>
                                    <button type="submit" class="btn btn-sm btn-outline-danger">Merge into keeper</button>
                                </form>
                                <?php elseif ($id === $recId): ?>
                                <span class="text-muted small">—</span>
                                <?php else: ?>
                                <span class="text-muted small">Pick keeper manually below</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>

    <div class="card border-0 shadow mb-4">
        <div class="card-header">Manual merge (by document id)</div>
        <div class="card-body">
            <p class="small text-muted">Use when you need a different keeper than the suggestion, or when no group was listed. Loser must still have a lower or equal richness score than the keeper.</p>
            <form method="post" class="row g-2 align-items-end">
                <div class="col-md-4">
                    <label class="form-label">Keeper <code>am_core_assets</code> id</label>
                    <input type="text" name="keeper_id" class="form-control" required placeholder="Doc id">
                </div>
                <div class="col-md-4">
                    <label class="form-label">Duplicate to remove</label>
                    <input type="text" name="loser_id" class="form-control" required placeholder="Other doc id">
                </div>
                <div class="col-md-2">
                    <label class="form-label">Mode</label>
                    <select name="merge_mode" class="form-select">
                        <option value="purgatory_30d">Purgatory 30 days</option>
                        <option value="immediate">Immediate (no snapshot)</option>
                    </select>
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-danger w-100">Merge</button>
                </div>
            </form>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
