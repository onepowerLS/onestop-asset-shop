<?php
/**
 * Help & User Guide — English copy (HTML fragments are trusted; authored in-repo).
 */
declare(strict_types=1);

return [
    'page_title' => 'Help & User Guide',
    'subtitle' => 'How to use 1PWR Asset Management',
    'toc_title' => 'Contents',
    'footer' => 'Need more help? Contact your system administrator.',
    'lang_label' => 'Language',
    'lang_en' => 'English',
    'lang_fr' => 'Français',
    'toc' => [
        'logging-in' => 'Logging In',
        'dashboard' => 'Dashboard',
        'catalog' => 'Catalog (Items)',
        'stock-levels' => 'Stock Levels',
        'checkout' => 'Check-Out & Check-In',
        'transactions' => 'Transactions',
        'requests' => 'Requests',
        'loadout-manifests' => 'Load-out manifests',
        'qr-codes' => 'QR Codes',
        'reports' => 'Reports & Export',
        'tablet-mode' => 'Tablet Mode',
        'admin' => 'Admin Pages',
        'roles' => 'Roles & Permissions',
    ],
    'sections' => [
        [
            'id' => 'logging-in',
            'icon' => 'fa-sign-in-alt',
            'title' => 'Logging In',
            'html' => '
<p>Go to <strong>am.1pwrafrica.com</strong>. Enter your 1PWR email address and password. The app uses Firebase — the same credentials as Procurement and Job Cards.</p>
<p>If you previously used a <strong>username</strong> (not an email), that can still work: the system resolves your account from the legacy mapping.</p>
<div class="alert alert-info mb-0">
    <i class="fas fa-info-circle me-1"></i>
    <strong>Forgot your password?</strong> Use “Forgot Password?” on the login page, or contact your administrator.
</div>',
        ],
        [
            'id' => 'dashboard',
            'icon' => 'fa-home',
            'title' => 'Dashboard',
            'html' => '
<p>After login, the dashboard summarizes the fleet:</p>
<ul class="mb-0">
    <li><strong>Total items</strong> across countries and classes</li>
    <li><strong>Classification breakdown</strong> — Fixed Assets, Materials, Consumables, Inventory; click a card to open a filtered catalog</li>
    <li><strong>Items by Country</strong> — Lesotho, Zambia, Benin</li>
    <li><strong>Items by Status</strong> — Available, CheckedOut, Allocated, etc.</li>
    <li><strong>Recent Transactions</strong> — the latest activities</li>
</ul>',
        ],
        [
            'id' => 'catalog',
            'icon' => 'fa-th-large',
            'title' => 'Catalog (Items)',
            'html' => '
<h5 class="h6 text-uppercase text-muted">Browsing</h5>
<p>Use <strong>Catalog</strong> in the sidebar:</p>
<ul>
    <li><strong>All Items</strong> — full catalog</li>
    <li><strong>Fixed Assets</strong> — vehicles, equipment, installed infrastructure (PP&amp;E)</li>
    <li><strong>Materials</strong> — construction/installation inputs</li>
    <li><strong>Consumables</strong> — PPE, office, maintenance</li>
    <li><strong>Inventory</strong> — meters, spare parts, kits</li>
</ul>
<p>Each row shows name, class badge, category, country, status, and condition. Use filters and the search box (name, serial, asset tag, QR code).</p>
<hr>
<h5 class="h6 text-uppercase text-muted">Adding an item</h5>
<ol>
    <li>Click <strong>+ Add New Item</strong> on the catalog</li>
    <li>Choose <strong>Classification</strong> — it controls which fields appear:
        <ul>
            <li><em>Fixed Asset</em>: serial number, manufacturer, model, purchase date/price, salvage value, warranty</li>
            <li><em>Material / Consumable / Inventory</em>: quantity, unit of measure, unit cost</li>
        </ul>
    </li>
    <li>Complete required fields (name, class, country, condition, status)</li>
    <li>Pick a <strong>Category</strong> (filtered by class)</li>
    <li>Click <strong>Save</strong></li>
</ol>
<p>Asset tags are generated as <code>1PWR-{CLASS}-{COUNTRY}-000001</code>.</p>
<hr>
<h5 class="h6 text-uppercase text-muted">Viewing &amp; editing</h5>
<p class="mb-0">Open a row for details: properties, QR (if assigned), allocation history, and transactions. Use <strong>Edit</strong> to update fields; change <strong>Status</strong> (e.g. Retired, WrittenOff) on the edit form.</p>',
        ],
        [
            'id' => 'stock-levels',
            'icon' => 'fa-warehouse',
            'title' => 'Stock Levels',
            'html' => '
<p><strong>Stock Levels</strong> tracks Materials, Consumables, and Inventory — quantity-based items.</p>
<table class="table table-sm">
    <thead><tr><th>Column</th><th>Meaning</th></tr></thead>
    <tbody>
        <tr><td><strong>On Hand</strong></td><td>Physical quantity</td></tr>
        <tr><td><strong>Allocated</strong></td><td>Assigned but not yet consumed/deployed</td></tr>
        <tr><td><strong>Available</strong></td><td>On hand minus allocated</td></tr>
        <tr><td><strong>Reorder Level</strong></td><td>Threshold for low-stock alerts</td></tr>
    </tbody>
</table>
<p class="mb-0">Use <strong>Low stock only</strong> and class/country filters to narrow the list.</p>',
        ],
        [
            'id' => 'checkout',
            'icon' => 'fa-hand-holding',
            'title' => 'Check-Out &amp; Check-In',
            'html' => '
<h5 class="h6 text-uppercase text-muted">Checking out</h5>
<ol>
    <li>Open <strong>Check-Out/In</strong></li>
    <li>In <strong>Check Out</strong>, pick an <code>Available</code> item</li>
    <li>Select the employee</li>
    <li>Optional: expected return date</li>
    <li>Click <strong>Check Out</strong></li>
</ol>
<p>Status becomes <code>CheckedOut</code> and an allocation is created.</p>
<h5 class="h6 text-uppercase text-muted mt-3">Checking in</h5>
<ol>
    <li>In <strong>Check In</strong>, select an active allocation</li>
    <li>Choose the return location</li>
    <li>Click <strong>Check In</strong></li>
</ol>
<p class="mb-0"><strong>Active Allocations</strong> lists everything currently checked out.</p>',
        ],
        [
            'id' => 'transactions',
            'icon' => 'fa-exchange-alt',
            'title' => 'Transactions',
            'html' => '
<p>Every state change is logged. Open <strong>Transactions</strong> for the audit trail.</p>
<table class="table table-sm">
    <thead><tr><th>Type</th><th>Meaning</th></tr></thead>
    <tbody>
        <tr><td><code>CheckOut</code></td><td>Issued to a person</td></tr>
        <tr><td><code>CheckIn</code></td><td>Returned</td></tr>
        <tr><td><code>StockIngestion</code></td><td>New stock received</td></tr>
        <tr><td><code>StockTake</code></td><td>Physical count recorded</td></tr>
        <tr><td><code>Transfer</code></td><td>Moved between locations</td></tr>
        <tr><td><code>Allocation</code></td><td>Reserved for a project</td></tr>
        <tr><td><code>Return</code></td><td>Returned from project allocation</td></tr>
        <tr><td><code>WriteOff</code></td><td>Removed from active stock</td></tr>
        <tr><td><code>Consume</code></td><td>Consumable used up</td></tr>
        <tr><td><code>Deploy</code></td><td>Permanently installed</td></tr>
        <tr><td><code>QRScan</code></td><td>Informational scan event</td></tr>
    </tbody>
</table>
<p class="mb-0">Filter by type and search to find events.</p>',
        ],
        [
            'id' => 'requests',
            'icon' => 'fa-clipboard-list',
            'title' => 'Requests',
            'html' => '
<h5 class="h6 text-uppercase text-muted">Procurement</h5>
<p>Under <strong>Requests → Procurement</strong>, submit needs that may tie into the PR system workflow.</p>
<ol>
    <li>Click <strong>New Request</strong></li>
    <li>Choose <strong>Item Class</strong>, <strong>Department</strong> (RET, FAC, O&amp;M, General)</li>
    <li>Set <strong>Country</strong> and optional location</li>
    <li>Set <strong>Priority</strong> and describe what you need</li>
    <li><strong>Submit</strong> — reference like <code>REQ-2026-0001</code></li>
</ol>
<p>Admins/Managers can <strong>Approve</strong>, <strong>Reject</strong> (with a note), or <strong>Fulfill</strong>.</p>
<h5 class="h6 text-uppercase text-muted mt-3">Service workflows</h5>
<p class="mb-0">Use <strong>Requests → Service workflows</strong> for template-driven AM requests stored in <code>am_core_requests</code> (types vary by template). Managers/Admins update status (Approved, Rejected, Fulfilled, Cancelled) from the list or detail view.</p>',
        ],
        [
            'id' => 'loadout-manifests',
            'icon' => 'fa-dolly',
            'title' => 'Load-out manifests',
            'html' => '
<p><strong>Load-out manifests</strong> are packing lists for stock or equipment moving from HQ toward a field site.</p>
<ul>
    <li>Create or edit a manifest from <strong>Load-out manifests</strong>; add line items linked to catalog assets where applicable.</li>
    <li>Link a manifest to Fleet / trip context using the <strong>trip ID</strong> when integrating with <code>fm.1pwrafrica.com</code> (API or Firestore).</li>
    <li>Filter the list by status or trip; open a manifest to view packing detail and history.</li>
</ul>
<p class="mb-0">Roles without write access (e.g. some auditors) may see manifests but cannot create or edit them.</p>',
        ],
        [
            'id' => 'qr-codes',
            'icon' => 'fa-qrcode',
            'title' => 'QR Codes',
            'html' => '
<h5 class="h6 text-uppercase text-muted">Generating</h5>
<p><strong>Admin → QR Labels</strong> shows coverage, items missing codes, and batch tools. Format: <code>1PWR-{COUNTRY}-{CLASS_PREFIX}-{SEQUENCE}</code>.</p>
<h5 class="h6 text-uppercase text-muted mt-3">Scanning</h5>
<ol class="mb-2">
    <li>Connect a USB or Bluetooth scanner</li>
    <li>Scan — input is typed like a keyboard</li>
    <li>The app resolves the code to the item</li>
    <li>From the item page: check out, edit, or review history</li>
</ol>
<div class="alert alert-light mb-0">
    <i class="fas fa-lightbulb me-1 text-warning"></i>
    <strong>Tip:</strong> Use <strong>Tablet Mode</strong> for scan-heavy warehouse work.
</div>',
        ],
        [
            'id' => 'reports',
            'icon' => 'fa-chart-bar',
            'title' => 'Reports &amp; Export',
            'html' => '
<p>Open <strong>Reports</strong>, set filters on each card, then download <strong>CSV</strong> or <strong>PDF</strong>.</p>
<table class="table table-sm mb-0">
    <thead><tr><th>Report</th><th>Description</th><th>Export</th></tr></thead>
    <tbody>
        <tr><td><strong>Asset Register</strong></td><td>Full register by class and country</td><td>CSV, PDF</td></tr>
        <tr><td><strong>Transaction Log</strong></td><td>Audit trail with filters</td><td>CSV, PDF</td></tr>
        <tr><td><strong>Stock Report</strong></td><td>Levels and reorder status</td><td>CSV, PDF</td></tr>
        <tr><td><strong>Allocation Report</strong></td><td>Active and historical allocations</td><td>CSV, PDF</td></tr>
        <tr><td><strong>QR Coverage</strong></td><td>Tagged vs untagged items</td><td>CSV</td></tr>
        <tr><td><strong>Classification Summary</strong></td><td>Counts/values by class and country</td><td>CSV, PDF</td></tr>
    </tbody>
</table>',
        ],
        [
            'id' => 'tablet-mode',
            'icon' => 'fa-tablet-screen-button',
            'title' => 'Tablet Mode',
            'html' => '
<p>Touch-first, full-screen UI for warehouse/field use with a scanner. Sidebar → <strong>Tablet Mode</strong> or <code>/tablet/</code>.</p>
<ul class="mb-0">
    <li><strong>Check-Out / In</strong> — scan, then allocate or return</li>
    <li><strong>Stock Count</strong> — scan and enter counted quantity</li>
    <li><strong>Quick Lookup</strong> — instant item details</li>
    <li><strong>Desktop Mode</strong> — return to the standard layout</li>
</ul>',
        ],
        [
            'id' => 'admin',
            'icon' => 'fa-cog',
            'title' => 'Admin Pages',
            'html' => '
<p>Visible to <strong>Admin</strong> only (unless noted).</p>
<ul>
    <li><strong>Categories</strong> — codes (e.g. <code>FA-VEH</code>), department scope, depreciation for Fixed Assets, reorder toggle</li>
    <li><strong>Locations</strong> — storage/operating sites across countries</li>
    <li><strong>Employees</strong> — directory shared with PR for allocations</li>
    <li><strong>QR Labels</strong> — generate and assign codes</li>
    <li><strong>Data Migration</strong> — batch import tooling</li>
    <li><strong>Provision auditor</strong> — create read-only auditor accounts</li>
</ul>',
        ],
        [
            'id' => 'roles',
            'icon' => 'fa-user-shield',
            'title' => 'Roles &amp; Permissions',
            'html' => '
<table class="table table-sm">
    <thead><tr><th>Role</th><th>Access</th></tr></thead>
    <tbody>
        <tr><td><strong>Admin</strong></td><td>Full access including admin pages and migration</td></tr>
        <tr><td><strong>Manager</strong></td><td>Operational pages; check-out/in; items; requests — no admin settings</td></tr>
        <tr><td><strong>Viewer</strong></td><td>Read catalog, stock, transactions; can submit requests</td></tr>
        <tr><td><strong>Auditor</strong></td><td>Read-only UI; cannot check out or mutate data (writes blocked in UI)</td></tr>
    </tbody>
</table>
<p class="mb-0">Your role comes from <strong>permissionLevel</strong> in the shared Firebase user profile. Ask an administrator to change it.</p>',
        ],
    ],
];
