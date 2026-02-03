<?php
/**
 * Remote Command Execution API
 * POST /api/kiosk/execute_command.php
 * Queues and executes commands on remote kiosk
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
    
    // Get command data
    $data = json_decode(file_get_contents('php://input'), true);
    
    $kiosk_id = intval($data['kiosk_id'] ?? 0);
    $command = $data['command'] ?? '';
    $command_type = $data['command_type'] ?? 'custom';  // custom, reboot, restart_service
    
    if ($kiosk_id <= 0) {
        throw new Exception('Invalid kiosk_id');
    }
    
    if (empty($command)) {
        throw new Exception('Command cannot be empty');
    }
    
    // Security: Validate command type for predefined commands
    $allowed_types = ['custom', 'reboot', 'restart_service', 'enable_fast_loop', 'disable_fast_loop'];
    if (!in_array($command_type, $allowed_types)) {
        throw new Exception('Invalid command type');
    }
    
    // Security: Restrict dangerous commands for custom type
    if ($command_type === 'custom') {
        $dangerous_patterns = [
            '/rm\s+-rf/',
            '/dd\s+if=/',
            '/mkfs/',
            '/\(\s*\)\s*\|/',  // Command substitution
            '/`/',  // Backticks
            '/\$\(/',  // Command substitution
        ];
        
        foreach ($dangerous_patterns as $pattern) {
            if (preg_match($pattern, $command)) {
                throw new Exception('Command contains potentially dangerous operations');
            }
        }
    }
    
    // Verify kiosk exists
    $stmt = $conn->prepare("SELECT id, device_id, status, company_id FROM kiosks WHERE id = ?");
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
    
    // Create command queue entry
    $status = 'pending';
    $executed_at = null;
    $output = null;
    $error = null;
    
    $stmt = $conn->prepare("
        INSERT INTO kiosk_command_queue (kiosk_id, command_type, command, status, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("isss", $kiosk_id, $command_type, $command, $status);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to queue command');
    }
    
    $command_id = $stmt->insert_id;
    
    // Log command execution request
    $log_stmt = $conn->prepare("
        INSERT INTO kiosk_command_logs (kiosk_id, command_id, action, details)
        VALUES (?, ?, 'queued', ?)
    ");
    $details = json_encode([
        'command_type' => $command_type,
        'command' => substr($command, 0, 200),  // Store first 200 chars
        'queued_by_user_id' => $_SESSION['user_id'] ?? null
    ]);
    $log_stmt->bind_param("iss", $kiosk_id, $command_id, $details);
    $log_stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Command queued successfully',
        'command_id' => $command_id,
        'kiosk_id' => $kiosk_id,
        'status' => 'pending'
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
