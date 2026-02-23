<?php
/**
 * API - Download Module Files
 * Serves module files to authorized kiosks
 * No session required - uses device_id authentication
 */
require_once '../dbkonfiguracia.php';
require_once 'auth.php';
require_once '../modules/module_standard.php';

$api_company = validate_api_token();

header('Content-Type: application/json');

$response = ['success' => false, 'message' => ''];

try {
    // Get request data
    $device_id = $_POST['device_id'] ?? $_GET['device_id'] ?? '';
    $module_name = $_POST['module_name'] ?? $_GET['module_name'] ?? '';
    
    if (empty($device_id) || empty($module_name)) {
        $response['message'] = 'Missing device_id or module_name';
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $conn = getDbConnection();
    
    // Verify kiosk exists and get group
    $stmt = $conn->prepare("
        SELECT k.id, k.company_id, kga.group_id
        FROM kiosks k
        LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
        WHERE k.device_id = ?
    ");
    $stmt->bind_param("s", $device_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    if ($result->num_rows === 0) {
        $response['message'] = 'Kiosk not found';
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $kiosk = $result->fetch_assoc();
    $kiosk_id = $kiosk['id'];
    $group_id = $kiosk['group_id'];
    $stmt->close();

    // Enforce company ownership
    api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');

    if (!empty($group_id)) {
        api_require_group_company($conn, $api_company, (int)$group_id);
    }
    
    // Check if module is authorized for this kiosk
    // Either through group assignment, direct kiosk assignment,
    // or group planner loop styles (kiosk_group_loop_plans.plan_json)
    $authorized = false;
    
    // Check group modules (use module_key instead of name)
    if ($group_id) {
        $stmt = $conn->prepare("
            SELECT kgm.id
            FROM kiosk_group_modules kgm
            JOIN modules m ON kgm.module_id = m.id
            WHERE kgm.group_id = ? AND m.module_key = ?
        ");
        $stmt->bind_param("is", $group_id, $module_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $authorized = true;
        }
        $stmt->close();
    }
    
    // Check direct kiosk modules (use module_key instead of name)
    if (!$authorized) {
        $stmt = $conn->prepare("
            SELECT km.id
            FROM kiosk_modules km
            JOIN modules m ON km.module_id = m.id
            WHERE km.kiosk_id = ? AND m.module_key = ?
        ");
        $stmt->bind_param("is", $kiosk_id, $module_name);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($result->num_rows > 0) {
            $authorized = true;
        }
        $stmt->close();
    }

    // Check group planner loop styles (JSON plan) for module_key
    if (!$authorized && !empty($group_id)) {
        $plan_stmt = $conn->prepare("SELECT plan_json FROM kiosk_group_loop_plans WHERE group_id = ? LIMIT 1");
        if ($plan_stmt) {
            $plan_stmt->bind_param("i", $group_id);
            $plan_stmt->execute();
            $plan_row = $plan_stmt->get_result()->fetch_assoc();
            $plan_stmt->close();

            if (!empty($plan_row['plan_json'])) {
                $decoded_plan = json_decode((string)$plan_row['plan_json'], true);
                if (is_array($decoded_plan)) {
                    $loop_styles = is_array($decoded_plan['loop_styles'] ?? null) ? $decoded_plan['loop_styles'] : [];
                    foreach ($loop_styles as $style) {
                        if (!is_array($style)) {
                            continue;
                        }
                        $items = is_array($style['items'] ?? null) ? $style['items'] : [];
                        foreach ($items as $item) {
                            if (!is_array($item)) {
                                continue;
                            }
                            $item_key = (string)($item['module_key'] ?? '');
                            if ($item_key !== '' && strcasecmp($item_key, $module_name) === 0) {
                                $authorized = true;
                                break 2;
                            }
                        }
                    }
                }
            }
        }
    }
    
    closeDbConnection($conn);
    
    if (!$authorized) {
        $response['message'] = 'Module not authorized for this kiosk';
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $validation = edudisplej_validate_module_folder($module_name);
    $runtime = $validation['runtime'];
    $module_dir_key = $runtime['folder'];
    $module_dir = $runtime['folder_abs'];

    if (!$validation['ok']) {
        $response['message'] = 'Module structure invalid: ' . implode('; ', $validation['errors']);
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!is_dir($module_dir)) {
        $response['message'] = "Module directory not found: {$module_dir_key}";
        echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // Get all files in module directory (recursively)
    $files = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($module_dir, RecursiveDirectoryIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $filepath = $file->getPathname();
            $relative_path = str_replace(rtrim($module_dir, '/\\') . DIRECTORY_SEPARATOR, '', $filepath);
            $relative_path = str_replace('\\', '/', $relative_path);
            
            // Read file content
            $content = file_get_contents($filepath);
            
            $files[] = [
                'path' => $relative_path,
                'content' => base64_encode($content),
                'size' => filesize($filepath),
                'modified' => date('Y-m-d H:i:s', filemtime($filepath))
            ];
        }
    }
    
    $response['success'] = true;
    $response['message'] = 'Module files retrieved successfully';
    $response['module_name'] = $module_name;
    $response['files'] = $files;
    $response['file_count'] = count($files);
    $response['last_update'] = date('Y-m-d H:i:s');
    
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    
} catch (Exception $e) {
    error_log('Download Module API Error: ' . $e->getMessage());
    $response['message'] = 'Server error: ' . $e->getMessage();
    echo json_encode($response, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
}
