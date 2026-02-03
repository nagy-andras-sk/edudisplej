<?php
/**
 * Get Command Execution Result - For Admin Panel
 * GET /api/kiosk/get_command_result.php?command_id=1
 */

header('Content-Type: application/json');

try {
    $conn = getDbConnection();
    
    $command_id = intval($_GET['command_id'] ?? 0);
    
    if ($command_id <= 0) {
        throw new Exception('Invalid command_id');
    }
    
    // Get command details
    $stmt = $conn->prepare("
        SELECT 
            kcq.id, kcq.kiosk_id, kcq.command_type, kcq.command, 
            kcq.status, kcq.output, kcq.error, 
            kcq.created_at, kcq.executed_at,
            k.name as kiosk_name, k.device_id
        FROM kiosk_command_queue kcq
        JOIN kiosks k ON kcq.kiosk_id = k.id
        WHERE kcq.id = ?
    ");
    $stmt->bind_param("i", $command_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Command not found');
    }
    
    $command = $result->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'command' => [
            'id' => $command['id'],
            'kiosk_id' => $command['kiosk_id'],
            'kiosk_name' => $command['kiosk_name'],
            'device_id' => $command['device_id'],
            'type' => $command['command_type'],
            'command' => $command['command'],
            'status' => $command['status'],
            'output' => $command['output'],
            'error' => $command['error'],
            'created_at' => $command['created_at'],
            'executed_at' => $command['executed_at']
        ]
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
