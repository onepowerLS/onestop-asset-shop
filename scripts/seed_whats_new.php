<?php
/**
 * One-off CLI seed: populate am_core_whats_new with the recent feature batch.
 *
 * Run from project root:
 *   php scripts/seed_whats_new.php
 *   php scripts/seed_whats_new.php --dry-run
 */
declare(strict_types=1);

$root = dirname(__DIR__);
require_once $root . '/web/config/app.php';
require_once $root . '/web/config/firestore.php';
require_once $root . '/web/config/firebase_admin_token.php';
require_once $root . '/web/config/whats_new.php';

$dryRun = in_array('--dry-run', $argv, true);

$adminToken = am_firestore_admin_access_token();
if ($adminToken === '') {
    $adminToken = am_firebase_admin_token();
}
if ($adminToken === '') {
    fwrite(STDERR, "No admin token available. Check firebase-service-account.json or FIREBASE_ADMIN_BEARER_TOKEN in .env\n");
    exit(1);
}

$entries = [
    [
        'title' => 'Inventory read API for forecast and reporting',
        'summary' => 'Forecast and reporting tools can now read stock on hand, allocations, movements and the part master via a secure API key. AM screens are unchanged.',
        'details' => '<p>Asset Management now exposes a read-only inventory API for machine consumers (uGridPREDICT, Nexus, reporting).</p><ul><li><strong>Stock position</strong> — on hand, allocated and available by part and site (<code>/api/v1/inventory</code>).</li><li><strong>Allocations and movements</strong> — what is reserved, and a signed movement history so consumption rate is measurable.</li><li><strong>Part master</strong> — AM identifiers as they are, plus optional <code>ugp_part_id</code>. Unmapped parts: <code>/api/v1/parts?unmapped=true</code>.</li></ul><p>No AM screen changed. Keys are issued by an AM admin (env <code>AM_API_KEY_UGRIDPREDICT</code> and siblings). Auth is the existing <code>X-API-Key</code> header.</p>',
        'category' => 'feature',
        'icon' => 'fa-chart-line',
        'released_at' => '2026-09-07T14:00:00Z',
        'deep_link' => '/whats-new.php',
    ],
    [
        'title' => 'Clear access-denial explanations',
        'summary' => 'Blocked actions now show your effective assignment, the role or capability required, and the correct access owner to contact.',
        'details' => '<p>Asset Management now explains privilege denials instead of showing a generic read-only or forbidden message.</p><ul><li>See the role, permission level, and department currently applied.</li><li>See the role or capability required for the attempted action.</li><li>Contact the correct owner: country HR for department assignments, Nexus/IS&amp;T for explicit tool access, or an AM Superadmin for protected local roles.</li><li>Sign out and back in after an access correction to refresh signed privileges.</li></ul>',
        'category' => 'improvement',
        'icon' => 'fa-user-shield',
        'released_at' => '2026-08-10T13:15:00Z',
        'deep_link' => '/help.php',
    ],
    [
        'title' => 'Item transaction history and request emails',
        'summary' => 'Item pages now show date, quantity, site, activity and operator; requesters receive itemized emails as requests move through submission, approval and fulfillment.',
        'details' => '<p><strong>Transactions now tell the full story.</strong></p><ul><li>Item detail shows transaction date, quantity, site, activity, operator and notes.</li><li>Older imported items show a clearly labelled opening snapshot instead of an empty section.</li><li>Catalog creation/edits, checkout/check-in, production, allocation and dispatch write linked ledger entries.</li><li>Requesters receive an itemized email at submission, approval and fulfillment (also rejection or cancellation).</li></ul>',
        'category' => 'feature',
        'icon' => 'fa-clock-rotate-left',
        'released_at' => '2026-08-05T14:00:00Z',
        'deep_link' => '/transactions/index.php',
    ],
    [
        'title' => 'Nexus single sign-on is now live',
        'summary' => 'Sign in through nexus.1pwrafrica.com — one login for Asset Management, Procurement, Job Cards, and all 1PWR tools. The old email/password form is available as a fallback via ?fallback=1.',
        'details' => "<p>AM now uses <strong>Nexus</strong> (<code>nexus.1pwrafrica.com</code>) as its single sign-on portal.</p><ul><li>When you visit <code>am.1pwrafrica.com</code>, you are redirected to Nexus to sign in.</li><li>After successful login, you are sent back to AM automatically.</li><li>The same Nexus account works across all 1PWR tools — no separate passwords.</li><li><strong>Fallback:</strong> If Nexus is down, add <code>?fallback=1</code> to the URL to use the local Firebase login.</li></ul>",
        'category' => 'feature',
        'icon' => 'fa-key',
        'released_at' => '2026-07-14T14:00:00Z',
        'deep_link' => '/help.php',
    ],
    [
        'title' => 'Search catalog before adding an item',
        'summary' => 'Add Item now opens with a live catalog search panel so you can avoid creating duplicates, with a yellow "similar item already in catalog" warning on the Name field.',
        'details' => "<p>The Add Item form now starts with a <strong>Search catalog first</strong> panel.</p><ul><li>Type 2+ characters to search name, tag, manufacturer, model, notes, category, and location.</li><li>Each match has View and Edit buttons so you can open the existing record instead of creating a duplicate.</li><li>Filter results by class — the filter auto-syncs with the classification you pick below.</li></ul>",
        'category' => 'feature',
        'icon' => 'fa-magnifying-glass',
        'released_at' => '2026-06-30T15:50:00Z',
        'deep_link' => '/assets/add.php',
    ],
    [
        'title' => 'Assemble / produce now supports stockable outputs (pole boxes, ready boards)',
        'summary' => 'Produce stockable inventory items like pole boxes and ready boards from raw materials, not just fixed assets.',
        'details' => "<p><strong>Catalog → Assemble / produce</strong> has a new output type picker:</p><ul><li><strong>Fixed Asset</strong> — unchanged (powerhouses, tracker stations).</li><li><strong>Stockable item</strong> — for pole boxes, ready boards, and other produced inventory. Either create a new catalog item or add quantity to an existing one.</li></ul><p>Source materials are consumed from the assembly location's stock, and lineage (built_from) is recorded on the result.</p>",
        'category' => 'feature',
        'icon' => 'fa-wrench',
        'released_at' => '2026-06-24T16:20:00Z',
        'deep_link' => '/assets/assemble.php',
    ],
    [
        'title' => 'Stock levels no longer double-count items at HQ',
        'summary' => 'If an item had two inventory rows at the same location (legacy alias keys like site1 + LSO-HQ), Item detail, Stock Levels, and Edit now all show the correct single quantity.',
        'details' => "<p>Duplicate inventory rows caused by legacy location alias keys are now <strong>merged on read</strong> instead of summed. This fixes the doubled meter counts (e.g. 13230 shown when actual was 6930) and keeps Item detail, Stock Levels, and Edit in sync.</p><p>If you spot an item still showing wrong totals, open it and Save once — the Edit flow now merges/deletes the duplicate rows in Firestore.</p>",
        'category' => 'fix',
        'icon' => 'fa-boxes-stacked',
        'released_at' => '2026-06-24T16:20:00Z',
        'deep_link' => '/inventory/index.php',
    ],
    [
        'title' => 'Department + Project shown when an item is Allocated',
        'summary' => 'When Status is Allocated, CheckedOut, InProject, or Deployed, you can now record the department and the project/concession on the item.',
        'details' => "<p>On <strong>Catalog → Edit item</strong>, two new fields appear when Status is one of Allocated, CheckedOut, InProject, or Deployed:</p><ul><li><strong>Allocated to department</strong> — RET / FAC / O&amp;M / IT / General / Finance / HR / Procurement / Fleet.</li><li><strong>Project / concession</strong> — free text (e.g. \"Sehlabathebe\", \"IT bench\").</li></ul><p>Item detail shows the department badge next to the status and the project line when set.</p>",
        'category' => 'feature',
        'icon' => 'fa-users',
        'released_at' => '2026-07-01T15:15:00Z',
        'deep_link' => '/assets/index.php',
    ],
    [
        'title' => 'Simpler Ready board request form',
        'summary' => 'Item Class picker removed (ready boards are always Inventory), Quantity field added, and the request list now shows Qty and Site columns.',
        'details' => "<p><strong>Requests → Ready board requests → New Request</strong> changes:</p><ul><li>Item Class picker removed — ready boards are always recorded as Inventory behind the scenes (no more Material/Inventory mix-ups).</li><li>Quantity field added (required).</li><li>Concession / site, receiver name and email fields added.</li><li>Request list shows Qty and Site columns instead of free-text Description.</li></ul>",
        'category' => 'reconfigure',
        'icon' => 'fa-paper-plane',
        'released_at' => '2026-07-01T15:15:00Z',
        'deep_link' => '/requests/index.php',
    ],
    [
        'title' => 'Bulk ready board allocation against a request',
        'summary' => 'Open a ready board request, tick the ready boards to assign, set target status/location/department, and submit once to update every selected asset and mark the request fulfilled.',
        'details' => "<p>Thabo: no more one-by-one status/location edits for ready board fulfillment.</p><p>Open any Ready board request and use the <strong>Allocate ready boards</strong> panel:</p><ul><li>Tick the ready boards to assign (or click <em>Select all available</em> / <em>Select requested (N)</em>).</li><li>Set Target status (Allocated / CheckedOut / InProject / Deployed), Department, and Target location (pre-filled with the request's site code).</li><li>Click <strong>Allocate selected and mark fulfilled</strong> — each selected ready board's status, location, department, and project (with request number) is updated in one submission.</li></ul><p>The request is marked Fulfilled with the assigned asset IDs stored on it.</p>",
        'category' => 'feature',
        'icon' => 'fa-check-double',
        'released_at' => '2026-07-01T15:15:00Z',
        'deep_link' => '/requests/index.php',
    ],
];

$existing = am_firestore_get_collection(AM_WHATS_NEW_COLLECTION, 500, $adminToken);
$existingTitles = [];
foreach ($existing as $e) {
    $t = strtolower(trim((string)($e['title'] ?? '')));
    if ($t !== '') {
        $existingTitles[$t] = true;
    }
}

echo "Existing entries: " . count($existing) . PHP_EOL;
echo "Dry run: " . ($dryRun ? 'YES' : 'NO') . PHP_EOL;
echo str_repeat('-', 60) . PHP_EOL;

$created = 0;
$skipped = 0;
foreach ($entries as $entry) {
    $key = strtolower(trim($entry['title']));
    if (isset($existingTitles[$key])) {
        echo "SKIP  " . substr($entry['title'], 0, 60) . PHP_EOL;
        $skipped++;
        continue;
    }
    $data = array_merge($entry, [
        'active' => 1,
        'created_at' => gmdate('c'),
    ]);
    if ($dryRun) {
        echo "DRY   " . substr($entry['title'], 0, 60) . PHP_EOL;
        continue;
    }
    $r = am_firestore_create_document(AM_WHATS_NEW_COLLECTION, $data, null, $adminToken);
    if ($r['ok']) {
        echo "OK    " . substr($entry['title'], 0, 60) . PHP_EOL;
        $created++;
    } else {
        echo "FAIL  " . substr($entry['title'], 0, 60) . ' — ' . ($r['error'] ?? 'Unknown') . PHP_EOL;
    }
}

echo str_repeat('-', 60) . PHP_EOL;
echo "Created: {$created}, Skipped: {$skipped}" . PHP_EOL;
