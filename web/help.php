<?php
require_once __DIR__ . '/config/app.php';
require_once __DIR__ . '/config/firebase.php';

if (!is_logged_in()) {
    header('Location: /login.php');
    exit;
}

$page_title = 'Help & User Guide';
include __DIR__ . '/includes/header.php';
?>

<div class="container-fluid px-4 py-4" style="max-width: 960px;">

<div class="d-flex justify-content-between align-items-center mb-4">
    <div>
        <h1 class="h2 mb-1">Help & User Guide</h1>
        <p class="text-muted mb-0">How to use 1PWR Asset Management</p>
    </div>
</div>

<div class="card border-0 shadow-sm mb-4">
<div class="card-body">
<nav id="help-toc" class="mb-4 p-3 bg-light rounded">
    <h5 class="mb-3"><i class="fas fa-list me-2"></i>Contents</h5>
    <ol class="mb-0" style="columns:2; column-gap:2rem;">
        <li><a href="#logging-in">Logging In</a></li>
        <li><a href="#dashboard">Dashboard</a></li>
        <li><a href="#catalog">Catalog (Items)</a></li>
        <li><a href="#stock-levels">Stock Levels</a></li>
        <li><a href="#checkout">Check-Out &amp; Check-In</a></li>
        <li><a href="#transactions">Transactions</a></li>
        <li><a href="#requests">Requests</a></li>
        <li><a href="#qr-codes">QR Codes</a></li>
        <li><a href="#reports">Reports &amp; Export</a></li>
        <li><a href="#tablet-mode">Tablet Mode</a></li>
        <li><a href="#admin">Admin Pages</a></li>
        <li><a href="#roles">Roles &amp; Permissions</a></li>
    </ol>
</nav>
</div>
</div>

<!-- Logging In -->
<div class="card border-0 shadow-sm mb-4" id="logging-in">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-sign-in-alt me-2 text-primary"></i>Logging In</h2>
    <p>Navigate to <strong>am.1pwrafrica.com</strong>. Enter your 1PWR email address and password. The system authenticates through Firebase — the same credentials you use for the Procurement and Job Cards systems.</p>
    <p>If you were previously using a <strong>username</strong> (not email), that still works — the system looks up your email in the legacy database.</p>
    <div class="alert alert-info mb-0">
        <i class="fas fa-info-circle me-1"></i>
        <strong>Forgot your password?</strong> Use the "Forgot Password?" link on the login page, or contact your system administrator.
    </div>
</div>
</div>

<!-- Dashboard -->
<div class="card border-0 shadow-sm mb-4" id="dashboard">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-home me-2 text-primary"></i>Dashboard</h2>
    <p>The landing page after login gives you an at-a-glance overview:</p>
    <ul>
        <li><strong>Total items</strong> across all countries and classifications</li>
        <li><strong>Classification breakdown</strong> — counts of Fixed Assets, Materials, Consumables, and Inventory. Click any card to jump to a filtered catalog view.</li>
        <li><strong>Items by Country</strong> — summary per operating country (Lesotho, Zambia, Benin)</li>
        <li><strong>Items by Status</strong> — how many items are Available, CheckedOut, Allocated, etc.</li>
        <li><strong>Recent Transactions</strong> — the latest 10 activities in the system</li>
    </ul>
</div>
</div>

<!-- Catalog -->
<div class="card border-0 shadow-sm mb-4" id="catalog">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-th-large me-2 text-primary"></i>Catalog (Items)</h2>

    <h5>Browsing</h5>
    <p>Use the <strong>Catalog</strong> menu in the sidebar to access:</p>
    <ul>
        <li><strong>All Items</strong> — the complete unfiltered catalog</li>
        <li><strong>Fixed Assets</strong> — vehicles, equipment, installed infrastructure (PP&amp;E)</li>
        <li><strong>Materials</strong> — construction and installation inputs (wire, poles, panels)</li>
        <li><strong>Consumables</strong> — PPE, office supplies, maintenance items</li>
        <li><strong>Inventory</strong> — meters, ready boards, spare parts</li>
    </ul>
    <p>Each row shows the item's name, classification badge, category, country, status, and condition. Use the <strong>filters</strong> at the top to narrow by classification, category, country, or status. The <strong>search box</strong> matches on name, serial number, asset tag, or QR code.</p>

    <hr>
    <h5>Adding an Item</h5>
    <ol>
        <li>Click <strong>+ Add New Item</strong> at the top of the catalog page</li>
        <li>Select the <strong>Classification</strong> — this controls which fields appear:
            <ul>
                <li><em>Fixed Asset</em> shows: serial number, manufacturer, model, purchase date, purchase price, salvage value, warranty expiry</li>
                <li><em>Material / Consumable / Inventory</em> shows: quantity, unit of measure, unit cost</li>
            </ul>
        </li>
        <li>Fill in the required fields (name, classification, country, condition, status)</li>
        <li>Choose a <strong>Category</strong> from the dropdown (filtered to your selected class)</li>
        <li>Click <strong>Save</strong></li>
    </ol>
    <p>An asset tag is auto-generated in the format <code>1PWR-{CLASS}-{COUNTRY}-000001</code>.</p>

    <hr>
    <h5>Viewing &amp; Editing</h5>
    <p>Click any item name in the catalog to open its detail page. You'll see all properties, QR code image (if assigned), allocation history, and transaction history. Click <strong>Edit</strong> to update fields. Use the Status dropdown to change status (e.g. mark as <code>Retired</code> or <code>WrittenOff</code>).</p>
</div>
</div>

<!-- Stock Levels -->
<div class="card border-0 shadow-sm mb-4" id="stock-levels">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-warehouse me-2 text-primary"></i>Stock Levels</h2>
    <p>The Stock Levels page tracks <strong>Materials</strong>, <strong>Consumables</strong>, and <strong>Inventory</strong> items — things counted by quantity rather than individual serial numbers.</p>
    <p>The table shows:</p>
    <table class="table table-sm">
        <thead><tr><th>Column</th><th>Meaning</th></tr></thead>
        <tbody>
            <tr><td><strong>On Hand</strong></td><td>Total physical quantity</td></tr>
            <tr><td><strong>Allocated</strong></td><td>Quantity assigned but not yet consumed/deployed</td></tr>
            <tr><td><strong>Available</strong></td><td>On hand minus allocated</td></tr>
            <tr><td><strong>Reorder Level</strong></td><td>Threshold below which a reorder alert triggers</td></tr>
        </tbody>
    </table>
    <p>Check <strong>Low stock only</strong> to filter to items at or below their reorder level. Use the class and country dropdowns to narrow the view.</p>
</div>
</div>

<!-- Check-Out/In -->
<div class="card border-0 shadow-sm mb-4" id="checkout">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-hand-holding me-2 text-primary"></i>Check-Out &amp; Check-In</h2>

    <h5>Checking Out</h5>
    <ol>
        <li>Go to <strong>Check-Out/In</strong> in the sidebar</li>
        <li>In the <strong>Check Out</strong> section, select the item (only <code>Available</code> items appear)</li>
        <li>Select the employee receiving the item</li>
        <li>Optionally set an expected return date</li>
        <li>Click <strong>Check Out</strong></li>
    </ol>
    <p>The item's status changes to <code>CheckedOut</code> and an allocation record is created.</p>

    <h5>Checking In</h5>
    <ol>
        <li>In the <strong>Check In</strong> section, select an active allocation from the dropdown</li>
        <li>Choose the return location</li>
        <li>Click <strong>Check In</strong></li>
    </ol>
    <p>The item's status reverts to <code>Available</code> and the allocation is closed.</p>
    <p>The <strong>Active Allocations</strong> table at the bottom shows all currently checked-out items.</p>
</div>
</div>

<!-- Transactions -->
<div class="card border-0 shadow-sm mb-4" id="transactions">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-exchange-alt me-2 text-primary"></i>Transactions</h2>
    <p>Every action that modifies an item's state is logged. Navigate to <strong>Transactions</strong> in the sidebar to see the full audit trail.</p>
    <table class="table table-sm">
        <thead><tr><th>Type</th><th>Meaning</th></tr></thead>
        <tbody>
            <tr><td><code>CheckOut</code></td><td>Item issued to an employee</td></tr>
            <tr><td><code>CheckIn</code></td><td>Item returned</td></tr>
            <tr><td><code>StockTake</code></td><td>Physical count recorded</td></tr>
            <tr><td><code>Transfer</code></td><td>Item moved between locations</td></tr>
            <tr><td><code>Allocation</code></td><td>Item reserved for a project</td></tr>
            <tr><td><code>Return</code></td><td>Item returned from project allocation</td></tr>
            <tr><td><code>WriteOff</code></td><td>Item removed from active inventory</td></tr>
            <tr><td><code>Consume</code></td><td>Consumable used up</td></tr>
            <tr><td><code>Deploy</code></td><td>Item permanently installed</td></tr>
        </tbody>
    </table>
    <p>Use the type filter and search to find specific transactions.</p>
</div>
</div>

<!-- Requests -->
<div class="card border-0 shadow-sm mb-4" id="requests">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-clipboard-list me-2 text-primary"></i>Requests</h2>

    <h5>Submitting a Request</h5>
    <ol>
        <li>Go to <strong>Requests</strong> and click <strong>New Request</strong></li>
        <li>Select the <strong>Item Class</strong> you need</li>
        <li>Select your <strong>Department</strong> (RET, FAC, O&amp;M, General)</li>
        <li>Choose the <strong>Country</strong> and optionally a location</li>
        <li>Set <strong>Priority</strong> (Low, Normal, High, Urgent)</li>
        <li>Describe what you need and the required-by date</li>
        <li>Click <strong>Submit</strong></li>
    </ol>
    <p>A request number is auto-generated (format: <code>REQ-2026-0001</code>).</p>

    <h5>Managing Requests (Admin/Manager)</h5>
    <p>The request list shows status summary cards at the top. Admins and Managers can <strong>Approve</strong>, <strong>Reject</strong> (with a note), or <strong>Fulfill</strong> requests.</p>
</div>
</div>

<!-- QR Codes -->
<div class="card border-0 shadow-sm mb-4" id="qr-codes">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-qrcode me-2 text-primary"></i>QR Codes</h2>

    <h5>Generating QR Codes</h5>
    <p>Navigate to <strong>Admin &gt; QR Labels</strong>. The page shows coverage stats, items without QR codes, and all assigned codes. Click <strong>Generate</strong> next to any item, or use <strong>Generate All</strong> for batch assignment.</p>
    <p>QR code format: <code>1PWR-{COUNTRY}-{CLASS_PREFIX}-{SEQUENCE}</code></p>

    <h5>Scanning QR Codes</h5>
    <ol>
        <li>Connect the barcode/QR scanner via USB or Bluetooth</li>
        <li>Scan a QR label — the scanner types the code as keyboard input</li>
        <li>The system detects the scan and shows the item's details</li>
        <li>From there you can check out, edit, or view history</li>
    </ol>
    <div class="alert alert-light mb-0">
        <i class="fas fa-lightbulb me-1 text-warning"></i>
        <strong>Tip:</strong> Use <strong>Tablet Mode</strong> for the best scan-and-go experience on warehouse tablets.
    </div>
</div>
</div>

<!-- Reports -->
<div class="card border-0 shadow-sm mb-4" id="reports">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-chart-bar me-2 text-primary"></i>Reports &amp; Export</h2>
    <p>Navigate to <strong>Reports</strong> in the sidebar. Available reports:</p>
    <table class="table table-sm">
        <thead><tr><th>Report</th><th>Description</th><th>Export</th></tr></thead>
        <tbody>
            <tr><td><strong>Asset Register</strong></td><td>Complete item register by class and country</td><td>CSV, PDF</td></tr>
            <tr><td><strong>Transaction Log</strong></td><td>Audit trail with type and date filters</td><td>CSV, PDF</td></tr>
            <tr><td><strong>Stock Report</strong></td><td>Current stock levels with reorder status</td><td>CSV, PDF</td></tr>
            <tr><td><strong>Allocation Report</strong></td><td>Active and historical item allocations</td><td>CSV, PDF</td></tr>
            <tr><td><strong>QR Coverage</strong></td><td>Items with and without QR assignments</td><td>CSV</td></tr>
            <tr><td><strong>Classification Summary</strong></td><td>Counts and values by class/category/country</td><td>CSV, PDF</td></tr>
        </tbody>
    </table>
    <p>Use the filter dropdowns on each report card, then click <strong>CSV</strong> or <strong>PDF</strong> to download.</p>
</div>
</div>

<!-- Tablet Mode -->
<div class="card border-0 shadow-sm mb-4" id="tablet-mode">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-tablet-screen-button me-2 text-primary"></i>Tablet Mode</h2>
    <p>Tablet Mode is a touch-optimized, full-screen interface designed for warehouse and field use with a QR scanner. Access it from the sidebar or go directly to <code>/tablet/</code>.</p>
    <p>Available operations:</p>
    <ul>
        <li><strong>Check-Out / In</strong> — scan an item, then allocate or return it</li>
        <li><strong>Stock Count</strong> — scan an item and record the physical quantity</li>
        <li><strong>Quick Lookup</strong> — scan to instantly view item details</li>
        <li><strong>Desktop Mode</strong> — return to the full dashboard</li>
    </ul>
    <p>The scanner input is captured automatically — just point and scan.</p>
</div>
</div>

<!-- Admin -->
<div class="card border-0 shadow-sm mb-4" id="admin">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-cog me-2 text-primary"></i>Admin Pages</h2>
    <p>Admin pages are visible only to users with the <strong>Admin</strong> role.</p>

    <h5>Categories</h5>
    <p>Manage the category list. Categories are grouped by item class and include a code (e.g. <code>FA-VEH</code>, <code>MAT-ELE</code>), department scope, depreciation settings (for Fixed Assets), and reorder tracking toggle.</p>

    <h5>Locations</h5>
    <p>Manage storage and operating locations across all countries. Locations are used for item check-out/in and multi-country filtering.</p>

    <h5>Employees</h5>
    <p>View and search the employee directory. Employee records are shared with the PR system and used for item allocation.</p>

    <h5>QR Labels</h5>
    <p>Generate and manage QR code assignments. See QR coverage stats and batch-generate codes for untagged items.</p>
</div>
</div>

<!-- Roles -->
<div class="card border-0 shadow-sm mb-4" id="roles">
<div class="card-body">
    <h2 class="h4 mb-3"><i class="fas fa-user-shield me-2 text-primary"></i>Roles &amp; Permissions</h2>
    <table class="table table-sm">
        <thead><tr><th>Role</th><th>Access</th></tr></thead>
        <tbody>
            <tr><td><strong>Admin</strong></td><td>Full access — all pages including admin (categories, locations, employees, QR labels, data migration)</td></tr>
            <tr><td><strong>Manager</strong></td><td>All operational pages — can check out/in, create items, manage requests. Cannot access admin pages.</td></tr>
            <tr><td><strong>Viewer</strong></td><td>Read-only access to the catalog, stock levels, and transaction history. Can submit requests.</td></tr>
        </tbody>
    </table>
    <p>Your role is determined by your <strong>permissionLevel</strong> in the shared Firebase user profile. Contact a system administrator to change your role.</p>
</div>
</div>

<div class="text-center text-muted py-3">
    <small>1PWR Asset Management v<?php echo APP_VERSION; ?> &mdash; Need more help? Contact your system administrator.</small>
</div>

</div>

<?php include __DIR__ . '/includes/footer.php'; ?>
