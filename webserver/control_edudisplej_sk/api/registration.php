<?php
/**
 * Registration API - Enhanced with detailed error reporting
 * EduDisplej Control Panel
 */

// Debug mode - set to true to see detailed errors in response
// IMPORTANT: Set to false in production!
// 
// Enable debug mode (true) when:
// - Setting up the system for the first time
// - Troubleshooting registration issues
// - Diagnosing "Server exception" errors
//
// Disable debug mode (false) when:
// - System is stable and working correctly
// - Running in production environment
// - You want to avoid exposing system details
//
define('DEBUG_MODE', true);

// Error reporting configuration
if (DEBUG_MODE) {
    ini_set('display_errors', 1);
    ini_set('display_startup_errors', 1);
    error_reporting(E_ALL);
} else {
    ini_set('display_errors', 0);
    error_reporting(E_ALL);
}

header('Content-Type: application/json');

// Response structure
$response = [
    'success' => false,
    'message' => '',
    'debug' => []
];

// Log function for debug mode
function add_debug($key, $value) {
    global $response;
    if (DEBUG_MODE) {
        $response['debug'][$key] = $value;
    }
    error_log("[Registration API] [$key] " . (is_string($value) ? $value : json_encode($value)));
}

try {
    add_debug('timestamp', date('Y-m-d H:i:s'));
    add_debug('php_version', PHP_VERSION);
    add_debug('request_method', $_SERVER['REQUEST_METHOD'] ?? 'unknown');
    add_debug('remote_addr', $_SERVER['REMOTE_ADDR'] ?? 'unknown');
    
    // Step 1: Read raw input
    $raw_input = file_get_contents('php://input');
    add_debug('raw_input_length', strlen($raw_input));
    
    if (DEBUG_MODE) {
        $response['debug']['raw_input'] = $raw_input;
    }
    
    if (empty($raw_input)) {
        $response['message'] = 'Empty request body';
        add_debug('error', 'Request body is empty');
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    // Step 2: Parse JSON
    add_debug('parsing_json', 'Attempting to decode JSON...');
    $data = json_decode($raw_input, true);
    
    if (json_last_error() !== JSON_ERROR_NONE) {
        $response['message'] = 'Invalid JSON: ' . json_last_error_msg();
        add_debug('json_error', json_last_error_msg());
        add_debug('json_error_code', json_last_error());
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    add_debug('json_parsed', 'JSON decoded successfully');
    add_debug('data_keys', array_keys($data));
    
    // Step 3: Extract parameters
    $mac = $data['mac'] ?? '';
    $hostname = $data['hostname'] ?? '';
    $hw_info = json_encode($data['hw_info'] ?? []);
    $public_ip = $_SERVER['REMOTE_ADDR'] ?? '';
    
    add_debug('extracted_mac', $mac);
    add_debug('extracted_hostname', $hostname);
    add_debug('extracted_public_ip', $public_ip);
    
    // Step 4: Validate MAC address
    if (empty($mac)) {
        $response['message'] = 'MAC address is required';
        add_debug('validation_error', 'MAC address missing in request');
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    if (strlen($mac) < 6) {
        $response['message'] = 'Invalid MAC address format (too short)';
        add_debug('validation_error', 'MAC address length: ' . strlen($mac));
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    add_debug('validation', 'Parameters validated successfully');
    
    // Step 5: Load database configuration
    add_debug('loading_db_config', 'Attempting to load dbkonfiguracia.php...');
    
    $db_config_path = __DIR__ . '/../dbkonfiguracia.php';
    add_debug('db_config_path', $db_config_path);
    add_debug('db_config_exists', file_exists($db_config_path) ? 'yes' : 'no');
    add_debug('db_config_readable', is_readable($db_config_path) ? 'yes' : 'no');
    
    if (!file_exists($db_config_path)) {
        $response['message'] = 'Database configuration file not found';
        add_debug('error', 'dbkonfiguracia.php does not exist at: ' . $db_config_path);
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    if (!is_readable($db_config_path)) {
        $response['message'] = 'Database configuration file not readable';
        add_debug('error', 'dbkonfiguracia.php is not readable - check permissions');
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    require_once $db_config_path;
    add_debug('db_config_loaded', 'dbkonfiguracia.php loaded successfully');
    
    // Step 6: Check if required functions exist
    if (!function_exists('getDbConnection')) {
        $response['message'] = 'Database function getDbConnection() not found';
        add_debug('error', 'getDbConnection() function missing in dbkonfiguracia.php');
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    add_debug('db_functions', 'Required database functions exist');
    
    // Step 7: Connect to database
    add_debug('connecting_db', 'Attempting database connection...');
    
    try {
        $conn = getDbConnection();
        add_debug('db_connection', 'Connected successfully');
        add_debug('db_host_info', $conn->host_info ?? 'unknown');
        add_debug('db_server_info', $conn->server_info ?? 'unknown');
    } catch (Exception $e) {
        $response['message'] = 'Database connection failed: ' . $e->getMessage();
        add_debug('db_connection_error', $e->getMessage());
        if (DEBUG_MODE) {
            $response['debug']['exception_trace'] = $e->getTraceAsString();
        }
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    // Step 8: Check if kiosks table exists
    add_debug('checking_table', 'Verifying kiosks table exists...');
    
    $table_check = $conn->query("SHOW TABLES LIKE 'kiosks'");
    if ($table_check && $table_check->num_rows === 0) {
        $response['message'] = 'Database table "kiosks" does not exist - run migration (dbjavito.php)';
        add_debug('table_error', 'kiosks table not found in database');
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    add_debug('table_check', 'kiosks table exists');
    
    // Step 9: Check if kiosk already exists
    add_debug('query_existing', 'Checking if kiosk already registered...');
    
    $stmt = $conn->prepare("
        SELECT k.id, k.device_id, k.is_configured, k.company_id,
               c.name as company_name,
               kg.name as group_name,
               kg.id as group_id
        FROM kiosks k
        LEFT JOIN companies c ON k.company_id = c.id
        LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
        LEFT JOIN kiosk_groups kg ON kga.group_id = kg.id
        WHERE k.mac = ?
    ");
    
    if (!$stmt) {
        $response['message'] = 'Database query preparation failed: ' . $conn->error;
        add_debug('prepare_error', $conn->error);
        add_debug('errno', $conn->errno);
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    $stmt->bind_param("s", $mac);
    
    if (!$stmt->execute()) {
        $response['message'] = 'Database query execution failed: ' . $stmt->error;
        add_debug('execute_error', $stmt->error);
        add_debug('errno', $stmt->errno);
        echo json_encode($response, JSON_PRETTY_PRINT);
        exit;
    }
    
    $result = $stmt->get_result();
    add_debug('query_rows', $result->num_rows);
    
    if ($result->num_rows > 0) {
        // Existing kiosk - update
        add_debug('operation', 'update_existing');
        $kiosk = $result->fetch_assoc();
        add_debug('existing_kiosk_id', $kiosk['id']);
        
        $update_stmt = $conn->prepare("UPDATE kiosks SET last_seen = NOW(), public_ip = ?, hw_info = ?, hostname = ?, status = 'online' WHERE id = ?");
        
        if (!$update_stmt) {
            $response['message'] = 'Update query preparation failed: ' . $conn->error;
            add_debug('update_prepare_error', $conn->error);
            echo json_encode($response, JSON_PRETTY_PRINT);
            exit;
        }
        
        $update_stmt->bind_param("sssi", $public_ip, $hw_info, $hostname, $kiosk['id']);
        
        if (!$update_stmt->execute()) {
            $response['message'] = 'Update execution failed: ' . $update_stmt->error;
            add_debug('update_execute_error', $update_stmt->error);
            echo json_encode($response, JSON_PRETTY_PRINT);
            exit;
        }
        
        $update_stmt->close();
        
        // Check if kiosk is fully configured (has company, group, and loop)
        $has_company = !empty($kiosk['company_id']);
        $has_group = !empty($kiosk['group_id']);
        $has_loop = false;
        
        // Check if group has loop modules
        if ($has_group) {
            $loop_check = $conn->prepare("SELECT COUNT(*) as count FROM kiosk_group_modules WHERE group_id = ?");
            $loop_check->bind_param("i", $kiosk['group_id']);
            $loop_check->execute();
            $loop_result = $loop_check->get_result()->fetch_assoc();
            $has_loop = $loop_result['count'] > 0;
            $loop_check->close();
        }
        
        $is_fully_configured = $has_company && $has_group && $has_loop;
        
        // Update is_configured flag if it changed
        if ($is_fully_configured != $kiosk['is_configured']) {
            $config_update = $conn->prepare("UPDATE kiosks SET is_configured = ? WHERE id = ?");
            $config_flag = $is_fully_configured ? 1 : 0;
            $config_update->bind_param("ii", $config_flag, $kiosk['id']);
            $config_update->execute();
            $config_update->close();
            add_debug('is_configured_updated', $is_fully_configured ? 'true' : 'false');
        }
        
        $response['success'] = true;
        $response['message'] = 'Kiosk synced successfully';
        $response['kiosk_id'] = (int)$kiosk['id'];
        $response['device_id'] = $kiosk['device_id'];
        $response['is_configured'] = $is_fully_configured;
        $response['company_assigned'] = $has_company;
        $response['company_name'] = $kiosk['company_name'] ?? 'Unknown';
        $response['group_name'] = $kiosk['group_name'] ?? 'Unknown';
        $response['has_loop'] = $has_loop;
        
        add_debug('result', 'Existing kiosk updated successfully');
        
    } else {
        // New kiosk - register
        add_debug('operation', 'register_new');
        
        $device_id = generateDeviceId($mac);
        add_debug('generated_device_id', $device_id);
        
        $insert_stmt = $conn->prepare("INSERT INTO kiosks (mac, device_id, hostname, hw_info, public_ip, status, is_configured, last_seen) VALUES (?, ?, ?, ?, ?, ?, 0, NOW())");
        
        if (!$insert_stmt) {
            $response['message'] = 'Insert query preparation failed: ' . $conn->error;
            add_debug('insert_prepare_error', $conn->error);
            echo json_encode($response, JSON_PRETTY_PRINT);
            exit;
        }
        
        $status_value = 'unconfigured';
        $insert_stmt->bind_param("ssssss", $mac, $device_id, $hostname, $hw_info, $public_ip, $status_value);
        
        if (!$insert_stmt->execute()) {
            $is_status_truncated = (
                $insert_stmt->errno === 1265 ||
                strpos($insert_stmt->error, "Data truncated for column 'status'") !== false
            );

            if ($is_status_truncated) {
                add_debug('status_fallback', 'Status value truncated, retrying with offline');
                $status_value = 'offline';

                if (!$insert_stmt->execute()) {
                    $response['message'] = 'Insert execution failed after fallback: ' . $insert_stmt->error;
                    add_debug('insert_execute_error', $insert_stmt->error);
                    echo json_encode($response, JSON_PRETTY_PRINT);
                    exit;
                }
            } else {
                $response['message'] = 'Insert execution failed: ' . $insert_stmt->error;
                add_debug('insert_execute_error', $insert_stmt->error);

                // Check for duplicate entry error
                if ($insert_stmt->errno === 1062) {
                    add_debug('duplicate_entry', 'MAC address already exists (race condition?)');
                }

                echo json_encode($response, JSON_PRETTY_PRINT);
                exit;
            }
        }
        
        $kiosk_id = $conn->insert_id;
        add_debug('insert_id', $kiosk_id);
        
        $insert_stmt->close();
        
        $response['success'] = true;
        $response['message'] = 'Kiosk registered successfully';
        $response['kiosk_id'] = $kiosk_id;
        $response['device_id'] = $device_id;
        $response['is_configured'] = false;
        $response['company_assigned'] = false;
        $response['company_name'] = 'Unknown';
        $response['group_name'] = 'Unknown';
        
        add_debug('result', 'New kiosk registered successfully');
    }
    
    $stmt->close();
    closeDbConnection($conn);
    
    add_debug('completed', 'API call completed successfully');
    
} catch (Exception $e) {
    $response['success'] = false;
    $response['message'] = 'Server exception: ' . $e->getMessage();
    
    add_debug('exception', $e->getMessage());
    add_debug('exception_file', $e->getFile());
    add_debug('exception_line', $e->getLine());
    
    if (DEBUG_MODE) {
        $response['debug']['exception_trace'] = explode("\n", $e->getTraceAsString());
    }
}

// Remove debug info if not in debug mode
if (!DEBUG_MODE) {
    unset($response['debug']);
}

echo json_encode($response, JSON_PRETTY_PRINT);

/**
 * Generate device ID: 4 random chars + 6 from MAC
 */
function generateDeviceId($mac) {
    // Generate 4 cryptographically secure random alphanumeric characters
    // Excluding easily confused characters (I, O, 0, 1)
    $chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $random_chars = '';
    $random_bytes = random_bytes(4);
    for ($i = 0; $i < 4; $i++) {
        $random_chars .= $chars[ord($random_bytes[$i]) % strlen($chars)];
    }
    
    // Extract 6 characters from MAC address (remove colons and take first 6)
    $mac_clean = str_replace([':', '-'], '', strtoupper($mac));
    $mac_chars = substr($mac_clean, 0, 6);
    
    // Combine to create device ID (min 10 chars)
    return $random_chars . $mac_chars;
}
?>

