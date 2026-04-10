<?php
require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/authz.php';
require_once __DIR__ . '/../../config/firestore.php';

header('Content-Type: application/json');

if (empty($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Not authenticated']);
    exit;
}
am_require_can_mutate_json();

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit;
}

$assetDocId = trim($_GET['asset_id'] ?? '');

if ($assetDocId === '') {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Asset ID required']);
    exit;
}

$asset = am_firestore_get_document('am_core_assets', $assetDocId);
if (!$asset) {
    http_response_code(404);
    echo json_encode(['success' => false, 'error' => 'Asset not found']);
    exit;
}

if (!empty($asset['qr_code_id'])) {
    echo json_encode(['success' => true, 'qr_code_id' => $asset['qr_code_id'], 'asset_id' => $assetDocId]);
    exit;
}

$countries = am_firestore_get_collection('pr_master_countries', 500);
$countryCode = 'UNK';
$countryId = (string)($asset['country_id'] ?? '');
foreach ($countries as $c) {
    if ((string)($c['country_id'] ?? $c['id'] ?? '') === $countryId) {
        $countryCode = (string)($c['country_code'] ?? 'UNK');
        break;
    }
}

$itemClass = (string)($asset['item_class'] ?? 'ITM');
$prefixes = ['FixedAsset' => 'FA', 'Material' => 'MAT', 'Consumable' => 'CON', 'Inventory' => 'INV'];
$classPrefix = $prefixes[$itemClass] ?? 'ITM';

$allAssets = am_firestore_get_collection('am_core_assets', 2000);
$qrPrefix = "1PWR-{$countryCode}-{$classPrefix}-";
$maxNum = 0;
foreach ($allAssets as $a) {
    $qr = (string)($a['qr_code_id'] ?? '');
    if (str_starts_with($qr, $qrPrefix)) {
        $numPart = (int)substr($qr, strlen($qrPrefix));
        if ($numPart > $maxNum) $maxNum = $numPart;
    }
}

$qrCodeId = $qrPrefix . str_pad((string)($maxNum + 1), 6, '0', STR_PAD_LEFT);

$result = am_firestore_update_document('am_core_assets', $assetDocId, [
    'qr_code_id' => $qrCodeId,
    'updated_at' => date('c'),
]);

if ($result['ok']) {
    echo json_encode(['success' => true, 'qr_code_id' => $qrCodeId, 'asset_id' => $assetDocId]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $result['error'] ?? 'Failed to assign QR code']);
}
