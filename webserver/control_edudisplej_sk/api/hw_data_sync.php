<?php
/**
 * Hardware Data Sync API
 * EduDisplej Control Panel
 */

header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';
require_once 'auth.php';

// Validate API authentication for device requests
$api_company = validate_api_token();

$response = ['success' => false, 'message' => ''];

try {
    // Get request data
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mac = $data['mac'] ?? '';
    $hostname = $data['hostname'] ?? '';
    $hw_info = json_encode($data['hw_info'] ?? []);
    $public_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $client_last_update = $data['last_update'] ?? null; // Timestamp from kiosk's loop.json
    
    // New technical info fields
    $version = $data['version'] ?? null;
    $screen_resolution = $data['screen_resolution'] ?? null;
    $screen_status = $data['screen_status'] ?? null;
    
    if (empty($mac)) {
        $response['message'] = 'MAC address required';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Update kiosk hardware data, public IP and technical info
    $stmt = $conn->prepare("UPDATE kiosks SET hostname = ?, hw_info = ?, public_ip = ?, version = ?, screen_resolution = ?, screen_status = ?, status = 'online', last_seen = NOW() WHERE mac = ?");
    $stmt->bind_param("sssssss", $hostname, $hw_info, $public_ip, $version, $screen_resolution, $screen_status, $mac);
    $stmt->execute();
    
    // Get kiosk data with company information
    $stmt = $conn->prepare("
        SELECT k.id, k.device_id, k.sync_interval, k.screenshot_requested, 
               COALESCE(k.screenshot_enabled, 0) as screenshot_enabled,
               k.company_id, c.name as company_name
        FROM kiosks k
        LEFT JOIN companies c ON k.company_id = c.id
        WHERE k.mac = ?
    ");
    $stmt->bind_param("s", $mac);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows > 0) {
        $kiosk = $result->fetch_assoc();

        // Enforce company ownership
        if (!empty($kiosk['company_id'])) {
            api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');
        } elseif (!empty($api_company['id']) && !api_is_admin_session($api_company)) {
            $assign_stmt = $conn->prepare("UPDATE kiosks SET company_id = ? WHERE id = ?");
            $assign_stmt->bind_param("ii", $api_company['id'], $kiosk['id']);
            $assign_stmt->execute();
            $assign_stmt->close();
            $kiosk['company_id'] = $api_company['id'];
        }
        $response['success'] = true;
        $response['kiosk_id'] = $kiosk['id'];
        $response['device_id'] = $kiosk['device_id'];
        $response['sync_interval'] = $kiosk['sync_interval'];
        $response['screenshot_requested'] = (bool)$kiosk['screenshot_requested'];
        $response['screenshot_enabled'] = (bool)$kiosk['screenshot_enabled'];
        
        // Add company information for config.json
        $response['company_id'] = $kiosk['company_id'];
        $response['company_name'] = $kiosk['company_name'] ?? '';
        
        // Add token if exists (for future use)
        if ($kiosk['company_id']) {
            $token_stmt = $conn->prepare("SELECT api_token FROM companies WHERE id = ?");
            $token_stmt->bind_param("i", $kiosk['company_id']);
            $token_stmt->execute();
            $token_result = $token_stmt->get_result();
            if ($token_row = $token_result->fetch_assoc()) {
                $response['token'] = $token_row['api_token'] ?? '';
            }
            $token_stmt->close();
        }
        
        // Check if configuration has been updated on server side
        $need_update = false;
        $update_reason = '';
        
        // Check if kiosk belongs to any group
        $group_stmt = $conn->prepare("SELECT group_id FROM kiosk_group_assignments WHERE kiosk_id = ? LIMIT 1");
        $group_stmt->bind_param("i", $kiosk['id']);
        $group_stmt->execute();
        $group_result = $group_stmt->get_result();
        $group_row = $group_result->fetch_assoc();
        $group_stmt->close();
        
        if ($group_row) {
            // Get latest update time from kiosk_group_modules
            // Try with updated_at first, fall back to checking if records exist
            $update_stmt = $conn->prepare("SELECT MAX(updated_at) as last_server_update, COUNT(*) as config_count FROM kiosk_group_modules WHERE group_id = ?");
            $update_stmt->bind_param("i", $group_row['group_id']);
            $update_stmt->execute();
            $update_result = $update_stmt->get_result();
            $update_row = $update_result->fetch_assoc();
            $update_stmt->close();
            
            if ($update_row && $update_row['config_count'] > 0) {
                // If updated_at is available, use it for comparison
                if ($update_row['last_server_update']) {
                    $server_timestamp = strtotime($update_row['last_server_update']);
                    
                    // Compare with client's last_update timestamp if provided
                    if ($client_last_update) {
                        $client_timestamp = is_numeric($client_last_update) ? $client_last_update : strtotime($client_last_update);
                        
                        if ($server_timestamp > $client_timestamp) {
                            $need_update = true;
                            $update_reason = 'Group configuration updated';
                        }
                    } else {
                        // No client timestamp provided, suggest update
                        $need_update = true;
                        $update_reason = 'No client timestamp provided';
                    }
                } else if (!$client_last_update) {
                    // updated_at column doesn't exist yet, but config exists and client has no timestamp
                    $need_update = true;
                    $update_reason = 'Initial configuration sync required';
                }
            }
        }
        
        // Add update flag to response
        $response['needs_update'] = $need_update;
        if ($need_update) {
            $response['update_reason'] = $update_reason;
            $response['update_action'] = 'restart'; // Signal to restart browser/processes
        }
        
        // Log sync
        $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'hw_data_sync', ?)");
        $details = json_encode([
            'hostname' => $hostname,
            'public_ip' => $public_ip,
            'timestamp' => date('Y-m-d H:i:s'),
            'needs_update' => $need_update,
            'update_reason' => $update_reason
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

