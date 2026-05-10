<?php
/**
 * Odometer Readings API
 *
 * GET /api/odometer/?asset_id=X - Get all readings for a vehicle
 * POST /api/odometer/ - Add new reading
 * DELETE /api/odometer/?id=X - Delete a reading
 *
 * Reads from Firestore am_core_odometer_readings collection.
 */

require_once __DIR__ . '/../../config/app.php';
require_once __DIR__ . '/../../config/firestore.php';

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

        $assetId = trim($_GET['asset_id']);

        $allReadings = am_firestore_get_collection('am_core_odometer_readings', 2000);
        $readings = [];
        foreach ($allReadings as $r) {
            if ((string)($r['asset_id'] ?? '') !== $assetId) continue;
            $readings[] = [
                'reading_id'    => (string)($r['id'] ?? ''),
                'asset_id'      => $assetId,
                'reading_km'    => (int)($r['reading_km'] ?? 0),
                'reading_date'  => (string)($r['reading_date'] ?? ''),
                'notes'         => (string)($r['notes'] ?? ''),
                'created_at'    => (string)($r['created_at'] ?? ''),
            ];
        }

        usort($readings, function ($a, $b) {
            return strcmp($b['reading_date'], $a['reading_date']) ?: ($b['reading_id'] <=> $a['reading_id']);
        });

        echo json_encode([
            'success'  => true,
            'count'    => count($readings),
            'readings' => $readings,
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

        $assetId = trim($input['asset_id']);
        $readingKm = (int)$input['reading_km'];
        $readingDate = $input['reading_date'];
        $notes = $input['notes'] ?? '';
        $recordedBy = $input['recorded_by'] ?? '';

        // Validate reading is not less than previous reading
        $allReadings = am_firestore_get_collection('am_core_odometer_readings', 2000);
        $lastReading = null;
        foreach ($allReadings as $r) {
            if ((string)($r['asset_id'] ?? '') !== $assetId) continue;
            $rd = (string)($r['reading_date'] ?? '');
            if ($rd <= $readingDate && ($lastReading === null || $rd > $lastReading['reading_date'])) {
                $lastReading = ['reading_km' => (int)($r['reading_km'] ?? 0), 'reading_date' => $rd];
            }
        }

        if ($lastReading && $readingKm < $lastReading['reading_km']) {
            http_response_code(400);
            echo json_encode([
                'error' => 'Reading cannot be less than previous reading',
                'previous_reading' => $lastReading['reading_km'],
            ]);
            exit;
        }

        $data = [
            'asset_id'      => $assetId,
            'reading_km'    => $readingKm,
            'reading_date'  => $readingDate,
            'notes'         => $notes,
            'recorded_by'   => $recordedBy,
            'created_at'    => date('c'),
        ];

        $result = am_firestore_create_document('am_core_odometer_readings', $data);
        if ($result['ok']) {
            echo json_encode([
                'success'    => true,
                'reading_id' => $result['id'],
                'message'    => 'Odometer reading added',
            ]);
        } else {
            http_response_code(500);
            echo json_encode(['error' => $result['error'] ?? 'Failed to save reading']);
        }
        break;

    case 'DELETE':
        if (!isset($_GET['id'])) {
            http_response_code(400);
            echo json_encode(['error' => 'id is required']);
            exit;
        }

        $readingId = trim($_GET['id']);
        $result = am_firestore_delete_document('am_core_odometer_readings', $readingId);

        if ($result['ok']) {
            echo json_encode(['success' => true, 'message' => 'Reading deleted']);
        } else {
            http_response_code(404);
            echo json_encode(['error' => 'Reading not found or could not be deleted']);
        }
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Method not allowed']);
}
