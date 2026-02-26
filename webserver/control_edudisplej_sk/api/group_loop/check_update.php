<?php
/**
 * API - Check if Loop/Modules were updated in Kiosk Group
 * Verifies device belongs to company and returns latest updated_at from kiosk_group_modules
 * Used for version checking before module download
 * No session required - uses device_id authentication
 */
require_once __DIR__ . '/../../dbkonfiguracia.php';
require_once __DIR__ . '/../auth.php';

$api_company = validate_api_token();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

function edudisplej_pick_newer_timestamp(?string $current, ?string $candidate): ?string {
    $candidate = trim((string)$candidate);
    if ($candidate === '') {
        return $current;
    }

    $current = trim((string)$current);
    if ($current === '') {
        return $candidate;
    }

    $currentTs = strtotime($current);
    $candidateTs = strtotime($candidate);
    if ($currentTs === false) {
        return $candidate;
    }
    if ($candidateTs === false) {
        return $current;
    }

    return $candidateTs > $currentTs ? $candidate : $current;
}

function edudisplej_meal_menu_latest_update_for_settings(mysqli $conn, int $company_id, array $settings): ?string {
    $institution_id = (int)($settings['institutionId'] ?? 0);
    if ($institution_id <= 0 || $company_id <= 0) {
        return null;
    }

    $source_type = strtolower(trim((string)($settings['sourceType'] ?? 'server')));
    if ($source_type === 'manual') {
        $sql = "SELECT MAX(updated_at) AS latest_update
                FROM meal_plan_items
                WHERE institution_id = ? AND (company_id = 0 OR company_id = ?)
                  AND source_type = 'manual'";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $institution_id, $company_id);
    } else {
        $sql = "SELECT MAX(updated_at) AS latest_update
                FROM meal_plan_items
                WHERE institution_id = ? AND (company_id = 0 OR company_id = ?)
                  AND source_type IN ('server', 'auto_jedalen')";
        $stmt = $conn->prepare($sql);
        if (!$stmt) {
            return null;
        }
        $stmt->bind_param('ii', $institution_id, $company_id);
    }

    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    $latest = trim((string)($row['latest_update'] ?? ''));
    return $latest !== '' ? $latest : null;
}

function edudisplej_collect_meal_menu_latest_update_from_rows(mysqli $conn, int $company_id, array $rows): ?string {
    $latest = null;
    foreach ($rows as $row) {
        $settingsRaw = $row['settings'] ?? null;
        $settings = null;
        if (is_string($settingsRaw) && $settingsRaw !== '') {
            $decoded = json_decode($settingsRaw, true);
            if (is_array($decoded)) {
                $settings = $decoded;
            }
        } elseif (is_array($settingsRaw)) {
            $settings = $settingsRaw;
        }

        if (!is_array($settings)) {
            continue;
        }

        $candidate = edudisplej_meal_menu_latest_update_for_settings($conn, $company_id, $settings);
        $latest = edudisplej_pick_newer_timestamp($latest, $candidate);
    }

    return $latest;
}

try {
    // Get device_id from POST, GET, or JSON body
    $device_id = $_POST['device_id'] ?? $_GET['device_id'] ?? '';
    
    // If not found in POST/GET, try to parse JSON body
    if (empty($device_id)) {
        $json_body = file_get_contents('php://input');
        if (!empty($json_body)) {
            $json_data = json_decode($json_body, true);
            $device_id = $json_data['device_id'] ?? '';
        }
    }
    
    if (empty($device_id)) {
        $response['message'] = 'Missing device_id';
        http_response_code(400);
        echo json_encode($response);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Get kiosk by device_id with company verification
    $stmt = $conn->prepare("SELECT k.id, k.device_id, k.company_id, c.name as company_name 
                            FROM kiosks k
                            LEFT JOIN companies c ON k.company_id = c.id
                            WHERE k.device_id = ?");
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        // Device not found - do not reveal why (security)
        $response['message'] = 'Unauthorized';
        http_response_code(403);
        echo json_encode($response);
        $stmt->close();
        closeDbConnection($conn);
        exit;
    }
    
    $kiosk = $result->fetch_assoc();
    $kiosk_id = $kiosk['id'];
    $company_id = $kiosk['company_id'];
    $company_name = $kiosk['company_name'];
    $stmt->close();

    // Enforce company ownership
    api_require_company_match($api_company, $company_id, 'Unauthorized');
    
    // SECURITY: Verify device belongs to a company
    if (empty($company_id) || $company_id === null) {
        $response['message'] = 'Device not assigned to any company';
        http_response_code(403);
        echo json_encode($response);
        closeDbConnection($conn);
        exit;
    }
    
    // Get kiosk's group assignment
    $stmt = $conn->prepare("SELECT group_id FROM kiosk_group_assignments WHERE kiosk_id = ? LIMIT 1");
    
    if (!$stmt) {
        throw new Exception("Prepare failed: " . $conn->error);
    }
    
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    $group_id = null;
    if ($result->num_rows > 0) {
        $row = $result->fetch_assoc();
        $group_id = $row['group_id'];
    }
    $stmt->close();
    
    // If no group assigned, check kiosk_modules
    if (empty($group_id)) {
        // Check if kiosk has direct module assignments
        $stmt = $conn->prepare("SELECT COUNT(*) as module_count FROM kiosk_modules 
                                WHERE kiosk_id = ? AND is_active = 1");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $stmt->close();
        
        if ($row['module_count'] == 0) {
            $response['message'] = 'No group or modules assigned';
            http_response_code(400);
            echo json_encode($response);
            closeDbConnection($conn);
            exit;
        }
        
        // Get latest update from kiosk_modules
        $stmt = $conn->prepare("SELECT MAX(created_at) as latest_update
                    FROM kiosk_modules 
                    WHERE kiosk_id = ? AND is_active = 1");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        $latest_update = $row['latest_update'] ?? date('Y-m-d H:i:s');
        $stmt->close();

        $meal_stmt = $conn->prepare("SELECT km.settings
                                     FROM kiosk_modules km
                                     JOIN modules m ON m.id = km.module_id
                                     WHERE km.kiosk_id = ? AND km.is_active = 1
                                       AND (LOWER(COALESCE(NULLIF(km.module_key, ''), m.module_key)) = 'meal-menu'
                                            OR LOWER(COALESCE(NULLIF(km.module_key, ''), m.module_key)) = 'meal_menu')");
        if ($meal_stmt) {
            $meal_stmt->bind_param('i', $kiosk_id);
            $meal_stmt->execute();
            $meal_rows = [];
            $meal_result = $meal_stmt->get_result();
            while ($meal_row = $meal_result->fetch_assoc()) {
                $meal_rows[] = $meal_row;
            }
            $meal_stmt->close();

            $meal_latest_update = edudisplej_collect_meal_menu_latest_update_from_rows($conn, (int)$company_id, $meal_rows);
            $latest_update = edudisplej_pick_newer_timestamp($latest_update, $meal_latest_update) ?? $latest_update;
        }
        
        $response['success'] = true;
        $response['kiosk_id'] = $kiosk_id;
        $response['device_id'] = $device_id;
        $response['company_id'] = $company_id;
        $response['company_name'] = $company_name;
        $response['config_source'] = 'kiosk';
        $response['loop_updated_at'] = $latest_update;
        
    } else {
        // SECURITY: Verify group belongs to same company
        $stmt = $conn->prepare("SELECT id, company_id FROM kiosk_groups WHERE id = ?");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $response['message'] = 'Unauthorized';
            http_response_code(403);
            echo json_encode($response);
            $stmt->close();
            closeDbConnection($conn);
            exit;
        }
        
        $group = $result->fetch_assoc();
        $stmt->close();
        
        // Verify group belongs to kiosk's company
        if ($group['company_id'] != $company_id) {
            $response['message'] = 'Unauthorized: Group does not belong to device\'s company';
            http_response_code(403);
            echo json_encode($response);
            closeDbConnection($conn);
            exit;
        }
        
        $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_loop_plans (
            group_id INT PRIMARY KEY,
            plan_json LONGTEXT NOT NULL,
            plan_version BIGINT NOT NULL DEFAULT 0,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_updated_at (updated_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        // Get latest update from group modules + loop plan publication
        $stmt = $conn->prepare("SELECT
                                    MAX(kgm.updated_at) as latest_update,
                                    MAX(kgm.created_at) as created_at,
                                    COUNT(kgm.id) as module_count,
                                    (SELECT klp.updated_at FROM kiosk_group_loop_plans klp WHERE klp.group_id = ? LIMIT 1) as plan_updated_at,
                                    (SELECT klp.plan_version FROM kiosk_group_loop_plans klp WHERE klp.group_id = ? LIMIT 1) as plan_version
                                FROM kiosk_group_modules kgm
                                WHERE kgm.group_id = ? AND kgm.is_active = 1");
        
        if (!$stmt) {
            throw new Exception("Prepare failed: " . $conn->error);
        }
        
        $stmt->bind_param("iii", $group_id, $group_id, $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $row = $result->fetch_assoc();
        
        if ($row['module_count'] == 0) {
            $response['message'] = 'No active modules in group';
            http_response_code(400);
            echo json_encode($response);
            $stmt->close();
            closeDbConnection($conn);
            exit;
        }
        
        $module_update = $row['latest_update'] ?? $row['created_at'] ?? null;
        $plan_update = $row['plan_updated_at'] ?? null;
        $latest_update = $module_update;
        if ($plan_update && (!$latest_update || strtotime($plan_update) > strtotime($latest_update))) {
            $latest_update = $plan_update;
        }
        if (!$latest_update) {
            $latest_update = date('Y-m-d H:i:s');
        }
        $stmt->close();

        $meal_stmt = $conn->prepare("SELECT kgm.settings
                                     FROM kiosk_group_modules kgm
                                     JOIN modules m ON m.id = kgm.module_id
                                     WHERE kgm.group_id = ? AND kgm.is_active = 1
                                       AND (LOWER(COALESCE(NULLIF(kgm.module_key, ''), m.module_key)) = 'meal-menu'
                                            OR LOWER(COALESCE(NULLIF(kgm.module_key, ''), m.module_key)) = 'meal_menu')");
        if ($meal_stmt) {
            $meal_stmt->bind_param('i', $group_id);
            $meal_stmt->execute();
            $meal_rows = [];
            $meal_result = $meal_stmt->get_result();
            while ($meal_row = $meal_result->fetch_assoc()) {
                $meal_rows[] = $meal_row;
            }
            $meal_stmt->close();

            $meal_latest_update = edudisplej_collect_meal_menu_latest_update_from_rows($conn, (int)$company_id, $meal_rows);
            $latest_update = edudisplej_pick_newer_timestamp($latest_update, $meal_latest_update) ?? $latest_update;
        }
        
        $response['success'] = true;
        $response['kiosk_id'] = $kiosk_id;
        $response['device_id'] = $device_id;
        $response['company_id'] = $company_id;
        $response['company_name'] = $company_name;
        $response['group_id'] = $group_id;
        $response['config_source'] = 'group';
        $response['module_count'] = (int)$row['module_count'];
        $response['loop_updated_at'] = $latest_update;
        $response['loop_plan_version'] = (int)($row['plan_version'] ?? 0);
    }
    
    closeDbConnection($conn);
    
    // Use JSON encoding options to prevent output truncation
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('Check Group Loop Update API Error: ' . $e->getMessage());
    $response['message'] = 'Server error';
    http_response_code(500);
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
