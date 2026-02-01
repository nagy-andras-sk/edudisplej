<?php
/**
 * Manage Company Info API
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

$company_id = $_SESSION['company_id'] ?? null;

if (!$company_id) {
    $response['message'] = 'No company assigned';
    echo json_encode($response);
    exit();
}

$action = $_POST['action'] ?? '';

try {
    $conn = getDbConnection();
    
    if ($action === 'update_company') {
        $name = trim($_POST['name'] ?? '');
        $contact = trim($_POST['contact'] ?? '');
        
        if (!$name) {
            $response['message'] = 'Company name is required';
            echo json_encode($response);
            exit();
        }
        
        $update_stmt = $conn->prepare("UPDATE companies SET name = ? WHERE id = ?");
        $update_stmt->bind_param("si", $name, $company_id);
        
        if ($update_stmt->execute()) {
            $response['success'] = true;
            $response['message'] = 'Company info updated successfully';
        } else {
            $response['message'] = 'Failed to update company info';
        }
        $update_stmt->close();
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log($e->getMessage());
}

echo json_encode($response);
?>

