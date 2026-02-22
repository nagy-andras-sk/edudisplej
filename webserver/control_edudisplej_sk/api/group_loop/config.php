<?php
/**
 * API - Get/Save Group Loop Configuration
 */
session_start();
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../../auth_roles.php';
require_once __DIR__ . '/../../modules/module_policy.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not authenticated']);
    exit();
}

function edudisplej_ensure_time_blocks_schema(mysqli $conn) {
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

function edudisplej_ensure_loop_plans_schema(mysqli $conn) {
    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_loop_plans (
        group_id INT PRIMARY KEY,
        plan_json LONGTEXT NOT NULL,
        plan_version BIGINT NOT NULL DEFAULT 0,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $has_plan_version = $conn->query("SHOW COLUMNS FROM kiosk_group_loop_plans LIKE 'plan_version'");
    if ($has_plan_version && $has_plan_version->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_group_loop_plans ADD COLUMN plan_version BIGINT NOT NULL DEFAULT 0 AFTER plan_json");
    }
}

function edudisplej_time_ranges_overlap($a_start, $a_end, $b_start, $b_end) {
    if ($a_start <= $a_end && $b_start <= $b_end) {
        return $a_start < $b_end && $b_start < $a_end;
    }

    return true;
}

function edudisplej_validate_time_blocks_conflicts(array $time_blocks) {
    $normalized = [];
    foreach ($time_blocks as $index => $block) {
        if (!is_array($block)) {
            continue;
        }

        $type = strtolower(trim((string)($block['block_type'] ?? 'weekly')));
        if (!in_array($type, ['weekly', 'date'], true)) {
            $type = 'weekly';
        }

        $start = trim((string)($block['start_time'] ?? '08:00:00'));
        $end = trim((string)($block['end_time'] ?? '12:00:00'));
        if (strlen($start) === 5) {
            $start .= ':00';
        }
        if (strlen($end) === 5) {
            $end .= ':00';
        }

        $days = [];
        if ($type === 'weekly') {
            foreach (explode(',', (string)($block['days_mask'] ?? '')) as $part) {
                $day = (int)trim($part);
                if ($day >= 1 && $day <= 7) {
                    $days[$day] = true;
                }
            }
            if (empty($days)) {
                foreach ([1,2,3,4,5,6,7] as $day) {
                    $days[$day] = true;
                }
            }
        }

        $normalized[] = [
            'index' => $index,
            'type' => $type,
            'specific_date' => trim((string)($block['specific_date'] ?? '')),
            'start' => $start,
            'end' => $end,
            'days' => $days,
            'name' => trim((string)($block['block_name'] ?? 'Időblokk')),
        ];
    }

    $count = count($normalized);
    for ($i = 0; $i < $count; $i++) {
        for ($j = $i + 1; $j < $count; $j++) {
            $a = $normalized[$i];
            $b = $normalized[$j];

            if ($a['type'] !== $b['type']) {
                continue;
            }

            if ($a['type'] === 'date') {
                if ($a['specific_date'] === '' || $b['specific_date'] === '' || $a['specific_date'] !== $b['specific_date']) {
                    continue;
                }
            } else {
                $common = false;
                foreach ($a['days'] as $day => $_) {
                    if (isset($b['days'][$day])) {
                        $common = true;
                        break;
                    }
                }
                if (!$common) {
                    continue;
                }
            }

            if (edudisplej_time_ranges_overlap($a['start'], $a['end'], $b['start'], $b['end'])) {
                return [
                    'ok' => false,
                    'message' => "Ütköző idősáv: '{$a['name']}' és '{$b['name']}'"
                ];
            }
        }
    }

    return ['ok' => true, 'message' => ''];
}

function edudisplej_resolve_module_key(mysqli $conn, int $module_id, array &$cache): string {
    if ($module_id <= 0) {
        return '';
    }

    if (isset($cache[$module_id])) {
        return $cache[$module_id];
    }

    $stmt = $conn->prepare("SELECT module_key FROM modules WHERE id = ? LIMIT 1");
    $stmt->bind_param("i", $module_id);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $module_key = strtolower(trim((string)($row['module_key'] ?? '')));
    $cache[$module_id] = $module_key;
    return $module_key;
}

function edudisplej_allowed_modules_for_company(mysqli $conn, int $company_id): array {
    $allowed = [];

    $stmt = $conn->prepare("SELECT m.id, m.module_key, m.name, COALESCE(ml.quantity, 0) AS license_quantity
                            FROM modules m
                            LEFT JOIN module_licenses ml ON ml.module_id = m.id AND ml.company_id = ?
                            WHERE m.is_active = 1");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $module_id = (int)($row['id'] ?? 0);
        $module_key = strtolower(trim((string)($row['module_key'] ?? '')));
        $module_name = trim((string)($row['name'] ?? $module_key));
        $licensed = ((int)($row['license_quantity'] ?? 0)) > 0;

        if ($module_key === 'unconfigured' || $licensed) {
            $allowed[$module_id] = [
                'module_key' => $module_key,
                'module_name' => $module_name,
            ];
        }
    }

    $stmt->close();
    return $allowed;
}

function edudisplej_video_duration_from_settings($settings): ?int {
    if (!is_array($settings)) {
        return null;
    }

    $raw = $settings['videoDurationSec'] ?? null;
    if (!is_numeric($raw)) {
        return null;
    }

    $duration = (int)$raw;
    if ($duration < 1) {
        $duration = 1;
    }
    if ($duration > 86400) {
        $duration = 86400;
    }

    return $duration;
}

$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];
$session_role = edudisplej_get_session_role();

if (!edudisplej_can_edit_module_content()) {
    echo json_encode(['success' => false, 'message' => 'Hozzáférés megtagadva']);
    exit();
}

$group_id = intval($_REQUEST['group_id'] ?? 0);

try {
    $conn = getDbConnection();
    edudisplej_ensure_user_role_column($conn);
    edudisplej_ensure_time_blocks_schema($conn);
    edudisplej_ensure_loop_plans_schema($conn);

    $default_check = $conn->query("SHOW COLUMNS FROM kiosk_groups LIKE 'is_default'");
    if ($default_check && $default_check->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_groups ADD COLUMN is_default TINYINT(1) NOT NULL DEFAULT 0");
    }
    
    // Check permissions
    $stmt = $conn->prepare("SELECT company_id, is_default, name FROM kiosk_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $stmt->close();
    
    if (!$group || (!$is_admin && $group['company_id'] != $company_id)) {
        echo json_encode(['success' => false, 'message' => 'Hozzáférés megtagadva']);
        exit();
    }

    $is_default_group = (!empty($group['is_default']) || strtolower($group['name']) === 'default');

    $unconfigured_stmt = $conn->prepare("SELECT id, name, description, module_key FROM modules WHERE module_key = 'unconfigured' LIMIT 1");
    $unconfigured_stmt->execute();
    $unconfigured_result = $unconfigured_stmt->get_result();
    $unconfigured_module = $unconfigured_result->fetch_assoc();
    $unconfigured_stmt->close();
    
    // GET - Retrieve loop configuration
    if ($_SERVER['REQUEST_METHOD'] === 'GET') {
        if ($is_default_group) {
            if (!$unconfigured_module) {
                echo json_encode(['success' => false, 'message' => 'Az unconfigured modul nem elerheto']);
                exit();
            }

            echo json_encode([
                'success' => true,
                'loops' => [[
                    'id' => null,
                    'module_id' => (int)$unconfigured_module['id'],
                    'module_name' => $unconfigured_module['name'],
                    'module_key' => 'unconfigured',
                    'description' => $unconfigured_module['description'] ?? '',
                    'duration_seconds' => 60,
                    'display_order' => 0,
                    'settings' => new stdClass(),
                    'is_active' => 1
                ]],
                'base_loop' => [[
                    'id' => null,
                    'module_id' => (int)$unconfigured_module['id'],
                    'module_name' => $unconfigured_module['name'],
                    'module_key' => 'unconfigured',
                    'description' => $unconfigured_module['description'] ?? '',
                    'duration_seconds' => 60,
                    'display_order' => 0,
                    'settings' => new stdClass(),
                    'is_active' => 1
                ]],
                'time_blocks' => []
            ]);
            exit();
        }

        $stmt = $conn->prepare("SELECT kgm.*, m.name as module_name, m.module_key, m.description
                                FROM kiosk_group_modules kgm
                                JOIN modules m ON kgm.module_id = m.id
                                WHERE kgm.group_id = ?
                                ORDER BY COALESCE(kgm.time_block_id, 0), kgm.display_order");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $base_loop = [];
        $block_loops = [];
        while ($row = $result->fetch_assoc()) {
            $duration_seconds = (int)$row['duration_seconds'];
            if (($row['module_key'] ?? '') === 'unconfigured') {
                $duration_seconds = 60;
            }
            $item = [
                'id' => $row['id'],
                'module_id' => $row['module_id'],
                'module_name' => $row['module_name'],
                'module_key' => $row['module_key'],
                'description' => $row['description'],
                'duration_seconds' => $duration_seconds,
                'display_order' => $row['display_order'],
                'settings' => $row['settings'] ? json_decode($row['settings'], true) : null,
                'is_active' => $row['is_active']
            ];

            $time_block_id = (int)($row['time_block_id'] ?? 0);
            if ($time_block_id > 0) {
                if (!isset($block_loops[$time_block_id])) {
                    $block_loops[$time_block_id] = [];
                }
                $block_loops[$time_block_id][] = $item;
            } else {
                $base_loop[] = $item;
            }
        }
        
        $stmt->close();

        $blocks_stmt = $conn->prepare("SELECT id, block_name, block_type, specific_date, start_time, end_time, days_mask, is_active, priority, display_order
                                       FROM kiosk_group_time_blocks
                                       WHERE group_id = ?
                                       ORDER BY display_order, id");
        $blocks_stmt->bind_param("i", $group_id);
        $blocks_stmt->execute();
        $blocks_result = $blocks_stmt->get_result();

        $time_blocks = [];
        while ($block = $blocks_result->fetch_assoc()) {
            $block_id = (int)$block['id'];
            $time_blocks[] = [
                'id' => $block_id,
                'block_name' => $block['block_name'],
                'block_type' => $block['block_type'] ?: 'weekly',
                'specific_date' => $block['specific_date'],
                'start_time' => $block['start_time'],
                'end_time' => $block['end_time'],
                'days_mask' => $block['days_mask'] ?: '1,2,3,4,5,6,7',
                'is_active' => (int)$block['is_active'],
                'priority' => (int)($block['priority'] ?? 100),
                'display_order' => (int)$block['display_order'],
                'loops' => $block_loops[$block_id] ?? []
            ];
        }
        $blocks_stmt->close();

        $loop_styles = [];
        $default_loop_style_id = null;
        $schedule_blocks = [];
        $plan_stmt = $conn->prepare("SELECT plan_json, plan_version, updated_at FROM kiosk_group_loop_plans WHERE group_id = ? LIMIT 1");
        $plan_stmt->bind_param("i", $group_id);
        $plan_stmt->execute();
        $plan_row = $plan_stmt->get_result()->fetch_assoc();
        $plan_stmt->close();

        $plan_version = 0;
        $plan_updated_at = null;

        if ($plan_row && !empty($plan_row['plan_json'])) {
            $decoded_plan = json_decode($plan_row['plan_json'], true);
            if (is_array($decoded_plan)) {
                $loop_styles = is_array($decoded_plan['loop_styles'] ?? null) ? $decoded_plan['loop_styles'] : [];
                $default_loop_style_id = isset($decoded_plan['default_loop_style_id']) ? (int)$decoded_plan['default_loop_style_id'] : null;
                $schedule_blocks = is_array($decoded_plan['schedule_blocks'] ?? null) ? $decoded_plan['schedule_blocks'] : [];
            }
            $plan_version = (int)($plan_row['plan_version'] ?? 0);
            $plan_updated_at = $plan_row['updated_at'] ?? null;
        }

        if (empty($loop_styles) && empty($time_blocks)) {
            $base_items = $base_loop;
            if (empty($base_items) && $unconfigured_module) {
                $base_items = [[
                    'id' => null,
                    'module_id' => (int)$unconfigured_module['id'],
                    'module_name' => $unconfigured_module['name'],
                    'module_key' => 'unconfigured',
                    'description' => $unconfigured_module['description'] ?? '',
                    'duration_seconds' => 60,
                    'display_order' => 0,
                    'settings' => new stdClass(),
                    'is_active' => 1
                ]];
            }

            $loop_styles = [[
                'id' => 1,
                'name' => 'Alap loop',
                'items' => $base_items
            ]];
            $default_loop_style_id = 1;
            $schedule_blocks = [];
        }

        echo json_encode([
            'success' => true,
            'loops' => $base_loop,
            'base_loop' => $base_loop,
            'time_blocks' => $time_blocks,
            'loop_styles' => $loop_styles,
            'default_loop_style_id' => $default_loop_style_id,
            'schedule_blocks' => $schedule_blocks,
            'plan_version' => $plan_version,
            'plan_updated_at' => $plan_updated_at
        ]);
    }
    
    // POST - Save loop configuration
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $payload = json_decode(file_get_contents('php://input'), true);

        if (!is_array($payload)) {
            echo json_encode(['success' => false, 'message' => 'Hibás loop konfiguráció']);
            exit();
        }

        $is_structured_payload = array_key_exists('base_loop', $payload) || array_key_exists('time_blocks', $payload);
        $has_planner_payload = is_array($payload['loop_styles'] ?? null) || is_array($payload['schedule_blocks'] ?? null);

        $loop_styles = $has_planner_payload && is_array($payload['loop_styles'] ?? null)
            ? $payload['loop_styles']
            : [];
        $default_loop_style_id = $has_planner_payload
            ? (int)($payload['default_loop_style_id'] ?? 0)
            : 0;
        $schedule_blocks = $has_planner_payload && is_array($payload['schedule_blocks'] ?? null)
            ? $payload['schedule_blocks']
            : [];

        $base_loop = $is_structured_payload
            ? (is_array($payload['base_loop'] ?? null) ? $payload['base_loop'] : [])
            : $payload;
        $time_blocks = $is_structured_payload && is_array($payload['time_blocks'] ?? null)
            ? $payload['time_blocks']
            : [];

        if ($has_planner_payload) {
            $style_map = [];
            foreach ($loop_styles as $style) {
                if (!is_array($style)) {
                    continue;
                }
                $style_id = (int)($style['id'] ?? 0);
                if ($style_id === 0) {
                    continue;
                }
                $style_items = is_array($style['items'] ?? null) ? $style['items'] : [];
                $style_map[$style_id] = [
                    'id' => $style_id,
                    'name' => trim((string)($style['name'] ?? ('Loop #' . $style_id))),
                    'items' => $style_items,
                ];
            }

            if ($default_loop_style_id === 0 && !empty($style_map)) {
                $first_keys = array_keys($style_map);
                $default_loop_style_id = (int)$first_keys[0];
            }

            $base_loop = $style_map[$default_loop_style_id]['items'] ?? [];
            $time_blocks = [];
            foreach ($schedule_blocks as $block) {
                if (!is_array($block)) {
                    continue;
                }
                $style_id = (int)($block['loop_style_id'] ?? 0);
                if ($style_id <= 0 || !isset($style_map[$style_id])) {
                    continue;
                }
                $expanded_block = $block;
                $expanded_block['loops'] = $style_map[$style_id]['items'];
                $time_blocks[] = $expanded_block;
            }
        }

        $validation = edudisplej_validate_time_blocks_conflicts($time_blocks);
        if (!$validation['ok']) {
            echo json_encode(['success' => false, 'message' => $validation['message']]);
            exit();
        }

        $all_loops = $base_loop;
        foreach ($time_blocks as $block) {
            if (!is_array($block) || !is_array($block['loops'] ?? null)) {
                continue;
            }
            foreach ($block['loops'] as $loop_item) {
                $all_loops[] = $loop_item;
            }
        }

        $allowed_modules = edudisplej_allowed_modules_for_company($conn, (int)$group['company_id']);
        foreach ($all_loops as $loop_item) {
            $module_id = (int)($loop_item['module_id'] ?? 0);
            if ($module_id <= 0) {
                echo json_encode(['success' => false, 'message' => 'Érvénytelen modul azonosító a loopban']);
                exit();
            }

            if (!isset($allowed_modules[$module_id])) {
                $module_name = trim((string)($loop_item['module_name'] ?? ''));
                $display_name = $module_name !== '' ? $module_name : ('ID ' . $module_id);
                echo json_encode([
                    'success' => false,
                    'message' => "A modul nincs engedélyezve ennél a cégnél: {$display_name}"
                ]);
                exit();
            }
        }

        if ($is_default_group) {
            echo json_encode(['success' => false, 'message' => 'A default csoport loopja nem szerkesztheto']);
            exit();
        }

        if ($session_role === 'content_editor') {
            $conn->begin_transaction();
            try {
                $module_key_cache = [];
                $existing_stmt = $conn->prepare("SELECT id FROM kiosk_group_modules WHERE group_id = ?");
                $existing_stmt->bind_param("i", $group_id);
                $existing_stmt->execute();
                $existing_result = $existing_stmt->get_result();
                $existing_ids = [];
                while ($existing = $existing_result->fetch_assoc()) {
                    $existing_ids[(int)$existing['id']] = true;
                }
                $existing_stmt->close();

                foreach ($all_loops as $loop) {
                    $loop_id = (int)($loop['id'] ?? 0);
                    if ($loop_id <= 0 || !isset($existing_ids[$loop_id])) {
                        continue;
                    }

                    $module_id = (int)($loop['module_id'] ?? 0);
                    $module_key = strtolower(trim((string)($loop['module_key'] ?? '')));
                    if ($module_key === '') {
                        $module_key = edudisplej_resolve_module_key($conn, $module_id, $module_key_cache);
                    }

                    $sanitized_settings = edudisplej_sanitize_module_settings($module_key, $loop['settings'] ?? []);
                    $video_duration = $module_key === 'video' ? edudisplej_video_duration_from_settings($sanitized_settings) : null;
                    $settings = json_encode($sanitized_settings, JSON_UNESCAPED_UNICODE);
                    if ($video_duration !== null) {
                        $update_stmt = $conn->prepare("UPDATE kiosk_group_modules SET settings = ?, duration_seconds = ? WHERE id = ? AND group_id = ?");
                        $update_stmt->bind_param("siii", $settings, $video_duration, $loop_id, $group_id);
                    } else {
                        $update_stmt = $conn->prepare("UPDATE kiosk_group_modules SET settings = ? WHERE id = ? AND group_id = ?");
                        $update_stmt->bind_param("sii", $settings, $loop_id, $group_id);
                    }
                    $update_stmt->execute();
                    $update_stmt->close();
                }

                $conn->commit();
                echo json_encode(['success' => true, 'message' => 'Modultartalom sikeresen mentve']);
            } catch (Exception $e) {
                $conn->rollback();
                throw $e;
            }
            exit();
        }
        
        // Start transaction
        $conn->begin_transaction();

        $response_plan_version = 0;
        
        try {
            $module_key_cache = [];
            // Delete existing loops
            $stmt = $conn->prepare("DELETE FROM kiosk_group_modules WHERE group_id = ?");
            $stmt->bind_param("i", $group_id);
            $stmt->execute();
            $stmt->close();

            $stmt = $conn->prepare("DELETE FROM kiosk_group_time_blocks WHERE group_id = ?");
            $stmt->bind_param("i", $group_id);
            $stmt->execute();
            $stmt->close();

            $persisted_blocks = [];
            foreach ($time_blocks as $block_index => $block) {
                if (!is_array($block)) {
                    continue;
                }

                $block_name = trim((string)($block['block_name'] ?? 'Időblokk'));
                $block_type = strtolower(trim((string)($block['block_type'] ?? 'weekly')));
                if (!in_array($block_type, ['weekly', 'date'], true)) {
                    $block_type = 'weekly';
                }
                $specific_date = $block_type === 'date' ? trim((string)($block['specific_date'] ?? '')) : null;
                $start_time = trim((string)($block['start_time'] ?? '08:00:00'));
                $end_time = trim((string)($block['end_time'] ?? '12:00:00'));
                $days_mask = trim((string)($block['days_mask'] ?? '1,2,3,4,5,6,7'));
                $is_active_block = !isset($block['is_active']) || (int)$block['is_active'] !== 0 ? 1 : 0;
                $priority = (int)($block['priority'] ?? ($block_type === 'date' ? 300 : 100));
                $loop_style_id = (int)($block['loop_style_id'] ?? 0);

                if ($block_name === '') {
                    $block_name = 'Időblokk';
                }

                if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time)) {
                    $start_time = '08:00:00';
                }
                if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) {
                    $end_time = '12:00:00';
                }
                if (strlen($start_time) === 5) {
                    $start_time .= ':00';
                }
                if (strlen($end_time) === 5) {
                    $end_time .= ':00';
                }

                $insert_block = $conn->prepare("INSERT INTO kiosk_group_time_blocks (group_id, block_name, block_type, specific_date, start_time, end_time, days_mask, is_active, priority, display_order) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
                $insert_block->bind_param("issssssiii", $group_id, $block_name, $block_type, $specific_date, $start_time, $end_time, $days_mask, $is_active_block, $priority, $block_index);
                $insert_block->execute();
                $new_block_id = (int)$insert_block->insert_id;
                $insert_block->close();

                $client_block_id = (int)($block['id'] ?? 0);
                $persisted_blocks[$client_block_id] = $new_block_id;
                $persisted_blocks[-1 - $block_index] = $new_block_id;
                if ($loop_style_id > 0) {
                    $persisted_blocks['style:' . $new_block_id] = $loop_style_id;
                }
            }
            
            // Insert new loops
            foreach ($base_loop as $index => $loop) {
                $module_id = intval($loop['module_id']);
                $module_key = strtolower(trim((string)($loop['module_key'] ?? '')));
                if ($module_key === '') {
                    $module_key = edudisplej_resolve_module_key($conn, $module_id, $module_key_cache);
                }
                $duration = edudisplej_clamp_module_duration($module_key, $loop['duration_seconds'] ?? null);
                $sanitized_settings = edudisplej_sanitize_module_settings($module_key, $loop['settings'] ?? []);
                $video_duration = $module_key === 'video' ? edudisplej_video_duration_from_settings($sanitized_settings) : null;
                if ($video_duration !== null) {
                    $duration = $video_duration;
                }
                $settings = json_encode($sanitized_settings, JSON_UNESCAPED_UNICODE);
                $display_order = $index;
                
                $stmt = $conn->prepare("INSERT INTO kiosk_group_modules 
                                        (group_id, time_block_id, module_id, module_key, display_order, duration_seconds, settings, is_active) 
                                        VALUES (?, NULL, ?, ?, ?, ?, ?, 1)");
                $stmt->bind_param("iisiis", $group_id, $module_id, $module_key, $display_order, $duration, $settings);
                $stmt->execute();
                $stmt->close();
            }

            foreach ($time_blocks as $block_index => $block) {
                if (!is_array($block) || !is_array($block['loops'] ?? null)) {
                    continue;
                }

                $client_block_id = (int)($block['id'] ?? 0);
                $resolved_block_id = $persisted_blocks[$client_block_id] ?? ($persisted_blocks[-1 - $block_index] ?? null);
                if (!$resolved_block_id) {
                    continue;
                }

                foreach ($block['loops'] as $index => $loop) {
                    $module_id = intval($loop['module_id']);
                    $module_key = strtolower(trim((string)($loop['module_key'] ?? '')));
                    if ($module_key === '') {
                        $module_key = edudisplej_resolve_module_key($conn, $module_id, $module_key_cache);
                    }
                    $duration = edudisplej_clamp_module_duration($module_key, $loop['duration_seconds'] ?? null);
                    $sanitized_settings = edudisplej_sanitize_module_settings($module_key, $loop['settings'] ?? []);
                    $video_duration = $module_key === 'video' ? edudisplej_video_duration_from_settings($sanitized_settings) : null;
                    if ($video_duration !== null) {
                        $duration = $video_duration;
                    }
                    $settings = json_encode($sanitized_settings, JSON_UNESCAPED_UNICODE);
                    $display_order = $index;

                    $stmt = $conn->prepare("INSERT INTO kiosk_group_modules 
                                            (group_id, time_block_id, module_id, module_key, display_order, duration_seconds, settings, is_active) 
                                            VALUES (?, ?, ?, ?, ?, ?, ?, 1)");
                    $stmt->bind_param("iiisiis", $group_id, $resolved_block_id, $module_id, $module_key, $display_order, $duration, $settings);
                    $stmt->execute();
                    $stmt->close();
                }
            }

            if ($has_planner_payload) {
                $plan_payload = [
                    'loop_styles' => $loop_styles,
                    'default_loop_style_id' => $default_loop_style_id,
                    'schedule_blocks' => $schedule_blocks,
                ];
                $plan_json = json_encode($plan_payload, JSON_UNESCAPED_UNICODE);
                if ($plan_json !== false) {
                    $response_plan_version = (int)round(microtime(true) * 1000);
                    $response_plan_version_str = (string)$response_plan_version;
                    $plan_stmt = $conn->prepare("INSERT INTO kiosk_group_loop_plans (group_id, plan_json, plan_version) VALUES (?, ?, ?) ON DUPLICATE KEY UPDATE plan_json = VALUES(plan_json), plan_version = VALUES(plan_version), updated_at = CURRENT_TIMESTAMP");
                    $plan_stmt->bind_param("iss", $group_id, $plan_json, $response_plan_version_str);
                    $plan_stmt->execute();
                    $plan_stmt->close();
                }
            } else {
                $cleanup_stmt = $conn->prepare("DELETE FROM kiosk_group_loop_plans WHERE group_id = ?");
                $cleanup_stmt->bind_param("i", $group_id);
                $cleanup_stmt->execute();
                $cleanup_stmt->close();
            }
            
            $conn->commit();
            echo json_encode([
                'success' => true,
                'message' => 'Loop konfiguráció sikeresen mentve',
                'plan_version' => $response_plan_version
            ]);
            
        } catch (Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    error_log($e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Adatbázis hiba: ' . $e->getMessage()]);
}
?>
