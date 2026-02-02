<?php
/**
 * Toggle Screenshot Feature API
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
    $screenshot_enabled = intval($data['screenshot_enabled'] ?? 0);
    
    if (!$kiosk_id) {
        $response['message'] = 'Kiosk ID is required';
        echo json_encode($response);
        exit();
    }
    
    // Validate boolean value
    $screenshot_enabled = $screenshot_enabled ? 1 : 0;
    
    $conn = getDbConnection();
    
    // Verify kiosk belongs to user's company
    $stmt = $conn->prepare("SELECT id FROM kiosks WHERE id = ? AND company_id = ?");
    $stmt->bind_param("ii", $kiosk_id, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $response['message'] = 'Kiosk not found or access denied';
        echo json_encode($response);
        exit();
    }
    $stmt->close();
    
    // Determine sync_interval based on screenshot_enabled
    // If screenshot enabled: 15 seconds, if disabled: 120 seconds
    $sync_interval = $screenshot_enabled ? 15 : 120;
    
    // Update kiosk settings
    $stmt = $conn->prepare("UPDATE kiosks SET screenshot_enabled = ?, sync_interval = ? WHERE id = ?");
    $stmt->bind_param("iii", $screenshot_enabled, $sync_interval, $kiosk_id);
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Screenshot setting updated successfully';
        $response['screenshot_enabled'] = $screenshot_enabled;
        $response['sync_interval'] = $sync_interval;
        
        // Log the change
        error_log("Screenshot " . ($screenshot_enabled ? "enabled" : "disabled") . " for kiosk ID: $kiosk_id, sync interval set to: {$sync_interval}s");
    } else {
        $response['message'] = 'Failed to update screenshot setting';
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error: ' . $e->getMessage();
    error_log('Screenshot toggle error: ' . $e->getMessage());
}

echo json_encode($response);
?>
