<?php
/**
 * API - Check if Loop/Modules were updated in Kiosk Group
 * Verifies device belongs to company and returns latest updated_at from kiosk_group_modules
 * Used for version checking before module download
 * No session required - uses device_id authentication
 */
require_once '../dbkonfiguracia.php';
require_once 'auth.php';

$api_company = validate_api_token();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    // Get device_id from POST, GET, or JSON body
    $device_id = $_POST['device_id'] ?? $_GET['device_id'] ?? '';
    
    // If not found in POST/GET, try to parse JSON body
    if (empty($device_id)) {
        $json_body = file_get_contents('php://input');
        if (!empty($json_body)) {
            $json_data = json_decode($json_body, true);
            $device_id = $json_data['device_id'] ?? '';
        }
    }
    
    if (empty($device_id)) {
        $response['message'] = 'Missing device_id';
        http_response_code(400);
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Get kiosk by device_id with company verification
    $stmt = $conn->prepare("SELECT k.id, k.device_id, k.company_id, c.name as company_name 
                            FROM kiosks k
                            LEFT JOIN companies c ON k.company_id = c.id
                            WHERE k.device_id = ?");
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Device not found - do not reveal why (security)
        $response['message'] = 'Unauthorized';
        http_response_code(403);
        echo json_encode($response);
        $stmt->close();
        closeDbConnection($conn);
        exit;
    }
    
    $kiosk = $result->fetch_assoc();
    $kiosk_id = $kiosk['id'];
    $company_id = $kiosk['company_id'];
    $company_name = $kiosk['company_name'];
    $stmt->close();

    // Enforce company ownership
    api_require_company_match($api_company, $company_id, 'Unauthorized');
    
    // SECURITY: Verify device belongs to a company
    if (empty($company_id) || $company_id === null) {
        $response['message'] = 'Device not assigned to any company';
        http_response_code(403);
        echo json_encode($response);
        closeDbConnection($conn);
        exit;
    }
    
    // Get kiosk's group assignment
    $stmt = $conn->prepare("SELECT group_id FROM kiosk_group_assignments WHERE kiosk_id = ? LIMIT 1");
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $group_id = null;
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $group_id = $row['group_id'];
    }
    $stmt->close();
    
    // If no group assigned, check kiosk_modules
    if (empty($group_id)) {
        // Check if kiosk has direct module assignments
        $stmt = $conn->prepare("SELECT COUNT(*) as module_count FROM kiosk_modules 
                                WHERE kiosk_id = ? AND is_active = 1");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row['module_count'] == 0) {
            $response['message'] = 'No group or modules assigned';
            http_response_code(400);
            echo json_encode($response);
            closeDbConnection($conn);
            exit;
        }
        
        // Get latest update from kiosk_modules
        $stmt = $conn->prepare("SELECT MAX(created_at) as latest_update
                    FROM kiosk_modules 
                    WHERE kiosk_id = ? AND is_active = 1");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $latest_update = $row['latest_update'] ?? date('Y-m-d H:i:s');
        $stmt->close();
        
        $response['success'] = true;
        $response['kiosk_id'] = $kiosk_id;
        $response['device_id'] = $device_id;
        $response['company_id'] = $company_id;
        $response['company_name'] = $company_name;
        $response['config_source'] = 'kiosk';
        $response['loop_updated_at'] = $latest_update;
        
    } else {
        // SECURITY: Verify group belongs to same company
        $stmt = $conn->prepare("SELECT id, company_id FROM kiosk_groups WHERE id = ?");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $response['message'] = 'Unauthorized';
            http_response_code(403);
            echo json_encode($response);
            $stmt->close();
            closeDbConnection($conn);
            exit;
        }
        
        $group = $result->fetch_assoc();
        $stmt->close();
        
        // Verify group belongs to kiosk's company
        if ($group['company_id'] != $company_id) {
            $response['message'] = 'Unauthorized: Group does not belong to device\'s company';
            http_response_code(403);
            echo json_encode($response);
            closeDbConnection($conn);
            exit;
        }
        
        // Get latest update from kiosk_group_modules
        $stmt = $conn->prepare("SELECT MAX(updated_at) as latest_update, MAX(created_at) as created_at, COUNT(*) as module_count
                                FROM kiosk_group_modules 
                                WHERE group_id = ? AND is_active = 1");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['module_count'] == 0) {
            $response['message'] = 'No active modules in group';
            http_response_code(400);
            echo json_encode($response);
            $stmt->close();
            closeDbConnection($conn);
            exit;
        }
        
        $latest_update = $row['latest_update'] ?? $row['created_at'] ?? date('Y-m-d H:i:s');
        $stmt->close();
        
        $response['success'] = true;
        $response['kiosk_id'] = $kiosk_id;
        $response['device_id'] = $device_id;
        $response['company_id'] = $company_id;
        $response['company_name'] = $company_name;
        $response['group_id'] = $group_id;
        $response['config_source'] = 'group';
        $response['module_count'] = (int)$row['module_count'];
        $response['loop_updated_at'] = $latest_update;
    }
    
    closeDbConnection($conn);
    
    // Use JSON encoding options to prevent output truncation
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('Check Group Loop Update API Error: ' . $e->getMessage());
    $response['message'] = 'Server error';
    http_response_code(500);
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
