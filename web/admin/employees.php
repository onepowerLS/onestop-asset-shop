<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['flash_error'] = 'Admin access required.';
    header('Location: ' . base_url('index.php'));
    exit;
}

$page_title = 'Employees';

$countries = am_firestore_get_collection('pr_master_countries', 500);
$countryById = [];
foreach ($countries as $c) {
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($cid !== '') $countryById[$cid] = $c;
}

$employees = am_firestore_get_collection('pr_master_employees', 2000);
if (empty($employees)) {
    $employees = am_firestore_get_collection('am_core_employees', 2000);
}

$searchTerm = strtolower(trim($_GET['search'] ?? ''));
$countryFilter = $_GET['country'] ?? '';

$filtered = [];
foreach ($employees as $emp) {
    if ($countryFilter !== '' && (string)($emp['country_id'] ?? '') !== $countryFilter) continue;
    if ($searchTerm !== '') {
        $blob = strtolower(implode(' ', [
            $emp['first_name'] ?? '', $emp['last_name'] ?? '', $emp['email'] ?? '', $emp['phone'] ?? '',
        ]));
        if (!str_contains($blob, $searchTerm)) continue;
    }
    $filtered[] = $emp;
}

usort($filtered, function ($a, $b) {
    return strcmp(($a['last_name'] ?? ''), ($b['last_name'] ?? ''));
});

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center py-4">
        <div>
            <h1 class="h2">Employees</h1>
            <p class="mb-0">Employee directory sourced from HR system. <?php echo count($filtered); ?> records.</p>
        </div>
    </div>

    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <form method="GET" class="row g-3">
                <div class="col-12 col-md-5">
                    <input type="text" class="form-control" name="search" value="<?php echo htmlspecialchars($_GET['search'] ?? ''); ?>" placeholder="Search by name, email, phone...">
                </div>
                <div class="col-12 col-md-3">
                    <select class="form-select" name="country">
                        <option value="">All Countries</option>
                        <?php foreach ($countries as $c):
                            $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
                        ?>
                        <option value="<?php echo htmlspecialchars($cid); ?>" <?php echo $countryFilter === $cid ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($c['country_name'] ?? ''); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-12 col-md-2">
                    <button type="submit" class="btn btn-primary w-100"><i class="fas fa-search me-2"></i>Search</button>
                </div>
            </form>
        </div>
    </div>

    <div class="card border-0 shadow">
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0" id="employeesTable">
                    <thead>
                        <tr><th>Name</th><th>Email</th><th>Phone</th><th>Country</th><th>Department</th><th>Status</th></tr>
                    </thead>
                    <tbody>
                        <?php if (empty($filtered)): ?>
                        <tr><td colspan="6" class="text-center text-gray-500 py-4">No employees found.</td></tr>
                        <?php else: ?>
                        <?php foreach ($filtered as $emp): ?>
                        <tr>
                            <td><strong><?php echo htmlspecialchars(($emp['first_name'] ?? '') . ' ' . ($emp['last_name'] ?? '')); ?></strong></td>
                            <td><?php echo htmlspecialchars($emp['email'] ?? '—'); ?></td>
                            <td><?php echo htmlspecialchars($emp['phone'] ?? '—'); ?></td>
                            <td>
                                <?php
                                $cname = $countryById[(string)($emp['country_id'] ?? '')] ?? [];
                                echo htmlspecialchars($cname['country_code'] ?? '—');
                                ?>
                            </td>
                            <td><?php echo htmlspecialchars($emp['department'] ?? $emp['department_id'] ?? '—'); ?></td>
                            <td>
                                <?php if ((int)($emp['active'] ?? 1)): ?>
                                <span class="badge bg-success">Active</span>
                                <?php else: ?>
                                <span class="badge bg-secondary">Inactive</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
$(document).ready(function() {
    $('#employeesTable').DataTable({ pageLength: 25, order: [[0, 'asc']] });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
