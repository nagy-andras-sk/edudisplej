<?php
/**
 * Update Kiosk Sync Timestamp API
 * Updates last_sync and loop_last_update when sync completes
 */

require_once '../dbkonfiguracia.php';
require_once 'auth.php';

header('Content-Type: application/json');

// Validate API authentication for device requests
validate_api_token();

$response = ['success' => false, 'message' => ''];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mac = $data['mac'] ?? '';
    $last_sync = $data['last_sync'] ?? date('Y-m-d H:i:s');
    $loop_last_update = $data['loop_last_update'] ?? null;
    
    if (empty($mac)) {
        $response['message'] = 'MAC address required';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Update last_sync timestamp
    if ($loop_last_update) {
        $stmt = $conn->prepare("UPDATE kiosks SET last_sync = ?, loop_last_update = ? WHERE mac = ?");
        $stmt->bind_param("sss", $last_sync, $loop_last_update, $mac);
    } else {
        $stmt = $conn->prepare("UPDATE kiosks SET last_sync = ? WHERE mac = ?");
        $stmt->bind_param("ss", $last_sync, $mac);
    }
    
    $stmt->execute();
    
    if ($conn->affected_rows > 0) {
        $response['success'] = true;
        $response['message'] = 'Sync timestamp updated';
    } else {
        $response['message'] = 'Kiosk not found';
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    error_log($e->getMessage());
}

echo json_encode($response);
?>
