<?php
/**
 * API Authentication Middleware
 * Validates bearer tokens and license keys for API requests
 */

function validate_api_token() {
    $response = [
        'success' => false,
        'message' => 'Authentication required'
    ];
    
    // Check for Authorization header
    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    
    // Also check for token in query string (for backward compatibility)
    $token_from_query = $_GET['token'] ?? '';
    
    // Extract token from Authorization header (Bearer token)
    $token = '';
    if (preg_match('/Bearer\s+(.+)$/i', $auth_header, $matches)) {
        $token = $matches[1];
    } elseif (!empty($token_from_query)) {
        $token = $token_from_query;
    }
    
    // If no token provided, check if this is a device request with MAC address
    // Devices can authenticate using their MAC address
    if (empty($token)) {
        // Get MAC from request body for device authentication
        $raw_input = file_get_contents('php://input');
        $data = json_decode($raw_input, true);
        $mac = $data['mac'] ?? '';
        
        if (!empty($mac)) {
            // Device authentication - validate MAC format
            if (strlen($mac) >= 6) {
                // Look up device token from database
                require_once __DIR__ . '/../dbkonfiguracia.php';
                try {
                    $conn = getDbConnection();
                    $stmt = $conn->prepare("
                        SELECT c.api_token, c.license_key, c.is_active 
                        FROM kiosks k 
                        JOIN companies c ON k.company_id = c.id 
                        WHERE k.mac = ? AND c.api_token IS NOT NULL
                    ");
                    $stmt->bind_param("s", $mac);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $row = $result->fetch_assoc();
                        
                        // Check if company is active
                        if (!$row['is_active']) {
                            $stmt->close();
                            $conn->close();
                            
                            header('HTTP/1.1 403 Forbidden');
                            header('Content-Type: application/json');
                            $response['message'] = 'Company license is inactive';
                            echo json_encode($response);
                            exit;
                        }
                        
                        $token = $row['api_token'];
                    }
                    $stmt->close();
                    $conn->close();
                } catch (Exception $e) {
                    // Allow without token for device registration
                    return true;
                }
            }
        }
    }
    
    // If still no token, deny access for protected endpoints
    if (empty($token)) {
        // Allow registration endpoint without token (for new devices)
        $script_name = basename($_SERVER['SCRIPT_NAME']);
        if ($script_name === 'registration.php') {
            return true;
        }
        
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }
    
    // Validate token against database
    require_once __DIR__ . '/../dbkonfiguracia.php';
    
    try {
        $conn = getDbConnection();
        
        // Check if token exists in companies table and verify license
        $stmt = $conn->prepare("SELECT id, name, license_key, is_active FROM companies WHERE api_token = ?");
        $stmt->bind_param("s", $token);
        $stmt->execute();
        $result = $stmt->get_result();
        
        if ($result->num_rows === 0) {
            $stmt->close();
            $conn->close();
            
            header('HTTP/1.1 401 Unauthorized');
            header('Content-Type: application/json');
            $response['message'] = 'Invalid API token';
            echo json_encode($response);
            exit;
        }
        
        $company = $result->fetch_assoc();
        $stmt->close();
        
        // Verify company is active
        if (!$company['is_active']) {
            $conn->close();
            
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: application/json');
            $response['message'] = 'Company license is inactive';
            echo json_encode($response);
            exit;
        }
        
        // Verify license key exists
        if (empty($company['license_key'])) {
            $conn->close();
            
            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: application/json');
            $response['message'] = 'No valid license key';
            echo json_encode($response);
            exit;
        }
        
        $conn->close();
        
        // Token is valid, store company info for use in API
        $_SESSION['api_company_id'] = $company['id'];
        $_SESSION['api_company_name'] = $company['name'];
        $_SESSION['api_license_key'] = $company['license_key'];
        
        return true;
        
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: application/json');
        $response['message'] = 'Authentication error: ' . $e->getMessage();
        echo json_encode($response);
        exit;
    }
}

/**
 * Verify OTP code for two-factor authentication
 * @param string $secret The user's OTP secret
 * @param string $code The code entered by user
 * @return bool True if valid
 */
function verify_otp_code($secret, $code) {
    // Time-based OTP using RFC 6238 (TOTP)
    $time = floor(time() / 30); // 30-second window
    
    // Check current window and ±1 window for clock drift
    for ($i = -1; $i <= 1; $i++) {
        $calc_code = generate_otp_code($secret, $time + $i);
        if (hash_equals($calc_code, $code)) {
            return true;
        }
    }
    
    return false;
}

/**
 * Generate OTP code for given time
 * @param string $secret Base32 encoded secret
 * @param int $time Time counter
 * @return string 6-digit code
 */
function generate_otp_code($secret, $time) {
    $secret = base32_decode($secret);
    $time = pack('N*', 0) . pack('N*', $time);
    $hash = hash_hmac('sha1', $time, $secret, true);
    $offset = ord($hash[19]) & 0xf;
    $code = (
        ((ord($hash[$offset + 0]) & 0x7f) << 24) |
        ((ord($hash[$offset + 1]) & 0xff) << 16) |
        ((ord($hash[$offset + 2]) & 0xff) << 8) |
        (ord($hash[$offset + 3]) & 0xff)
    ) % 1000000;
    
    return str_pad($code, 6, '0', STR_PAD_LEFT);
}

/**
 * Decode Base32 string
 * @param string $data Base32 encoded data
 * @return string Decoded binary data
 */
function base32_decode($data) {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $data = strtoupper($data);
    $output = '';
    $v = 0;
    $vbits = 0;
    
    for ($i = 0, $j = strlen($data); $i < $j; $i++) {
        $v <<= 5;
        if (($idx = strpos($alphabet, $data[$i])) !== false) {
            $v += $idx;
        }
        $vbits += 5;
        
        while ($vbits >= 8) {
            $vbits -= 8;
            $output .= chr($v >> $vbits);
            $v &= ((1 << $vbits) - 1);
        }
    }
    
    return $output;
}

/**
 * Generate a new OTP secret
 * @return string Base32 encoded secret
 */
function generate_otp_secret() {
    $alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    $secret = '';
    for ($i = 0; $i < 32; $i++) {
        $secret .= $alphabet[random_int(0, 31)];
    }
    return $secret;
}

// Auto-start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
