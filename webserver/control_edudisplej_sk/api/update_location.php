<?php
/**
 * Update Kiosk Location API
 * EduDisplej Control Panel
 */

session_start();
header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$response = ['success' => false, 'message' => ''];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $kiosk_id = intval($data['kiosk_id'] ?? 0);
    $location = trim($data['location'] ?? '');
    
    if ($kiosk_id <= 0) {
        $response['message'] = 'Invalid kiosk ID';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Update kiosk location
    $stmt = $conn->prepare("UPDATE kiosks SET location = ? WHERE id = ?");
    $stmt->bind_param("si", $location, $kiosk_id);
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Location updated successfully';
        $response['location'] = $location;
    } else {
        $response['message'] = 'Failed to update location';
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error';
    error_log($e->getMessage());
}

echo json_encode($response);
?>

