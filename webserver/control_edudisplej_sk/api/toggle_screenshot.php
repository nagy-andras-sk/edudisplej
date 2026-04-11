<?php
/**
 * Toggle Screenshot Feature API
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once 'auth.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => ''
];

// Check authentication
$api_company = null;
if (!isset($_SESSION['user_id'])) {
    $api_company = validate_api_token();
} else {
    $user_id = $_SESSION['user_id'];
    $company_id = $_SESSION['company_id'] ?? null;
}

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
    
    // Verify kiosk belongs to user's company or token company
    if ($api_company && !api_is_admin_session($api_company)) {
        $stmt = $conn->prepare("SELECT id, company_id, sync_interval FROM kiosks WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
    } else {
        $stmt = $conn->prepare("SELECT id, sync_interval FROM kiosks WHERE id = ? AND company_id = ?");
        $stmt->bind_param("ii", $kiosk_id, $company_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();

    if ($api_company && !api_is_admin_session($api_company)) {
        api_require_company_match($api_company, $row['company_id'] ?? null, 'Unauthorized');
    }
    
    if ($result->num_rows === 0) {
        $response['message'] = 'Kiosk not found or access denied';
        echo json_encode($response);
        exit();
    }
    $stmt->close();
    
    // Update kiosk settings without changing the main sync cadence.
    $stmt = $conn->prepare("UPDATE kiosks SET screenshot_enabled = ? WHERE id = ?");
    $stmt->bind_param("ii", $screenshot_enabled, $kiosk_id);
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Screenshot setting updated successfully';
        $response['screenshot_enabled'] = $screenshot_enabled;
        $response['sync_interval'] = isset($row['sync_interval']) ? (int)$row['sync_interval'] : null;
        
        // Log the change
        error_log("Screenshot " . ($screenshot_enabled ? "enabled" : "disabled") . " for kiosk ID: $kiosk_id");
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
