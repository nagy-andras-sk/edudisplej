<?php
header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';

$response = ['success' => false, 'message' => '', 'modules' => []];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mac = $data['mac'] ?? '';
    $kiosk_id = $data['kiosk_id'] ?? null;
    
    if (empty($mac) && empty($kiosk_id)) {
        $response['message'] = 'MAC address or kiosk ID required';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Find kiosk
    if ($kiosk_id) {
        $stmt = $conn->prepare("SELECT id, is_configured, company_id, device_id, sync_interval FROM kiosks WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
    } else {
        $stmt = $conn->prepare("SELECT id, is_configured, company_id, device_id, sync_interval FROM kiosks WHERE mac = ?");
        $stmt->bind_param("s", $mac);
    }
    
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows == 0) {
        $response['message'] = 'Kiosk not found';
        echo json_encode($response);
        exit;
    }
    
    $kiosk = $result->fetch_assoc();
    $stmt->close();
    
    // Update last_seen
    $update_stmt = $conn->prepare("UPDATE kiosks SET last_seen = NOW() WHERE id = ?");
    $update_stmt->bind_param("i", $kiosk['id']);
    $update_stmt->execute();
    $update_stmt->close();
    
    $response['kiosk_id'] = $kiosk['id'];
    $response['device_id'] = $kiosk['device_id'];
    $response['sync_interval'] = (int)$kiosk['sync_interval'];
    $response['is_configured'] = (bool)$kiosk['is_configured'];
    
    // If not configured, return unconfigured module
    if (!$kiosk['is_configured']) {
        $response['success'] = true;
        $response['message'] = 'Kiosk not configured';
        $response['modules'] = [
            [
                'module_key' => 'unconfigured',
                'display_order' => 0,
                'duration_seconds' => 300,
                'settings' => []
            ]
        ];
        
        // Log modules sync
        $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'modules_sync', ?)");
        $details = json_encode(['status' => 'unconfigured', 'timestamp' => date('Y-m-d H:i:s')]);
        $log_stmt->bind_param("is", $kiosk['id'], $details);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode($response);
        exit;
    }
    
    // Get active modules for this kiosk
    $query = "SELECT m.module_key, m.name, km.display_order, km.duration_seconds, km.settings
              FROM kiosk_modules km
              JOIN modules m ON km.module_id = m.id
              WHERE km.kiosk_id = ? AND km.is_active = 1
              ORDER BY km.display_order ASC";
    
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $kiosk['id']);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $modules = [];
    while ($row = $result->fetch_assoc()) {
        $settings = [];
        if (!empty($row['settings'])) {
            $settings = json_decode($row['settings'], true) ?? [];
        }
        
        $modules[] = [
            'module_key' => $row['module_key'],
            'name' => $row['name'],
            'display_order' => (int)$row['display_order'],
            'duration_seconds' => (int)$row['duration_seconds'],
            'settings' => $settings
        ];
    }
    
    $stmt->close();
    $response['success'] = true;
    $response['message'] = count($modules) > 0 ? 'Modules retrieved' : 'No modules configured';
    $response['modules'] = $modules;
    
    // Log modules sync
    $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'modules_sync', ?)");
    $details = json_encode(['module_count' => count($modules), 'timestamp' => date('Y-m-d H:i:s')]);
    $log_stmt->bind_param("is", $kiosk['id'], $details);
    $log_stmt->execute();
    $log_stmt->close();
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Server error';
    error_log($e->getMessage());
}

echo json_encode($response);
?>
