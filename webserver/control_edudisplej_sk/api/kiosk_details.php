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
    
    // Get kiosk data
    $stmt = $conn->prepare("SELECT k.* FROM kiosks k WHERE k.id = ? AND k.company_id = ?");
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

