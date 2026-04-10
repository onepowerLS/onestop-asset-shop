<?php
/**
 * Tablet Mode -- scan-centric launcher for field operations.
 *
 * Provides three workflows:
 *   1. Check-Out / Check-In  (scan → allocate/return)
 *   2. Stock Count            (scan → record physical count)
 *   3. Quick Lookup           (scan → view item detail)
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/authz.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['firebase_id_token'])) {
    header('Location: /login.php'); exit;
}

require_once __DIR__ . '/../config/firestore.php';
am_require_can_mutate();

$userName = htmlspecialchars($_SESSION['username'] ?? 'User');
$amRole   = $_SESSION['role'] ?? $_SESSION['am_role'] ?? 'Viewer';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=no">
    <title>1PWR AM — Tablet Mode</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --1pwr-blue: #1a3a5c;
            --1pwr-green: #28a745;
            --1pwr-orange: #fd7e14;
        }
        * { -webkit-tap-highlight-color: transparent; }
        body {
            background: #f0f2f5;
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
            min-height: 100vh;
            min-height: 100dvh;
            overflow: hidden;
        }
        .tablet-header {
            background: var(--1pwr-blue);
            color: white;
            padding: 12px 20px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .tablet-header .logo { font-weight: 700; font-size: 1.2rem; }
        .tablet-header .user-info { font-size: 0.85rem; opacity: 0.8; }

        .mode-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 16px;
            padding: 20px;
            max-width: 600px;
            margin: 0 auto;
        }
        .mode-card {
            background: white;
            border-radius: 16px;
            padding: 28px 20px;
            text-align: center;
            box-shadow: 0 2px 12px rgba(0,0,0,0.08);
            cursor: pointer;
            transition: transform 0.15s, box-shadow 0.15s;
            text-decoration: none;
            color: inherit;
        }
        .mode-card:active { transform: scale(0.97); }
        .mode-card:hover { box-shadow: 0 4px 20px rgba(0,0,0,0.12); }
        .mode-card .icon {
            font-size: 2.5rem;
            margin-bottom: 12px;
        }
        .mode-card h3 { font-size: 1.1rem; margin-bottom: 4px; }
        .mode-card p { font-size: 0.8rem; color: #6c757d; margin: 0; }
        .mode-card.checkout .icon { color: var(--1pwr-orange); }
        .mode-card.stockcount .icon { color: var(--1pwr-green); }
        .mode-card.lookup .icon { color: var(--1pwr-blue); }
        .mode-card.desktop .icon { color: #6c757d; }

        /* Scan overlay */
        .scan-overlay {
            position: fixed; inset: 0;
            background: rgba(0,0,0,0.92);
            color: white;
            display: none;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            z-index: 9999;
        }
        .scan-overlay.active { display: flex; }
        .scan-overlay .scan-ring {
            width: 200px; height: 200px;
            border: 4px solid rgba(255,255,255,0.3);
            border-top-color: var(--1pwr-green);
            border-radius: 50%;
            animation: pulse 1.5s ease-in-out infinite;
        }
        @keyframes pulse {
            0%, 100% { transform: scale(1); border-top-color: var(--1pwr-green); }
            50% { transform: scale(1.05); border-top-color: #fff; }
        }
        .scan-overlay h2 { margin-top: 24px; font-size: 1.4rem; }
        .scan-overlay .scan-input {
            position: absolute;
            opacity: 0;
            width: 1px; height: 1px;
        }
        .scan-overlay .cancel-btn {
            margin-top: 30px;
            padding: 12px 40px;
            border-radius: 30px;
        }

        /* Result panel */
        .result-panel {
            position: fixed; inset: 0;
            background: #f0f2f5;
            display: none;
            flex-direction: column;
            z-index: 9998;
            overflow-y: auto;
        }
        .result-panel.active { display: flex; }
        .result-header {
            background: var(--1pwr-blue);
            color: white;
            padding: 14px 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        .result-body { padding: 16px; flex: 1; }
        .item-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.06);
            margin-bottom: 16px;
        }
        .item-card .badge-class {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 0.75rem;
            font-weight: 600;
        }
        .badge-FixedAsset { background: #e3f2fd; color: #1565c0; }
        .badge-Material { background: #fff3e0; color: #e65100; }
        .badge-Consumable { background: #e0f7fa; color: #00838f; }
        .badge-Inventory { background: #e8f5e9; color: #2e7d32; }
        .field-row { display: flex; justify-content: space-between; padding: 6px 0; border-bottom: 1px solid #f0f0f0; }
        .field-label { color: #6c757d; font-size: 0.85rem; }
        .field-value { font-weight: 500; font-size: 0.85rem; }

        .action-bar {
            padding: 16px;
            background: white;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.06);
        }
        .action-bar .btn { border-radius: 10px; padding: 14px; font-size: 1rem; }

        /* Stock count form */
        .stock-form input[type=number] {
            font-size: 2rem;
            text-align: center;
            border-radius: 12px;
            padding: 16px;
        }
    </style>
</head>
<body>

<header class="tablet-header">
    <div>
        <span class="logo">1PWR Asset Management</span>
        <span class="badge bg-light text-dark ms-2">Tablet Mode</span>
    </div>
    <div class="user-info">
        <i class="fas fa-user-circle me-1"></i> <?= $userName ?>
        <a href="<?= base_url('index.php') ?>" class="btn btn-sm btn-outline-light ms-2">Desktop</a>
    </div>
</header>

<div id="launcher">
    <div style="text-align:center; padding:24px 20px 8px;">
        <h2 style="font-size:1.3rem; color: var(--1pwr-blue);">Select Operation</h2>
    </div>
    <div class="mode-grid">
        <a class="mode-card checkout" onclick="startScan('checkout')">
            <div class="icon"><i class="fas fa-hand-holding-hand"></i></div>
            <h3>Check-Out / In</h3>
            <p>Scan item to allocate or return</p>
        </a>
        <a class="mode-card stockcount" onclick="startScan('stockcount')">
            <div class="icon"><i class="fas fa-clipboard-check"></i></div>
            <h3>Stock Count</h3>
            <p>Scan item and record quantity</p>
        </a>
        <a class="mode-card lookup" onclick="startScan('lookup')">
            <div class="icon"><i class="fas fa-search"></i></div>
            <h3>Quick Lookup</h3>
            <p>Scan to view item details</p>
        </a>
        <a class="mode-card desktop" href="<?= base_url('index.php') ?>">
            <div class="icon"><i class="fas fa-desktop"></i></div>
            <h3>Desktop Mode</h3>
            <p>Full dashboard view</p>
        </a>
    </div>
</div>

<!-- Scan overlay -->
<div id="scanOverlay" class="scan-overlay">
    <div class="scan-ring"></div>
    <h2 id="scanTitle">Scan QR Code</h2>
    <p style="opacity:0.6;">Point the scanner at the item label</p>
    <input id="scanInput" class="scan-input" autocomplete="off" autofocus>
    <button class="btn btn-outline-light cancel-btn" onclick="cancelScan()">Cancel</button>
</div>

<!-- Result panel -->
<div id="resultPanel" class="result-panel">
    <div class="result-header">
        <button class="btn btn-sm btn-outline-light" onclick="backToLauncher()"><i class="fas fa-arrow-left"></i></button>
        <h5 class="mb-0" id="resultTitle">Item Details</h5>
    </div>
    <div class="result-body" id="resultBody"></div>
    <div class="action-bar" id="actionBar"></div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentMode = '';
let currentItem = null;

function startScan(mode) {
    currentMode = mode;
    const titles = { checkout: 'Scan Item for Check-Out/In', stockcount: 'Scan Item for Stock Count', lookup: 'Scan Item to View' };
    document.getElementById('scanTitle').textContent = titles[mode] || 'Scan QR Code';
    document.getElementById('scanOverlay').classList.add('active');
    const inp = document.getElementById('scanInput');
    inp.value = '';
    inp.focus();
}

function cancelScan() {
    document.getElementById('scanOverlay').classList.remove('active');
    currentMode = '';
}

function backToLauncher() {
    document.getElementById('resultPanel').classList.remove('active');
    currentItem = null;
}

// HID scanner captures -- scanner types code + Enter
document.getElementById('scanInput').addEventListener('keydown', function(e) {
    if (e.key === 'Enter') {
        e.preventDefault();
        const code = this.value.trim();
        if (code.length >= 3) {
            lookupByCode(code);
        }
        this.value = '';
    }
});

// Keep focus on scan input when overlay is active
document.getElementById('scanOverlay').addEventListener('click', function() {
    document.getElementById('scanInput').focus();
});

async function lookupByCode(code) {
    document.getElementById('scanOverlay').classList.remove('active');

    const resp = await fetch('<?= base_url('tablet/api.php') ?>?action=lookup&code=' + encodeURIComponent(code));
    const data = await resp.json();

    if (!data.ok || !data.item) {
        showError('Item not found: ' + code);
        return;
    }

    currentItem = data.item;
    showResult();
}

function showError(msg) {
    const body = document.getElementById('resultBody');
    body.innerHTML = '<div class="item-card text-center"><i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i><h4>' + msg + '</h4><p class="text-muted">Try scanning again.</p></div>';
    document.getElementById('actionBar').innerHTML = '<button class="btn btn-secondary w-100" onclick="startScan(currentMode || \'lookup\')"><i class="fas fa-qrcode me-2"></i>Scan Again</button>';
    document.getElementById('resultTitle').textContent = 'Not Found';
    document.getElementById('resultPanel').classList.add('active');
}

function showResult() {
    const item = currentItem;
    const cls = item.item_class || 'FixedAsset';
    const body = document.getElementById('resultBody');

    let html = '<div class="item-card">';
    html += '<div class="d-flex justify-content-between align-items-start mb-3">';
    html += '<div><h4 class="mb-1">' + esc(item.name) + '</h4>';
    html += '<span class="badge-class badge-' + cls + '">' + cls + '</span></div>';
    html += '<span class="badge bg-' + statusColor(item.status) + ' fs-6">' + esc(item.status || 'Available') + '</span>';
    html += '</div>';

    const fields = [
        ['Asset Tag', item.asset_tag],
        ['QR Code', item.qr_code_id],
        ['Serial #', item.serial_number],
        ['Category', item.category_code],
        ['Location', item.location_code],
        ['Country', item.country_code],
        ['Condition', item.condition_status],
        ['Quantity', item.quantity],
    ];
    fields.forEach(f => {
        if (f[1]) html += '<div class="field-row"><span class="field-label">' + f[0] + '</span><span class="field-value">' + esc(String(f[1])) + '</span></div>';
    });
    html += '</div>';

    body.innerHTML = html;
    document.getElementById('resultTitle').textContent = item.name;

    // Action bar depends on mode
    let actions = '';
    if (currentMode === 'checkout') {
        if (item.status === 'Available') {
            actions = '<button class="btn btn-warning w-100 mb-2" onclick="showCheckoutForm()"><i class="fas fa-arrow-right-from-bracket me-2"></i>Check Out</button>';
        } else if (item.status === 'CheckedOut') {
            actions = '<button class="btn btn-success w-100 mb-2" onclick="doCheckin()"><i class="fas fa-arrow-right-to-bracket me-2"></i>Check In</button>';
        } else {
            actions = '<div class="alert alert-info mb-2">Item status is <strong>' + esc(item.status) + '</strong> — cannot check out/in.</div>';
        }
    } else if (currentMode === 'stockcount') {
        actions = '<div class="stock-form"><label class="form-label fw-bold">Physical Count</label>';
        actions += '<input type="number" id="stockCountInput" class="form-control mb-3" min="0" value="' + (item.quantity || 1) + '">';
        actions += '<button class="btn btn-success w-100" onclick="doStockCount()"><i class="fas fa-check me-2"></i>Record Count</button></div>';
    }

    actions += '<button class="btn btn-outline-secondary w-100 mt-2" onclick="startScan(currentMode)"><i class="fas fa-qrcode me-2"></i>Scan Next</button>';
    document.getElementById('actionBar').innerHTML = actions;
    document.getElementById('resultPanel').classList.add('active');
}

function showCheckoutForm() {
    const bar = document.getElementById('actionBar');
    let html = '<div class="mb-3"><label class="form-label fw-bold">Employee Name</label>';
    html += '<input type="text" id="employeeName" class="form-control" placeholder="Enter employee name" style="font-size:1.1rem; padding:12px;"></div>';
    html += '<div class="mb-3"><label class="form-label fw-bold">Notes (optional)</label>';
    html += '<input type="text" id="checkoutNotes" class="form-control" placeholder="Purpose / project"></div>';
    html += '<button class="btn btn-warning w-100 mb-2" onclick="doCheckout()"><i class="fas fa-check me-2"></i>Confirm Check-Out</button>';
    html += '<button class="btn btn-outline-secondary w-100" onclick="showResult()">Cancel</button>';
    bar.innerHTML = html;
    document.getElementById('employeeName').focus();
}

async function doCheckout() {
    const employee = document.getElementById('employeeName').value.trim();
    if (!employee) { alert('Enter employee name'); return; }
    const notes = document.getElementById('checkoutNotes').value.trim();

    const resp = await fetch('<?= base_url('tablet/api.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'checkout', asset_id: currentItem.id, employee_name: employee, notes: notes })
    });
    const data = await resp.json();
    if (data.ok) {
        currentItem.status = 'CheckedOut';
        showToast('Checked out to ' + employee);
        showResult();
    } else {
        alert('Error: ' + (data.error || 'Unknown'));
    }
}

async function doCheckin() {
    const resp = await fetch('<?= base_url('tablet/api.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'checkin', asset_id: currentItem.id })
    });
    const data = await resp.json();
    if (data.ok) {
        currentItem.status = 'Available';
        showToast('Item checked in');
        showResult();
    } else {
        alert('Error: ' + (data.error || 'Unknown'));
    }
}

async function doStockCount() {
    const count = parseInt(document.getElementById('stockCountInput').value) || 0;
    const resp = await fetch('<?= base_url('tablet/api.php') ?>', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({ action: 'stockcount', asset_id: currentItem.id, counted: count })
    });
    const data = await resp.json();
    if (data.ok) {
        currentItem.quantity = count;
        showToast('Count recorded: ' + count);
        startScan('stockcount');
    } else {
        alert('Error: ' + (data.error || 'Unknown'));
    }
}

function showToast(msg) {
    const el = document.createElement('div');
    el.style.cssText = 'position:fixed;top:20px;left:50%;transform:translateX(-50%);background:#28a745;color:white;padding:12px 24px;border-radius:10px;z-index:99999;font-weight:600;box-shadow:0 4px 12px rgba(0,0,0,0.2);';
    el.textContent = msg;
    document.body.appendChild(el);
    setTimeout(() => el.remove(), 2500);
}

function statusColor(s) {
    const map = { Available: 'success', CheckedOut: 'warning', Allocated: 'info', InProject: 'primary', Consumed: 'secondary', Missing: 'danger', Retired: 'dark' };
    return map[s] || 'secondary';
}

function esc(s) {
    const d = document.createElement('div');
    d.textContent = s || '';
    return d.innerHTML;
}
</script>
</body>
</html>
