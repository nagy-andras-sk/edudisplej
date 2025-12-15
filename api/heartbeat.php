<?php
/**
 * Heartbeat Service
 * This script runs periodically to send heartbeat signals to the main server
 */

// Include shared configuration (contains loadEnv function)
require_once __DIR__ . '/config.php';

/**
 * Send heartbeat to main server
 * @return array Response data
 */
function sendHeartbeat() {
    global $config;
    
    $pairingId = $config['pairing_id'];
    $mainServer = $config['main_server'];
    
    if (empty($pairingId)) {
        return ['success' => false, 'error' => 'Pairing ID not configured'];
    }
    
    // Prepare heartbeat data
    $heartbeatData = [
        'pairing_id' => $pairingId,
        'timestamp' => time(),
        'status' => 'online',
        'ip' => getPublicIp(),
        'upnp_enabled' => $config['upnp_enabled'],
    ];
    
    // Send heartbeat to main server
    $url = rtrim($mainServer, '/') . '/api/file_server_heartbeat.php';
    
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($heartbeatData));
    curl_setopt($ch, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        'User-Agent: TR2-FileServer/1.0'
    ]);
    curl_setopt($ch, CURLOPT_TIMEOUT, 10);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);
    
    if ($error) {
        logMessage("Heartbeat failed: $error", 'ERROR');
        return ['success' => false, 'error' => $error];
    }
    
    logMessage("Heartbeat sent successfully (HTTP $httpCode)", 'INFO');
    
    $responseData = json_decode($response, true);
    return $responseData ?: ['success' => true, 'http_code' => $httpCode];
}

/**
 * Get public IP address
 * @return string Public IP or 'unknown'
 */
function getPublicIp() {
    static $cachedIp = null;
    
    if ($cachedIp !== null) {
        return $cachedIp;
    }
    
    // Try to get from environment first
    $publicIp = getenv('TR2_PUBLIC_IP');
    if (!empty($publicIp)) {
        $cachedIp = $publicIp;
        return $cachedIp;
    }
    
    // Try multiple services to get public IP
    $services = [
        'https://api.ipify.org',
        'https://ifconfig.me/ip',
        'https://icanhazip.com'
    ];
    
    foreach ($services as $service) {
        $ch = curl_init($service);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 5);
        $ip = trim(curl_exec($ch));
        curl_close($ch);
        
        if (!empty($ip) && filter_var($ip, FILTER_VALIDATE_IP)) {
            $cachedIp = $ip;
            return $cachedIp;
        }
    }
    
    $cachedIp = 'unknown';
    return $cachedIp;
}

// Main execution when called directly
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    // Check if running as CLI (for daemon mode) or via HTTP request
    if (php_sapi_name() === 'cli') {
        // Daemon mode - run continuously
        echo "TR2 Heartbeat Service Starting..." . PHP_EOL;
        echo "Pairing ID: " . $config['pairing_id'] . PHP_EOL;
        echo "Main Server: " . $config['main_server'] . PHP_EOL;
        echo "Public IP: " . getPublicIp() . PHP_EOL;
        echo "Heartbeat Interval: " . $config['heartbeat_interval'] . " seconds (dynamic)" . PHP_EOL;
        echo "Heartbeat URL: " . rtrim($config['main_server'], '/') . '/api/file_server_heartbeat.php' . PHP_EOL;
        echo str_repeat('=', 40) . PHP_EOL;
        
        // Run diagnostics on startup
        echo "[" . date('Y-m-d H:i:s') . "] Running network diagnostics..." . PHP_EOL;
        system('php ' . __DIR__ . '/diagnostics.php > /dev/null 2>&1');
        
        while (true) {
            $result = sendHeartbeat();
            
            if ($result['success']) {
                echo "[" . date('Y-m-d H:i:s') . "] Heartbeat sent successfully" . PHP_EOL;
            } else {
                echo "[" . date('Y-m-d H:i:s') . "] Heartbeat failed: " . ($result['error'] ?? 'Unknown error') . PHP_EOL;
            }
            
            // Sleep for configured interval
            sleep($config['heartbeat_interval']);
        }
    } else {
        // HTTP request mode - send single heartbeat
        $result = sendHeartbeat();
        sendResponse($result, $result['success'] ? 200 : 500);
    }
}
