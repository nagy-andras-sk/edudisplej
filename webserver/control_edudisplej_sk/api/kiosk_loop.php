<?php
/**
 * API - Get Kiosk Loop Configuration by Device ID
 * Returns loop config and module list for download
 * No session required - uses device_id authentication
 */
require_once '../dbkonfiguracia.php';
require_once 'auth.php';

$api_company = validate_api_token();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

function edudisplej_ensure_time_block_schema(mysqli $conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_time_blocks (
        id INT AUTO_INCREMENT PRIMARY KEY,
        group_id INT NOT NULL,
        block_name VARCHAR(120) NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        days_mask VARCHAR(40) NOT NULL DEFAULT '1,2,3,4,5,6,7',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        display_order INT NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_group (group_id),
        INDEX idx_active (group_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $has_block_type = $conn->query("SHOW COLUMNS FROM kiosk_group_time_blocks LIKE 'block_type'");
    if ($has_block_type && $has_block_type->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_time_blocks ADD COLUMN block_type VARCHAR(16) NOT NULL DEFAULT 'weekly' AFTER block_name");
    }

    $has_specific_date = $conn->query("SHOW COLUMNS FROM kiosk_group_time_blocks LIKE 'specific_date'");
    if ($has_specific_date && $has_specific_date->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_time_blocks ADD COLUMN specific_date DATE NULL AFTER block_type");
    }

    $has_priority = $conn->query("SHOW COLUMNS FROM kiosk_group_time_blocks LIKE 'priority'");
    if ($has_priority && $has_priority->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_time_blocks ADD COLUMN priority INT NOT NULL DEFAULT 100 AFTER is_active");
    }

    $has_time_block_col = $conn->query("SHOW COLUMNS FROM kiosk_group_modules LIKE 'time_block_id'");
    if ($has_time_block_col && $has_time_block_col->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_modules ADD COLUMN time_block_id INT NULL AFTER group_id");
        $conn->query("CREATE INDEX idx_group_time_block ON kiosk_group_modules (group_id, time_block_id)");
    }
}

function edudisplej_get_active_block_id(mysqli $conn, int $group_id): array {
    $stmt = $conn->prepare("SELECT id, block_type, specific_date, start_time, end_time, days_mask, priority, display_order FROM kiosk_group_time_blocks WHERE group_id = ? AND is_active = 1 ORDER BY display_order, id");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();

    $blocks = [];
    while ($row = $result->fetch_assoc()) {
        $blocks[] = $row;
    }
    $stmt->close();

    if (empty($blocks)) {
        return ['has_blocks' => false, 'active_block_id' => null];
    }

    $day = (int)date('N');
    $now = date('H:i:s');

    $date_key = date('Y-m-d');
    $candidates = [];
    foreach ($blocks as $block) {
        $block_type = strtolower(trim((string)($block['block_type'] ?? 'weekly')));
        if (!in_array($block_type, ['weekly', 'date'], true)) {
            $block_type = 'weekly';
        }

        if ($block_type === 'date' && (string)($block['specific_date'] ?? '') !== $date_key) {
            continue;
        }

        $allowed_days = [];
        if ($block_type === 'weekly') {
            foreach (explode(',', (string)($block['days_mask'] ?? '')) as $part) {
                $val = (int)trim($part);
                if ($val >= 1 && $val <= 7) {
                    $allowed_days[$val] = true;
                }
            }
        }

        if ($block_type === 'weekly' && !empty($allowed_days) && !isset($allowed_days[$day])) {
            continue;
        }

        $start = (string)$block['start_time'];
        $end = (string)$block['end_time'];
        if ($start <= $end) {
            if ($now >= $start && $now <= $end) {
                $candidates[] = $block;
            }
        } else {
            if ($now >= $start || $now <= $end) {
                $candidates[] = $block;
            }
        }
    }

    if (!empty($candidates)) {
        usort($candidates, function ($a, $b) {
            $typeA = strtolower(trim((string)($a['block_type'] ?? 'weekly'))) === 'date' ? 2 : 1;
            $typeB = strtolower(trim((string)($b['block_type'] ?? 'weekly'))) === 'date' ? 2 : 1;
            if ($typeA !== $typeB) {
                return $typeB <=> $typeA;
            }

            $priorityA = (int)($a['priority'] ?? 100);
            $priorityB = (int)($b['priority'] ?? 100);
            if ($priorityA !== $priorityB) {
                return $priorityB <=> $priorityA;
            }

            $orderA = (int)($a['display_order'] ?? 0);
            $orderB = (int)($b['display_order'] ?? 0);
            if ($orderA !== $orderB) {
                return $orderA <=> $orderB;
            }

            return ((int)$a['id']) <=> ((int)$b['id']);
        });

        return ['has_blocks' => true, 'active_block_id' => (int)$candidates[0]['id']];
    }

    return ['has_blocks' => true, 'active_block_id' => null];
}

try {
    // Get device_id from POST or GET
    $device_id = $_POST['device_id'] ?? $_GET['device_id'] ?? '';
    
    if (empty($device_id)) {
        $response['message'] = 'Missing device_id';
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    edudisplej_ensure_time_block_schema($conn);
    
    // Get kiosk by device_id
    $stmt = $conn->prepare("SELECT id, device_id, company_id FROM kiosks WHERE device_id = ?");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $response['message'] = 'Kiosk not found';
        echo json_encode($response);
        exit;
    }
    
    $kiosk = $result->fetch_assoc();
    $kiosk_id = $kiosk['id'];
    $company_id = $kiosk['company_id'];
    $stmt->close();

    // Enforce company ownership
    api_require_company_match($api_company, $company_id, 'Unauthorized');
    
    // Get kiosk's loop configuration
    // First check if kiosk has specific modules assigned
    $stmt = $conn->prepare("
        SELECT km.*, m.name as module_name, m.module_key
        FROM kiosk_modules km
        JOIN modules m ON km.module_id = m.id
        WHERE km.kiosk_id = ? AND km.is_active = 1
        ORDER BY km.display_order
    ");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $loop_config = [];
    $preload_map = [];
    $config_source = 'kiosk';
    $group_id = null;
    while ($row = $result->fetch_assoc()) {
        $loop_config[] = [
            'module_id' => (int)$row['module_id'],
            'module_name' => $row['module_name'],
            'module_key' => $row['module_key'],
            'duration_seconds' => (int)$row['duration_seconds'],
            'display_order' => (int)$row['display_order'],
            'settings' => $row['settings'] ? json_decode($row['settings'], true) : (object)[],
            'source' => 'kiosk'
        ];

        $module_key = (string)($row['module_key'] ?? '');
        if ($module_key !== '' && !isset($preload_map[$module_key])) {
            $preload_map[$module_key] = [
                'module_key' => $module_key,
                'name' => (string)($row['module_name'] ?? $module_key)
            ];
        }
    }
    $stmt->close();
    
    // If no specific modules, get from group(s)
    if (empty($loop_config)) {
        $config_source = 'group';
        $group_stmt = $conn->prepare("SELECT group_id FROM kiosk_group_assignments WHERE kiosk_id = ? LIMIT 1");
        $group_stmt->bind_param("i", $kiosk_id);
        $group_stmt->execute();
        $group_row = $group_stmt->get_result()->fetch_assoc();
        $group_stmt->close();

        if ($group_row) {
            $group_id = (int)$group_row['group_id'];
            $block_state = edudisplej_get_active_block_id($conn, $group_id);
            $active_block_id = $block_state['active_block_id'];
            $has_blocks = $block_state['has_blocks'];

            $preload_stmt = $conn->prepare("SELECT DISTINCT m.module_key, m.name as module_name
                                            FROM kiosk_group_modules kgm
                                            JOIN modules m ON kgm.module_id = m.id
                                            WHERE kgm.group_id = ? AND kgm.is_active = 1
                                            ORDER BY m.module_key ASC");
            $preload_stmt->bind_param("i", $group_id);
            $preload_stmt->execute();
            $preload_result = $preload_stmt->get_result();
            while ($preload_row = $preload_result->fetch_assoc()) {
                $module_key = (string)($preload_row['module_key'] ?? '');
                if ($module_key === '') {
                    continue;
                }
                $preload_map[$module_key] = [
                    'module_key' => $module_key,
                    'name' => (string)($preload_row['module_name'] ?? $module_key)
                ];
            }
            $preload_stmt->close();

            if ($has_blocks && $active_block_id !== null) {
                $stmt = $conn->prepare(" 
                    SELECT kgm.*, m.name as module_name, m.module_key
                    FROM kiosk_group_modules kgm
                    JOIN modules m ON kgm.module_id = m.id
                    WHERE kgm.group_id = ? AND kgm.time_block_id = ?
                    ORDER BY kgm.display_order
                ");
                $stmt->bind_param("ii", $group_id, $active_block_id);
            } else {
                $stmt = $conn->prepare(" 
                    SELECT kgm.*, m.name as module_name, m.module_key
                    FROM kiosk_group_modules kgm
                    JOIN modules m ON kgm.module_id = m.id
                    WHERE kgm.group_id = ? AND kgm.time_block_id IS NULL
                    ORDER BY kgm.display_order
                ");
                $stmt->bind_param("i", $group_id);
            }

            $stmt->execute();
            $result = $stmt->get_result();

            while ($row = $result->fetch_assoc()) {
                $loop_config[] = [
                    'module_id' => (int)$row['module_id'],
                    'module_name' => $row['module_name'],
                    'module_key' => $row['module_key'],
                    'duration_seconds' => (int)$row['duration_seconds'],
                    'display_order' => (int)$row['display_order'],
                    'settings' => $row['settings'] ? json_decode($row['settings'], true) : (object)[],
                    'source' => 'group'
                ];

                $module_key = (string)($row['module_key'] ?? '');
                if ($module_key !== '' && !isset($preload_map[$module_key])) {
                    $preload_map[$module_key] = [
                        'module_key' => $module_key,
                        'name' => (string)($row['module_name'] ?? $module_key)
                    ];
                }
            }
            $stmt->close();
            $response['active_time_block_id'] = $active_block_id;
            $response['active_scope'] = $active_block_id !== null ? 'block' : 'base';
        }

        if ($group_id) {
            api_require_group_company($conn, $api_company, $group_id);
        }
    }
    
    // Determine loop last update based on source
    // Use MAX of created_at to detect module changes
    $loop_last_update = null;
    if ($config_source === 'kiosk') {
        $stmt = $conn->prepare("SELECT MAX(created_at) as last_update 
                                FROM kiosk_modules 
                                WHERE kiosk_id = ? AND is_active = 1");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $loop_last_update = $row['last_update'] ?? null;
        $stmt->close();
    } else if ($group_id) {
        $stmt = $conn->prepare("SELECT MAX(created_at) as last_update 
                                FROM kiosk_group_modules 
                                WHERE group_id = ? AND is_active = 1");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $loop_last_update = $row['last_update'] ?? null;
        $stmt->close();
    }
    if (!$loop_last_update) {
        $loop_last_update = date('Y-m-d H:i:s');
    }
    
    closeDbConnection($conn);
    
    $response['success'] = true;
    $response['kiosk_id'] = $kiosk_id;
    $response['device_id'] = $device_id;
    $response['loop_config'] = $loop_config;
    $response['module_count'] = count($loop_config);
    $response['preload_modules'] = array_values($preload_map);
    if (!isset($response['active_scope'])) {
        $response['active_scope'] = 'base';
    }
    $response['loop_last_update'] = $loop_last_update;
    
    // Use JSON encoding options to prevent output truncation
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('Kiosk Loop API Error: ' . $e->getMessage());
    $response['message'] = 'Server error: ' . $e->getMessage();
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
