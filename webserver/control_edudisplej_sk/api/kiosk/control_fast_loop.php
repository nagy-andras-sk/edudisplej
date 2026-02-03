<?php
/**
 * Control Fast Loop Mode
 * POST /api/kiosk/control_fast_loop.php
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
    $enable = $data['enable'] === true;  // Boolean
    
    if ($kiosk_id <= 0) {
        throw new Exception('Invalid kiosk_id');
    }
    
    // Verify kiosk exists
    $stmt = $conn->prepare("SELECT id, company_id FROM kiosks WHERE id = ?");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    if ($result->num_rows === 0) {
        throw new Exception('Kiosk not found');
    }
    $kiosk = $result->fetch_assoc();

    if ($api_company && !api_is_admin_session($api_company)) {
        api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');
    }
    
    // Create command to control fast loop
    $command_type = $enable ? 'enable_fast_loop' : 'disable_fast_loop';
    $command = $enable ? 'touch /opt/edudisplej/.fast_loop_enabled' : 'rm -f /opt/edudisplej/.fast_loop_enabled';
    $status = 'pending';
    
    $stmt = $conn->prepare("
        INSERT INTO kiosk_command_queue (kiosk_id, command_type, command, status, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("isss", $kiosk_id, $command_type, $command, $status);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to queue fast loop command');
    }
    
    $command_id = $stmt->insert_id;
    
    // Log the action
    $log_stmt = $conn->prepare("
        INSERT INTO kiosk_command_logs (kiosk_id, command_id, action, details)
        VALUES (?, ?, 'fast_loop_control', ?)
    ");
    $action = $enable ? 'fast_loop_enabled' : 'fast_loop_disabled';
    $details = json_encode(['enabled' => $enable]);
    $log_stmt->bind_param("iss", $kiosk_id, $command_id, $details);
    $log_stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Fast loop control command queued',
        'command_id' => $command_id,
        'fast_loop_enabled' => $enable
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
