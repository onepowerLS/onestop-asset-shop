<?php
/**
 * QR Code Generator for OneStop Asset Shop
 * 
 * Generates QR codes for assets and handles label printing
 */

require_once __DIR__ . '/../config/database.php';

class QRGenerator {
    private $db;
    
    public function __construct($database) {
        $this->db = $database;
    }
    
    /**
     * Generate QR code ID for an asset
     * Format: 1PWR-{COUNTRY_CODE}-{ASSET_ID_PADDED}
     */
    public function generateQRCodeID($assetId, $countryCode = 'LSO') {
        $paddedId = str_pad($assetId, 6, '0', STR_PAD_LEFT);
        return "1PWR-{$countryCode}-{$paddedId}";
    }
    
    /**
     * Assign QR code to asset if not already assigned
     */
    public function assignQRCodeToAsset($assetId) {
        // Get asset with country info
        $stmt = $this->db->prepare("
            SELECT a.asset_id, c.country_code 
            FROM assets a
            JOIN countries c ON a.country_id = c.country_id
            WHERE a.asset_id = ?
        ");
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$asset) {
            throw new Exception("Asset not found: {$assetId}");
        }
        
        // Check if QR code already exists
        $checkStmt = $this->db->prepare("SELECT qr_code_id FROM assets WHERE asset_id = ?");
        $checkStmt->execute([$assetId]);
        $existing = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if (!empty($existing['qr_code_id'])) {
            return $existing['qr_code_id'];
        }
        
        // Generate and assign QR code
        $qrCodeId = $this->generateQRCodeID($assetId, $asset['country_code']);
        
        $updateStmt = $this->db->prepare("
            UPDATE assets 
            SET qr_code_id = ? 
            WHERE asset_id = ?
        ");
        $updateStmt->execute([$qrCodeId, $assetId]);
        
        return $qrCodeId;
    }
    
    /**
     * Generate QR code image (using a library like phpqrcode or similar)
     * Returns base64 encoded image data
     */
    public function generateQRCodeImage($qrCodeId, $size = 300) {
        // Using a simple approach - in production, use a library like:
        // - phpqrcode (PHP)
        // - qrcode.js (JavaScript)
        // - Google Charts API
        
        $qrUrl = "https://api.qrserver.com/v1/create-qr-code/?size={$size}x{$size}&data=" . urlencode($qrCodeId);
        
        // For production, use a local library instead of external API
        // Example with phpqrcode:
        /*
        require_once 'phpqrcode/qrlib.php';
        $tempFile = tempnam(sys_get_temp_dir(), 'qr_');
        QRcode::png($qrCodeId, $tempFile, QR_ECLEVEL_L, 10);
        $imageData = file_get_contents($tempFile);
        unlink($tempFile);
        return base64_encode($imageData);
        */
        
        return $qrUrl; // For now, return URL to external service
    }
    
    /**
     * Record label printing in database
     */
    public function recordLabelPrint($assetId, $userId, $printerModel = 'Brother PT-P710BT') {
        $asset = $this->db->prepare("SELECT qr_code_id FROM assets WHERE asset_id = ?");
        $asset->execute([$assetId]);
        $assetData = $asset->fetch(PDO::FETCH_ASSOC);
        
        if (empty($assetData['qr_code_id'])) {
            throw new Exception("Asset does not have a QR code assigned");
        }
        
        $stmt = $this->db->prepare("
            INSERT INTO qr_labels (asset_id, qr_code_id, printed_by, printer_model, label_printed_at)
            VALUES (?, ?, ?, ?, NOW())
            ON DUPLICATE KEY UPDATE 
                label_printed_at = NOW(),
                printed_by = ?
        ");
        
        $stmt->execute([
            $assetId,
            $assetData['qr_code_id'],
            $userId,
            $printerModel,
            $userId
        ]);
        
        return $this->db->lastInsertId();
    }
    
    /**
     * Get label data for printing (formatted for Brother printer)
     */
    public function getLabelData($assetId) {
        $stmt = $this->db->prepare("
            SELECT 
                a.asset_id,
                a.qr_code_id,
                a.asset_tag,
                a.name,
                a.serial_number,
                c.country_code,
                c.country_name
            FROM assets a
            JOIN countries c ON a.country_id = c.country_id
            WHERE a.asset_id = ?
        ");
        $stmt->execute([$assetId]);
        $asset = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$asset) {
            throw new Exception("Asset not found");
        }
        
        return [
            'qr_code' => $asset['qr_code_id'],
            'asset_tag' => $asset['asset_tag'] ?? $asset['asset_id'],
            'name' => $asset['name'],
            'serial' => $asset['serial_number'] ?? 'N/A',
            'country' => $asset['country_code'],
            'qr_image_url' => $this->generateQRCodeImage($asset['qr_code_id'], 200)
        ];
    }
}

// API Endpoint Example
if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['action'])) {
    header('Content-Type: application/json');
    
    try {
        $db = new PDO(
            "mysql:host={$db_host};dbname={$db_name};charset=utf8mb4",
            $db_user,
            $db_pass,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        
        $generator = new QRGenerator($db);
        
        switch ($_GET['action']) {
            case 'generate':
                $assetId = intval($_GET['asset_id']);
                $qrCodeId = $generator->assignQRCodeToAsset($assetId);
                echo json_encode(['success' => true, 'qr_code_id' => $qrCodeId]);
                break;
                
            case 'label_data':
                $assetId = intval($_GET['asset_id']);
                $labelData = $generator->getLabelData($assetId);
                echo json_encode(['success' => true, 'data' => $labelData]);
                break;
                
            case 'print':
                $assetId = intval($_GET['asset_id']);
                $userId = intval($_GET['user_id'] ?? 1);
                $labelId = $generator->recordLabelPrint($assetId, $userId);
                echo json_encode(['success' => true, 'label_id' => $labelId]);
                break;
                
            default:
                throw new Exception("Invalid action");
        }
    } catch (Exception $e) {
        http_response_code(400);
        echo json_encode(['success' => false, 'error' => $e->getMessage()]);
    }
    exit;
}
