<?php
/**
 * Report Command Execution Status - Kiosk sends results
 * POST /api/kiosk/command_result.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../auth.php';

try {
    // This must be called by a kiosk with valid API token
    $api_company = validate_api_token();
    $conn = getDbConnection();
    
    // Get result data
    $data = json_decode(file_get_contents('php://input'), true);
    
    $command_id = intval($data['command_id'] ?? 0);
    $device_id = $data['device_id'] ?? '';
    $kiosk_id = intval($data['kiosk_id'] ?? 0);
    $status = $data['status'] ?? '';  // executed, failed, timeout
    $output = $data['output'] ?? '';
    $error = $data['error'] ?? '';
    
    if ($command_id <= 0) {
        throw new Exception('Invalid command_id');
    }
    
    if (!in_array($status, ['executed', 'failed', 'timeout'])) {
        throw new Exception('Invalid status');
    }
    
    // Resolve kiosk by device_id or kiosk_id and enforce company ownership
    if (empty($device_id) && $kiosk_id <= 0) {
        throw new Exception('Missing device_id or kiosk_id');
    }

    $lookup = $conn->prepare("SELECT id, company_id FROM kiosks WHERE device_id = ? OR id = ? LIMIT 1");
    $lookup->bind_param("si", $device_id, $kiosk_id);
    $lookup->execute();
    $lookup_result = $lookup->get_result();
    if ($lookup_result->num_rows === 0) {
        throw new Exception('Kiosk not found');
    }
    $kiosk = $lookup_result->fetch_assoc();
    $lookup->close();

    api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');
    $kiosk_id = (int)$kiosk['id'];

    // Verify command belongs to this kiosk
    $stmt = $conn->prepare("
        SELECT id FROM kiosk_command_queue 
        WHERE id = ? AND kiosk_id = ?
    ");
    $stmt->bind_param("ii", $command_id, $kiosk_id);
    $stmt->execute();
    
    if ($stmt->get_result()->num_rows === 0) {
        throw new Exception('Command not found or does not belong to this kiosk');
    }
    
    // Update command status
    $executed_at = date('Y-m-d H:i:s');
    $stmt = $conn->prepare("
        UPDATE kiosk_command_queue
        SET status = ?, output = ?, error = ?, executed_at = ?
        WHERE id = ?
    ");
    $stmt->bind_param("ssssi", $status, $output, $error, $executed_at, $command_id);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to update command status');
    }
    
    // Log command result
    $log_stmt = $conn->prepare("
        INSERT INTO kiosk_command_logs (kiosk_id, command_id, action, details)
        VALUES (?, ?, ?, ?)
    ");
    $details = json_encode([
        'status' => $status,
        'output_length' => strlen($output),
        'error_length' => strlen($error)
    ]);
    $log_stmt->bind_param("isss", $kiosk_id, $command_id, $status, $details);
    $log_stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Command result recorded',
        'command_id' => $command_id
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
