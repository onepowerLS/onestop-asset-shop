<?php
/**
 * QR Code Generation API Endpoint
 */
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../../qr/generator.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$assetId = intval($_GET['asset_id'] ?? 0);

if (!$assetId) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Asset ID required']);
    exit;
}

try {
    $generator = new QRGenerator($pdo);
    $qrCodeId = $generator->assignQRCodeToAsset($assetId);
    
    echo json_encode([
        'success' => true,
        'qr_code_id' => $qrCodeId,
        'asset_id' => $assetId
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
