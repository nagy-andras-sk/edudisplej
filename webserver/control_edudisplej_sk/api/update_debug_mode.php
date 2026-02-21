<?php
/**
 * Update kiosk debug mode (per kiosk)
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
$debug_mode = !empty($data['debug_mode']) ? 1 : 0;
$company_id = $_SESSION['company_id'] ?? null;

if (!$kiosk_id) {
    $response['message'] = 'Invalid kiosk ID';
    echo json_encode($response);
    exit();
}

try {
    $conn = getDbConnection();

    $check_col = $conn->query("SHOW COLUMNS FROM kiosks LIKE 'debug_mode'");
    if (!$check_col || $check_col->num_rows === 0) {
        $conn->query("ALTER TABLE kiosks ADD COLUMN debug_mode TINYINT(1) NOT NULL DEFAULT 0");
    }

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

    $stmt = $conn->prepare("UPDATE kiosks SET debug_mode = ? WHERE id = ?");
    $stmt->bind_param("ii", $debug_mode, $kiosk_id);
    $stmt->execute();
    $stmt->close();

    $response['success'] = true;
    $response['message'] = 'Debug mode updated';
    $response['debug_mode'] = (bool)$debug_mode;

    closeDbConnection($conn);
} catch (Exception $e) {
    $response['message'] = 'Database error';
    error_log('update_debug_mode: ' . $e->getMessage());
}

echo json_encode($response);
?>
