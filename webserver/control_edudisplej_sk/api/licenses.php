<?php
/**
 * API - License Management
 * Actions: save_license, deactivate_device, activate_device
 * Requires admin session.
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../logging.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || empty($_SESSION['isadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    $conn = getDbConnection();

    switch ($action) {
        case 'save_license':
            $company_id   = (int)($_POST['company_id']   ?? 0);
            $valid_from   = trim($_POST['valid_from']    ?? '');
            $valid_until  = trim($_POST['valid_until']   ?? '');
            $device_limit = (int)($_POST['device_limit'] ?? 10);
            $notes        = trim($_POST['notes']         ?? '');
            $status       = trim($_POST['status']        ?? 'active');
            $lid          = (int)($_POST['license_id']   ?? 0);

            if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
                $status = 'active';
            }

            if ($company_id <= 0 || empty($valid_from) || empty($valid_until)) {
                echo json_encode(['success' => false, 'message' => 'company_id, valid_from and valid_until required']);
                break;
            }

            if ($lid > 0) {
                $stmt = $conn->prepare("UPDATE company_licenses SET valid_from=?, valid_until=?, device_limit=?, status=?, notes=? WHERE id=? AND company_id=?");
                $stmt->bind_param("ssissii", $valid_from, $valid_until, $device_limit, $status, $notes, $lid, $company_id);
            } else {
                $stmt = $conn->prepare("INSERT INTO company_licenses (company_id, valid_from, valid_until, device_limit, status, notes) VALUES (?,?,?,?,?,?)");
                $stmt->bind_param("ississ", $company_id, $valid_from, $valid_until, $device_limit, $status, $notes);
            }
            $stmt->execute();
            $stmt->close();

            log_security_event('license_change', $_SESSION['user_id'], $_SESSION['username'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', ['company_id' => $company_id, 'action' => $lid > 0 ? 'update' : 'create']);

            echo json_encode(['success' => true, 'message' => $lid > 0 ? 'License updated' : 'License created']);
            break;

        case 'deactivate_device':
            $kiosk_id = (int)($_POST['kiosk_id'] ?? 0);
            if ($kiosk_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'kiosk_id required']);
                break;
            }

            $stmt = $conn->prepare("UPDATE kiosks SET license_active = 0 WHERE id = ?");
            $stmt->bind_param("i", $kiosk_id);
            $stmt->execute();
            $stmt->close();

            log_security_event('license_change', $_SESSION['user_id'], $_SESSION['username'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', ['kiosk_id' => $kiosk_id, 'action' => 'deactivate']);

            echo json_encode(['success' => true, 'message' => 'Device deactivated']);
            break;

        case 'activate_device':
            $kiosk_id = (int)($_POST['kiosk_id'] ?? 0);
            if ($kiosk_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'kiosk_id required']);
                break;
            }

            // Check slot availability
            $stmt = $conn->prepare("SELECT k.company_id FROM kiosks k WHERE k.id = ?");
            $stmt->bind_param("i", $kiosk_id);
            $stmt->execute();
            $krow = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$krow) {
                echo json_encode(['success' => false, 'message' => 'Device not found']);
                break;
            }

            $cid = (int)$krow['company_id'];
            $stmt = $conn->prepare("SELECT cl.device_limit, (SELECT COUNT(*) FROM kiosks WHERE company_id=? AND license_active=1) AS used_slots FROM company_licenses cl WHERE cl.company_id=? AND cl.status='active' ORDER BY cl.valid_until DESC LIMIT 1");
            $stmt->bind_param("ii", $cid, $cid);
            $stmt->execute();
            $lic = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($lic && (int)$lic['used_slots'] >= (int)$lic['device_limit']) {
                echo json_encode(['success' => false, 'message' => 'No free license slots']);
                break;
            }

            $stmt = $conn->prepare("UPDATE kiosks SET license_active = 1 WHERE id = ?");
            $stmt->bind_param("i", $kiosk_id);
            $stmt->execute();
            $stmt->close();

            log_security_event('license_change', $_SESSION['user_id'], $_SESSION['username'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', ['kiosk_id' => $kiosk_id, 'action' => 'activate']);

            echo json_encode(['success' => true, 'message' => 'Device activated']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }

    closeDbConnection($conn);
} catch (Exception $e) {
    error_log('api/licenses: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
