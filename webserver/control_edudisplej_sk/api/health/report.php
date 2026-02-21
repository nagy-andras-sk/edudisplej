<?php
/**
 * Health Report Endpoint - Receives health status from Kiosks
 * POST /api/health/report.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../auth.php';

$api_company = validate_api_token();

try {
    $conn = getDbConnection();
    
    // Get JSON data from kiosk
    $input = file_get_contents('php://input');
    $data = json_decode($input, true);
    
    if (!$data) {
        throw new Exception('Invalid JSON data');
    }
    
    // Extract kiosk identification
    $device_id_raw = trim((string)($data['kiosk']['device_id'] ?? ''));
    $device_id = in_array(strtolower($device_id_raw), ['', 'null', 'unknown'], true) ? null : $device_id_raw;

    $kiosk_id_raw = $data['kiosk']['kiosk_id'] ?? null;
    $kiosk_id = (is_numeric($kiosk_id_raw) && (int)$kiosk_id_raw > 0) ? (int)$kiosk_id_raw : null;

    $mac_raw = trim((string)($data['kiosk']['mac'] ?? ($data['mac'] ?? '')));
    $mac = in_array(strtolower($mac_raw), ['', 'null', 'unknown'], true) ? null : $mac_raw;

    $hostname_raw = trim((string)($data['kiosk']['hostname'] ?? ($data['hostname'] ?? '')));
    $hostname = in_array(strtolower($hostname_raw), ['', 'null', 'unknown'], true) ? null : $hostname_raw;

    $timestamp = $data['timestamp'] ?? date('Y-m-d H:i:s');
    
    if (!$device_id && !$kiosk_id && !$mac && !$hostname) {
        throw new Exception('Missing kiosk identification');
    }
    
    // Find kiosk by strongest identifiers first, then fallback
    $kiosk = null;

    if ($kiosk_id) {
        $stmt = $conn->prepare("SELECT id, company_id FROM kiosks WHERE id = ? LIMIT 1");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $kiosk = $result->fetch_assoc();
        }
        $stmt->close();
    }

    if (!$kiosk && $device_id) {
        $stmt = $conn->prepare("SELECT id, company_id FROM kiosks WHERE device_id = ? LIMIT 1");
        $stmt->bind_param("s", $device_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $kiosk = $result->fetch_assoc();
        }
        $stmt->close();
    }

    if (!$kiosk && $mac) {
        $stmt = $conn->prepare("SELECT id, company_id FROM kiosks WHERE REPLACE(LOWER(mac), ':', '') = REPLACE(LOWER(?), ':', '') LIMIT 1");
        $stmt->bind_param("s", $mac);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $kiosk = $result->fetch_assoc();
        }
        $stmt->close();
    }

    if (!$kiosk && $hostname) {
        $stmt = $conn->prepare("SELECT id, company_id FROM kiosks WHERE hostname = ? LIMIT 1");
        $stmt->bind_param("s", $hostname);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $kiosk = $result->fetch_assoc();
        }
        $stmt->close();
    }

    if (!$kiosk) {
        throw new Exception('Kiosk not found');
    }

    $kiosk_id = $kiosk['id'];

    api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');
    
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
        $stmt->bind_param("isssss", $kiosk_id, $overall_status, $system_data, $services_data, $network_data, $sync_data);
    
    if (!$stmt->execute()) {
        throw new Exception('Failed to store health data');
    }
    
    // Update kiosk status (online/offline/warning)
    $new_status = match($overall_status) {
        'healthy' => 'online',
        'warning' => 'warning',
        'critical' => 'offline',
            default => 'pending'
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
