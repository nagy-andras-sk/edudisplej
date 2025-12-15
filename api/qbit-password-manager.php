<?php
/**
 * qBittorrent Password Manager
 * Manages qBittorrent authentication credentials
 */

// Include shared configuration (contains loadEnv function)
require_once __DIR__ . '/config.php';

/**
 * Get qBittorrent credentials
 * @return array Credentials array with username and password
 */
function getQbitCredentials() {
    $username = getenv('QBIT_USERNAME');
    $password = getenv('QBIT_PASSWORD');
    
    // Use defaults if not set
    if (empty($username)) {
        $username = 'admin';
    }
    
    if (empty($password)) {
        // Try to read from file if exists
        $passwordFile = '/var/www/qbit_password.txt';
        if (file_exists($passwordFile)) {
            $password = trim(file_get_contents($passwordFile));
        } else {
            $password = 'adminadmin';
        }
    }
    
    return [
        'username' => $username,
        'password' => $password
    ];
}

/**
 * Set qBittorrent credentials
 * @param string $username Username
 * @param string $password Password
 * @return bool Success status
 */
function setQbitCredentials($username, $password) {
    // Update environment variables
    putenv("QBIT_USERNAME=$username");
    $_ENV['QBIT_USERNAME'] = $username;
    
    putenv("QBIT_PASSWORD=$password");
    $_ENV['QBIT_PASSWORD'] = $password;
    
    // Save password to file for persistence
    $passwordFile = '/var/www/qbit_password.txt';
    if (file_put_contents($passwordFile, $password) === false) {
        logMessage("Failed to save qBittorrent password to file", 'ERROR');
        return false;
    }
    
    logMessage("qBittorrent credentials updated", 'INFO');
    return true;
}

/**
 * Verify qBittorrent connection
 * @return bool True if connection successful
 */
function verifyQbitConnection() {
    $credentials = getQbitCredentials();
    $qbitUrl = getenv('QBIT_URL') ?: 'http://localhost:8080';
    
    // Try to login to qBittorrent
    $ch = curl_init("$qbitUrl/api/v2/auth/login");
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'username' => $credentials['username'],
        'password' => $credentials['password']
    ]));
    curl_setopt($ch, CURLOPT_TIMEOUT, 5);
    
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    return ($httpCode === 200 && $response === 'Ok.');
}

// Handle API requests
if (basename(__FILE__) == basename($_SERVER['SCRIPT_FILENAME'])) {
    $method = $_SERVER['REQUEST_METHOD'];
    
    if ($method === 'GET') {
        // Get credentials (masked password)
        $credentials = getQbitCredentials();
        sendResponse([
            'success' => true,
            'username' => $credentials['username'],
            'password_set' => !empty($credentials['password']),
            'connection_ok' => verifyQbitConnection()
        ]);
    } elseif ($method === 'POST') {
        // Set credentials
        $data = getJsonInput();
        
        if (empty($data['username']) || empty($data['password'])) {
            sendResponse([
                'success' => false,
                'error' => 'Username and password are required'
            ], 400);
        }
        
        $success = setQbitCredentials($data['username'], $data['password']);
        
        sendResponse([
            'success' => $success,
            'message' => $success ? 'Credentials updated successfully' : 'Failed to update credentials'
        ], $success ? 200 : 500);
    } else {
        sendResponse([
            'success' => false,
            'error' => 'Method not allowed'
        ], 405);
    }
}
