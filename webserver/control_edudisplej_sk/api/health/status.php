<?php
/**
 * Get Kiosk Health Status - Returns latest health data for kiosk
 * GET /api/health/status.php?kiosk_id=1
 */

header('Content-Type: application/json');

try {
    $conn = getDbConnection();
    
    // Get kiosk_id from query parameters
    $kiosk_id = intval($_GET['kiosk_id'] ?? 0);
    
    if ($kiosk_id <= 0) {
        throw new Exception('Invalid kiosk_id');
    }
    
    // Verify kiosk exists
    $stmt = $conn->prepare("SELECT id, device_id, status FROM kiosks WHERE id = ?");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Kiosk not found');
    }
    
    $kiosk = $result->fetch_assoc();
    
    // Get latest health data
    $stmt = $conn->prepare("
        SELECT status, system_data, services_data, network_data, sync_data, timestamp
        FROM kiosk_health
        WHERE kiosk_id = ?
        ORDER BY timestamp DESC
        LIMIT 1
    ");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        echo json_encode([
            'success' => false,
            'message' => 'No health data available for this kiosk',
            'kiosk_id' => $kiosk_id
        ]);
        exit();
    }
    
    $health = $result->fetch_assoc();
    
    echo json_encode([
        'success' => true,
        'kiosk_id' => $kiosk_id,
        'device_id' => $kiosk['device_id'],
        'status' => $health['status'],
        'kiosk_status' => $kiosk['status'],
        'system' => json_decode($health['system_data'], true),
        'services' => json_decode($health['services_data'], true),
        'network' => json_decode($health['network_data'], true),
        'sync' => json_decode($health['sync_data'], true),
        'timestamp' => $health['timestamp']
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
