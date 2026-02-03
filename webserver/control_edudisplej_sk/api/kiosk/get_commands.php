<?php
/**
 * Get Pending Commands API - For Kiosk
 * GET /api/kiosk/get_commands.php
 * Called by kiosk to retrieve pending commands to execute
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../auth.php';

try {
    // Validate token
    $api_company = validate_api_token();

    $device_id = $_GET['device_id'] ?? '';
    $kiosk_id = intval($_GET['kiosk_id'] ?? 0);

    if (empty($device_id) && $kiosk_id <= 0) {
        throw new Exception('Missing device_id or kiosk_id');
    }

    $conn = getDbConnection();

    // Resolve kiosk by device_id or id and enforce company ownership
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
