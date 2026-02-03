<?php
/**
 * Update kiosk sync interval
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once 'auth.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $api_company = validate_api_token();
} else {
    $api_company = ['is_admin' => !empty($_SESSION['isadmin']), 'id' => $_SESSION['company_id'] ?? null];
}

$data = json_decode(file_get_contents('php://input'), true);
$kiosk_id = intval($data['kiosk_id'] ?? 0);
$sync_interval = intval($data['sync_interval'] ?? 0);
$company_id = $_SESSION['company_id'] ?? null;

$allowed = [10, 120, 300, 600];
if (!$kiosk_id || !in_array($sync_interval, $allowed, true)) {
    $response['message'] = 'Invalid kiosk ID or sync interval';
    echo json_encode($response);
    exit();
}

try {
    $conn = getDbConnection();
    
    if ($api_company && !api_is_admin_session($api_company)) {
        $stmt = $conn->prepare("SELECT id, company_id FROM kiosks WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
    } else {
        $stmt = $conn->prepare("SELECT id FROM kiosks WHERE id = ? AND company_id = ?");
        $stmt->bind_param("ii", $kiosk_id, $company_id);
    }
    $stmt->execute();
    $result = $stmt->get_result();
    if ($api_company && !api_is_admin_session($api_company)) {
        $row = $result->fetch_assoc();
        api_require_company_match($api_company, $row['company_id'] ?? null, 'Unauthorized');
        $result->data_seek(0);
    }
    $stmt->close();
    
    if ($result->num_rows === 0) {
        $response['message'] = 'Kiosk not found or access denied';
        echo json_encode($response);
        exit();
    }
    
    $stmt = $conn->prepare("UPDATE kiosks SET sync_interval = ? WHERE id = ?");
    $stmt->bind_param("ii", $sync_interval, $kiosk_id);
    $stmt->execute();
    $stmt->close();
    
    $response['success'] = true;
    $response['message'] = 'Sync interval updated';
    
    closeDbConnection($conn);
} catch (Exception $e) {
    $response['message'] = 'Database error';
    error_log($e->getMessage());
}

echo json_encode($response);
?>
