<?php
/**
 * Device Registration API
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
    
    // Check if kiosk already exists
    $stmt = $conn->prepare("SELECT id, device_id FROM kiosks WHERE mac = ?");
    $stmt->bind_param("s", $mac);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $kiosk = $result->fetch_assoc();
        $response['success'] = true;
        $response['message'] = 'Kiosk already registered';
        $response['kiosk_id'] = $kiosk['id'];
        $response['device_id'] = $kiosk['device_id'];
    } else {
        // Generate special device ID: 4 random chars + 6 from MAC (min 10 chars)
        $device_id = generateDeviceId($mac);
        
        // Register new kiosk
        $stmt = $conn->prepare("INSERT INTO kiosks (mac, device_id, hostname, hw_info, public_ip, status, last_seen) VALUES (?, ?, ?, ?, ?, 'online', NOW())");
        $stmt->bind_param("sssss", $mac, $device_id, $hostname, $hw_info, $public_ip);
        
        if ($stmt->execute()) {
            $kiosk_id = $conn->insert_id;
            $response['success'] = true;
            $response['message'] = 'Kiosk registered successfully';
            $response['kiosk_id'] = $kiosk_id;
            $response['device_id'] = $device_id;
        } else {
            $response['message'] = 'Registration failed';
        }
    }
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error';
    error_log($e->getMessage());
}

echo json_encode($response);

/**
 * Generate special device ID: 4 random chars + 6 from MAC
 * @param string $mac MAC address
 * @return string Generated device ID
 */
function generateDeviceId($mac) {
    // Generate 4 cryptographically secure random alphanumeric characters
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $random_chars = '';
    $random_bytes = random_bytes(4);
    for ($i = 0; $i < 4; $i++) {
        $random_chars .= $chars[ord($random_bytes[$i]) % strlen($chars)];
    }
    
    // Extract 6 characters from MAC address (remove colons and take first 6)
    $mac_clean = str_replace([':', '-'], '', strtoupper($mac));
    $mac_chars = substr($mac_clean, 0, 6);
    
    // Combine to create device ID (min 10 chars)
    return $random_chars . $mac_chars;
}
?>
