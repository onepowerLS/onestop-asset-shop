<?php
/**
 * Recent Firestore mutations (scoped to current country filter + access).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/country_scope.php';
require_login();

$page_title = 'Mutation log';

$rows = am_firestore_get_collection(AM_CORE_MUTATION_LOGS_COLLECTION, 4000);
$scoped = am_mutation_log_filter_by_country_scope(
    $rows,
    am_country_active_codes(),
    am_user_may_see_unscoped_country_assets()
);

$readTok = am_firestore_resolve_id_token(null);
foreach ($scoped as $i => $row) {
    if (is_array($row) && $readTok !== '') {
        $scoped[$i] = am_mutation_log_enrich_entry_for_display($row, $readTok);
    }
}

usort($scoped, static function ($a, $b) {
    $ta = strtotime((string)($a['mutation_at'] ?? ''));
    $tb = strtotime((string)($b['mutation_at'] ?? ''));
    return $tb <=> $ta;
});

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 py-4">
        <div>
            <h1 class="h2">Mutation log</h1>
            <p class="mb-0 text-gray-600"><?php echo count($scoped); ?> entries (country scope + filter applied)</p>
        </div>
        <div class="small text-gray-600">
            JSON API: <code><?php echo htmlspecialchars(base_url('api/mutations/index.php')); ?></code>
            — use <code>Authorization: Bearer &lt;Firebase ID token&gt;</code> or <code>?id_token=</code> from tools at your site.
        </div>
    </div>

    <div class="card border-0 shadow">
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="mutTable">
                    <thead>
                        <tr>
                            <th>When</th>
                            <th>Op</th>
                            <th>Collection</th>
                            <th>Doc</th>
                            <th>Country</th>
                            <th>Who</th>
                            <th>Emp #</th>
                            <th>Source</th>
                            <th>Summary</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($scoped)): ?>
                        <tr><td colspan="9" class="text-center text-gray-500 py-4">No mutations in scope.</td></tr>
                        <?php else: ?>
                        <?php foreach ($scoped as $m): ?>
                        <tr>
                            <td><?php echo htmlspecialchars(date('Y-m-d H:i', strtotime((string)($m['mutation_at'] ?? '')))); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars((string)($m['operation'] ?? '')); ?></span></td>
                            <td><code><?php echo htmlspecialchars((string)($m['target_collection'] ?? '')); ?></code></td>
                            <td><code class="small"><?php echo htmlspecialchars(substr((string)($m['target_document_id'] ?? ''), 0, 24)); ?></code></td>
                            <td><?php echo htmlspecialchars((string)($m['country_code'] ?? '—')); ?></td>
                            <td class="small">
                                <?php
                                $line = trim((string)($m['actor_line'] ?? ''));
                                if ($line !== '') {
                                    echo htmlspecialchars($line);
                                } else {
                                    $nm = trim((string)($m['actor_display_name'] ?? ''));
                                    echo htmlspecialchars($nm !== '' ? $nm : substr((string)($m['actor_uid'] ?? ''), 0, 14) . '…');
                                }
                                ?>
                            </td>
                            <td class="small"><?php echo htmlspecialchars((string)($m['actor_employee_number'] ?? '—')); ?></td>
                            <td><?php echo htmlspecialchars((string)($m['source'] ?? '')); ?></td>
                            <td class="small"><?php echo htmlspecialchars((string)($m['summary'] ?? '')); ?></td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<?php if (count($scoped) > 0): ?>
<script>
$(document).ready(function() {
    $('#mutTable').DataTable({ pageLength: 25, order: [[0, 'desc']] });
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/../includes/footer.php'; ?>
