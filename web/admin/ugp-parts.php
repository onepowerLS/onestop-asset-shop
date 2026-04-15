<?php
/**
 * Admin: align UGP assembly parts with AM Inventory (manual JSON import).
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/ugp_parts.php';
require_once __DIR__ . '/../config/authz.php';
require_login();

if (($_SESSION['role'] ?? '') !== 'Admin') {
    $_SESSION['flash_error'] = 'Admin access required.';
    header('Location: ' . base_url('index.php'));
    exit;
}

$page_title = 'UGP ↔ Inventory alignment';
$errors = [];
$runLog = [];
$stats = null;

$countries = am_firestore_get_collection('pr_master_countries', 500);
$countries = array_values(array_filter($countries, fn($c) => (int)($c['active'] ?? 1) === 1));
$categories = am_firestore_get_collection('pr_master_categories', 1000);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $countryId = trim((string)($_POST['country_id'] ?? ''));
    $payload = trim((string)($_POST['json_payload'] ?? ''));
    $dryRun = isset($_POST['dry_run']);
    $linkName = !isset($_POST['no_name_link']);

    if ($countryId === '') {
        $errors[] = 'Select a country.';
    }
    $decoded = json_decode($payload, true);
    if ($payload === '') {
        $errors[] = 'Paste JSON.';
    } elseif (!is_array($decoded)) {
        $errors[] = 'JSON must be an array of part objects, or { "parts": [...] }.';
    }

    $parts = null;
    if (is_array($decoded)) {
        if (isset($decoded['parts']) && is_array($decoded['parts'])) {
            $parts = $decoded['parts'];
        } elseif (array_is_list($decoded)) {
            $parts = $decoded;
        }
    }
    if ($parts === null && empty($errors)) {
        $errors[] = 'Could not find a parts array.';
    }

    if (empty($errors) && is_array($parts)) {
        $allAssets = am_firestore_get_collection('am_core_assets', 2000);
        foreach ($parts as $part) {
            if (!is_array($part)) {
                continue;
            }
            $part['country_id'] = $countryId;
            $ctx = [
                'countries' => $countries,
                'categories' => $categories,
                'all_assets' => $allAssets,
                'id_token_override' => null,
                'link_on_normalized_name' => $linkName,
                'dry_run' => $dryRun,
                'created_by' => (string)($_SESSION['user_id'] ?? ''),
            ];
            $r = am_ugp_sync_single_part($part, $ctx);
            $runLog[] = $r;
            if (($r['ok'] ?? false) && (($r['action'] ?? '') === 'created') && !empty($r['asset_id'])) {
                $allAssets[] = [
                    'id' => $r['asset_id'],
                    'asset_id' => $r['asset_id'],
                    'asset_tag' => $r['asset_tag'] ?? '',
                    'item_class' => 'Inventory',
                ];
            }
        }
        $stats = ['updated' => 0, 'linked' => 0, 'created' => 0, 'ambiguous' => 0, 'errors' => 0];
        foreach ($runLog as $r) {
            $act = (string)($r['action'] ?? '');
            if ($r['ok'] ?? false) {
                if ($act === 'updated') {
                    $stats['updated']++;
                } elseif ($act === 'linked') {
                    $stats['linked']++;
                } elseif ($act === 'created') {
                    $stats['created']++;
                }
            } else {
                if ($act === 'ambiguous') {
                    $stats['ambiguous']++;
                } else {
                    $stats['errors']++;
                }
            }
        }
    }
}

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <nav aria-label="breadcrumb">
        <ol class="breadcrumb mb-2">
            <li class="breadcrumb-item"><a href="<?php echo base_url('index.php'); ?>">Home</a></li>
            <li class="breadcrumb-item active">UGP parts alignment</li>
        </ol>
    </nav>
    <h1 class="h3 mb-3"><?php echo htmlspecialchars($page_title); ?></h1>
    <p class="text-muted">
        Each UGP part should map to one <strong>Inventory</strong> row in AM. Use stable <code>ugp_part_id</code> from UGP.
        If that id is already on an AM item, we refresh metadata only. If not, but the <strong>normalized name</strong> matches exactly one existing Inventory line (same country, no UGP id yet), we <strong>link</strong> without creating a duplicate.
        Otherwise we create a new Inventory row (quantity defaults to 0).
    </p>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><?php echo htmlspecialchars(implode(' ', $errors)); ?></div>
    <?php endif; ?>

    <?php if ($stats !== null): ?>
    <div class="alert alert-info">
        Run: updated <?php echo (int)$stats['updated']; ?>,
        linked <?php echo (int)$stats['linked']; ?>,
        created <?php echo (int)$stats['created']; ?>,
        ambiguous <?php echo (int)$stats['ambiguous']; ?>,
        errors <?php echo (int)$stats['errors']; ?>.
        <?php if (!empty($_POST['dry_run'])): ?><strong>Dry run — no writes.</strong><?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow mb-4">
        <div class="card-body">
            <form method="post">
                <div class="mb-3">
                    <label class="form-label">Country (catalog)</label>
                    <select name="country_id" class="form-select" required>
                        <option value="">—</option>
                        <?php foreach ($countries as $c): ?>
                        <?php $cid = (string)($c['country_id'] ?? $c['id'] ?? ''); ?>
                        <option value="<?php echo htmlspecialchars($cid); ?>" <?php echo (isset($_POST['country_id']) && $_POST['country_id'] === $cid) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars((string)($c['country_name'] ?? '') . ' (' . (string)($c['country_code'] ?? '') . ')'); ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="mb-3">
                    <label class="form-label">Parts JSON</label>
                    <textarea name="json_payload" class="form-control font-monospace" rows="14" placeholder='[{"ugp_part_id":"ugp-mtr-001","name":"Single phase meter","description":"...","quantity":0}]'><?php echo htmlspecialchars((string)($_POST['json_payload'] ?? '')); ?></textarea>
                    <div class="form-text">Array of objects with <code>ugp_part_id</code>, <code>name</code>, optional <code>description</code>, <code>quantity</code>, <code>unit_of_measure</code>.</div>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="dry_run" id="dry_run" <?php echo !empty($_POST['dry_run']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="dry_run">Dry run (no Firestore writes)</label>
                </div>
                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" name="no_name_link" id="no_name_link" <?php echo !empty($_POST['no_name_link']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="no_name_link">Do not auto-link by normalized name (only match on <code>ugp_part_id</code> or create new)</label>
                </div>
                <button type="submit" class="btn btn-primary">Run alignment</button>
            </form>
        </div>
    </div>

    <?php if (!empty($runLog)): ?>
    <h2 class="h6">Results</h2>
    <div class="table-responsive">
        <table class="table table-sm table-bordered">
            <thead><tr><th>OK</th><th>Action</th><th>Asset</th><th>Message</th></tr></thead>
            <tbody>
                <?php foreach ($runLog as $row): ?>
                <tr>
                    <td><?php echo !empty($row['ok']) ? 'yes' : 'no'; ?></td>
                    <td><?php echo htmlspecialchars((string)($row['action'] ?? '')); ?></td>
                    <td><code><?php echo htmlspecialchars((string)($row['asset_id'] ?? '')); ?></code></td>
                    <td><?php echo htmlspecialchars((string)($row['message'] ?? '')); ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>

    <div class="card border-0 shadow">
        <div class="card-body">
            <h2 class="h6">API (for UGP automation)</h2>
            <p class="small mb-1"><code>POST <?php echo htmlspecialchars(base_url('api/ugp/parts-sync.php')); ?></code></p>
            <p class="small text-muted mb-0">Use <code>Authorization: Bearer</code> (Firebase ID token) or <code>X-API-Key</code> with server <code>FIREBASE_ADMIN_BEARER_TOKEN</code>. Body: <code>country_id</code>, <code>parts</code>[], optional <code>dry_run</code>.</p>
        </div>
    </div>
</div>

<?php include __DIR__ . '/../includes/footer.php'; ?>
