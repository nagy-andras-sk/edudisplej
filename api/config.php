<?php
/**
 * Shared Configuration File
 * This file contains common functions and should be included only once in each script
 */

// Prevent redeclaration by checking if function already exists
if (!function_exists('loadEnv')) {
    /**
     * Load environment variables from .env file
     * @param string $envFile Path to the .env file
     * @return bool True if successful, false otherwise
     */
    function loadEnv($envFile = '/var/www/.env') {
        if (!file_exists($envFile)) {
            error_log("loadEnv: .env file not found at $envFile");
            return false;
        }
        
        $lines = file($envFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            error_log("loadEnv: Failed to read .env file at $envFile");
            return false;
        }
        
        foreach ($lines as $line) {
            // Skip comments
            if (strpos(trim($line), '#') === 0) {
                continue;
            }
            
            // Parse KEY=VALUE format
            if (strpos($line, '=') !== false) {
                list($key, $value) = explode('=', $line, 2);
                $key = trim($key);
                $value = trim($value);
                
                // Remove quotes from value if present
                $value = trim($value, '"\'');
                
                // Set environment variable
                putenv("$key=$value");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }
        
        return true;
    }
}

// Load environment variables
loadEnv();

// Common configuration
$config = [
    'env_file' => '/var/www/.env',
    'log_file' => '/var/log/tr2-server.log',
    'main_server' => getenv('TR2_MAIN_SERVER') ?: 'https://tr.nagyandras.sk',
    'pairing_id' => getenv('TR2_PAIRING_ID') ?: '',
    'upnp_enabled' => filter_var(getenv('TR2_UPNP_ENABLED') ?: 'false', FILTER_VALIDATE_BOOLEAN),
    'heartbeat_interval' => (int)(getenv('TR2_HEARTBEAT_INTERVAL') ?: 30),
];

/**
 * Log a message with timestamp
 * @param string $message The message to log
 * @param string $level Log level (INFO, ERROR, WARNING)
 */
function logMessage($message, $level = 'INFO') {
    global $config;
    $timestamp = date('Y-m-d H:i:s');
    $logEntry = "[$timestamp] [$level] $message" . PHP_EOL;
    
    // Log to file
    if (isset($config['log_file'])) {
        error_log($logEntry, 3, $config['log_file']);
    }
    
    // Also log to PHP error log
    error_log($message);
}

/**
 * Send HTTP response
 * @param mixed $data Response data
 * @param int $statusCode HTTP status code
 */
function sendResponse($data, $statusCode = 200) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

/**
 * Get POST data as JSON
 * @return array|null Decoded JSON data or null on error
 */
function getJsonInput() {
    $input = file_get_contents('php://input');
    if (empty($input)) {
        return null;
    }
    
    $data = json_decode($input, true);
    if (json_last_error() !== JSON_ERROR_NONE) {
        return null;
    }
    
    return $data;
}
