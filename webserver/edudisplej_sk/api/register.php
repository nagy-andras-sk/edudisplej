<?php
/**
 * EduDisplej Device Registration API
 * 
 * This endpoint registers kiosk devices to the central database
 * Expected POST parameters:
 * - hostname: Device hostname
 * - mac: MAC address of the device
 * 
 * Response:
 * - Success: {"success": true, "message": "Device registered", "id": <device_id>}
 * - Error: {"success": false, "message": "Error description"}
 */

header('Content-Type: application/json');

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'edud_server');
define('DB_PASS', '6)mb5Tr[bx56kHih');
define('DB_NAME', 'edudisplej');

// Function to sanitize input
function sanitize_input($data) {
    return htmlspecialchars(strip_tags(trim($data)));
}

// Function to get POST data (supports both form data and JSON)
function get_post_data() {
    $content_type = isset($_SERVER["CONTENT_TYPE"]) ? trim($_SERVER["CONTENT_TYPE"]) : '';
    
    if (stripos($content_type, 'application/json') !== false) {
        $json = file_get_contents('php://input');
        $data = json_decode($json, true);
        return $data ? $data : [];
    }
    
    return $_POST;
}

// Main logic
try {
    // Only accept POST requests
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        throw new Exception('Only POST requests are allowed');
    }
    
    // Get POST data
    $post_data = get_post_data();
    
    // Validate required fields
    if (empty($post_data['hostname'])) {
        throw new Exception('Missing required field: hostname');
    }
    
    if (empty($post_data['mac'])) {
        throw new Exception('Missing required field: mac');
    }
    
    // Sanitize inputs
    $hostname = sanitize_input($post_data['hostname']);
    $mac = sanitize_input($post_data['mac']);
    
    // Validate MAC address format (basic validation)
    if (!preg_match('/^([0-9A-Fa-f]{2}[:-]){5}([0-9A-Fa-f]{2})$/', $mac)) {
        throw new Exception('Invalid MAC address format');
    }
    
    // Connect to database
    $mysqli = new mysqli(DB_HOST, DB_USER, DB_PASS, DB_NAME);
    
    if ($mysqli->connect_error) {
        throw new Exception('Database connection failed: ' . $mysqli->connect_error);
    }
    
    // Set charset to UTF-8
    $mysqli->set_charset('utf8mb4');
    
    // Check if device already exists (by MAC address)
    $stmt = $mysqli->prepare("SELECT id, hostname FROM kiosks WHERE mac = ?");
    $stmt->bind_param("s", $mac);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        // Device already exists - update hostname if changed
        $row = $result->fetch_assoc();
        $device_id = $row['id'];
        $old_hostname = $row['hostname'];
        
        if ($old_hostname !== $hostname) {
            // Update hostname
            $update_stmt = $mysqli->prepare("UPDATE kiosks SET hostname = ? WHERE id = ?");
            $update_stmt->bind_param("si", $hostname, $device_id);
            $update_stmt->execute();
            $update_stmt->close();
            
            $message = 'Device already registered, hostname updated';
        } else {
            $message = 'Device already registered';
        }
    } else {
        // Insert new device
        $insert_stmt = $mysqli->prepare("INSERT INTO kiosks (hostname, mac, installed) VALUES (?, ?, NOW())");
        $insert_stmt->bind_param("ss", $hostname, $mac);
        
        if (!$insert_stmt->execute()) {
            throw new Exception('Failed to register device: ' . $insert_stmt->error);
        }
        
        $device_id = $insert_stmt->insert_id;
        $insert_stmt->close();
        $message = 'Device registered successfully';
    }
    
    $stmt->close();
    $mysqli->close();
    
    // Return success response
    echo json_encode([
        'success' => true,
        'message' => $message,
        'id' => $device_id
    ]);
    
} catch (Exception $e) {
    // Return error response
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
