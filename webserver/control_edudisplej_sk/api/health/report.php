<?php
/**
 * Health Report Endpoint - Receives health status from Kiosks
 * POST /api/health/report.php
 */

header('Content-Type: application/json');

try {
    $conn = getDbConnection();
    
    // Get JSON data from kiosk
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        throw new Exception('Invalid JSON data');
    }
    
    // Extract kiosk identification
    $device_id = $data['kiosk']['device_id'] ?? null;
    $kiosk_id = $data['kiosk']['kiosk_id'] ?? null;
    $timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');
    
    if (!$device_id && !$kiosk_id) {
        throw new Exception('Missing kiosk identification');
    }
    
    // Find kiosk by device_id or kiosk_id
    $stmt = $conn->prepare("SELECT id FROM kiosks WHERE device_id = ? OR id = ? LIMIT 1");
    $stmt->bind_param("si", $device_id, $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        throw new Exception('Kiosk not found');
    }
    
    $kiosk = $result->fetch_assoc();
    $kiosk_id = $kiosk['id'];
    
    // Determine overall status
    $overall_status = $data['status'] ?? 'unknown';
    $system_data = json_encode($data['system'] ?? []);
    $services_data = json_encode($data['services'] ?? []);
    $network_data = json_encode($data['network'] ?? []);
    $sync_data = json_encode($data['sync'] ?? []);
    
    // Create or update kiosk health record
    $stmt = $conn->prepare("
        INSERT INTO kiosk_health (kiosk_id, status, system_data, services_data, network_data, sync_data, timestamp)
        VALUES (?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE
        status = VALUES(status),
        system_data = VALUES(system_data),
        services_data = VALUES(services_data),
        network_data = VALUES(network_data),
        sync_data = VALUES(sync_data),
        timestamp = NOW()
    ");
    $stmt->bind_param("issss", $kiosk_id, $overall_status, $system_data, $services_data, $network_data, $sync_data);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to store health data');
    }
    
    // Update kiosk status (online/offline/warning)
    $new_status = match($overall_status) {
        'healthy' => 'online',
        'warning' => 'warning',
        'critical' => 'offline',
        default => 'unknown'
    };
    
    $stmt = $conn->prepare("UPDATE kiosks SET status = ?, last_heartbeat = NOW() WHERE id = ?");
    $stmt->bind_param("si", $new_status, $kiosk_id);
    $stmt->execute();
    
    // Log the health report
    $log_stmt = $conn->prepare("
        INSERT INTO kiosk_health_logs (kiosk_id, status, details)
        VALUES (?, ?, ?)
    ");
    $details = json_encode([
        'system' => $data['system'] ?? [],
        'services' => $data['services'] ?? [],
        'network' => $data['network'] ?? []
    ]);
    $log_stmt->bind_param("iss", $kiosk_id, $overall_status, $details);
    $log_stmt->execute();
    
    echo json_encode([
        'success' => true,
        'message' => 'Health status recorded',
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
