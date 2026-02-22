<?php
/**
 * Assign Kiosk to Group API
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => ''
];

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    $response['message'] = t_def('api.common.not_authenticated', 'Not authenticated');
    echo json_encode($response);
    exit();
}

$kiosk_id = intval($_GET['kiosk_id'] ?? 0);
$group_id = intval($_GET['group_id'] ?? 0);
$user_id = (int)($_SESSION['user_id'] ?? 0);
$company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : null;

if (!$kiosk_id || !$group_id) {
    $response['message'] = t_def('api.common.invalid_parameters', 'Invalid parameters');
    echo json_encode($response);
    exit();
}

try {
    $conn = getDbConnection();

    if (!$company_id && $user_id > 0) {
        $company_stmt = $conn->prepare("SELECT company_id FROM users WHERE id = ? LIMIT 1");
        $company_stmt->bind_param("i", $user_id);
        $company_stmt->execute();
        $company_result = $company_stmt->get_result()->fetch_assoc();
        $company_stmt->close();

        if ($company_result && isset($company_result['company_id'])) {
            $company_id = (int)$company_result['company_id'];
        }
    }

    if (!$company_id) {
        $response['message'] = t_def('api.common.company_context_not_found', 'Company context not found');
        echo json_encode($response);
        exit();
    }
    
    // Verify group belongs to user's company
    $verify_stmt = $conn->prepare("SELECT id FROM kiosk_groups WHERE id = ? AND company_id = ?");
    $verify_stmt->bind_param("ii", $group_id, $company_id);
    $verify_stmt->execute();
    if ($verify_stmt->get_result()->num_rows === 0) {
        $verify_stmt->close();
        $response['message'] = t_def('api.assign_kiosk_group.group_not_found_or_denied', 'Group not found or access denied');
        echo json_encode($response);
        exit();
    }
    $verify_stmt->close();

    // Verify kiosk belongs to user's company
    $kiosk_verify_stmt = $conn->prepare("SELECT id FROM kiosks WHERE id = ? AND company_id = ?");
    $kiosk_verify_stmt->bind_param("ii", $kiosk_id, $company_id);
    $kiosk_verify_stmt->execute();
    if ($kiosk_verify_stmt->get_result()->num_rows === 0) {
        $kiosk_verify_stmt->close();
        $response['message'] = t_def('api.assign_kiosk_group.kiosk_not_found_or_denied', 'Kiosk not found or access denied');
        echo json_encode($response);
        exit();
    }
    $kiosk_verify_stmt->close();
    
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
        $response['message'] = t_def('api.assign_kiosk_group.success', 'Kiosk assigned to group successfully');
    } else {
        $response['message'] = t_def('api.assign_kiosk_group.failed', 'Failed to assign kiosk');
    }
    $assign_stmt->close();
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = t_def('api.common.database_error', 'Database error') . ': ' . $e->getMessage();
    error_log($e->getMessage());
}

echo json_encode($response);
?>

