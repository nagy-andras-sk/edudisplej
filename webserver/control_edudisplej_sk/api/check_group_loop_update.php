<?php
/**
 * Check Group Loop Update API  –  /api/check_group_loop_update.php
 *
 * A kiosk lekérdezi, szükséges-e új loop-tartalom letöltése.
 * POST paraméterek (JSON body):
 *   device_id – a kiosk MAC-címe (az /api/registration.php által visszaadott device_id)
 *
 * Válasz:
 *   {"success":true,"loop_updated_at":"<ISO datetime vagy YmdHis>"}
 *   Ha nincs frissítés: "loop_updated_at" null.
 */

require_once __DIR__ . '/../error_handler_api.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../dbkonfiguracia.php';

header('Content-Type: application/json; charset=utf-8');

$company = validate_api_token();

$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true) ?: [];

$device_id = strtolower(trim((string)($body['device_id'] ?? $_POST['device_id'] ?? '')));

if (empty($device_id)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'device_id required']);
    exit;
}

try {
    $conn = getDbConnection();

    // Resolve kiosk by mac_address (device_id)
    $stmt = $conn->prepare("SELECT id FROM kiosks WHERE mac_address = ? LIMIT 1");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row) {
        closeDbConnection($conn);
        echo json_encode(['success' => true, 'loop_updated_at' => null]);
        exit;
    }

    $kiosk_id = (int)$row['id'];

    // Latest loop version from group modules assigned to this kiosk
    $stmt = $conn->prepare("
        SELECT DATE_FORMAT(MAX(COALESCE(kgm.updated_at, kgm.created_at)), '%Y-%m-%d %H:%i:%s') AS loop_updated_at
        FROM kiosk_group_assignments kga
        JOIN kiosk_group_modules kgm ON kgm.group_id = kga.group_id
        WHERE kga.kiosk_id = ? AND kgm.is_active = 1
    ");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $res = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $loop_updated_at = $res['loop_updated_at'] ?? null;

    // Fallback: per-kiosk module timestamps
    if ($loop_updated_at === null) {
        $stmt2 = $conn->prepare("
            SELECT DATE_FORMAT(MAX(created_at), '%Y-%m-%d %H:%i:%s') AS loop_updated_at
            FROM kiosk_modules
            WHERE kiosk_id = ? AND is_active = 1
        ");
        $stmt2->bind_param("i", $kiosk_id);
        $stmt2->execute();
        $res2 = $stmt2->get_result()->fetch_assoc();
        $stmt2->close();
        $loop_updated_at = $res2['loop_updated_at'] ?? null;
    }

    closeDbConnection($conn);

    echo json_encode([
        'success'        => true,
        'loop_updated_at' => $loop_updated_at,
    ]);

} catch (Exception $e) {
    error_log('api/check_group_loop_update.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
