<?php
/**
 * API Authentication Middleware
 * Validates bearer tokens for API requests
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
                        SELECT c.api_token 
                        FROM kiosks k 
                        JOIN companies c ON k.company_id = c.id 
                        WHERE k.mac = ? AND c.api_token IS NOT NULL
                    ");
                    $stmt->bind_param("s", $mac);
                    $stmt->execute();
                    $result = $stmt->get_result();
                    
                    if ($result->num_rows > 0) {
                        $row = $result->fetch_assoc();
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
        
        // Check if token exists in companies table
        $stmt = $conn->prepare("SELECT id, name FROM companies WHERE api_token = ?");
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
        $conn->close();
        
        // Token is valid, store company info for use in API
        $_SESSION['api_company_id'] = $company['id'];
        $_SESSION['api_company_name'] = $company['name'];
        
        return true;
        
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: application/json');
        $response['message'] = 'Authentication error: ' . $e->getMessage();
        echo json_encode($response);
        exit;
    }
}

// Auto-start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
