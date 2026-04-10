<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['flash_error'] = 'Admin access required.';
    header('Location: ' . base_url('index.php'));
    exit;
}

$page_title = 'Locations (from PR Portal)';

$locations = am_get_pr_sites();
$countries = am_firestore_get_collection('pr_master_countries', 500);
$countries = array_values(array_filter($countries, fn($c) => ((int)($c['active'] ?? 1)) !== 0));

$countryNames = [];
foreach ($countries as $c) {
    $code = (string)($c['country_code'] ?? $c['id'] ?? '');
    $countryNames[$code] = (string)($c['country_name'] ?? $code);
}

$byCountry = [];
foreach ($locations as $l) {
    $cc = (string)($l['country_code'] ?? '');
    $byCountry[$cc][] = $l;
}

include __DIR__ . '/../includes/header.php';
?>

<main class="content">
<?php include __DIR__ . '/../includes/topbar.php'; ?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center py-4">
        <div>
            <h1 class="h2 mb-1">Locations</h1>
            <p class="text-muted mb-0">Synced from the <strong>PR Portal</strong> &mdash; manage sites at <a href="https://pr.1pwrafrica.com" target="_blank" rel="noopener">pr.1pwrafrica.com</a></p>
        </div>
        <span class="badge bg-info fs-6"><?php echo count($locations); ?> sites</span>
    </div>

    <div class="alert alert-light border mb-4">
        <i class="fas fa-info-circle me-2 text-primary"></i>
        Location data is read live from the PR portal's <code>sites</code> and <code>referenceData_sites</code> collections.
        To add, rename, or remove a site, update it in the PR portal &mdash; changes appear here automatically.
    </div>

    <?php foreach ($byCountry as $cc => $locs):
        $cname = $countryNames[$cc] ?? strtoupper($cc);
    ?>
    <div class="card border-0 shadow mb-4">
        <div class="card-header">
            <h2 class="fs-5 fw-bold mb-0"><?php echo htmlspecialchars($cname); ?> (<?php echo count($locs); ?> sites)</h2>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th>Code</th><th>Name</th><th>Type</th><th>Region</th><th>Status</th></tr></thead>
                    <tbody>
                        <?php foreach ($locs as $loc): ?>
                        <tr>
                            <td><code><?php echo htmlspecialchars($loc['location_code'] ?? ''); ?></code></td>
                            <td><?php echo htmlspecialchars($loc['location_name'] ?? ''); ?></td>
                            <td><span class="badge bg-gray-200 text-gray-800"><?php echo htmlspecialchars($loc['location_type'] ?? 'Site'); ?></span></td>
                            <td><?php echo htmlspecialchars($loc['region'] ?? ''); ?></td>
                            <td>
                                <?php if (($loc['active'] ?? 1)): ?>
                                    <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                    <span class="badge bg-secondary">Inactive</span>
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

    <?php if (empty($byCountry)): ?>
    <div class="card border-0 shadow"><div class="card-body text-center text-gray-500 py-4">No sites found in the PR portal.</div></div>
    <?php endif; ?>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
