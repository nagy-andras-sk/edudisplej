<?php
header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';

$response = ['success' => false, 'message' => ''];

try {
    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mac = $data['mac'] ?? '';
    $device_id = $data['device_id'] ?? '';
    
    if (empty($mac)) {
        $response['message'] = 'MAC address required';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Get kiosk data
    $stmt = $conn->prepare("SELECT id, device_id, sync_interval FROM kiosks WHERE mac = ?");
    $stmt->bind_param("s", $mac);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $kiosk = $result->fetch_assoc();
        
        // Build modules data
        $modules = [];
        
        // Default module
        $default_module = [
            'name' => 'default',
            'data' => [
                'sync_interval' => $kiosk['sync_interval'],
                'device_id' => $kiosk['device_id'],
                'last_updated' => date('Y-m-d H:i:s')
            ]
        ];
        $modules[] = $default_module;
        
        // Clock module (placeholder)
        $clock_module = [
            'name' => 'clock',
            'data' => [
                'enabled' => true,
                'format' => '24h',
                'timezone' => 'Europe/Bratislava'
            ]
        ];
        $modules[] = $clock_module;
        
        $response['success'] = true;
        $response['kiosk_id'] = $kiosk['id'];
        $response['device_id'] = $kiosk['device_id'];
        $response['modules'] = $modules;
        
        // Log modules sync
        $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'modules_sync', ?)");
        $details = json_encode(['module_count' => count($modules), 'timestamp' => date('Y-m-d H:i:s')]);
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
