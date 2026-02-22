<?php
/**
 * Update Kiosk Location API
 * EduDisplej Control Panel
 */

session_start();
header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';
require_once 'auth.php';

// Check if user is logged in and is admin, otherwise require token
$api_company = null;
if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    $api_company = validate_api_token();
}

$response = ['success' => false, 'message' => ''];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $kiosk_id = intval($data['kiosk_id'] ?? 0);
    $location = trim($data['location'] ?? '');
    
    if ($kiosk_id <= 0) {
        $response['message'] = t_def('api.common.invalid_kiosk_id', 'Invalid kiosk ID');
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Enforce company ownership if using token
    if ($api_company && !api_is_admin_session($api_company)) {
        $check_stmt = $conn->prepare("SELECT company_id FROM kiosks WHERE id = ?");
        $check_stmt->bind_param("i", $kiosk_id);
        $check_stmt->execute();
        $check_result = $check_stmt->get_result();
        $check_row = $check_result->fetch_assoc();
        $check_stmt->close();
        api_require_company_match($api_company, $check_row['company_id'] ?? null, 'Unauthorized');
    }

    // Update kiosk location
    $stmt = $conn->prepare("UPDATE kiosks SET location = ? WHERE id = ?");
    $stmt->bind_param("si", $location, $kiosk_id);
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = t_def('api.update_location.success', 'Location updated successfully');
        $response['location'] = $location;
    } else {
        $response['message'] = t_def('api.update_location.failed', 'Failed to update location');
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = t_def('api.common.server_error', 'Server error');
    error_log($e->getMessage());
}

echo json_encode($response);
?>

