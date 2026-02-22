<?php
/**
 * Screenshot Request API
 * POST /api/screenshot_request.php
 *
 * Called by the control panel dashboard when a user opens the realtime
 * screenshot view for a kiosk.  Sets / extends the TTL window that tells
 * the kiosk to send screenshots actively.
 *
 * Authentication: session (dashboard) or Bearer token
 *
 * Request body (JSON):
 *   { "kiosk_id": 42, "ttl_seconds": 60 }   – default TTL is 60 s
 *   { "kiosk_id": 42, "action": "stop" }     – immediately clear the request
 *
 * Response:
 *   { "success": true, "screenshot_requested_until": "2025-01-01 12:00:00" }
 */

session_start();
header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';
require_once 'auth.php';

$response = ['success' => false, 'message' => ''];

// Accept either session or token auth
$api_company = null;
$session_company_id = null;
$is_admin = false;

if (!empty($_SESSION['user_id'])) {
    $session_company_id = $_SESSION['company_id'] ?? null;
    $is_admin = !empty($_SESSION['isadmin']);
} else {
    $api_company = validate_api_token();
}

try {
    $data = json_decode(file_get_contents('php://input'), true) ?? [];
    $kiosk_id = intval($data['kiosk_id'] ?? 0);
    $action    = $data['action'] ?? 'request';  // 'request' | 'stop'
    $ttl       = max(5, min(300, intval($data['ttl_seconds'] ?? 60)));

    if ($kiosk_id <= 0) {
        $response['message'] = t_def('api.screenshot_request.kiosk_id_required', 'kiosk_id required');
        echo json_encode($response);
        exit;
    }

    $conn = getDbConnection();

    // Verify ownership
    $stmt = $conn->prepare("SELECT id, company_id FROM kiosks WHERE id = ?");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $kiosk = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$kiosk) {
        $response['message'] = t_def('api.common.kiosk_not_found', 'Kiosk not found');
        echo json_encode($response);
        $conn->close();
        exit;
    }

    // Enforce company ownership
    if ($api_company) {
        api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');
    } elseif (!empty($session_company_id) && empty($is_admin)) {
        if ((int)$session_company_id !== (int)$kiosk['company_id']) {
            http_response_code(403);
            $response['message'] = t_def('api.common.unauthorized', 'Unauthorized');
            echo json_encode($response);
            $conn->close();
            exit;
        }
    }

    if ($action === 'stop') {
        // Clear the request immediately
        $upd = $conn->prepare(
            "UPDATE kiosks SET screenshot_requested_until = NULL WHERE id = ?"
        );
        $upd->bind_param("i", $kiosk_id);
        $upd->execute();
        $upd->close();

        $response['success'] = true;
        $response['screenshot_requested_until'] = null;
        $response['message'] = t_def('api.screenshot_request.cleared', 'Screenshot request cleared');
    } else {
        // Set / extend TTL
        $upd = $conn->prepare(
            "UPDATE kiosks
                SET screenshot_requested_until = DATE_ADD(NOW(), INTERVAL ? SECOND)
              WHERE id = ?"
        );
        $upd->bind_param("ii", $ttl, $kiosk_id);
        $upd->execute();
        $upd->close();

        // Fetch the exact value we just stored
        $sel = $conn->prepare(
            "SELECT screenshot_requested_until FROM kiosks WHERE id = ?"
        );
        $sel->bind_param("i", $kiosk_id);
        $sel->execute();
        $row = $sel->get_result()->fetch_assoc();
        $sel->close();

        $response['success'] = true;
        $response['screenshot_requested_until'] = $row['screenshot_requested_until'];
        $response['ttl_seconds'] = $ttl;
    }

    $conn->close();

} catch (Exception $e) {
    $response['message'] = t_def('api.common.server_error', 'Server error');
    error_log('screenshot_request.php: ' . $e->getMessage());
}

echo json_encode($response);
