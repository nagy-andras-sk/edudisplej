<?php
/**
 * Update Sync Timestamp API  –  /api/update_sync_timestamp.php
 *
 * A kiosk jelzi a sikeres szinkronizáció időpontját.
 * POST paraméterek (JSON body):
 *   mac       – kiosk MAC-cím
 *   last_sync – szinkron időbélyege ('Y-m-d H:i:s'), opcionális (default: NOW())
 *
 * Válasz:
 *   {"success":true}
 */

require_once __DIR__ . '/../error_handler_api.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/../dbkonfiguracia.php';

header('Content-Type: application/json; charset=utf-8');

$company = validate_api_token();

$raw  = file_get_contents('php://input');
$body = json_decode($raw ?: '{}', true) ?: [];

$mac       = strtolower(trim((string)($body['mac'] ?? $_POST['mac'] ?? '')));
$last_sync = trim((string)($body['last_sync'] ?? $_POST['last_sync'] ?? ''));

if (empty($mac)) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'mac address required']);
    exit;
}

// Sanitize last_sync: accept 'Y-m-d H:i:s' or fall back to NOW()
$sync_time = date('Y-m-d H:i:s'); // default
if ($last_sync !== '') {
    $parsed = strtotime($last_sync);
    if ($parsed !== false) {
        $sync_time = date('Y-m-d H:i:s', $parsed);
    }
}

try {
    $conn = getDbConnection();

    $stmt = $conn->prepare(
        "UPDATE kiosks SET last_sync = ?, last_seen = GREATEST(COALESCE(last_seen, ?), ?) WHERE mac_address = ?"
    );
    $stmt->bind_param("ssss", $sync_time, $sync_time, $sync_time, $mac);
    $stmt->execute();
    $stmt->close();

    closeDbConnection($conn);

    echo json_encode(['success' => true]);

} catch (Exception $e) {
    error_log('api/update_sync_timestamp.php error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
