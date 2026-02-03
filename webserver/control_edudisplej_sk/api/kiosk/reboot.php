<?php
/**
 * Reboot Kiosk
 * POST /api/kiosk/reboot.php
 */

header('Content-Type: application/json');

try {
    $conn = getDbConnection();
    
    $data = json_decode(file_get_contents('php://input'), true);
    
    $kiosk_id = intval($data['kiosk_id'] ?? 0);
    $graceful = $data['graceful'] === true;  // Graceful shutdown before reboot
    $delay = intval($data['delay'] ?? 0);  // Delay in seconds before reboot
    
    if ($kiosk_id <= 0) {
        throw new Exception('Invalid kiosk_id');
    }
    
    // Verify kiosk exists
    $stmt = $conn->prepare("SELECT id, name FROM kiosks WHERE id = ?");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Kiosk not found');
    }
    
    $kiosk = $result->fetch_assoc();
    
    // Build reboot command
    $command = "reboot";
    if ($delay > 0) {
        $command = "shutdown -r +" . intval($delay / 60);  // Convert seconds to minutes
    } else {
        $command = "sudo shutdown -r now";
    }
    
    // Create command
    $command_type = 'reboot';
    $status = 'pending';
    
    $stmt = $conn->prepare("
        INSERT INTO kiosk_command_queue (kiosk_id, command_type, command, status, created_at)
        VALUES (?, ?, ?, ?, NOW())
    ");
    $stmt->bind_param("isss", $kiosk_id, $command_type, $command, $status);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to queue reboot command');
    }
    
    $command_id = $stmt->insert_id;
    
    // Log reboot request
    $log_stmt = $conn->prepare("
        INSERT INTO kiosk_command_logs (kiosk_id, command_id, action, details)
        VALUES (?, ?, 'reboot_requested', ?)
    ");
    $details = json_encode([
        'graceful' => $graceful,
        'delay' => $delay,
        'requested_by_user_id' => $_SESSION['user_id'] ?? null
    ]);
    $log_stmt->bind_param("iss", $kiosk_id, $command_id, $details);
    $log_stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Reboot command queued for ' . $kiosk['name'],
        'command_id' => $command_id,
        'kiosk_id' => $kiosk_id,
        'delay' => $delay
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
