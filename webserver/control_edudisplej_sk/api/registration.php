<?php
// Enhanced error reporting for debugging
ini_set('display_errors', 0);  // Don't display in response
ini_set('log_errors', 1);
error_reporting(E_ALL);

header('Content-Type: application/json');

// Log function
function log_debug($message) {
    error_log("[Registration API] " . $message);
}

try {
    require_once '../dbkonfiguracia.php';
    
    $response = ['success' => false, 'message' => ''];
    
    // Get request data
    $raw_input = file_get_contents('php://input');
    log_debug("Received request: " . $raw_input);
    
    $data = json_decode($raw_input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $response['message'] = 'Invalid JSON: ' . json_last_error_msg();
        log_debug("JSON parse error: " . json_last_error_msg());
        echo json_encode($response);
        exit;
    }
    
    $mac = $data['mac'] ?? '';
    $hostname = $data['hostname'] ?? '';
    $hw_info = json_encode($data['hw_info'] ?? []);
    $public_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    log_debug("MAC: $mac, Hostname: $hostname, IP: $public_ip");
    
    if (empty($mac)) {
        $response['message'] = 'MAC address required';
        log_debug("Error: MAC address missing");
        echo json_encode($response);
        exit;
    }
    
    // Database connection
    try {
        $conn = getDbConnection();
        log_debug("Database connection successful");
    } catch (Exception $e) {
        $response['message'] = 'Database connection failed: ' . $e->getMessage();
        log_debug("DB connection error: " . $e->getMessage());
        echo json_encode($response);
        exit;
    }
    
    // Check if kiosk already exists
    $stmt = $conn->prepare("SELECT id, device_id, is_configured, company_id FROM kiosks WHERE mac = ?");
    
    if (!$stmt) {
        $response['message'] = 'Database query preparation failed: ' . $conn->error;
        log_debug("Query prep error: " . $conn->error);
        echo json_encode($response);
        exit;
    }
    
    $stmt->bind_param("s", $mac);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $kiosk = $result->fetch_assoc();
        log_debug("Existing kiosk found: ID=" . $kiosk['id']);
        
        // Update last_seen
        $update_stmt = $conn->prepare("UPDATE kiosks SET last_seen = NOW(), public_ip = ?, hw_info = ?, hostname = ?, status = 'online' WHERE id = ?");
        
        if (!$update_stmt) {
            $response['message'] = 'Update query preparation failed: ' . $conn->error;
            log_debug("Update prep error: " . $conn->error);
            echo json_encode($response);
            exit;
        }
        
        $update_stmt->bind_param("sssi", $public_ip, $hw_info, $hostname, $kiosk['id']);
        
        if (!$update_stmt->execute()) {
            $response['message'] = 'Update execution failed: ' . $update_stmt->error;
            log_debug("Update exec error: " . $update_stmt->error);
            echo json_encode($response);
            exit;
        }
        
        $update_stmt->close();
        
        $response['success'] = true;
        $response['message'] = 'Kiosk already registered';
        $response['kiosk_id'] = $kiosk['id'];
        $response['device_id'] = $kiosk['device_id'];
        $response['is_configured'] = (bool)$kiosk['is_configured'];
        $response['company_assigned'] = !empty($kiosk['company_id']);
        
        log_debug("Kiosk updated successfully");
    } else {
        // Generate special device ID: 4 random chars + 6 from MAC (min 10 chars)
        $device_id = generateDeviceId($mac);
        log_debug("New kiosk - registering...");
        log_debug("Generated device ID: $device_id");
        
        // Register new kiosk
        $stmt = $conn->prepare("INSERT INTO kiosks (mac, device_id, hostname, hw_info, public_ip, status, is_configured, last_seen) VALUES (?, ?, ?, ?, ?, 'unconfigured', 0, NOW())");
        
        if (!$stmt) {
            $response['message'] = 'Insert query preparation failed: ' . $conn->error;
            log_debug("Insert prep error: " . $conn->error);
            echo json_encode($response);
            exit;
        }
        
        $stmt->bind_param("sssss", $mac, $device_id, $hostname, $hw_info, $public_ip);
        
        if ($stmt->execute()) {
            $kiosk_id = $conn->insert_id;
            log_debug("New kiosk registered with ID: $kiosk_id");
            
            $response['success'] = true;
            $response['message'] = 'Kiosk registered successfully';
            $response['kiosk_id'] = $kiosk_id;
            $response['device_id'] = $device_id;
            $response['is_configured'] = false;
            $response['company_assigned'] = false;
        } else {
            $response['message'] = 'Registration failed: ' . $stmt->error;
            log_debug("Insert exec error: " . $stmt->error);
        }
    }
    $stmt->close();
    closeDbConnection($conn);
    
    log_debug("Response: " . json_encode($response));
    
} catch (Exception $e) {
    $response['message'] = 'Server exception: ' . $e->getMessage();
    $response['trace'] = $e->getTraceAsString();
    log_debug("Exception: " . $e->getMessage());
    log_debug("Trace: " . $e->getTraceAsString());
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
