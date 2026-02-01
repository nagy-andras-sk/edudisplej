<?php
/**
 * Assign Kiosk to Group API
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

$kiosk_id = intval($_GET['kiosk_id'] ?? 0);
$group_id = intval($_GET['group_id'] ?? 0);
$company_id = $_SESSION['company_id'] ?? null;

if (!$kiosk_id || !$group_id) {
    $response['message'] = 'Invalid parameters';
    echo json_encode($response);
    exit();
}

try {
    $conn = getDbConnection();
    
    // Verify group belongs to user's company
    $verify_stmt = $conn->prepare("SELECT id FROM kiosk_groups WHERE id = ? AND company_id = ?");
    $verify_stmt->bind_param("ii", $group_id, $company_id);
    $verify_stmt->execute();
    if ($verify_stmt->get_result()->num_rows === 0) {
        $verify_stmt->close();
        $response['message'] = 'Group not found or access denied';
        echo json_encode($response);
        exit();
    }
    $verify_stmt->close();
    
    // Remove from all other groups for this kiosk
    $delete_stmt = $conn->prepare("DELETE FROM kiosk_group_assignments WHERE kiosk_id = ?");
    $delete_stmt->bind_param("i", $kiosk_id);
    $delete_stmt->execute();
    $delete_stmt->close();
    
    // Assign to new group
    $assign_stmt = $conn->prepare("INSERT IGNORE INTO kiosk_group_assignments (kiosk_id, group_id) VALUES (?, ?)");
    $assign_stmt->bind_param("ii", $kiosk_id, $group_id);
    
    if ($assign_stmt->execute()) {
        $response['success'] = true;
        $response['message'] = 'Kiosk assigned to group successfully';
    } else {
        $response['message'] = 'Failed to assign kiosk';
    }
    $assign_stmt->close();
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log($e->getMessage());
}

echo json_encode($response);
?>

