<?php
/**
 * Queue Core Update Command
 * POST /api/kiosk/queue_core_update.php
 * Queues a core-only system update for a kiosk.
 */

session_start();
header('Content-Type: application/json');
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../auth.php';

try {
    $api_company = null;
    if (!isset($_SESSION['user_id']) || empty($_SESSION['isadmin'])) {
        $api_company = validate_api_token();
    }

    $conn = getDbConnection();

    $data = json_decode(file_get_contents('php://input'), true);
    $kiosk_id = intval($data['kiosk_id'] ?? 0);

    if ($kiosk_id <= 0) {
        throw new Exception('Invalid kiosk_id');
    }

    $stmt = $conn->prepare("SELECT id, company_id FROM kiosks WHERE id = ?");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception('Kiosk not found');
    }
    $kiosk = $result->fetch_assoc();
    $stmt->close();

    if ($api_company && !api_is_admin_session($api_company)) {
        api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');
    }

    $command_type = 'core_update';
    $command = '';
    $status = 'pending';

    $stmt = $conn->prepare("\n        INSERT INTO kiosk_command_queue (kiosk_id, command_type, command, status, created_at)\n        VALUES (?, ?, ?, ?, NOW())\n    ");
    $stmt->bind_param("isss", $kiosk_id, $command_type, $command, $status);

    if (!$stmt->execute()) {
        throw new Exception('Failed to queue core update command');
    }

    $command_id = $stmt->insert_id;
    $stmt->close();

    $log_stmt = $conn->prepare("\n        INSERT INTO kiosk_command_logs (kiosk_id, command_id, action, details)\n        VALUES (?, ?, 'core_update_queued', ?)\n    ");
    $details = json_encode([
        'requested_by_user_id' => $_SESSION['user_id'] ?? null,
        'queued_at' => date('Y-m-d H:i:s')
    ]);
    $log_stmt->bind_param("iss", $kiosk_id, $command_id, $details);
    $log_stmt->execute();
    $log_stmt->close();

    closeDbConnection($conn);

    echo json_encode([
        'success' => true,
        'message' => 'Core update command queued',
        'command_id' => $command_id,
        'kiosk_id' => $kiosk_id
    ]);
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>