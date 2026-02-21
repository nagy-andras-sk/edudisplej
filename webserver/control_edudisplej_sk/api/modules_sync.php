<?php
$start_time = microtime(true);
header('Content-Type: application/json');
require_once '../dbkonfiguracia.php';
require_once 'auth.php';
require_once '../logging.php';

// Validate API authentication for device requests
$api_company = validate_api_token();

$response = ['success' => false, 'message' => '', 'modules' => []];

function parse_unix_timestamp($value) {
    if (!$value) {
        return null;
    }
    if (is_numeric($value)) {
        return (int)$value;
    }
    $ts = strtotime($value);
    return $ts ? $ts : null;
}

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
        $stmt = $conn->prepare("SELECT id, is_configured, company_id, device_id, sync_interval, loop_last_update FROM kiosks WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
    } else {
        $stmt = $conn->prepare("SELECT id, is_configured, company_id, device_id, sync_interval, loop_last_update FROM kiosks WHERE mac = ?");
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

    // Enforce company ownership
    if (!empty($kiosk['company_id'])) {
        api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');
    } elseif (!empty($api_company['id']) && !api_is_admin_session($api_company)) {
        $assign_stmt = $conn->prepare("UPDATE kiosks SET company_id = ? WHERE id = ?");
        $assign_stmt->bind_param("ii", $api_company['id'], $kiosk['id']);
        $assign_stmt->execute();
        $assign_stmt->close();
        $kiosk['company_id'] = $api_company['id'];
    }
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
                'duration_seconds' => 60,
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

    if ($group_row && !empty($group_row['group_id'])) {
        api_require_group_company($conn, $api_company, (int)$group_row['group_id']);
    }
    
    $server_timestamp = null;
    $needs_update = true;

    $stored_last_update = $kiosk['loop_last_update'] ?? null;
    $stored_ts = parse_unix_timestamp($stored_last_update);
    $client_ts = parse_unix_timestamp($last_loop_update);

    if ($client_ts && (!$stored_ts || $client_ts > $stored_ts)) {
        $stored_ts = $client_ts;
        $stored_last_update = date('Y-m-d H:i:s', $client_ts);
        $update_loop_stmt = $conn->prepare("UPDATE kiosks SET loop_last_update = ? WHERE id = ?");
        $update_loop_stmt->bind_param("si", $stored_last_update, $kiosk['id']);
        $update_loop_stmt->execute();
        $update_loop_stmt->close();
    }

    if ($group_row) {
        $server_timestamp = $group_row['latest_update'];
        $server_ts = parse_unix_timestamp($server_timestamp);

        if ($server_ts) {
            if (!$stored_ts) {
                $needs_update = true;
            } elseif ($server_ts <= $stored_ts) {
                $needs_update = false;
            }
        }
    } else {
        $ts_stmt = $conn->prepare("SELECT MAX(updated_at) as latest_update, MAX(created_at) as created_at FROM kiosk_modules WHERE kiosk_id = ? AND is_active = 1");
        $ts_stmt->bind_param("i", $kiosk['id']);
        $ts_stmt->execute();
        $ts_result = $ts_stmt->get_result();
        $ts_row = $ts_result->fetch_assoc();
        $ts_stmt->close();

        $server_timestamp = $ts_row['latest_update'] ?? $ts_row['created_at'] ?? null;
        $server_ts = parse_unix_timestamp($server_timestamp);
        if ($server_ts) {
            if (!$stored_ts) {
                $needs_update = true;
            } elseif ($server_ts <= $stored_ts) {
                $needs_update = false;
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
            $duration = (int)$row['duration_seconds'];
            if (($row['module_key'] ?? '') === 'unconfigured') {
                $duration = 60;
            }
            
            $modules[] = [
                'module_key' => $row['module_key'],
                'name' => $row['name'],
                'display_order' => (int)$row['display_order'],
                'duration_seconds' => $duration,
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
            $duration = (int)$row['duration_seconds'];
            if (($row['module_key'] ?? '') === 'unconfigured') {
                $duration = 60;
            }
            
            $modules[] = [
                'module_key' => $row['module_key'],
                'name' => $row['name'],
                'display_order' => (int)$row['display_order'],
                'duration_seconds' => $duration,
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

// Log API request
$execution_time = microtime(true) - $start_time;
$status_code = $response['success'] ? 200 : 400;
log_api_request(
    $kiosk['company_id'] ?? null,
    $kiosk['id'] ?? null,
    '/api/modules_sync.php',
    'POST',
    $status_code,
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $_SERVER['HTTP_USER_AGENT'] ?? null,
    null,
    null,
    $execution_time
);

echo json_encode($response);
?>

