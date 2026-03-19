<?php
/**
 * Report export endpoint -- generates CSV or PDF downloads.
 *
 * GET ?report=<type>&format=csv|pdf&<filters...>
 */
require_once __DIR__ . '/../config/app.php';

if (session_status() === PHP_SESSION_NONE) session_start();
if (empty($_SESSION['firebase_id_token'])) {
    header('Location: /login.php'); exit;
}

require_once __DIR__ . '/../config/firestore.php';

$report = $_GET['report'] ?? '';
$format = $_GET['format'] ?? 'csv';

switch ($report) {
    case 'asset_register':  $data = report_asset_register(); break;
    case 'transactions':    $data = report_transactions();   break;
    case 'stock':           $data = report_stock();          break;
    case 'allocations':     $data = report_allocations();    break;
    case 'qr_coverage':     $data = report_qr_coverage();   break;
    case 'summary':         $data = report_summary();        break;
    default:
        header('HTTP/1.1 400 Bad Request');
        echo 'Unknown report type.'; exit;
}

$filename = "1PWR_AM_{$report}_" . date('Y-m-d');

if ($format === 'pdf') {
    output_pdf($data['title'], $data['headers'], $data['rows'], $filename);
} else {
    output_csv($data['headers'], $data['rows'], $filename);
}
exit;

// ── Report builders ─────────────────────────────────────────────────

function report_asset_register(): array {
    $assets = am_firestore_get_collection('am_core_assets', 2000);
    $ic = $_GET['item_class'] ?? '';
    $cc = $_GET['country_code'] ?? '';

    $rows = [];
    foreach ($assets as $a) {
        if ($ic && ($a['item_class'] ?? '') !== $ic) continue;
        if ($cc && ($a['country_code'] ?? '') !== $cc) continue;
        $rows[] = [
            $a['asset_tag'] ?? '',
            $a['name'] ?? '',
            $a['item_class'] ?? '',
            $a['category_code'] ?? '',
            $a['serial_number'] ?? '',
            $a['manufacturer'] ?? '',
            $a['model'] ?? '',
            $a['country_code'] ?? '',
            $a['location_code'] ?? '',
            $a['status'] ?? '',
            $a['condition_status'] ?? '',
            $a['quantity'] ?? 1,
            $a['unit_of_measure'] ?? 'EA',
            fmt_num($a['purchase_price'] ?? 0),
            fmt_num($a['current_value'] ?? 0),
            $a['purchase_date'] ?? '',
            $a['qr_code_id'] ?? '',
            $a['notes'] ?? '',
        ];
    }

    return [
        'title' => '1PWR Asset Register' . ($ic ? " — {$ic}" : '') . ($cc ? " ({$cc})" : ''),
        'headers' => ['Asset Tag','Name','Class','Category','Serial #','Manufacturer','Model','Country','Location','Status','Condition','Qty','UOM','Purchase Price','Current Value','Purchase Date','QR Code','Notes'],
        'rows' => $rows,
    ];
}

function report_transactions(): array {
    $txns = am_firestore_get_collection('am_core_transactions', 2000);
    $type = $_GET['transaction_type'] ?? '';
    $from = $_GET['date_from'] ?? '';
    $to   = $_GET['date_to'] ?? '';

    $assets = am_firestore_get_collection('am_core_assets', 2000);
    $assetNames = [];
    foreach ($assets as $a) {
        $assetNames[$a['id'] ?? ''] = $a['name'] ?? $a['asset_tag'] ?? '';
    }

    $rows = [];
    foreach ($txns as $t) {
        if ($type && ($t['transaction_type'] ?? '') !== $type) continue;
        $txDate = substr($t['transaction_date'] ?? '', 0, 10);
        if ($from && $txDate < $from) continue;
        if ($to && $txDate > $to) continue;

        $aid = $t['asset_id'] ?? '';
        $rows[] = [
            $t['transaction_date'] ?? '',
            $t['transaction_type'] ?? '',
            $assetNames[$aid] ?? $aid,
            $t['quantity'] ?? '',
            $t['employee_name'] ?? '',
            $t['device_type'] ?? '',
            $t['notes'] ?? '',
            $t['performed_by'] ?? '',
        ];
    }

    usort($rows, fn($a, $b) => strcmp($b[0], $a[0]));

    return [
        'title' => 'Transaction Log' . ($type ? " — {$type}" : ''),
        'headers' => ['Date','Type','Item','Qty','Employee','Device','Notes','Performed By'],
        'rows' => $rows,
    ];
}

function report_stock(): array {
    $assets = am_firestore_get_collection('am_core_assets', 2000);
    $cc = $_GET['country_code'] ?? '';
    $lowOnly = !empty($_GET['low_stock_only']);

    $levels = am_firestore_get_collection('am_core_inventory_levels', 2000);
    $levelMap = [];
    foreach ($levels as $l) {
        $levelMap[$l['asset_id'] ?? ''] = $l;
    }

    $rows = [];
    foreach ($assets as $a) {
        $ic = $a['item_class'] ?? '';
        if (!in_array($ic, ['Material', 'Consumable', 'Inventory'])) continue;
        if ($cc && ($a['country_code'] ?? '') !== $cc) continue;

        $lvl = $levelMap[$a['id'] ?? ''] ?? [];
        $onHand = (int)($lvl['quantity_on_hand'] ?? $a['quantity'] ?? 0);
        $allocated = (int)($lvl['quantity_allocated'] ?? 0);
        $available = $onHand - $allocated;
        $reorder = (int)($lvl['reorder_level'] ?? 0);

        if ($lowOnly && $reorder > 0 && $available > $reorder) continue;
        if ($lowOnly && $reorder === 0) continue;

        $rows[] = [
            $a['name'] ?? '',
            $ic,
            $a['category_code'] ?? '',
            $a['country_code'] ?? '',
            $a['location_code'] ?? '',
            $onHand,
            $allocated,
            $available,
            $reorder ?: '-',
            ($reorder > 0 && $available <= $reorder) ? 'LOW' : 'OK',
            $a['unit_of_measure'] ?? 'EA',
        ];
    }

    return [
        'title' => 'Stock Report' . ($cc ? " ({$cc})" : '') . ($lowOnly ? ' — Low Stock' : ''),
        'headers' => ['Item','Class','Category','Country','Location','On Hand','Allocated','Available','Reorder Level','Status','UOM'],
        'rows' => $rows,
    ];
}

function report_allocations(): array {
    $allocs = am_firestore_get_collection('am_core_allocations', 2000);
    $status = $_GET['alloc_status'] ?? '';

    $assets = am_firestore_get_collection('am_core_assets', 2000);
    $assetNames = [];
    foreach ($assets as $a) {
        $assetNames[$a['id'] ?? ''] = $a['name'] ?? $a['asset_tag'] ?? '';
    }

    $rows = [];
    foreach ($allocs as $al) {
        if ($status && ($al['status'] ?? '') !== $status) continue;
        $aid = $al['asset_id'] ?? '';
        $rows[] = [
            $al['allocation_date'] ?? '',
            $assetNames[$aid] ?? $aid,
            $al['employee_name'] ?? $al['employee_id'] ?? '',
            $al['status'] ?? '',
            $al['expected_return_date'] ?? '',
            $al['actual_return_date'] ?? '',
            $al['notes'] ?? '',
        ];
    }

    usort($rows, fn($a, $b) => strcmp($b[0], $a[0]));

    return [
        'title' => 'Allocation Report' . ($status ? " — {$status}" : ''),
        'headers' => ['Allocated Date','Item','Employee','Status','Expected Return','Actual Return','Notes'],
        'rows' => $rows,
    ];
}

function report_qr_coverage(): array {
    $assets = am_firestore_get_collection('am_core_assets', 2000);
    $filter = $_GET['qr_filter'] ?? '';

    $rows = [];
    foreach ($assets as $a) {
        $hasQR = !empty($a['qr_code_id']);
        if ($filter === 'assigned' && !$hasQR) continue;
        if ($filter === 'missing' && $hasQR) continue;
        $rows[] = [
            $a['asset_tag'] ?? '',
            $a['name'] ?? '',
            $a['item_class'] ?? '',
            $a['country_code'] ?? '',
            $hasQR ? $a['qr_code_id'] : '(none)',
            $hasQR ? 'Assigned' : 'Missing',
        ];
    }

    return [
        'title' => 'QR Code Coverage',
        'headers' => ['Asset Tag','Name','Class','Country','QR Code','Status'],
        'rows' => $rows,
    ];
}

function report_summary(): array {
    $assets = am_firestore_get_collection('am_core_assets', 2000);

    $byClass = []; $byCountry = []; $byStatus = [];
    foreach ($assets as $a) {
        $ic = $a['item_class'] ?? 'Unknown';
        $cc = $a['country_code'] ?? 'Unknown';
        $st = $a['status'] ?? 'Unknown';
        $qty = (int)($a['quantity'] ?? 1);
        $val = (float)($a['purchase_price'] ?? 0);

        $byClass[$ic] = ($byClass[$ic] ?? ['count' => 0, 'qty' => 0, 'value' => 0]);
        $byClass[$ic]['count']++;
        $byClass[$ic]['qty'] += $qty;
        $byClass[$ic]['value'] += $val;

        $byCountry[$cc] = ($byCountry[$cc] ?? ['count' => 0, 'qty' => 0]);
        $byCountry[$cc]['count']++;
        $byCountry[$cc]['qty'] += $qty;

        $byStatus[$st] = ($byStatus[$st] ?? 0) + 1;
    }

    $rows = [];
    $rows[] = ['=== BY ITEM CLASS ===', '', '', ''];
    foreach ($byClass as $k => $v) {
        $rows[] = [$k, $v['count'], $v['qty'], fmt_num($v['value'])];
    }
    $rows[] = ['', '', '', ''];
    $rows[] = ['=== BY COUNTRY ===', '', '', ''];
    foreach ($byCountry as $k => $v) {
        $rows[] = [$k, $v['count'], $v['qty'], ''];
    }
    $rows[] = ['', '', '', ''];
    $rows[] = ['=== BY STATUS ===', '', '', ''];
    foreach ($byStatus as $k => $v) {
        $rows[] = [$k, $v, '', ''];
    }

    return [
        'title' => 'Classification Summary',
        'headers' => ['Dimension','Items','Quantity','Value'],
        'rows' => $rows,
    ];
}

// ── Output helpers ──────────────────────────────────────────────────

function fmt_num($v): string {
    if (!$v) return '';
    return number_format((float)$v, 2);
}

function output_csv(array $headers, array $rows, string $filename): void {
    header('Content-Type: text/csv; charset=utf-8');
    header("Content-Disposition: attachment; filename=\"{$filename}.csv\"");
    $out = fopen('php://output', 'w');
    fprintf($out, chr(0xEF) . chr(0xBB) . chr(0xBF)); // BOM for Excel
    fputcsv($out, $headers);
    foreach ($rows as $row) {
        fputcsv($out, $row);
    }
    fclose($out);
}

function output_pdf(string $title, array $headers, array $rows, string $filename): void {
    $colCount = count($headers);
    $colWidth = max(80, intval(700 / $colCount));
    $tableWidth = $colWidth * $colCount;

    header('Content-Type: text/html; charset=utf-8');
    ?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title><?= htmlspecialchars($title) ?></title>
    <style>
        @page { size: landscape; margin: 15mm; }
        @media print { body { -webkit-print-color-adjust: exact; print-color-adjust: exact; } }
        body { font-family: Arial, Helvetica, sans-serif; font-size: 9px; color: #333; }
        .header { text-align: center; margin-bottom: 10px; }
        .header h1 { font-size: 16px; margin: 0; color: #1a3a5c; }
        .header .meta { font-size: 10px; color: #666; }
        table { width: 100%; border-collapse: collapse; }
        th { background: #1a3a5c; color: white; padding: 5px 4px; font-size: 8px; text-align: left; }
        td { padding: 4px; border-bottom: 1px solid #e0e0e0; font-size: 8px; }
        tr:nth-child(even) td { background: #f8f9fa; }
        .footer { text-align: center; font-size: 8px; color: #999; margin-top: 10px; }
        .print-btn { text-align: center; margin: 10px 0; }
        .print-btn button { padding: 8px 24px; font-size: 12px; cursor: pointer; }
        @media print { .print-btn { display: none; } }
    </style>
</head>
<body>
    <div class="print-btn">
        <button onclick="window.print()">Print / Save as PDF</button>
        <button onclick="window.close()">Close</button>
    </div>
    <div class="header">
        <h1><?= htmlspecialchars($title) ?></h1>
        <div class="meta">Generated <?= date('d M Y H:i') ?> by <?= htmlspecialchars($_SESSION['username'] ?? 'System') ?></div>
    </div>
    <table>
        <thead>
            <tr><?php foreach ($headers as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $row): ?>
            <tr>
                <?php foreach ($row as $cell): ?>
                    <td><?= htmlspecialchars((string)$cell) ?></td>
                <?php endforeach; ?>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
    <div class="footer">
        1PWR Africa — OneStop Asset Shop | <?= count($rows) ?> records | <?= date('Y') ?>
    </div>
    <script>
        document.title = '<?= htmlspecialchars($filename) ?>';
    </script>
</body>
</html>
    <?php
}
