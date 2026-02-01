<?php
/**
 * Get Groups API
 * Returns groups for the logged-in user's company
 */

session_start();
require_once '../dbkonfiguracia.php';

header('Content-Type: application/json');

$response = [];

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode($response);
    exit();
}

$company_id = $_SESSION['company_id'] ?? null;

if (!$company_id) {
    echo json_encode($response);
    exit();
}

try {
    $conn = getDbConnection();
    
    $stmt = $conn->prepare("SELECT id, name FROM kiosk_groups WHERE company_id = ? ORDER BY name");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $response[] = $row;
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
} catch (Exception $e) {
    error_log($e->getMessage());
}

echo json_encode($response);
?>

