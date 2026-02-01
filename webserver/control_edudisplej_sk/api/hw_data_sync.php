<?php
/**
 * Hardware Data Sync API
 * EduDisplej Control Panel
 */

header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';

$response = ['success' => false, 'message' => ''];

try {
    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mac = $data['mac'] ?? '';
    $hostname = $data['hostname'] ?? '';
    $hw_info = json_encode($data['hw_info'] ?? []);
    $public_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    if (empty($mac)) {
        $response['message'] = 'MAC address required';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Update kiosk hardware data and public IP
    $stmt = $conn->prepare("UPDATE kiosks SET hostname = ?, hw_info = ?, public_ip = ?, status = 'online', last_seen = NOW() WHERE mac = ?");
    $stmt->bind_param("ssss", $hostname, $hw_info, $public_ip, $mac);
    $stmt->execute();
    
    // Get kiosk data
    $stmt = $conn->prepare("SELECT id, device_id, sync_interval, screenshot_requested FROM kiosks WHERE mac = ?");
    $stmt->bind_param("s", $mac);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $kiosk = $result->fetch_assoc();
        $response['success'] = true;
        $response['kiosk_id'] = $kiosk['id'];
        $response['device_id'] = $kiosk['device_id'];
        $response['sync_interval'] = $kiosk['sync_interval'];
        $response['screenshot_requested'] = (bool)$kiosk['screenshot_requested'];
        
        // Log sync
        $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'hw_data_sync', ?)");
        $details = json_encode([
            'hostname' => $hostname,
            'public_ip' => $public_ip,
            'timestamp' => date('Y-m-d H:i:s')
        ]);
        $log_stmt->bind_param("is", $kiosk['id'], $details);
        $log_stmt->execute();
        $log_stmt->close();
    } else {
        $response['message'] = 'Kiosk not found. Please register first.';
    }
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error';
    error_log($e->getMessage());
}

echo json_encode($response);
?>

