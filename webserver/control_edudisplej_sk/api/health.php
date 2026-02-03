<?php
/**
 * Health Check API
 * EduDisplej Control Panel
 * 
 * Usage: curl https://control.edudisplej.sk/api/health.php
 */
$start_time = microtime(true);
header('Content-Type: application/json');
require_once __DIR__ . '/auth.php';

validate_api_token();

$health = [
    'status' => 'ok',
    'timestamp' => date('Y-m-d H:i:s'),
    'checks' => []
];

// Check 1: PHP Version
$health['checks']['php_version'] = [
    'value' => PHP_VERSION,
    'status' => version_compare(PHP_VERSION, '7.4.0', '>=') ? 'ok' : 'warning'
];

// Check 2: Required PHP Extensions
$required_extensions = ['mysqli', 'json', 'mbstring'];
foreach ($required_extensions as $ext) {
    $health['checks']['extension_' . $ext] = [
        'loaded' => extension_loaded($ext),
        'status' => extension_loaded($ext) ? 'ok' : 'error'
    ];
    
    if (!extension_loaded($ext)) {
        $health['status'] = 'error';
    }
}

// Check 3: Database Configuration File
$db_config_path = __DIR__ . '/../dbkonfiguracia.php';
$health['checks']['db_config_file'] = [
    'exists' => file_exists($db_config_path),
    'readable' => is_readable($db_config_path),
    'status' => (file_exists($db_config_path) && is_readable($db_config_path)) ? 'ok' : 'error'
];

if (!file_exists($db_config_path) || !is_readable($db_config_path)) {
    $health['status'] = 'error';
}

// Check 4: Database Connection
try {
    if (file_exists($db_config_path)) {
        require_once $db_config_path;
        
        if (function_exists('getDbConnection')) {
            $conn = getDbConnection();
            
            $health['checks']['database_connection'] = [
                'status' => 'ok',
                'host_info' => $conn->host_info ?? 'unknown',
                'server_info' => $conn->server_info ?? 'unknown',
                'protocol_version' => $conn->protocol_version ?? 'unknown'
            ];
            
            // Check 5: Kiosks Table
            $table_check = $conn->query("SHOW TABLES LIKE 'kiosks'");
            $health['checks']['kiosks_table'] = [
                'exists' => ($table_check && $table_check->num_rows > 0),
                'status' => ($table_check && $table_check->num_rows > 0) ? 'ok' : 'error'
            ];
            
            if (!$table_check || $table_check->num_rows === 0) {
                $health['status'] = 'error';
                $health['checks']['kiosks_table']['message'] = 'Table does not exist - run migration';
            }
            
            // Check 6: Count kiosks
            if ($table_check && $table_check->num_rows > 0) {
                $count_result = $conn->query("SELECT COUNT(*) as total FROM kiosks");
                if ($count_result) {
                    $row = $count_result->fetch_assoc();
                    $health['checks']['kiosks_count'] = [
                        'total' => (int)$row['total'],
                        'status' => 'info'
                    ];
                }
            }
            
            closeDbConnection($conn);
        } else {
            $health['checks']['database_connection'] = [
                'status' => 'error',
                'message' => 'getDbConnection() function not found in dbkonfiguracia.php'
            ];
            $health['status'] = 'error';
        }
    }
} catch (Exception $e) {
    $health['checks']['database_connection'] = [
        'status' => 'error',
        'message' => $e->getMessage(),
        'file' => $e->getFile(),
        'line' => $e->getLine()
    ];
    $health['status'] = 'error';
}

// Check 7: Write permissions (logs directory)
$log_dir = __DIR__ . '/../../logs';
$health['checks']['log_directory'] = [
    'exists' => is_dir($log_dir),
    'writable' => is_writable($log_dir),
    'status' => (is_dir($log_dir) && is_writable($log_dir)) ? 'ok' : 'warning'
];

// Overall status summary
$error_count = 0;
$warning_count = 0;
foreach ($health['checks'] as $check) {
    if (isset($check['status'])) {
        if ($check['status'] === 'error') $error_count++;
        if ($check['status'] === 'warning') $warning_count++;
    }
}

$health['summary'] = [
    'errors' => $error_count,
    'warnings' => $warning_count,
    'overall_status' => $health['status']
];

// Set HTTP status code based on health
if ($health['status'] === 'error') {
    http_response_code(503); // Service Unavailable
} else {
    http_response_code(200);
}

// Log API request
require_once '../logging.php';
$execution_time = microtime(true) - $start_time;
$status_code = $health['status'] === 'ok' ? 200 : 503;
log_api_request(
    null,
    null,
    '/api/health.php',
    'GET',
    $status_code,
    $_SERVER['REMOTE_ADDR'] ?? 'unknown',
    $_SERVER['HTTP_USER_AGENT'] ?? null,
    null,
    null,
    $execution_time
);

echo json_encode($health, JSON_PRETTY_PRINT);
?>

