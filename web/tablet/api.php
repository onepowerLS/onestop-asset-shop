<?php
/**
 * Tablet API -- lightweight JSON endpoint for scan-based operations.
 *
 * GET  ?action=lookup&code=<qr_or_tag>
 * POST { action: 'checkout', asset_id, employee_name, notes }
 * POST { action: 'checkin',  asset_id }
 * POST { action: 'stockcount', asset_id, counted }
 */
require_once __DIR__ . '/../config/app.php';
require_once __DIR__ . '/../config/authz.php';

if (session_status() === PHP_SESSION_NONE) session_start();
header('Content-Type: application/json');

if (empty($_SESSION['firebase_id_token'])) {
    echo json_encode(['ok' => false, 'error' => 'Not authenticated']);
    exit;
}

require_once __DIR__ . '/../config/firestore.php';

$method = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $action = $_GET['action'] ?? '';
    $code   = trim($_GET['code'] ?? '');

    if ($action === 'lookup' && $code !== '') {
        $assets = am_firestore_get_collection('am_core_assets', 2000);
        $found = null;
        $codeLower = strtolower($code);
        foreach ($assets as $a) {
            if (strtolower($a['qr_code_id'] ?? '') === $codeLower
                || strtolower($a['asset_tag'] ?? '') === $codeLower
                || strtolower($a['serial_number'] ?? '') === $codeLower) {
                $found = $a;
                break;
            }
        }
        echo json_encode(['ok' => (bool)$found, 'item' => $found]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Invalid action']);
    exit;
}

if ($method === 'POST') {
    am_require_can_mutate_json();
    $input = json_decode(file_get_contents('php://input'), true) ?: [];
    $action = $input['action'] ?? '';
    $assetId = $input['asset_id'] ?? '';
    $uid = $_SESSION['firebase_uid'] ?? 'system';

    if (!$assetId) {
        echo json_encode(['ok' => false, 'error' => 'Missing asset_id']);
        exit;
    }

    if ($action === 'checkout') {
        $employee = trim($input['employee_name'] ?? '');
        $notes    = trim($input['notes'] ?? '');
        if (!$employee) {
            echo json_encode(['ok' => false, 'error' => 'Missing employee_name']);
            exit;
        }

        $alloc = am_firestore_create_document('am_core_allocations', [
            'asset_id'          => $assetId,
            'employee_name'     => $employee,
            'allocated_by'      => $uid,
            'allocation_date'   => date('c'),
            'status'            => 'Active',
            'notes'             => $notes,
        ]);

        $tx = am_firestore_create_document('am_core_transactions', [
            'transaction_type'  => 'CheckOut',
            'asset_id'          => $assetId,
            'employee_name'     => $employee,
            'performed_by'      => $uid,
            'device_type'       => 'Tablet',
            'notes'             => $notes,
            'transaction_date'  => date('c'),
        ]);

        $upd = am_firestore_update_document('am_core_assets', $assetId, [
            'status'     => 'CheckedOut',
            'updated_at' => date('c'),
        ]);

        echo json_encode(['ok' => $alloc['ok'] && $upd['ok'], 'allocation_id' => $alloc['id'] ?? '']);
        exit;
    }

    if ($action === 'checkin') {
        $allocs = am_firestore_get_collection('am_core_allocations', 2000);
        $activeAlloc = null;
        foreach ($allocs as $al) {
            if (($al['asset_id'] ?? '') === $assetId && ($al['status'] ?? '') === 'Active') {
                $activeAlloc = $al;
                break;
            }
        }

        if ($activeAlloc && !empty($activeAlloc['id'])) {
            am_firestore_update_document('am_core_allocations', $activeAlloc['id'], [
                'status'             => 'Returned',
                'actual_return_date' => date('c'),
            ]);
        }

        am_firestore_create_document('am_core_transactions', [
            'transaction_type'  => 'CheckIn',
            'asset_id'          => $assetId,
            'performed_by'      => $uid,
            'device_type'       => 'Tablet',
            'transaction_date'  => date('c'),
        ]);

        am_firestore_update_document('am_core_assets', $assetId, [
            'status'     => 'Available',
            'updated_at' => date('c'),
        ]);

        echo json_encode(['ok' => true]);
        exit;
    }

    if ($action === 'stockcount') {
        $counted = (int)($input['counted'] ?? 0);

        am_firestore_create_document('am_core_transactions', [
            'transaction_type'  => 'StockTake',
            'asset_id'          => $assetId,
            'quantity'          => $counted,
            'performed_by'      => $uid,
            'device_type'       => 'Tablet',
            'notes'             => "Physical count: {$counted}",
            'transaction_date'  => date('c'),
        ]);

        am_firestore_update_document('am_core_assets', $assetId, [
            'quantity'   => $counted,
            'updated_at' => date('c'),
        ]);

        echo json_encode(['ok' => true, 'counted' => $counted]);
        exit;
    }

    echo json_encode(['ok' => false, 'error' => 'Unknown action']);
    exit;
}

echo json_encode(['ok' => false, 'error' => 'Method not allowed']);
