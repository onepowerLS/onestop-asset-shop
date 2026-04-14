<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/it_am.php';
require_login();

$page_title = 'SIM registry';

$sims = am_firestore_get_collection(AM_SIM_CARDS_COLLECTION, 4000);
usort($sims, function ($a, $b) {
    return strcmp((string)($a['msisdn_normalized'] ?? ''), (string)($b['msisdn_normalized'] ?? ''));
});

include __DIR__ . '/../includes/header.php';
?>
<div class="d-flex justify-content-between flex-wrap flex-md-nowrap align-items-center pb-3 mb-3 border-bottom">
    <h1 class="h2 mb-0">SIM registry</h1>
    <?php if (am_can_sim_team_assign() || am_can_sim_phone_link()): ?>
        <a class="btn btn-sm btn-primary" href="<?php echo base_url('sim/sim-edit.php'); ?>">Register SIM</a>
    <?php endif; ?>
</div>

<p class="text-muted small">Finance capability assigns SIMs to <strong>teams</strong>; IT capability links SIMs to <strong>phone assets</strong>. Admins can do both.</p>

<div class="card border-0 shadow">
    <div class="card-body p-0">
        <div class="table-responsive">
            <table class="table table-hover table-striped mb-0" id="simTable">
                <thead>
                    <tr>
                        <th>MSISDN</th>
                        <th>Pool</th>
                        <th>Location / label</th>
                        <th>Status</th>
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
                                <a class="btn btn-sm btn-outline-primary" href="<?php echo base_url('sim/sim-edit.php?id=' . rawurlencode($sid)); ?>">Edit</a>
                                <?php if (am_can_sim_team_assign() || am_can_sim_phone_link()): ?>
                                    <a class="btn btn-sm btn-outline-secondary" href="<?php echo base_url('sim/assignment-new.php?sim_id=' . rawurlencode($sid)); ?>">Assign</a>
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
