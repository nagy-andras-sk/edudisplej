<?php
/**
 * File Server Heartbeat Receiver
 * This endpoint receives heartbeat signals from remote file servers
 */

// Include shared configuration (contains loadEnv function)
require_once __DIR__ . '/config.php';

/**
 * Process heartbeat from file server
 * @param array $data Heartbeat data
 * @return array Response data
 */
function processHeartbeat($data) {
    // Validate required fields
    if (empty($data['pairing_id'])) {
        return [
            'success' => false,
            'error' => 'Missing pairing_id'
        ];
    }
    
    $pairingId = $data['pairing_id'];
    $timestamp = $data['timestamp'] ?? time();
    $status = $data['status'] ?? 'unknown';
    $ip = $data['ip'] ?? 'unknown';
    $upnpEnabled = $data['upnp_enabled'] ?? false;
    
    // Log heartbeat
    logMessage(
        "Heartbeat received from $pairingId - Status: $status, IP: $ip, UPnP: " . 
        ($upnpEnabled ? 'enabled' : 'disabled'),
        'INFO'
    );
    
    // Store heartbeat data (in production, this would go to a database)
    $heartbeatFile = "/tmp/heartbeat_$pairingId.json";
    $heartbeatData = [
        'pairing_id' => $pairingId,
        'last_seen' => $timestamp,
        'status' => $status,
        'ip' => $ip,
        'upnp_enabled' => $upnpEnabled,
        'received_at' => time()
    ];
    
    file_put_contents($heartbeatFile, json_encode($heartbeatData, JSON_PRETTY_PRINT));
    
    // Prepare response with any commands or updates for the file server
    $response = [
        'success' => true,
        'message' => 'Heartbeat received',
        'server_time' => time(),
        'interval' => 30, // Heartbeat interval in seconds
        'commands' => [] // Any pending commands for the file server
    ];
    
    // Check for pending commands (in production, this would come from database)
    $commandFile = "/tmp/commands_$pairingId.json";
    if (file_exists($commandFile)) {
        $commands = json_decode(file_get_contents($commandFile), true);
        if (is_array($commands)) {
            $response['commands'] = $commands;
            // Clear commands after sending
            unlink($commandFile);
        }
    }
    
    return $response;
}

/**
 * Get file server status
 * @param string $pairingId Pairing ID
 * @return array Status data
 */
function getFileServerStatus($pairingId) {
    $heartbeatFile = "/tmp/heartbeat_$pairingId.json";
    
    if (!file_exists($heartbeatFile)) {
        return [
            'success' => false,
            'error' => 'No heartbeat data found',
            'status' => 'offline'
        ];
    }
    
    $data = json_decode(file_get_contents($heartbeatFile), true);
    $lastSeen = $data['last_seen'] ?? 0;
    $timeSinceLastSeen = time() - $lastSeen;
    
    // Consider offline if no heartbeat in last 2 minutes
    $isOnline = $timeSinceLastSeen < 120;
    
    return [
        'success' => true,
        'pairing_id' => $pairingId,
        'status' => $isOnline ? 'online' : 'offline',
        'last_seen' => $lastSeen,
        'time_since_last_seen' => $timeSinceLastSeen,
        'data' => $data
    ];
}

// Main execution
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'POST') {
        // Receive heartbeat from file server
        $data = getJsonInput();
        
        if ($data === null) {
            sendResponse([
                'success' => false,
                'error' => 'Invalid JSON data'
            ], 400);
        }
        
        $response = processHeartbeat($data);
        sendResponse($response, $response['success'] ? 200 : 400);
        
    } elseif ($method === 'GET') {
        // Get file server status
        $pairingId = $_GET['pairing_id'] ?? '';
        
        if (empty($pairingId)) {
            // List all file servers
            $heartbeatFiles = glob('/tmp/heartbeat_*.json');
            $servers = [];
            
            foreach ($heartbeatFiles as $file) {
                $data = json_decode(file_get_contents($file), true);
                if ($data) {
                    $servers[] = getFileServerStatus($data['pairing_id']);
                }
            }
            
            sendResponse([
                'success' => true,
                'servers' => $servers,
                'count' => count($servers)
            ]);
        } else {
            // Get specific file server status
            $status = getFileServerStatus($pairingId);
            sendResponse($status, $status['success'] ? 200 : 404);
        }
        
    } else {
        sendResponse([
            'success' => false,
            'error' => 'Method not allowed'
        ], 405);
    }
}
