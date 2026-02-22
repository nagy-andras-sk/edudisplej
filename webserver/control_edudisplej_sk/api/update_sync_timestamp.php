<?php
/**
 * Update Kiosk Sync Timestamp API
 * Updates last_sync and loop_last_update when sync completes
 */

require_once '../dbkonfiguracia.php';
require_once '../i18n.php';
require_once 'auth.php';

header('Content-Type: application/json');

// Validate API authentication for device requests
$api_company = validate_api_token();

$response = ['success' => false, 'message' => ''];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mac = $data['mac'] ?? '';
    $last_sync = $data['last_sync'] ?? date('Y-m-d H:i:s');
    $loop_last_update = $data['loop_last_update'] ?? null;
    
    if (empty($mac)) {
        $response['message'] = t_def('api.update_sync_timestamp.mac_required', 'MAC address required');
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Verify kiosk and enforce company ownership
    $kiosk_lookup = $conn->prepare("SELECT id, company_id FROM kiosks WHERE mac = ? LIMIT 1");
    $kiosk_lookup->bind_param("s", $mac);
    $kiosk_lookup->execute();
    $kiosk_result = $kiosk_lookup->get_result();
    $kiosk_row = $kiosk_result->fetch_assoc();
    $kiosk_lookup->close();

    if (!$kiosk_row) {
        $response['message'] = t_def('api.common.kiosk_not_found', 'Kiosk not found');
        echo json_encode($response);
        exit;
    }

    api_require_company_match($api_company, $kiosk_row['company_id'], 'Unauthorized');

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
        $response['message'] = t_def('api.update_sync_timestamp.updated', 'Sync timestamp updated');
    } else {
        $response['message'] = t_def('api.common.kiosk_not_found', 'Kiosk not found');
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = t_def('api.common.server_error', 'Server error') . ': ' . $e->getMessage();
    error_log($e->getMessage());
}

echo json_encode($response);
?>
