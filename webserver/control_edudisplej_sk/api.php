<?php
/**
 * Kiosk Registration and Sync API
 * EduDisplej Control Panel
 */

header('Content-Type: application/json');
require_once 'dbkonfiguracia.php';

$response = ['success' => false, 'message' => ''];

try {
    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    $action = $_GET['action'] ?? '';
    
    $conn = getDbConnection();
    
    switch ($action) {
        case 'register':
            // Register new kiosk
            $mac = $data['mac'] ?? '';
            $hostname = $data['hostname'] ?? '';
            $hw_info = json_encode($data['hw_info'] ?? []);
            
            if (empty($mac)) {
                $response['message'] = 'MAC address required';
                break;
            }
            
            // Check if kiosk already exists
            $stmt = $conn->prepare("SELECT id FROM kiosks WHERE mac = ?");
            $stmt->bind_param("s", $mac);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $kiosk = $result->fetch_assoc();
                $response['success'] = true;
                $response['message'] = 'Kiosk already registered';
                $response['kiosk_id'] = $kiosk['id'];
            } else {
                // Register new kiosk
                $stmt = $conn->prepare("INSERT INTO kiosks (mac, hostname, hw_info, status, last_seen) VALUES (?, ?, ?, 'online', NOW())");
                $stmt->bind_param("sss", $mac, $hostname, $hw_info);
                
                if ($stmt->execute()) {
                    $kiosk_id = $conn->insert_id;
                    $response['success'] = true;
                    $response['message'] = 'Kiosk registered successfully';
                    $response['kiosk_id'] = $kiosk_id;
                } else {
                    $response['message'] = 'Registration failed';
                }
            }
            $stmt->close();
            break;
            
        case 'sync':
            // Sync kiosk status
            $mac = $data['mac'] ?? '';
            $hostname = $data['hostname'] ?? '';
            $hw_info = json_encode($data['hw_info'] ?? []);
            
            if (empty($mac)) {
                $response['message'] = 'MAC address required';
                break;
            }
            
            // Update kiosk status
            $stmt = $conn->prepare("UPDATE kiosks SET hostname = ?, hw_info = ?, status = 'online', last_seen = NOW() WHERE mac = ?");
            $stmt->bind_param("sss", $hostname, $hw_info, $mac);
            $stmt->execute();
            
            // Get kiosk data
            $stmt = $conn->prepare("SELECT id, sync_interval, screenshot_requested FROM kiosks WHERE mac = ?");
            $stmt->bind_param("s", $mac);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $kiosk = $result->fetch_assoc();
                $response['success'] = true;
                $response['kiosk_id'] = $kiosk['id'];
                $response['sync_interval'] = $kiosk['sync_interval'];
                $response['screenshot_requested'] = (bool)$kiosk['screenshot_requested'];
                
                // Log sync
                $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'sync', ?)");
                $details = json_encode(['hostname' => $hostname, 'timestamp' => date('Y-m-d H:i:s')]);
                $log_stmt->bind_param("is", $kiosk['id'], $details);
                $log_stmt->execute();
                $log_stmt->close();
            } else {
                $response['message'] = 'Kiosk not found. Please register first.';
            }
            $stmt->close();
            break;
            
        case 'screenshot':
            // Upload screenshot
            $mac = $data['mac'] ?? '';
            $screenshot_data = $data['screenshot'] ?? '';
            
            if (empty($mac) || empty($screenshot_data)) {
                $response['message'] = 'MAC address and screenshot data required';
                break;
            }
            
            // Save screenshot
            $filename = 'screenshot_' . md5($mac . time()) . '.png';
            $filepath = 'screenshots/' . $filename;
            
            // Create screenshots directory if it doesn't exist
            if (!is_dir('screenshots')) {
                mkdir('screenshots', 0755, true);
            }
            
            // Decode and save base64 image
            $image_data = base64_decode(preg_replace('#^data:image/\w+;base64,#i', '', $screenshot_data));
            file_put_contents($filepath, $image_data);
            
            // Update kiosk
            $stmt = $conn->prepare("UPDATE kiosks SET screenshot_url = ?, screenshot_requested = 0 WHERE mac = ?");
            $stmt->bind_param("ss", $filepath, $mac);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Screenshot uploaded successfully';
            } else {
                $response['message'] = 'Screenshot upload failed';
            }
            $stmt->close();
            break;
            
        case 'heartbeat':
            // Simple heartbeat to update last_seen
            $mac = $data['mac'] ?? '';
            
            if (empty($mac)) {
                $response['message'] = 'MAC address required';
                break;
            }
            
            $stmt = $conn->prepare("UPDATE kiosks SET last_seen = NOW(), status = 'online' WHERE mac = ?");
            $stmt->bind_param("s", $mac);
            
            if ($stmt->execute()) {
                $response['success'] = true;
                $response['message'] = 'Heartbeat recorded';
            }
            $stmt->close();
            break;
            
        default:
            $response['message'] = 'Invalid action';
            break;
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error';
    error_log($e->getMessage());
}

echo json_encode($response);
?>
