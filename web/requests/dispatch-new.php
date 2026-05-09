<?php
/**
 * Dispatch Request — custom form.
 * Select items from catalog, specify quantities, site, receiver.
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/firestore.php';
require_once __DIR__ . '/../config/authz.php';
require_once __DIR__ . '/../config/country_scope.php';
require_once __DIR__ . '/../config/request_workflows.php';
require_login();

$page_title = 'Dispatch request';
$template = am_request_workflow_template('inventory_dispatch');

// ── Country resolution ───────────────────────────────────────
$countries = am_firestore_get_collection('pr_master_countries', 500);
$countryById = [];
foreach ($countries as $c) {
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($cid !== '') $countryById[$cid] = $c;
}

$allowCodes = am_country_allow_codes();
$userCountries = [];
foreach ($countries as $c) {
    $cc = strtoupper((string)($c['country_code'] ?? ''));
    $cid = (string)($c['country_id'] ?? $c['id'] ?? '');
    if ($cc !== '' && $cid !== '' && in_array($cc, $allowCodes, true)) {
        $userCountries[$cid] = $c;
    }
}

// Default to first allowed country
$selectedCountryId = $_POST['country_id'] ?? (array_key_first($userCountries) ?? '');

// ── Sites ─────────────────────────────────────────────────────
$allSites = am_get_pr_sites();
$sites = [];
foreach ($allSites as $s) {
    $cc = (string)($s['country_code'] ?? '');
    if (in_array($cc, $allowCodes, true)) {
        $sites[] = $s;
    }
}

// ── Employees ─────────────────────────────────────────────────
$employees = am_firestore_get_collection('pr_master_employees', 2000);
if (empty($employees)) {
    $employees = am_firestore_get_collection('am_core_employees', 2000);
}
// Index employees by email for quick lookup
$employeeByEmail = [];
foreach ($employees as $emp) {
    $email = strtolower(trim((string)($emp['email'] ?? '')));
    if ($email !== '') {
        $employeeByEmail[$email] = $emp;
    }
}

// ── POST handling ─────────────────────────────────────────────
$errors = [];
$submitted = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    am_require_can_mutate();

    $submitterName  = trim((string)($_POST['submitter_name'] ?? ''));
    $submitterEmail = trim((string)($_POST['submitter_email'] ?? ''));
    $siteCode       = trim((string)($_POST['site_code'] ?? ''));
    $dispatchDate   = trim((string)($_POST['dispatch_date'] ?? ''));
    $receiverName   = trim((string)($_POST['receiver_name'] ?? ''));
    $receiverEmail  = trim((string)($_POST['receiver_email'] ?? ''));
    $rawItems       = trim((string)($_POST['line_items_json'] ?? ''));

    if ($submitterName === '')  $errors[] = 'Your name is required.';
    if ($submitterEmail === '' || !filter_var($submitterEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid email is required.';
    if ($siteCode === '')       $errors[] = 'Destination site is required.';
    if ($dispatchDate === '')   $errors[] = 'Dispatch date is required.';
    if ($receiverName === '')   $errors[] = 'Receiver name is required.';
    if ($receiverEmail === '' || !filter_var($receiverEmail, FILTER_VALIDATE_EMAIL)) $errors[] = 'A valid receiver email is required.';

    $lineItems = [];
    if ($rawItems !== '') {
        $decoded = json_decode($rawItems, true);
        if (is_array($decoded)) $lineItems = $decoded;
    }
    if (empty($lineItems)) $errors[] = 'At least one item is required.';

    // Validate each line item
    $validItems = [];
    foreach ($lineItems as $idx => $li) {
        $aid = trim((string)($li['asset_id'] ?? ''));
        $qty = (int)($li['quantity'] ?? 0);
        if ($aid === '') {
            $errors[] = 'Item #' . ($idx + 1) . ' is missing an asset reference.';
            continue;
        }
        if ($qty < 1) {
            $errors[] = 'Item #' . ($idx + 1) . ' must have quantity ≥ 1.';
            continue;
        }
        $validItems[] = [
            'asset_id'   => $aid,
            'name'       => trim((string)($li['name'] ?? '')),
            'asset_tag'  => trim((string)($li['asset_tag'] ?? '')),
            'item_class' => trim((string)($li['item_class'] ?? '')),
            'quantity'   => $qty,
            'unit'       => trim((string)($li['unit'] ?? 'EA')),
        ];
    }

    // Verify receiver against employee directory
    $recvEmailLower = strtolower($receiverEmail);
    if (!isset($employeeByEmail[$recvEmailLower])) {
        $errors[] = 'Receiver email not found in the employee directory. Please select a valid employee.';
    }

    // Verify site is in user's country scope
    $siteCountryOk = false;
    $siteName = '';
    foreach ($sites as $s) {
        $sc = (string)($s['location_code'] ?? '');
        if ($sc === $siteCode) {
            $siteCountryOk = true;
            $siteName = (string)($s['location_name'] ?? '');
            break;
        }
    }
    if (!$siteCountryOk) $errors[] = 'Selected site is not in your permitted countries.';

    if (empty($errors)) {
        $allReq = am_firestore_get_collection('am_core_requests', 3000);
        $seq = count($allReq) + 1;
        $reqNum = 'AMW-' . date('Y') . '-' . str_pad((string)$seq, 5, '0', STR_PAD_LEFT);

        $payload = [
            'submitter_name'  => $submitterName,
            'submitter_email' => $submitterEmail,
            'site_code'       => $siteCode,
            'site_name'       => $siteName,
            'dispatch_date'   => $dispatchDate,
            'receiver_name'   => $receiverName,
            'receiver_email'  => $receiverEmail,
            'line_items'      => $validItems,
        ];

        $summary = am_workflow_summary_line('inventory_dispatch', $payload);

        $data = [
            'request_number'        => $reqNum,
            'workflow_type'         => 'inventory_dispatch',
            'workflow_label'        => 'Dispatch request',
            'status'                => 'Submitted',
            'requested_by'          => (string)($_SESSION['user_id'] ?? ''),
            'requested_for_country' => $selectedCountryId,
            'summary'               => $summary,
            'requested_date'        => date('c'),
            'payload'               => $payload,
        ];

        $result = am_firestore_create_document('am_core_requests', $data);
        if ($result['ok']) {
            $_SESSION['flash_success'] = 'Submitted ' . $reqNum . '.';
            header('Location: ' . base_url('requests/dispatch-view.php?id=' . urlencode($result['id'] ?? '')));
            exit;
        }
        $errors[] = $result['error'] ?? 'Could not save request.';
    }
    $submitted = !empty($errors);
}

$defaults = [
    'submitter_email' => (string)($_SESSION['email'] ?? ''),
    'submitter_name'  => (string)($_SESSION['username'] ?? ''),
];

include __DIR__ . '/../includes/header.php';
?>

<div class="py-4">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2 py-4">
        <div>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-2">
                    <li class="breadcrumb-item"><a href="<?php echo base_url('requests/workflow-index.php'); ?>">Service workflows</a></li>
                    <li class="breadcrumb-item active"><?php echo htmlspecialchars($template['label'] ?? 'Inventory dispatch'); ?></li>
                </ol>
            </nav>
            <h1 class="h2"><?php echo htmlspecialchars($template['label'] ?? 'Inventory dispatch request'); ?></h1>
            <p class="mb-0 text-gray-600">Request items dispatched from HQ/warehouse to a site within your country.</p>
        </div>
        <a href="<?php echo base_url('requests/workflow-index.php'); ?>" class="btn btn-outline-secondary btn-sm">Back to list</a>
    </div>

    <?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
    <?php endif; ?>

    <?php if (am_is_auditor_readonly()): ?>
    <div class="alert alert-warning">Read-only accounts cannot submit dispatch requests.</div>
    <?php else: ?>
    <form method="post" action="" id="dispatchForm">
        <!-- Hidden field for line items JSON -->
        <input type="hidden" name="line_items_json" id="lineItemsJson" value="">

        <!-- Section A: Requester Identity -->
        <div class="card border-0 shadow mb-4">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Your details</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="submitter_name">Full name <span class="text-danger">*</span></label>
                        <input type="text" name="submitter_name" id="submitter_name" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['submitter_name'] ?? $defaults['submitter_name']); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="submitter_email">Email <span class="text-danger">*</span></label>
                        <input type="email" name="submitter_email" id="submitter_email" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['submitter_email'] ?? $defaults['submitter_email']); ?>" required autocomplete="email">
                    </div>
                </div>
            </div>
        </div>

        <!-- Section B: Line Items -->
        <div class="card border-0 shadow mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h2 class="fs-5 fw-bold mb-0">Items requested</h2>
                <button type="button" class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#itemSearchModal">
                    <i class="fas fa-plus me-1"></i> Add item
                </button>
            </div>
            <div class="card-body p-0">
                <table class="table table-hover mb-0" id="lineItemsTable">
                    <thead>
                        <tr>
                            <th>Item</th>
                            <th>Tag</th>
                            <th style="width:100px">Quantity</th>
                            <th style="width:80px">Unit</th>
                            <th style="width:40px"></th>
                        </tr>
                    </thead>
                    <tbody id="lineItemsBody">
                        <tr id="noItemsRow">
                            <td colspan="5" class="text-center text-gray-500 py-4">No items added yet. Click <strong>Add item</strong> to search the catalog.</td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <!-- Section C: Destination & Receiver -->
        <div class="card border-0 shadow mb-4">
            <div class="card-header"><h2 class="fs-5 fw-bold mb-0">Destination &amp; receiver</h2></div>
            <div class="card-body">
                <div class="row g-3">
                    <div class="col-md-4">
                        <label class="form-label" for="country_id">Country</label>
                        <?php if (count($userCountries) <= 1): ?>
                        <input type="text" class="form-control" readonly
                            value="<?php echo htmlspecialchars($userCountries[$selectedCountryId]['country_name'] ?? ''); ?>">
                        <input type="hidden" name="country_id" value="<?php echo htmlspecialchars($selectedCountryId); ?>">
                        <?php else: ?>
                        <select name="country_id" id="country_id" class="form-select" required>
                            <?php foreach ($userCountries as $cid => $c): ?>
                            <option value="<?php echo htmlspecialchars($cid); ?>" <?php echo $selectedCountryId === $cid ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($c['country_name'] ?? '') . ' (' . ($c['country_code'] ?? '') . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                        <?php endif; ?>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="site_code">Site <span class="text-danger">*</span></label>
                        <select name="site_code" id="site_code" class="form-select" required>
                            <option value="">Select site…</option>
                            <?php foreach ($sites as $s):
                                $scc = (string)($s['country_code'] ?? '');
                            ?>
                            <option value="<?php echo htmlspecialchars($s['location_code'] ?? ''); ?>"
                                data-country="<?php echo htmlspecialchars($scc); ?>"
                                <?php echo ($_POST['site_code'] ?? '') === ($s['location_code'] ?? '') ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(($s['location_name'] ?? '') . ' (' . ($s['location_code'] ?? '') . ')'); ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-4">
                        <label class="form-label" for="dispatch_date">Est. dispatch date <span class="text-danger">*</span></label>
                        <input type="date" name="dispatch_date" id="dispatch_date" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['dispatch_date'] ?? ''); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="receiver_name">Receiver name <span class="text-danger">*</span></label>
                        <input type="text" name="receiver_name" id="receiver_name" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['receiver_name'] ?? ''); ?>"
                            placeholder="Start typing to find employee…" required
                            list="employeeList" autocomplete="off">
                        <datalist id="employeeList">
                            <?php foreach ($employees as $emp):
                                $eName = trim(((string)($emp['first_name'] ?? '') . ' ' . (string)($emp['last_name'] ?? '')));
                                $eEmail = (string)($emp['email'] ?? '');
                                if ($eName === '' || $eEmail === '') continue;
                            ?>
                            <option value="<?php echo htmlspecialchars($eName); ?>" data-email="<?php echo htmlspecialchars($eEmail); ?>">
                                <?php echo htmlspecialchars($eName . ' <' . $eEmail . '>'); ?>
                            </option>
                            <?php endforeach; ?>
                        </datalist>
                        <div class="form-text">Select from the employee directory. Email will auto-fill.</div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="receiver_email">Receiver email <span class="text-danger">*</span></label>
                        <input type="email" name="receiver_email" id="receiver_email" class="form-control"
                            value="<?php echo htmlspecialchars($_POST['receiver_email'] ?? ''); ?>" required autocomplete="off"
                            placeholder="Auto-filled from employee selection">
                    </div>
                </div>
            </div>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-paper-plane me-2"></i>Submit request
            </button>
            <a href="<?php echo base_url('requests/workflow-index.php'); ?>" class="btn btn-gray-200">Cancel</a>
        </div>
    </form>
    <?php endif; ?>
</div>

<!-- Item Search Modal -->
<div class="modal fade" id="itemSearchModal" tabindex="-1" aria-labelledby="itemSearchModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h3 class="modal-title fs-5" id="itemSearchModalLabel">Search catalog</h3>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="mb-3">
                    <input type="text" id="itemSearchInput" class="form-control" placeholder="Search by name, asset tag, description…" autocomplete="off">
                </div>
                <div class="table-responsive" style="max-height:50vh;">
                    <table class="table table-sm table-hover mb-0" id="searchResultsTable">
                        <thead>
                            <tr><th>Name</th><th>Tag</th><th>Class</th><th>Location</th><th>Stock</th><th>Status</th><th></th></tr>
                        </thead>
                        <tbody id="searchResultsBody">
                            <tr id="searchLoadingRow"><td colspan="7" class="text-center text-gray-500 py-3">Type to search catalog items in your country…</td></tr>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<style>
#itemSearchInput:focus { box-shadow: 0 0 0 0.2rem rgba(25,118,210,0.25); border-color: #1976d2; }
</style>

<script>
// Line items state
var lineItems = [];
var countryId = <?php echo json_encode($selectedCountryId); ?>;
var searchApiUrl = <?php echo json_encode(base_url('api/dispatch/search-items.php')); ?>;
var employeeMap = {};
<?php foreach ($employees as $emp):
    $eName = trim(((string)($emp['first_name'] ?? '') . ' ' . (string)($emp['last_name'] ?? '')));
    $eEmail = (string)($emp['email'] ?? '');
    if ($eName !== '' && $eEmail !== ''):
?>employeeMap[<?php echo json_encode($eName); ?>] = <?php echo json_encode($eEmail); ?>;
<?php endif; endforeach; ?>

function renderLineItems() {
    var tbody = document.getElementById('lineItemsBody');
    var noRow = document.getElementById('noItemsRow');
    tbody.innerHTML = '';
    if (lineItems.length === 0) {
        noRow = document.createElement('tr');
        noRow.id = 'noItemsRow';
        noRow.innerHTML = '<td colspan="5" class="text-center text-gray-500 py-4">No items added yet. Click <strong>Add item</strong> to search inventory.</td>';
        tbody.appendChild(noRow);
    } else {
        lineItems.forEach(function(item, idx) {
            var tr = document.createElement('tr');
            tr.innerHTML =
                '<td><strong>' + escHtml(item.name) + '</strong>' +
                (item.item_class ? ' <span class="badge bg-secondary ms-1">' + escHtml(item.item_class) + '</span>' : '') +
                '</td>' +
                '<td><code class="text-muted">' + escHtml(item.asset_tag || '—') + '</code></td>' +
                '<td><input type="number" class="form-control form-control-sm qty-input" value="' + item.quantity + '" min="1" data-idx="' + idx + '" style="width:80px"></td>' +
                '<td><span class="text-muted">' + escHtml(item.unit || 'EA') + '</span></td>' +
                '<td><button type="button" class="btn btn-sm btn-outline-danger remove-btn" data-idx="' + idx + '" title="Remove"><i class="fas fa-trash"></i></button></td>';
            tbody.appendChild(tr);
        });

        // Bind quantity change
        tbody.querySelectorAll('.qty-input').forEach(function(inp) {
            inp.addEventListener('change', function() {
                var idx = parseInt(this.dataset.idx);
                var val = parseInt(this.value) || 1;
                if (val < 1) val = 1;
                this.value = val;
                lineItems[idx].quantity = val;
                syncHiddenField();
            });
        });

        // Bind remove
        tbody.querySelectorAll('.remove-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var idx = parseInt(this.dataset.idx);
                lineItems.splice(idx, 1);
                renderLineItems();
                syncHiddenField();
            });
        });
    }
}

function escHtml(s) {
    var d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}

function syncHiddenField() {
    document.getElementById('lineItemsJson').value = JSON.stringify(lineItems);
}

// ── Country → site filtering ──────────────────────────────
var countryToCode = {};
<?php foreach ($userCountries as $cid => $c):
    $cc = strtoupper((string)($c['country_code'] ?? ''));
    if ($cc !== ''):
?>countryToCode[<?php echo json_encode($cid); ?>] = <?php echo json_encode($cc); ?>;
<?php endif; endforeach; ?>

function filterSitesByCountry() {
    var selCountryId = document.getElementById('country_id').value;
    var targetCode = countryToCode[selCountryId] || '';
    var siteSelect = document.getElementById('site_code');
    var currentVal = siteSelect.value;
    var found = false;
    for (var i = 0; i < siteSelect.options.length; i++) {
        var opt = siteSelect.options[i];
        if (opt.value === '') continue; // placeholder
        var optCountry = opt.getAttribute('data-country') || '';
        if (targetCode === '' || optCountry === targetCode) {
            opt.style.display = '';
            if (opt.value === currentVal) found = true;
        } else {
            opt.style.display = 'none';
        }
    }
    if (!found) siteSelect.value = '';
    // Keep search API in sync with selected country
    countryId = selCountryId;
}
document.getElementById('country_id').addEventListener('change', filterSitesByCountry);
// Run once on load to filter to default country
filterSitesByCountry();

// ── Employee autocomplete ──────────────────────────────────
document.getElementById('receiver_name').addEventListener('input', function() {
    var name = this.value.trim();
    var email = employeeMap[name] || '';
    document.getElementById('receiver_email').value = email;
});

// ── Item search modal ──────────────────────────────────────
var searchTimer = null;
document.getElementById('itemSearchInput').addEventListener('input', function() {
    var q = this.value.trim();
    clearTimeout(searchTimer);
    searchTimer = setTimeout(function() { doSearch(q); }, 300);
});

function doSearch(q) {
    var tbody = document.getElementById('searchResultsBody');
    tbody.innerHTML = '<tr><td colspan="7" class="text-center text-gray-500 py-3"><div class="spinner-border spinner-border-sm" role="status"></div> Searching…</td></tr>';

    if (q.length < 2) {
        tbody.innerHTML = '<tr><td colspan="7" class="text-center text-gray-500 py-3">Type at least 2 characters to search…</td></tr>';
        return;
    }

    var url = searchApiUrl + '?country_id=' + encodeURIComponent(countryId) + '&q=' + encodeURIComponent(q);
    fetch(url, { credentials: 'same-origin' })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            tbody.innerHTML = '';
            if (!data.ok || !data.items || data.items.length === 0) {
                tbody.innerHTML = '<tr><td colspan="7" class="text-center text-gray-500 py-3">No items found matching "' + escHtml(q) + '".</td></tr>';
                return;
            }
            data.items.forEach(function(item) {
                // Skip if already in line items
                var already = lineItems.some(function(li) { return li.asset_id === item.asset_id; });
                var clsBadge = {
                    'Material': 'warning', 'Consumable': 'info', 'Inventory': 'success', 'FixedAsset': 'primary'
                };
                var stockDisplay = item.quantity_on_hand > 0
                    ? '<span class="fw-bold text-success">' + item.quantity_on_hand + '</span>'
                    : '<span class="text-muted">0</span>';
                var tr = document.createElement('tr');
                if (already) tr.classList.add('table-secondary');
                tr.innerHTML =
                    '<td><strong>' + escHtml(item.name) + '</strong></td>' +
                    '<td><code>' + escHtml(item.asset_tag || item.legacy_tag || '—') + '</code></td>' +
                    '<td><span class="badge bg-' + (clsBadge[item.item_class] || 'secondary') + '">' + escHtml(item.item_class) + '</span></td>' +
                    '<td>' + escHtml(item.location_name || '—') + '</td>' +
                    '<td>' + stockDisplay + '</td>' +
                    '<td><span class="badge bg-light text-dark">' + escHtml(item.status) + '</span></td>' +
                    '<td>' + (already
                        ? '<span class="text-muted small">Added</span>'
                        : '<button type="button" class="btn btn-sm btn-outline-primary add-item-btn" data-json=\'' + JSON.stringify(item).replace(/'/g, "&#39;") + '\'>Add</button>'
                    ) + '</td>';
                tbody.appendChild(tr);
            });

            // Bind add buttons
            tbody.querySelectorAll('.add-item-btn').forEach(function(btn) {
                btn.addEventListener('click', function() {
                    var item = JSON.parse(this.dataset.json);
                    lineItems.push({
                        asset_id: item.asset_id,
                        name: item.name,
                        asset_tag: item.asset_tag || item.legacy_tag || '',
                        item_class: item.item_class || '',
                        quantity: 1,
                        unit: item.unit_of_measure || 'EA'
                    });
                    renderLineItems();
                    syncHiddenField();
                    this.closest('tr').classList.add('table-secondary');
                    this.outerHTML = '<span class="text-muted small">Added</span>';
                });
            });
        })
        .catch(function() {
            tbody.innerHTML = '<tr><td colspan="7" class="text-center text-danger py-3">Search failed. Try again.</td></tr>';
        });
}

// Reset modal search when opened
document.getElementById('itemSearchModal').addEventListener('shown.bs.modal', function() {
    document.getElementById('itemSearchInput').value = '';
    document.getElementById('searchResultsBody').innerHTML = '<tr><td colspan="7" class="text-center text-gray-500 py-3">Type to search catalog items in your country…</td></tr>';
    document.getElementById('itemSearchInput').focus();
});

// Form submit: validate at least one item
document.getElementById('dispatchForm').addEventListener('submit', function(e) {
    syncHiddenField();
    if (lineItems.length === 0) {
        e.preventDefault();
        alert('Please add at least one item to the request.');
        return false;
    }
    return true;
});

// Initial render
renderLineItems();
syncHiddenField();
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
