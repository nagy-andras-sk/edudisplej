<?php
/**
 * Kiosk Details API
 * Returns detailed information about a kiosk
 */

session_start();
require_once '../dbkonfiguracia.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => ''
];

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    $response['message'] = 'Not authenticated';
    echo json_encode($response);
    exit();
}

// Handle bulk refresh for dashboard
if (isset($_GET['refresh_list'])) {
    $kiosk_ids = array_map('intval', explode(',', $_GET['refresh_list']));
    $company_id = $_SESSION['company_id'] ?? null;
    
    try {
        $conn = getDbConnection();
        $placeholders = implode(',', array_fill(0, count($kiosk_ids), '?'));
        
        $query = "
            SELECT k.id, k.hostname, k.last_seen, k.status, k.screenshot_url, k.screenshot_timestamp,
                   GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') as group_names,
                   k.version, k.screen_resolution, k.screen_status,
                   k.last_sync, k.loop_last_update
            FROM kiosks k 
            LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
            LEFT JOIN kiosk_groups g ON kga.group_id = g.id
            WHERE k.id IN ($placeholders) AND k.company_id = ?
            GROUP BY k.id
        ";
        
        $stmt = $conn->prepare($query);
        $types = str_repeat('i', count($kiosk_ids)) . 'i';
        $params = array_merge($kiosk_ids, [$company_id]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $kiosks = [];
        while ($row = $result->fetch_assoc()) {
            $kiosks[] = $row;
        }
        $stmt->close();
        closeDbConnection($conn);
        
        echo json_encode([
            'success' => true,
            'kiosks' => $kiosks
        ]);
        exit();
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        exit();
    }
}

$kiosk_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;

if (!$kiosk_id) {
    $response['message'] = 'Kiosk ID is required';
    echo json_encode($response);
    exit();
}

try {
    $conn = getDbConnection();
    
    // Get kiosk data with group information
    $stmt = $conn->prepare("
        SELECT k.*, 
               GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') as group_names,
               GROUP_CONCAT(DISTINCT g.id SEPARATOR ',') as group_ids
        FROM kiosks k 
        LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
        LEFT JOIN kiosk_groups g ON kga.group_id = g.id
        WHERE k.id = ? AND k.company_id = ?
        GROUP BY k.id
    ");
    $stmt->bind_param("ii", $kiosk_id, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $kiosk = $result->fetch_assoc();
    $stmt->close();
    
    if (!$kiosk) {
        $response['message'] = 'Kiosk not found or access denied';
        echo json_encode($response);
        exit();
    }
    
    $response['success'] = true;
    $response['id'] = $kiosk['id'];
    $response['hostname'] = $kiosk['hostname'];
    $response['mac'] = $kiosk['mac'];
    $response['status'] = $kiosk['status'];
    $response['location'] = $kiosk['location'];
    $response['last_seen'] = $kiosk['last_seen'] ? date('Y-m-d H:i', strtotime($kiosk['last_seen'])) : 'Never';
    $response['sync_interval'] = (int)$kiosk['sync_interval'];
    $response['screenshot_enabled'] = (bool)$kiosk['screenshot_enabled'];
    $response['screenshot_url'] = $kiosk['screenshot_url'] ?? null;
    $response['screenshot_timestamp'] = $kiosk['screenshot_timestamp'] ? date('Y-m-d H:i:s', strtotime($kiosk['screenshot_timestamp'])) : null;
    
    // Add group information
    $response['group_names'] = $kiosk['group_names'] ?? null;
    $response['group_ids'] = $kiosk['group_ids'] ?? null;
    
    // Add technical information
    $response['version'] = $kiosk['version'] ?? null;
    $response['screen_resolution'] = $kiosk['screen_resolution'] ?? null;
    $response['screen_status'] = $kiosk['screen_status'] ?? null;
    
    // Add sync timing information
    $response['last_sync'] = $kiosk['last_sync'] ? date('Y-m-d H:i:s', strtotime($kiosk['last_sync'])) : null;
    $response['loop_last_update'] = $kiosk['loop_last_update'] ? date('Y-m-d H:i:s', strtotime($kiosk['loop_last_update'])) : null;
    
    // Parse HW info
    if ($kiosk['hw_info']) {
        try {
            $hw_data = json_decode($kiosk['hw_info'], true);
            $response['hw_info'] = $hw_data;
        } catch (Exception $e) {
            $response['hw_info'] = null;
        }
    }
    
    // Get assigned modules
    $mod_query = "SELECT m.name FROM modules m 
                  INNER JOIN kiosk_modules km ON m.id = km.module_id 
                  WHERE km.kiosk_id = ? 
                  ORDER BY km.display_order";
    $mod_stmt = $conn->prepare($mod_query);
    $mod_stmt->bind_param("i", $kiosk_id);
    $mod_stmt->execute();
    $mod_result = $mod_stmt->get_result();
    
    $modules = [];
    while ($mod = $mod_result->fetch_assoc()) {
        $modules[] = $mod['name'];
    }
    $mod_stmt->close();
    
    $response['modules'] = $modules;
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log($e->getMessage());
}

echo json_encode($response);
?>

