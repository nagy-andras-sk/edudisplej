<?php
/**
 * API & Security Logging Helper Functions
 * EduDisplej Control Panel
 */

/**
 * Log API request
 * @param int $company_id Company ID
 * @param int $kiosk_id Kiosk ID (optional)
 * @param string $endpoint API endpoint
 * @param string $method HTTP method
 * @param int $status_code HTTP status code
 * @param string $ip_address IP address
 * @param string $user_agent User agent
 * @param array $request_data Request data (optional)
 * @param array $response_data Response data (optional)
 * @param float $execution_time Execution time in seconds
 */
function log_api_request($company_id, $kiosk_id, $endpoint, $method, $status_code, $ip_address, $user_agent = null, $request_data = null, $response_data = null, $execution_time = null) {
    try {
        require_once __DIR__ . '/dbkonfiguracia.php';
        $conn = getDbConnection();
        
        // Check if table exists
        $table_check = $conn->query("SHOW TABLES LIKE 'api_logs'");
        if ($table_check->num_rows === 0) {
            // Create table if it doesn't exist
            $conn->query("
                CREATE TABLE IF NOT EXISTS api_logs (
                    id INT AUTO_INCREMENT PRIMARY KEY,
                    company_id INT NULL,
                    kiosk_id INT NULL,
                    endpoint VARCHAR(255) NOT NULL,
                    method VARCHAR(10) NOT NULL DEFAULT 'GET',
                    status_code INT NOT NULL DEFAULT 200,
                    ip_address VARCHAR(45) NULL,
                    user_agent TEXT NULL,
                    request_data TEXT NULL,
                    response_data TEXT NULL,
                    execution_time FLOAT NULL,
                    timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_company (company_id),
                    INDEX idx_endpoint (endpoint),
                    INDEX idx_timestamp (timestamp),
                    INDEX idx_status (status_code),
                    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
                    FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE SET NULL
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        }
        
        $stmt = $conn->prepare("
            INSERT INTO api_logs 
            (company_id, kiosk_id, endpoint, method, status_code, ip_address, user_agent, request_data, response_data, execution_time) 
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $request_json = $request_data ? json_encode($request_data) : null;
        $response_json = $response_data ? json_encode($response_data) : null;
        
        $stmt->bind_param(
            "iisssisssd",
            $company_id,
            $kiosk_id,
            $endpoint,
            $method,
            $status_code,
            $ip_address,
            $user_agent,
            $request_json,
            $response_json,
            $execution_time
        );
        
        $stmt->execute();
        $stmt->close();
        closeDbConnection($conn);
    } catch (Exception $e) {
        error_log('Failed to log API request: ' . $e->getMessage());
    }
}

/**
 * Log security event
 * @param string $event_type Event type (failed_login, password_change, otp_setup, etc.)
 * @param int $user_id User ID (optional)
 * @param string $username Username
 * @param string $ip_address IP address
 * @param string $user_agent User agent (optional)
 * @param array $details Additional details (optional)
 */
if (!function_exists('log_security_event')) {
    function log_security_event($event_type, $user_id, $username, $ip_address, $user_agent = null, $details = null) {
        try {
            require_once __DIR__ . '/dbkonfiguracia.php';
            $conn = getDbConnection();
            
            // Check if table exists
            $table_check = $conn->query("SHOW TABLES LIKE 'security_logs'");
            if ($table_check->num_rows === 0) {
                // Create table if it doesn't exist
                $conn->query("
                    CREATE TABLE IF NOT EXISTS security_logs (
                        id INT AUTO_INCREMENT PRIMARY KEY,
                        event_type VARCHAR(50) NOT NULL,
                        user_id INT NULL,
                        username VARCHAR(100) NOT NULL,
                        ip_address VARCHAR(45) NOT NULL,
                        user_agent TEXT NULL,
                        details TEXT NULL,
                        timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_event_type (event_type),
                        INDEX idx_user (user_id),
                        INDEX idx_timestamp (timestamp),
                        INDEX idx_username (username),
                        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
                ");
            }
            
            $stmt = $conn->prepare("
                INSERT INTO security_logs 
                (event_type, user_id, username, ip_address, user_agent, details) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            
            $details_json = $details ? json_encode($details) : null;
            
            $stmt->bind_param(
                "sissss",
                $event_type,
                $user_id,
                $username,
                $ip_address,
                $user_agent,
                $details_json
            );
            
            $stmt->execute();
            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            error_log('Failed to log security event: ' . $e->getMessage());
        }
    }
}

/**
 * Get client IP address
 * @return string IP address
 */
function get_client_ip() {
    $ip_keys = ['HTTP_CLIENT_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_FORWARDED', 'HTTP_FORWARDED_FOR', 'HTTP_FORWARDED', 'REMOTE_ADDR'];
    foreach ($ip_keys as $key) {
        if (array_key_exists($key, $_SERVER) === true) {
            foreach (explode(',', $_SERVER[$key]) as $ip) {
                $ip = trim($ip);
                if (filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    return $ip;
                }
            }
        }
    }
    return $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
}

/**
 * Get user agent string
 * @return string User agent
 */
function get_user_agent() {
    return $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';
}

/**
 * Clean old logs (older than specified days)
 * @param int $days Number of days to keep
 */
function cleanup_old_logs($days = 90) {
    try {
        require_once __DIR__ . '/dbkonfiguracia.php';
        $conn = getDbConnection();
        
        // Clean API logs
        $conn->query("DELETE FROM api_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL $days DAY)");
        
        // Clean security logs (keep longer - 180 days)
        $security_days = max($days, 180);
        $conn->query("DELETE FROM security_logs WHERE timestamp < DATE_SUB(NOW(), INTERVAL $security_days DAY)");
        
        closeDbConnection($conn);
    } catch (Exception $e) {
        error_log('Failed to cleanup old logs: ' . $e->getMessage());
    }
}
