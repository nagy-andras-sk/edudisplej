<?php
header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';
require_once 'auth.php';

// Validate API authentication for device requests
validate_api_token();

$response = ['success' => false, 'message' => '', 'modules' => []];

try {
    $data = json_decode(file_get_contents('php://input'), true);
    
    $mac = $data['mac'] ?? '';
    $kiosk_id = $data['kiosk_id'] ?? null;
    $last_loop_update = $data['last_loop_update'] ?? null; // Client's last known update timestamp
    
    if (empty($mac) && empty($kiosk_id)) {
        $response['message'] = 'MAC address or kiosk ID required';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Find kiosk
    if ($kiosk_id) {
        if ($kiosk_id <= 0) {
            $response['message'] = 'Invalid kiosk ID';
            echo json_encode($response);
            exit;
        }
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
        $response['needs_update'] = false;
        
        // Log modules sync
        $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'modules_sync', ?)");
        $details = json_encode(['status' => 'unconfigured', 'timestamp' => date('Y-m-d H:i:s')]);
        $log_stmt->bind_param("is", $kiosk['id'], $details);
        $log_stmt->execute();
        $log_stmt->close();
        
        echo json_encode($response);
        exit;
    }
    
    // Check if kiosk belongs to any group and get the latest update timestamp
    $group_query = "SELECT kga.group_id, MAX(kgm.updated_at) as latest_update 
                    FROM kiosk_group_assignments kga
                    JOIN kiosk_group_modules kgm ON kga.group_id = kgm.group_id
                    WHERE kga.kiosk_id = ?
                    GROUP BY kga.group_id
                    LIMIT 1";
    $group_stmt = $conn->prepare($group_query);
    $group_stmt->bind_param("i", $kiosk['id']);
    $group_stmt->execute();
    $group_result = $group_stmt->get_result();
    $group_row = $group_result->fetch_assoc();
    $group_stmt->close();
    
    $server_timestamp = null;
    $needs_update = true;
    
    if ($group_row) {
        $server_timestamp = $group_row['latest_update'];
        
        // Compare timestamps to determine if update is needed
        if ($last_loop_update && $server_timestamp) {
            // Validate timestamp format using DateTime
            try {
                $client_dt = new DateTime($last_loop_update);
                $server_dt = new DateTime($server_timestamp);
                
                $client_time = $client_dt->getTimestamp();
                $server_time = $server_dt->getTimestamp();
                
                // Only send update if server timestamp is newer
                if ($server_time <= $client_time) {
                    $needs_update = false;
                }
            } catch (Exception $e) {
                // Invalid timestamp format, force update to be safe
                error_log('Invalid timestamp in modules_sync: ' . $e->getMessage());
                $needs_update = true;
            }
        }
    }
    
    $response['server_timestamp'] = $server_timestamp;
    $response['needs_update'] = $needs_update;
    
    // If no update needed, return minimal response
    if (!$needs_update) {
        $response['success'] = true;
        $response['message'] = 'No update needed';
        $response['modules'] = []; // Empty array indicates no changes
        
        closeDbConnection($conn);
        echo json_encode($response);
        exit;
    }
    
    // Fetch modules (update needed)
    $modules = [];
    
    if ($group_row) {
        // Get modules from group configuration
        $query = "SELECT m.module_key, m.name, kgm.display_order, kgm.duration_seconds, kgm.settings
                  FROM kiosk_group_modules kgm
                  JOIN modules m ON kgm.module_id = m.id
                  WHERE kgm.group_id = ? AND kgm.is_active = 1
                  ORDER BY kgm.display_order ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $group_row['group_id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
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
        $response['config_source'] = 'group';
        $response['group_id'] = (int)$group_row['group_id'];
    }
    
    // If no group modules found, fall back to kiosk-specific configuration
    if (empty($modules)) {
        $query = "SELECT m.module_key, m.name, km.display_order, km.duration_seconds, km.settings
                  FROM kiosk_modules km
                  JOIN modules m ON km.module_id = m.id
                  WHERE km.kiosk_id = ? AND km.is_active = 1
                  ORDER BY km.display_order ASC";
        
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $kiosk['id']);
        $stmt->execute();
        $result = $stmt->get_result();
        
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
        $response['config_source'] = 'kiosk';
    }
    
    $response['success'] = true;
    $response['message'] = count($modules) > 0 ? 'Modules retrieved' : 'No modules configured';
    $response['modules'] = $modules;
    
    // Log modules sync
    $log_stmt = $conn->prepare("INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'modules_sync', ?)");
    $details = json_encode([
        'module_count' => count($modules), 
        'timestamp' => date('Y-m-d H:i:s'),
        'needs_update' => $needs_update
    ]);
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

