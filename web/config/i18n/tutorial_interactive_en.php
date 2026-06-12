<?php
/**
 * Interactive tutorial copy (EN) — Fleet-style [data-tutorial] steps.
 */
declare(strict_types=1);

$tracks = [
    'overview' => [
        'label' => 'Full app tour',
        'steps' => [
            [
                'id' => 'dash-kpis',
                'path' => 'index.php',
                'target' => 'tutorial-dashboard-kpis',
                'title' => 'Dashboard',
                'body' => 'Your home view: totals, pending requests, countries, and availability. Use the sidebar to reach every area.',
                'suggestion' => 'Later steps visit the catalog, stock, requests, load-outs, and reports.',
            ],
            [
                'id' => 'dash-class',
                'path' => 'index.php',
                'target' => 'tutorial-dashboard-class',
                'title' => 'Classification',
                'body' => 'Counts by Fixed Asset, Material, Consumable, and Inventory. Click a card to open a filtered catalog.',
            ],
            [
                'id' => 'dash-recent',
                'path' => 'index.php',
                'target' => 'tutorial-dashboard-recent',
                'title' => 'Recent activity',
                'body' => 'The latest transactions across the system. Open Transactions from a item’s detail page for the full log.',
            ],
            [
                'id' => 'nav-catalog',
                'path' => 'index.php',
                'target' => 'nav-catalog',
                'title' => 'Catalog',
                'body' => 'The Catalog menu lists All Items and shortcuts by class. Expand it, then tap Next to open the full catalog.',
            ],
            [
                'id' => 'assets-header',
                'path' => 'assets/index.php',
                'target' => 'tutorial-assets-header',
                'title' => 'Item register',
                'body' => 'Browse all items with search and filters. Each row links to detail, history, and QR.',
            ],
            [
                'id' => 'assets-add',
                'path' => 'assets/index.php',
                'target' => 'tutorial-assets-add',
                'title' => 'Add items',
                'body' => 'Managers and Admins use Add New Item to register stock or assets. Classification controls which fields appear.',
            ],
            [
                'id' => 'nav-stock',
                'path' => 'assets/index.php',
                'target' => 'nav-inventory',
                'title' => 'Stock levels',
                'body' => 'Quantity-based items are tracked under Stock Levels. Tap Next to open that page.',
            ],
            [
                'id' => 'inventory',
                'path' => 'inventory/index.php',
                'target' => 'tutorial-inventory-header',
                'title' => 'Stock Levels',
                'body' => 'See on-hand, allocated, available, and reorder thresholds. Filter by class and country.',
            ],
            [
                'id' => 'nav-requests',
                'path' => 'inventory/index.php',
                'target' => 'nav-requests-procurement',
                'title' => 'Requests',
                'body' => 'Procurement and Service workflows live under Requests. Next opens Procurement requests.',
            ],
            [
                'id' => 'requests',
                'path' => 'requests/index.php',
                'target' => 'tutorial-requests-header',
                'title' => 'Procurement requests',
                'body' => 'Submit needs that may tie into the PR process. Managers approve, reject, or fulfill.',
            ],
            [
                'id' => 'nav-loadout',
                'path' => 'requests/index.php',
                'target' => 'nav-loadout',
                'title' => 'Load-out manifests',
                'body' => 'Packing lists for HQ → site moves. Link manifests to Fleet trips via trip ID (see Fleet Hub).',
            ],
            [
                'id' => 'loadout',
                'path' => 'loadout/index.php',
                'target' => 'tutorial-loadout-header',
                'title' => 'Manifests',
                'body' => 'Create and manage manifests; add line items from the catalog where applicable.',
            ],
            [
                'id' => 'nav-reports',
                'path' => 'loadout/index.php',
                'target' => 'nav-reports',
                'title' => 'Reports',
                'body' => 'Export registers, transaction logs, stock, allocations, and more as CSV or PDF.',
            ],
            [
                'id' => 'reports',
                'path' => 'reports/index.php',
                'target' => 'tutorial-reports-grid',
                'title' => 'Reports & export',
                'body' => 'Pick a report card, set filters, then download. Auditors often use these for evidence.',
            ],
            [
                'id' => 'nav-help',
                'path' => 'reports/index.php',
                'target' => 'nav-help',
                'title' => 'Help',
                'body' => 'The Help page has the full user guide (EN/FR). Tutorial and Help are also in the top bar.',
            ],
            [
                'id' => 'help',
                'path' => 'help.php',
                'target' => 'tutorial-help-header',
                'title' => 'Done',
                'body' => 'That covers the main areas. Use Tablet Mode from the sidebar for scan-heavy warehouse work.',
                'suggestion' => 'Finish to close the overlay, or Exit any time from the bottom panel.',
            ],
        ],
    ],
    'checkout' => [
        'label' => 'Check-out / check-in',
        'steps' => [
            [
                'id' => 'co-intro',
                'path' => 'checkout/index.php',
                'target' => 'tutorial-checkout-out',
                'title' => 'Check out',
                'body' => 'Pick an Available item, an employee, and optional return date, then submit.',
            ],
            [
                'id' => 'co-in',
                'path' => 'checkout/index.php',
                'target' => 'tutorial-checkout-in',
                'title' => 'Check in',
                'body' => 'Select an active allocation and return location to close the loan.',
            ],
            [
                'id' => 'co-active',
                'path' => 'checkout/index.php',
                'target' => 'tutorial-checkout-active',
                'title' => 'Active allocations',
                'body' => 'Review everything currently checked out. Item detail and Transactions show full history.',
            ],
        ],
    ],
    'requests' => [
        'label' => 'Requests (procurement & service)',
        'steps' => [
            [
                'id' => 'rq-pr',
                'path' => 'requests/index.php',
                'target' => 'tutorial-requests-header',
                'title' => 'Procurement',
                'body' => 'Create requests by class, department, country, and priority.',
            ],
            [
                'id' => 'rq-wf',
                'path' => 'requests/workflow-index.php',
                'target' => 'tutorial-workflow-header',
                'title' => 'Service workflows',
                'body' => 'Template-driven AM requests. Managers update status (Approved, Rejected, Fulfilled, Cancelled).',
            ],
        ],
    ],
    'loadout' => [
        'label' => 'Load-out manifests',
        'steps' => [
            [
                'id' => 'lo-intro',
                'path' => 'loadout/index.php',
                'target' => 'tutorial-loadout-header',
                'title' => 'Manifest list',
                'body' => 'Filter by status or trip ID. Create new manifests for packing lists toward field sites.',
            ],
            [
                'id' => 'lo-fleet',
                'path' => 'loadout/index.php',
                'target' => 'tutorial-loadout-fleet',
                'title' => 'Fleet integration',
                'body' => 'Link manifests to Fleet Hub trips using the trip ID. Full line editing stays in Asset Management.',
            ],
        ],
    ],
    'tablet' => [
        'label' => 'Tablet mode',
        'steps' => [
            [
                'id' => 'tb-intro',
                'path' => 'tablet/index.php',
                'target' => 'tutorial-tablet-modes',
                'title' => 'Tablet Mode',
                'body' => 'Touch-first UI for scanners: check-out/in, stock count, and quick lookup.',
            ],
            [
                'id' => 'tb-desktop',
                'path' => 'tablet/index.php',
                'target' => 'tutorial-tablet-desktop',
                'title' => 'Return to desktop',
                'body' => 'Use Desktop Mode when you are done scanning to return to the standard layout.',
            ],
        ],
    ],
    'reports' => [
        'label' => 'Reports & export',
        'steps' => [
            [
                'id' => 'rp-grid',
                'path' => 'reports/index.php',
                'target' => 'tutorial-reports-grid',
                'title' => 'Choose a report',
                'body' => 'Each card is a report type. Set filters, then CSV or PDF.',
            ],
            [
                'id' => 'rp-asset',
                'path' => 'reports/index.php',
                'target' => 'tutorial-reports-asset-card',
                'title' => 'Example: Asset register',
                'body' => 'Typical exports include the full register, transaction log, stock, and QR coverage.',
            ],
        ],
    ],
];

$track_order = ['overview', 'checkout', 'requests', 'loadout', 'tablet', 'reports'];

$query_map = [
    '1' => 'overview',
    'start' => 'overview',
    'overview' => 'overview',
    'tour' => 'overview',
    'checkout' => 'checkout',
    'checkin' => 'checkout',
    'requests' => 'requests',
    'procurement' => 'requests',
    'loadout' => 'loadout',
    'manifest' => 'loadout',
    'tablet' => 'tablet',
    'reports' => 'reports',
];

return [
    'tracks' => $tracks,
    'track_order' => $track_order,
    'query_map' => $query_map,
];
