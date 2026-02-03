<?php
/**
 * API - Get Kiosk Loop Configuration by Device ID
 * Returns loop config and module list for download
 * No session required - uses device_id authentication
 */
require_once '../dbkonfiguracia.php';
require_once 'auth.php';

$api_company = validate_api_token();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    // Get device_id from POST or GET
    $device_id = $_POST['device_id'] ?? $_GET['device_id'] ?? '';
    
    if (empty($device_id)) {
        $response['message'] = 'Missing device_id';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Get kiosk by device_id
    $stmt = $conn->prepare("SELECT id, device_id, company_id FROM kiosks WHERE device_id = ?");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $response['message'] = 'Kiosk not found';
        echo json_encode($response);
        exit;
    }
    
    $kiosk = $result->fetch_assoc();
    $kiosk_id = $kiosk['id'];
    $company_id = $kiosk['company_id'];
    $stmt->close();

    // Enforce company ownership
    api_require_company_match($api_company, $company_id, 'Unauthorized');
    
    // Get kiosk's loop configuration
    // First check if kiosk has specific modules assigned
    $stmt = $conn->prepare("
        SELECT km.*, m.name as module_name, m.module_key
        FROM kiosk_modules km
        JOIN modules m ON km.module_id = m.id
        WHERE km.kiosk_id = ? AND km.is_active = 1
        ORDER BY km.display_order
    ");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $loop_config = [];
    $config_source = 'kiosk';
    $group_id = null;
    while ($row = $result->fetch_assoc()) {
        $loop_config[] = [
            'module_id' => (int)$row['module_id'],
            'module_name' => $row['module_name'],
            'module_key' => $row['module_key'],
            'duration_seconds' => (int)$row['duration_seconds'],
            'display_order' => (int)$row['display_order'],
            'settings' => $row['settings'] ? json_decode($row['settings'], true) : (object)[],
            'source' => 'kiosk'
        ];
    }
    $stmt->close();
    
    // If no specific modules, get from group(s)
    if (empty($loop_config)) {
        $config_source = 'group';
        $stmt = $conn->prepare("
            SELECT kgm.*, m.name as module_name, m.module_key, kga.group_id
            FROM kiosk_group_assignments kga
            JOIN kiosk_group_modules kgm ON kga.group_id = kgm.group_id
            JOIN modules m ON kgm.module_id = m.id
            WHERE kga.kiosk_id = ?
            ORDER BY kgm.display_order
        ");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            if ($group_id === null) {
                $group_id = (int)$row['group_id'];
            }
            $loop_config[] = [
                'module_id' => (int)$row['module_id'],
                'module_name' => $row['module_name'],
                'module_key' => $row['module_key'],
                'duration_seconds' => (int)$row['duration_seconds'],
                'display_order' => (int)$row['display_order'],
                'settings' => $row['settings'] ? json_decode($row['settings'], true) : (object)[],
                'source' => 'group'
            ];
        }
        $stmt->close();

        if ($group_id) {
            api_require_group_company($conn, $api_company, $group_id);
        }
    }
    
    // Determine loop last update based on source
    // Use MAX of created_at to detect module changes
    $loop_last_update = null;
    if ($config_source === 'kiosk') {
        $stmt = $conn->prepare("SELECT MAX(created_at) as last_update 
                                FROM kiosk_modules 
                                WHERE kiosk_id = ? AND is_active = 1");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $loop_last_update = $row['last_update'] ?? null;
        $stmt->close();
    } else if ($group_id) {
        $stmt = $conn->prepare("SELECT MAX(created_at) as last_update 
                                FROM kiosk_group_modules 
                                WHERE group_id = ? AND is_active = 1");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $loop_last_update = $row['last_update'] ?? null;
        $stmt->close();
    }
    if (!$loop_last_update) {
        $loop_last_update = date('Y-m-d H:i:s');
    }
    
    closeDbConnection($conn);
    
    $response['success'] = true;
    $response['kiosk_id'] = $kiosk_id;
    $response['device_id'] = $device_id;
    $response['loop_config'] = $loop_config;
    $response['module_count'] = count($loop_config);
    $response['loop_last_update'] = $loop_last_update;
    
    // Use JSON encoding options to prevent output truncation
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('Kiosk Loop API Error: ' . $e->getMessage());
    $response['message'] = 'Server error: ' . $e->getMessage();
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
