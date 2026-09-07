<?php
/**
 * Admin: canonical data sync status + manual refresh.
 *
 * Shows each canonical type (sites, organizations, countries, employees,
 * departments, vehicles) with cache item count, last sync timestamp, TTL
 * freshness, and a "Refresh now" button. See docs/CANONICAL_DATA_SYNC_PLAN.md.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firebase.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/canonical_sync.php';
require_login();
am_require_admin();

$page_title = 'Canonical data sync';
$flash = '';
$flashKind = 'info';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $type = trim($_POST['type'] ?? '');
    $mode = ($_POST['mode'] ?? 'full') === 'incremental' ? 'incremental' : 'full';

    if ($action === 'refresh') {
        if ($type === 'all') {
            $results = am_canonical_refresh_all($mode);
            $okCount = 0;
            $parts = [];
            foreach ($results as $t => $r) {
                $parts[] = $t . '=' . ($r['ok'] ? $r['count'] : 'ERR');
                if ($r['ok']) {
                    $okCount++;
                }
            }
            $flash = 'Refreshed all (' . $mode . '): ' . implode(', ', $parts);
            $flashKind = $okCount === count($results) ? 'success' : 'warning';
        } elseif (in_array($type, AM_CANONICAL_TYPES, true)) {
            $r = am_canonical_refresh($type, $mode);
            $flash = 'Refresh ' . $type . ' (' . $mode . '): ' .
                ($r['ok'] ? $r['count'] . ' items written' : 'FAILED — ' . $r['error']);
            $flashKind = $r['ok'] ? 'success' : 'danger';
        } else {
            $flash = 'Unknown type: ' . htmlspecialchars($type);
            $flashKind = 'danger';
        }
    }
}

$rows = am_canonical_status();

include __DIR__ . '/../includes/header.php';
?>
<div class="container-fluid py-3">
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h1 class="h3 mb-0">Canonical data sync</h1>
            <p class="mb-0 text-muted">
                AM-owned cache of reference data pulled from PR / HR / FM APIs.
                <a href="<?php echo base_url('docs/CANONICAL_DATA_SYNC_PLAN.md'); ?>" target="_blank">Plan</a>.
            </p>
        </div>
        <form method="post" class="d-inline">
            <input type="hidden" name="action" value="refresh">
            <input type="hidden" name="type" value="all">
            <input type="hidden" name="mode" value="full">
            <button type="submit" class="btn btn-sm btn-primary"><i class="fas fa-rotate"></i> Refresh all</button>
        </form>
    </div>

    <?php if ($flash !== ''): ?>
        <div class="alert alert-<?php echo htmlspecialchars($flashKind); ?> py-2"><?php echo htmlspecialchars($flash); ?></div>
    <?php endif; ?>

    <div class="card border-0 shadow-sm">
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead class="table-light">
                    <tr>
                        <th>Type</th>
                        <th>Source</th>
                        <th>Cache collection</th>
                        <th class="text-end">Items</th>
                        <th>Last sync</th>
                        <th>Mode</th>
                        <th>Fresh?</th>
                        <th>Error</th>
                        <th class="text-end">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php foreach ($rows as $r): ?>
                    <tr>
                        <td><strong><?php echo htmlspecialchars($r['type']); ?></strong></td>
                        <td><span class="badge bg-secondary"><?php echo htmlspecialchars($r['source']); ?></span></td>
                        <td><code><?php echo htmlspecialchars($r['collection']); ?></code></td>
                        <td class="text-end"><?php echo (int)$r['count']; ?></td>
                        <td><?php echo $r['last_sync_at'] ? htmlspecialchars($r['last_sync_at']) : '<span class="text-muted">never</span>'; ?></td>
                        <td><?php echo htmlspecialchars($r['last_mode']); ?></td>
                        <td>
                            <?php if ($r['push_driven']): ?>
                                <span class="badge bg-info">push</span>
                            <?php elseif ($r['fresh']): ?>
                                <span class="badge bg-success">fresh</span>
                            <?php else: ?>
                                <span class="badge bg-warning text-dark">stale</span>
                            <?php endif; ?>
                        </td>
                        <td class="small text-muted"><?php echo htmlspecialchars($r['last_error']); ?></td>
                        <td class="text-end">
                            <?php if (!$r['push_driven']): ?>
                            <form method="post" class="d-inline">
                                <input type="hidden" name="action" value="refresh">
                                <input type="hidden" name="type" value="<?php echo htmlspecialchars($r['type']); ?>">
                                <input type="hidden" name="mode" value="full">
                                <button type="submit" class="btn btn-sm btn-outline-secondary" title="Refresh now">
                                    <i class="fas fa-rotate"></i>
                                </button>
                            </form>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <p class="text-muted small mt-3 mb-0">
        Cron: <code>curl -s "https://am.1pwrafrica.com/cron/canonical-sync.php?secret=CRON_SECRET"</code> (every 15 min).
        CLI: <code>php scripts/canonical_sync.php --status</code>.
    </p>
</div>
<?php include __DIR__ . '/../includes/footer.php';
