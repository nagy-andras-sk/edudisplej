<?php
/**
 * Get All Kiosks Health Status
 * GET /api/health/list.php
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../auth.php';
require_once __DIR__ . '/../../kiosk_status.php';

$api_company = validate_api_token();

try {
    $conn = getDbConnection();
    
    // Get optional filters
    $company_id = intval($_GET['company_id'] ?? 0);
    $status_filter = $_GET['status'] ?? '';

    // Non-admins are restricted to their own company
    if (!api_is_admin_session($api_company)) {
        $company_id = (int)$api_company['id'];
    }
    
    // Build query
    $query = "
        SELECT 
            k.id, k.device_id, k.name, k.status, k.last_sync, k.last_seen, k.last_heartbeat, k.upgrade_started_at,
            k.company_id, c.name as company_name,
            h.status as health_status, h.system_data, h.services_data, h.network_data, h.sync_data, h.timestamp
        FROM kiosks k
        LEFT JOIN companies c ON k.company_id = c.id
        LEFT JOIN kiosk_health h ON k.id = h.kiosk_id AND h.timestamp = (
            SELECT MAX(timestamp) FROM kiosk_health WHERE kiosk_id = k.id
        )
        WHERE 1=1
    ";
    
    $params = [];
    $types = "";
    
    if ($company_id > 0) {
        $query .= " AND k.company_id = ?";
        $params[] = $company_id;
        $types .= "i";
    }
    
    $query .= " ORDER BY k.name ASC";
    
    $stmt = $conn->prepare($query);
    
    if (!empty($params)) {
        $stmt->bind_param($types, ...$params);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    $kiosks = [];
    while ($row = $result->fetch_assoc()) {
        kiosk_apply_effective_status($row);

        if (!empty($status_filter) && $row['status'] !== $status_filter) {
            continue;
        }

        $kiosks[] = [
            'id' => $row['id'],
            'device_id' => $row['device_id'],
            'name' => $row['name'],
            'status' => $row['status'],
            'company_id' => $row['company_id'],
            'company_name' => $row['company_name'],
            'health' => [
                'status' => $row['health_status'],
                'system' => json_decode($row['system_data'] ?? '{}', true),
                'services' => json_decode($row['services_data'] ?? '{}', true),
                'network' => json_decode($row['network_data'] ?? '{}', true),
                'sync' => json_decode($row['sync_data'] ?? '{}', true),
                'timestamp' => $row['timestamp']
            ]
        ];
    }
    
    // Statistics
    $total = count($kiosks);
    $healthy = array_sum(array_map(fn($k) => $k['status'] === 'online' ? 1 : 0, $kiosks));
    $warning = array_sum(array_map(fn($k) => $k['status'] === 'warning' ? 1 : 0, $kiosks));
    $offline = array_sum(array_map(fn($k) => $k['status'] === 'offline' ? 1 : 0, $kiosks));
    
    echo json_encode([
        'success' => true,
        'statistics' => [
            'total' => $total,
            'online' => $healthy,
            'warning' => $warning,
            'offline' => $offline
        ],
        'kiosks' => $kiosks
    ]);
    
} catch (Exception $e) {
    http_response_code(400);
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
