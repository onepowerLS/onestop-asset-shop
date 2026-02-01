<?php
/**
 * Odometer Readings API
 * 
 * GET /api/odometer/?asset_id=X - Get all readings for a vehicle
 * POST /api/odometer/ - Add new reading
 * DELETE /api/odometer/?id=X - Delete a reading
 */

require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        if (!isset($_GET['asset_id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'asset_id is required']);
            exit;
        }
        
        $assetId = (int)$_GET['asset_id'];
        
        $stmt = $pdo->prepare("
            SELECT 
                r.reading_id,
                r.asset_id,
                r.reading_km,
                r.reading_date,
                r.notes,
                r.recorded_by,
                u.username as recorded_by_name,
                r.created_at
            FROM odometer_readings r
            LEFT JOIN users u ON r.recorded_by = u.user_id
            WHERE r.asset_id = ?
            ORDER BY r.reading_date DESC, r.reading_id DESC
        ");
        $stmt->execute([$assetId]);
        $readings = $stmt->fetchAll();
        
        echo json_encode([
            'success' => true,
            'count' => count($readings),
            'readings' => $readings
        ]);
        break;
        
    case 'POST':
        $input = json_decode(file_get_contents('php://input'), true);
        
        if (!$input) {
            $input = $_POST;
        }
        
        if (empty($input['asset_id']) || empty($input['reading_km']) || empty($input['reading_date'])) {
            http_response_code(400);
            echo json_encode(['error' => 'asset_id, reading_km, and reading_date are required']);
            exit;
        }
        
        $assetId = (int)$input['asset_id'];
        $readingKm = (int)$input['reading_km'];
        $readingDate = $input['reading_date'];
        $notes = $input['notes'] ?? null;
        $recordedBy = $input['recorded_by'] ?? null;
        
        // Validate reading is not less than previous reading
        $stmt = $pdo->prepare("
            SELECT reading_km FROM odometer_readings 
            WHERE asset_id = ? AND reading_date <= ?
            ORDER BY reading_date DESC, reading_id DESC
            LIMIT 1
        ");
        $stmt->execute([$assetId, $readingDate]);
        $lastReading = $stmt->fetch();
        
        if ($lastReading && $readingKm < $lastReading['reading_km']) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Reading cannot be less than previous reading',
                'previous_reading' => $lastReading['reading_km']
            ]);
            exit;
        }
        
        $stmt = $pdo->prepare("
            INSERT INTO odometer_readings (asset_id, reading_km, reading_date, notes, recorded_by)
            VALUES (?, ?, ?, ?, ?)
        ");
        $stmt->execute([$assetId, $readingKm, $readingDate, $notes, $recordedBy]);
        
        $readingId = $pdo->lastInsertId();
        
        echo json_encode([
            'success' => true,
            'reading_id' => $readingId,
            'message' => 'Odometer reading added'
        ]);
        break;
        
    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'id is required']);
            exit;
        }
        
        $readingId = (int)$_GET['id'];
        
        $stmt = $pdo->prepare("DELETE FROM odometer_readings WHERE reading_id = ?");
        $stmt->execute([$readingId]);
        
        if ($stmt->rowCount() > 0) {
            echo json_encode(['success' => true, 'message' => 'Reading deleted']);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Reading not found']);
        }
        break;
        
    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
