<?php
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['flash_error'] = 'Admin access required.';
    header('Location: ' . base_url('index.php'));
    exit;
}

$page_title = 'QR Labels';

$assets = am_firestore_get_collection('am_core_assets', 2000);
$countries = am_firestore_get_collection('pr_master_countries', 500);

$countryById = [];
foreach ($countries as $c) {
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($cid !== '') $countryById[$cid] = $c;
}

$withQr = [];
$withoutQr = [];
foreach ($assets as $a) {
    if (!empty($a['qr_code_id'])) {
        $withQr[] = $a;
    } else {
        $withoutQr[] = $a;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'batch_generate') {
    $ids = $_POST['asset_ids'] ?? [];
    $generated = 0;

    foreach ($ids as $docId) {
        $docId = trim($docId);
        if ($docId === '') continue;

        $asset = am_firestore_get_document('am_core_assets', $docId);
        if (!$asset || !empty($asset['qr_code_id'])) continue;

        $countryCode = 'UNK';
        $cid = (string)($asset['country_id'] ?? '');
        if (isset($countryById[$cid])) {
            $countryCode = (string)($countryById[$cid]['country_code'] ?? 'UNK');
        }

        $itemClass = (string)($asset['item_class'] ?? '');
        $prefixes = ['FixedAsset' => 'FA', 'Material' => 'MAT', 'Consumable' => 'CON', 'Inventory' => 'INV'];
        $classPrefix = $prefixes[$itemClass] ?? 'ITM';
        $qrPrefix = "1PWR-{$countryCode}-{$classPrefix}-";

        $maxNum = 0;
        foreach ($assets as $a) {
            $qr = (string)($a['qr_code_id'] ?? '');
            if (str_starts_with($qr, $qrPrefix)) {
                $numPart = (int)substr($qr, strlen($qrPrefix));
                if ($numPart > $maxNum) $maxNum = $numPart;
            }
        }

        $qrCodeId = $qrPrefix . str_pad((string)($maxNum + 1 + $generated), 6, '0', STR_PAD_LEFT);

        $result = am_firestore_update_document('am_core_assets', $docId, [
            'qr_code_id' => $qrCodeId,
            'updated_at' => date('c'),
        ]);

        if ($result['ok']) {
            $generated++;
        }
    }

    $_SESSION['flash_success'] = $generated . ' QR code(s) generated.';
    header('Location: ' . base_url('admin/qr-labels.php'));
    exit;
}

$flash = $_SESSION['flash_success'] ?? '';
unset($_SESSION['flash_success']);

$classColors = ['FixedAsset' => 'primary', 'Material' => 'warning', 'Consumable' => 'info', 'Inventory' => 'success'];

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-center py-4">
        <div>
            <h1 class="h2">QR Labels</h1>
            <p class="mb-0"><?php echo count($withQr); ?> assigned, <?php echo count($withoutQr); ?> pending</p>
        </div>
    </div>

    <?php if ($flash): ?>
    <div class="alert alert-success alert-dismissible fade show"><?php echo htmlspecialchars($flash); ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
    <?php endif; ?>

    <!-- Stats -->
    <div class="row mb-4">
        <div class="col-12 col-md-4 mb-4">
            <div class="card border-0 shadow text-center py-3">
                <div class="card-body py-2">
                    <i class="fas fa-qrcode text-success fa-2x"></i>
                    <h3 class="fw-extrabold mb-0 mt-2"><?php echo count($withQr); ?></h3>
                    <small class="text-gray-500">QR Codes Assigned</small>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4 mb-4">
            <div class="card border-0 shadow text-center py-3">
                <div class="card-body py-2">
                    <i class="fas fa-exclamation-circle text-warning fa-2x"></i>
                    <h3 class="fw-extrabold mb-0 mt-2"><?php echo count($withoutQr); ?></h3>
                    <small class="text-gray-500">Without QR Code</small>
                </div>
            </div>
        </div>
        <div class="col-12 col-md-4 mb-4">
            <div class="card border-0 shadow text-center py-3">
                <div class="card-body py-2">
                    <i class="fas fa-percentage text-primary fa-2x"></i>
                    <h3 class="fw-extrabold mb-0 mt-2">
                        <?php echo count($assets) > 0 ? round(count($withQr) / count($assets) * 100) : 0; ?>%
                    </h3>
                    <small class="text-gray-500">Coverage</small>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($withoutQr)): ?>
    <!-- Items Without QR -->
    <div class="card border-0 shadow mb-4">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h2 class="fs-5 fw-bold mb-0">Items Without QR Codes (<?php echo count($withoutQr); ?>)</h2>
            <form method="POST" action="" id="batchForm">
                <input type="hidden" name="action" value="batch_generate">
                <button type="submit" class="btn btn-sm btn-primary" onclick="return selectAllAndSubmit()">
                    <i class="fas fa-magic me-2"></i>Generate All
                </button>
            </form>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive">
                <table class="table table-hover mb-0">
                    <thead><tr><th><input type="checkbox" id="selectAll" onchange="toggleAll(this)"></th><th>Tag</th><th>Name</th><th>Class</th><th>Country</th><th>Action</th></tr></thead>
                    <tbody>
                        <?php foreach ($withoutQr as $a):
                            $docId = (string)($a['id'] ?? '');
                            $cls = (string)($a['item_class'] ?? '');
                            $cid = (string)($a['country_id'] ?? '');
                        ?>
                        <tr>
                            <td><input type="checkbox" class="asset-check" name="asset_ids[]" value="<?php echo htmlspecialchars($docId); ?>" form="batchForm"></td>
                            <td><code><?php echo htmlspecialchars($a['asset_tag'] ?? '—'); ?></code></td>
                            <td><?php echo htmlspecialchars($a['name'] ?? ''); ?></td>
                            <td><span class="badge bg-<?php echo $classColors[$cls] ?? 'secondary'; ?>"><?php echo htmlspecialchars($cls); ?></span></td>
                            <td><?php echo htmlspecialchars(($countryById[$cid] ?? [])['country_code'] ?? '—'); ?></td>
                            <td>
                                <button class="btn btn-sm btn-outline-primary" onclick="generateSingle('<?php echo htmlspecialchars($docId); ?>')">
                                    <i class="fas fa-qrcode me-1"></i>Generate
                                </button>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Items With QR -->
    <div class="card border-0 shadow">
        <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Assigned QR Codes (<?php echo count($withQr); ?>)</h2></div>
        <div class="card-body">
            <div class="table-responsive">
                <table class="table table-hover" id="qrTable">
                    <thead><tr><th>QR Code</th><th>Tag</th><th>Name</th><th>Class</th><th>Country</th><th>Preview</th></tr></thead>
                    <tbody>
                        <?php foreach ($withQr as $a):
                            $qr = (string)($a['qr_code_id'] ?? '');
                            $cls = (string)($a['item_class'] ?? '');
                            $cid = (string)($a['country_id'] ?? '');
                        ?>
                        <tr>
                            <td><code class="text-primary"><?php echo htmlspecialchars($qr); ?></code></td>
                            <td><?php echo htmlspecialchars($a['asset_tag'] ?? '—'); ?></td>
                            <td>
                                <a href="<?php echo base_url('assets/view.php?id=' . urlencode($a['id'] ?? '')); ?>">
                                    <?php echo htmlspecialchars($a['name'] ?? ''); ?>
                                </a>
                            </td>
                            <td><span class="badge bg-<?php echo $classColors[$cls] ?? 'secondary'; ?>"><?php echo htmlspecialchars($cls); ?></span></td>
                            <td><?php echo htmlspecialchars(($countryById[$cid] ?? [])['country_code'] ?? '—'); ?></td>
                            <td>
                                <img src="https://api.qrserver.com/v1/create-qr-code/?size=80x80&data=<?php echo urlencode($qr); ?>"
                                     alt="QR" style="width:40px;height:40px;" loading="lazy">
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>

<script>
function toggleAll(el) {
    document.querySelectorAll('.asset-check').forEach(function(cb) { cb.checked = el.checked; });
}
function selectAllAndSubmit() {
    document.querySelectorAll('.asset-check').forEach(function(cb) { cb.checked = true; });
    return true;
}
async function generateSingle(assetId) {
    try {
        var response = await fetch('<?php echo base_url('api/qr/generate.php'); ?>?asset_id=' + encodeURIComponent(assetId));
        var result = await response.json();
        if (result.success) {
            alert('QR Code generated: ' + result.qr_code_id);
            location.reload();
        } else {
            alert('Error: ' + (result.error || 'Failed'));
        }
    } catch (e) {
        alert('Error: ' + e.message);
    }
}
$(document).ready(function() {
    $('#qrTable').DataTable({ pageLength: 25, order: [[0, 'asc']] });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
