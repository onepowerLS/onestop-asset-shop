<?php
/**
 * Generate QR Code for Asset
 * 
 * GET /api/qr/generate.php?asset_id=X - Generate a unique QR code ID for an asset
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if (!isset($_GET['asset_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'asset_id is required']);
    exit;
}

$assetId = (int)$_GET['asset_id'];

try {
    // Check if asset exists and doesn't already have a QR code
    $stmt = $pdo->prepare("SELECT asset_id, name, qr_code_id, country_id FROM assets WHERE asset_id = ?");
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch();
    
    if (!$asset) {
        http_response_code(404);
        echo json_encode(['error' => 'Asset not found']);
        exit;
    }
    
    if (!empty($asset['qr_code_id'])) {
        echo json_encode([
            'success' => true,
            'qr_code_id' => $asset['qr_code_id'],
            'message' => 'QR code already exists'
        ]);
        exit;
    }
    
    // Get country code for prefix
    $stmt = $pdo->prepare("SELECT country_code FROM countries WHERE country_id = ?");
    $stmt->execute([$asset['country_id']]);
    $country = $stmt->fetch();
    $countryCode = $country ? $country['country_code'] : 'XXX';
    
    // Generate unique QR code ID: 1PWR-{COUNTRY}-{ASSET_ID}-{RANDOM}
    $randomPart = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
    $qrCodeId = sprintf("1PWR-%s-%05d-%s", $countryCode, $assetId, $randomPart);
    
    // Update asset with QR code
    $stmt = $pdo->prepare("UPDATE assets SET qr_code_id = ? WHERE asset_id = ?");
    $stmt->execute([$qrCodeId, $assetId]);
    
    echo json_encode([
        'success' => true,
        'qr_code_id' => $qrCodeId,
        'asset_id' => $assetId,
        'message' => 'QR code generated successfully'
    ]);
    
} catch (PDOException $e) {
    error_log("Error generating QR code: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to generate QR code: ' . $e->getMessage()]);
}
