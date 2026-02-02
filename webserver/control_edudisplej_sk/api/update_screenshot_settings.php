<?php
/**
 * Update Kiosk Screenshot Settings API
 * Allows customizable sync intervals for screenshot feature
 * EduDisplej Control Panel
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

$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $kiosk_id = intval($data['kiosk_id'] ?? 0);
    
    if (!$kiosk_id) {
        $response['message'] = 'Kiosk ID is required';
        echo json_encode($response);
        exit();
    }
    
    $conn = getDbConnection();
    
    // Verify kiosk belongs to user's company
    $stmt = $conn->prepare("SELECT id, screenshot_enabled FROM kiosks WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $kiosk_id, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $kiosk = $result->fetch_assoc();
    
    if (!$kiosk) {
        $response['message'] = 'Kiosk not found or access denied';
        echo json_encode($response);
        exit();
    }
    $stmt->close();
    
    // Handle screenshot toggle
    if (isset($data['screenshot_enabled'])) {
        $screenshot_enabled = intval($data['screenshot_enabled']) ? 1 : 0;
        $stmt = $conn->prepare("UPDATE kiosks SET screenshot_enabled = ? WHERE id = ?");
        $stmt->bind_param("ii", $screenshot_enabled, $kiosk_id);
        $stmt->execute();
        $stmt->close();
        
        $response['screenshot_enabled'] = $screenshot_enabled;
    }
    
    // Handle custom sync interval for screenshot
    if (isset($data['screenshot_interval'])) {
        $screenshot_interval = intval($data['screenshot_interval']);
        
        // Validate interval (between 5 and 600 seconds)
        if ($screenshot_interval < 5 || $screenshot_interval > 600) {
            $response['message'] = 'Screenshot interval must be between 5 and 600 seconds';
            echo json_encode($response);
            exit();
        }
        
        $stmt = $conn->prepare("UPDATE kiosks SET sync_interval = ? WHERE id = ?");
        $stmt->bind_param("ii", $screenshot_interval, $kiosk_id);
        $stmt->execute();
        $stmt->close();
        
        $response['screenshot_interval'] = $screenshot_interval;
    }
    
    $response['success'] = true;
    $response['message'] = 'Settings updated successfully';
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    error_log('Screenshot settings error: ' . $e->getMessage());
}

echo json_encode($response);
?>
