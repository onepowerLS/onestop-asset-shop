<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/it_am.php';
require_once __DIR__ . '/../config/locale.php';
require_login();

$page_title = am_ui('sidebar_sim_registry');

$can_edit_sim = am_can_sim_team_assign() || am_can_sim_phone_link();

$sims = am_firestore_get_collection(AM_SIM_CARDS_COLLECTION, 4000);
usort($sims, function ($a, $b) {
    return strcmp((string)($a['msisdn_normalized'] ?? ''), (string)($b['msisdn_normalized'] ?? ''));
});

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-3 mb-3 border-bottom">
    <h1 class="h2 mb-0"><?php echo htmlspecialchars(am_ui('sidebar_sim_registry')); ?></h1>
    <?php if ($can_edit_sim): ?>
        <a class="btn btn-sm btn-primary" href="<?php echo base_url('sim/sim-edit.php'); ?>"><?php echo htmlspecialchars(am_ui('sim_register')); ?></a>
    <?php endif; ?>
</div>

<p class="text-muted small"><?php echo am_ui('sim_finance_it_hint'); ?></p>

<div class="card border-0 shadow">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0" id="simTable">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(am_ui('sim_msisdn')); ?></th>
                        <th><?php echo htmlspecialchars(am_ui('sim_pool')); ?></th>
                        <th><?php echo htmlspecialchars(am_ui('sim_location_label')); ?></th>
                        <th><?php echo htmlspecialchars(am_ui('sim_status')); ?></th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($sims as $s): ?>
                        <?php $sid = (string)($s['id'] ?? ''); ?>
                        <tr>
                            <td><?php echo htmlspecialchars((string)($s['msisdn_normalized'] ?? $s['msisdn_display'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($s['pool'] ?? '')); ?></td>
                            <td><?php echo htmlspecialchars((string)($s['sim_location'] ?? '')); ?></td>
                            <td><span class="badge bg-secondary"><?php echo htmlspecialchars((string)($s['status'] ?? '')); ?></span></td>
                            <td class="text-nowrap">
                                <?php if ($can_edit_sim): ?>
                                    <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('sim/sim-edit.php?id=' . rawurlencode($sid)); ?>"><?php echo htmlspecialchars(am_ui('common_edit')); ?></a>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('sim/assignment-new.php?sim_id=' . rawurlencode($sid)); ?>"><?php echo htmlspecialchars(am_ui('sim_assign')); ?></a>
                                <?php else: ?>
                                    <span class="text-muted small">&mdash;</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
$(function () {
    if ($.fn.DataTable && $('#simTable tbody tr').length) {
        $('#simTable').DataTable({ pageLength: 50 });
    }
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
