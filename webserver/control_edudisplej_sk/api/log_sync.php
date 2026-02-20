<?php
/**
 * Log Sync API
 * EduDisplej Control Panel
 * 
 * Receives logs from kiosk devices and stores them for troubleshooting
 *
 * @deprecated Use /api/v1/device/sync.php instead (include logs in the sync payload).
 */

header('Content-Type: application/json');
header('X-EDU-Deprecated: true');
header('X-EDU-Successor: /api/v1/device/sync.php');
require_once '../dbkonfiguracia.php';
require_once 'auth.php';

// Validate API authentication for device requests
$api_company = validate_api_token();

$response = ['success' => false, 'message' => ''];

try {
    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mac = $data['mac'] ?? '';
    $device_id = $data['device_id'] ?? '';
    $logs = $data['logs'] ?? [];
    
    if (empty($mac) || empty($device_id)) {
        $response['message'] = 'MAC address and device_id required';
        echo json_encode($response);
        exit;
    }
    
    if (empty($logs)) {
        $response['message'] = 'No logs provided';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Find kiosk by MAC or device_id
    $stmt = $conn->prepare("SELECT id, company_id FROM kiosks WHERE mac = ? OR device_id = ? LIMIT 1");
    $stmt->bind_param("ss", $mac, $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $response['message'] = 'Kiosk not found';
        echo json_encode($response);
        exit;
    }
    
    $kiosk = $result->fetch_assoc();
    api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');
    $kiosk_id = $kiosk['id'];
    
    // Create logs table if it doesn't exist
    $create_table_sql = "
        CREATE TABLE IF NOT EXISTS kiosk_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            kiosk_id INT NOT NULL,
            log_type VARCHAR(50) NOT NULL,
            log_level VARCHAR(20) NOT NULL,
            message TEXT NOT NULL,
            details TEXT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_kiosk_id (kiosk_id),
            INDEX idx_log_type (log_type),
            INDEX idx_log_level (log_level),
            INDEX idx_created_at (created_at),
            FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    ";
    $conn->query($create_table_sql);
    
    // Insert logs
    $stmt = $conn->prepare("INSERT INTO kiosk_logs (kiosk_id, log_type, log_level, message, details) VALUES (?, ?, ?, ?, ?)");
    
    $inserted_count = 0;
    foreach ($logs as $log) {
        $log_type = $log['type'] ?? 'general';
        $log_level = $log['level'] ?? 'info';
        $message = $log['message'] ?? '';
        $details = isset($log['details']) ? json_encode($log['details']) : null;
        
        if (empty($message)) {
            continue;
        }
        
        $stmt->bind_param("issss", $kiosk_id, $log_type, $log_level, $message, $details);
        if ($stmt->execute()) {
            $inserted_count++;
        }
    }
    
    // Clean up old logs (keep only last 30 days) - run in batches to avoid table locks
    $cleanup_sql = "DELETE FROM kiosk_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1000";
    $conn->query($cleanup_sql);
    
    $response['success'] = true;
    $response['message'] = 'Logs received successfully';
    $response['logs_inserted'] = $inserted_count;
    
    $conn->close();
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
}

echo json_encode($response);
