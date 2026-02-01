<?php
/**
 * Kiosk Company Assignment API
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
    $company_id = $data['company_id'] === null ? null : intval($data['company_id']);
    
    if ($kiosk_id <= 0) {
        $response['message'] = 'Invalid kiosk ID';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Update kiosk company assignment
    if ($company_id === null) {
        $stmt = $conn->prepare("UPDATE kiosks SET company_id = NULL WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
    } else {
        $stmt = $conn->prepare("UPDATE kiosks SET company_id = ? WHERE id = ?");
        $stmt->bind_param("ii", $company_id, $kiosk_id);
    }
    
    if ($stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Kiosk assignment updated successfully';
        
        // Log the assignment change
        $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'company_assignment', ?)");
        $details = json_encode(['company_id' => $company_id, 'updated_by' => $_SESSION['username']]);
        $log_stmt->bind_param("is", $kiosk_id, $details);
        $log_stmt->execute();
        $log_stmt->close();
    } else {
        $response['message'] = 'Failed to update kiosk assignment';
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error';
    error_log($e->getMessage());
}

echo json_encode($response);
?>

