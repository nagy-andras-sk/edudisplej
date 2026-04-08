<?php
/**
 * Update kiosk screen off mode (per kiosk)
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';
require_once 'auth.php';

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

if (!isset($_SESSION['user_id'])) {
    $api_company = validate_api_token();
} else {
    $api_company = ['is_admin' => !empty($_SESSION['isadmin']), 'id' => $_SESSION['company_id'] ?? null];
}

$company_id = (int)($_SESSION['company_id'] ?? 0);
$is_super_admin_session = isset($_SESSION['isadmin']) && $_SESSION['isadmin'] && empty($_SESSION['admin_acting_company_id']);

$data = json_decode(file_get_contents('php://input'), true);
$kiosk_id = intval($data['kiosk_id'] ?? 0);
$screen_off_mode = strtolower(trim((string)($data['screen_off_mode'] ?? 'signal_off')));
$allowed_modes = ['signal_off', 'black_screen'];

if (!$kiosk_id) {
    $response['message'] = t_def('api.common.invalid_kiosk_id', 'Invalid kiosk ID');
    echo json_encode($response);
    exit();
}

if (!in_array($screen_off_mode, $allowed_modes, true)) {
    $response['message'] = 'Invalid screen off mode';
    echo json_encode($response);
    exit();
}

try {
    $conn = getDbConnection();

    $check_col = $conn->query("SHOW COLUMNS FROM kiosks LIKE 'screen_off_mode'");
    if (!$check_col || $check_col->num_rows === 0) {
        $conn->query("ALTER TABLE kiosks ADD COLUMN screen_off_mode VARCHAR(20) NOT NULL DEFAULT 'signal_off'");
    }

    if ($api_company && !api_is_admin_session($api_company)) {
        $stmt = $conn->prepare("SELECT id, company_id FROM kiosks WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
    } elseif ($is_super_admin_session) {
        $stmt = $conn->prepare("SELECT id FROM kiosks WHERE id = ?");
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
        $response['message'] = t_def('api.common.kiosk_not_found_or_denied', 'Kiosk not found or access denied');
        echo json_encode($response);
        exit();
    }

    $stmt = $conn->prepare("UPDATE kiosks SET screen_off_mode = ? WHERE id = ?");
    $stmt->bind_param("si", $screen_off_mode, $kiosk_id);
    $stmt->execute();
    $stmt->close();

    $response['success'] = true;
    $response['message'] = 'Screen off mode updated';
    $response['screen_off_mode'] = $screen_off_mode;

    closeDbConnection($conn);
} catch (Exception $e) {
    $response['message'] = t_def('api.common.database_error', 'Database error');
    error_log('update_screen_off_mode: ' . $e->getMessage());
}

echo json_encode($response);
?>