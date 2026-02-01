<?php
/**
 * Delete Asset API
 * 
 * DELETE /api/assets/delete.php?id=X - Delete an asset
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

if (!isset($_GET['id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Asset ID is required']);
    exit;
}

$assetId = (int)$_GET['id'];

try {
    // Check if asset exists
    $stmt = $pdo->prepare("SELECT asset_id, name FROM assets WHERE asset_id = ?");
    $stmt->execute([$assetId]);
    $asset = $stmt->fetch();
    
    if (!$asset) {
        http_response_code(404);
        echo json_encode(['error' => 'Asset not found']);
        exit;
    }
    
    // Delete related odometer readings first (if any)
    $stmt = $pdo->prepare("DELETE FROM odometer_readings WHERE asset_id = ?");
    $stmt->execute([$assetId]);
    
    // Delete the asset
    $stmt = $pdo->prepare("DELETE FROM assets WHERE asset_id = ?");
    $stmt->execute([$assetId]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Asset deleted successfully',
        'deleted_asset' => $asset['name']
    ]);
    
} catch (PDOException $e) {
    error_log("Error deleting asset: " . $e->getMessage());
    http_response_code(500);
    echo json_encode(['error' => 'Failed to delete asset: ' . $e->getMessage()]);
}
