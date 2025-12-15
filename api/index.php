<?php
/**
 * API Index
 * Main entry point for the TR2 File Server API
 */

// Include shared configuration (contains loadEnv function)
require_once __DIR__ . '/config.php';

/**
 * Get API status
 * @return array Status information
 */
function getApiStatus() {
    global $config;
    
    return [
        'success' => true,
        'service' => 'TR2 File Server API',
        'version' => '1.0.0',
        'status' => 'running',
        'pairing_id' => $config['pairing_id'],
        'main_server' => $config['main_server'],
        'upnp_enabled' => $config['upnp_enabled'],
        'heartbeat_interval' => $config['heartbeat_interval'],
        'timestamp' => date('Y-m-d H:i:s'),
        'endpoints' => [
            '/api/index.php' => 'API status and information',
            '/api/heartbeat.php' => 'Send heartbeat to main server',
            '/api/diagnostics.php' => 'System diagnostics',
            '/api/qbit-password-manager.php' => 'qBittorrent credentials management',
            '/api/file_server_heartbeat.php' => 'Receive heartbeat from file servers'
        ]
    ];
}

// Main execution
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    $status = getApiStatus();
    logMessage("API status check - Service running", 'INFO');
    sendResponse($status);
}
