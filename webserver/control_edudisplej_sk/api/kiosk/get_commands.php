<?php
/**
 * Get Pending Commands API - For Kiosk
 * GET /api/kiosk/get_commands.php
 * Called by kiosk to retrieve pending commands to execute
 */

header('Content-Type: application/json');

try {
    // This must be called by a kiosk with valid API token
    $auth = validate_api_token();
    if (!$auth['valid']) {
        throw new Exception('Invalid API token');
    }
    
    $kiosk_id = $auth['kiosk_id'];
    $conn = getDbConnection();
    
    // Get pending commands for this kiosk
    $stmt = $conn->prepare("
        SELECT id, command_id, command_type, command, created_at
        FROM kiosk_command_queue
        WHERE kiosk_id = ? AND status = 'pending'
        ORDER BY created_at ASC
        LIMIT 10
    ");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $commands = [];
    while ($row = $result->fetch_assoc()) {
        $commands[] = [
            'id' => $row['id'],
            'command_id' => $row['command_id'],
            'type' => $row['command_type'],
            'command' => $row['command'],
            'created_at' => $row['created_at']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'kiosk_id' => $kiosk_id,
        'commands' => $commands,
        'count' => count($commands)
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
