<?php
/**
 * Security Configuration
 * EduDisplej Control Panel
 * 
 * Provides encryption and security utilities
 */

// Session security settings
ini_set('session.cookie_httponly', 1);
ini_set('session.cookie_secure', 1);
ini_set('session.use_only_cookies', 1);
ini_set('session.cookie_samesite', 'Strict');

// Encryption key - should be stored securely in production
define('ENCRYPTION_KEY', getenv('EDUDISPLEJ_ENCRYPTION_KEY') ?: 'default-key-change-in-production');
define('ENCRYPTION_METHOD', 'AES-256-CBC');

/**
 * Encrypt sensitive data
 * @param string $data Data to encrypt
 * @return string Encrypted data (base64 encoded)
 */
function encrypt_data($data) {
    $iv = openssl_random_pseudo_bytes(openssl_cipher_iv_length(ENCRYPTION_METHOD));
    $encrypted = openssl_encrypt($data, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);
    
    // Return base64 encoded string with IV prepended
    return base64_encode($iv . $encrypted);
}

/**
 * Decrypt sensitive data
 * @param string $data Encrypted data (base64 encoded)
 * @return string|false Decrypted data or false on failure
 */
function decrypt_data($data) {
    $data = base64_decode($data);
    $iv_length = openssl_cipher_iv_length(ENCRYPTION_METHOD);
    
    $iv = substr($data, 0, $iv_length);
    $encrypted = substr($data, $iv_length);
    
    return openssl_decrypt($encrypted, ENCRYPTION_METHOD, ENCRYPTION_KEY, 0, $iv);
}

/**
 * Sanitize input to prevent XSS
 * @param string $data Input data
 * @return string Sanitized data
 */
function sanitize_input($data) {
    $data = trim($data);
    $data = stripslashes($data);
    $data = htmlspecialchars($data, ENT_QUOTES, 'UTF-8');
    return $data;
}

/**
 * Validate email address
 * @param string $email Email to validate
 * @return bool True if valid
 */
function validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/**
 * Generate secure random token
 * @param int $length Token length
 * @return string Random token
 */
function generate_secure_token($length = 32) {
    return bin2hex(random_bytes($length));
}

/**
 * Hash API token for storage
 * @param string $token Token to hash
 * @return string Hashed token
 */
function hash_api_token($token) {
    return hash('sha256', $token);
}

/**
 * Verify API token against hash
 * @param string $token Plain token
 * @param string $hash Stored hash
 * @return bool True if matches
 */
function verify_api_token($token, $hash) {
    return hash_equals(hash('sha256', $token), $hash);
}

/**
 * Rate limit check for API endpoints
 * @param string $identifier Unique identifier (IP, user ID, etc.)
 * @param int $max_requests Maximum requests
 * @param int $time_window Time window in seconds
 * @return bool True if allowed
 */
function check_rate_limit($identifier, $max_requests = 100, $time_window = 60) {
    $cache_key = 'rate_limit_' . md5($identifier);
    
    // Using session for simple rate limiting (could be replaced with Redis/Memcached)
    if (!isset($_SESSION[$cache_key])) {
        $_SESSION[$cache_key] = ['count' => 0, 'reset_time' => time() + $time_window];
    }
    
    $current_time = time();
    $rate_data = $_SESSION[$cache_key];
    
    // Reset counter if time window expired
    if ($current_time >= $rate_data['reset_time']) {
        $_SESSION[$cache_key] = ['count' => 1, 'reset_time' => $current_time + $time_window];
        return true;
    }
    
    // Check if limit exceeded
    if ($rate_data['count'] >= $max_requests) {
        return false;
    }
    
    // Increment counter
    $_SESSION[$cache_key]['count']++;
    return true;
}

/**
 * Log security event
 * @param string $event Event type
 * @param array $details Event details
 */
function log_security_event($event, $details = []) {
    $log_entry = [
        'timestamp' => date('Y-m-d H:i:s'),
        'event' => $event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'unknown',
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown',
        'details' => $details
    ];
    
    error_log('SECURITY: ' . json_encode($log_entry));
}

/**
 * Check if connection is HTTPS
 * @return bool True if HTTPS
 */
function is_https() {
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || $_SERVER['SERVER_PORT'] == 443
        || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https');
}

/**
 * Force HTTPS redirect (optional, can be enabled in production)
 */
function force_https() {
    if (!is_https() && php_sapi_name() !== 'cli') {
        header('Location: https://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'], true, 301);
        exit;
    }
}

/**
 * Add security headers
 */
function add_security_headers() {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('X-XSS-Protection: 1; mode=block');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    
    // Only add HSTS if on HTTPS
    if (is_https()) {
        header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
    }
}

// Apply security headers automatically
add_security_headers();
?>
