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

    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    if (!empty($_SESSION['user_id'])) {
        $company_id = $_SESSION['company_id'] ?? null;
        $company_name = $_SESSION['company_name'] ?? null;
        $license_key = $_SESSION['license_key'] ?? null;
        $is_admin = !empty($_SESSION['isadmin']);

        $_SESSION['api_company_id'] = $company_id;
        $_SESSION['api_company_name'] = $company_name;
        $_SESSION['api_license_key'] = $license_key;
        $_SESSION['api_is_admin'] = $is_admin;

        return [
            'id' => $company_id,
            'name' => $company_name,
            'license_key' => $license_key,
            'is_admin' => $is_admin,
            'auth_type' => 'session'
        ];
    }

    $headers = getallheaders();
    $auth_header = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $token_from_query = $_GET['token'] ?? '';
    $token_from_header = $headers['X-API-Token'] ?? $headers['x-api-token'] ?? '';

    $token = '';
    if (preg_match('/Bearer\s+(.+)$/i', $auth_header, $matches)) {
        $token = trim($matches[1]);
    } elseif (!empty($token_from_header)) {
        $token = trim($token_from_header);
    } elseif (!empty($token_from_query)) {
        $token = trim($token_from_query);
    }

    if (empty($token)) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode($response);
        exit;
    }

    require_once __DIR__ . '/../dbkonfiguracia.php';

    try {
        $conn = getDbConnection();
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

        if (!$company['is_active']) {
            $conn->close();

            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: application/json');
            $response['message'] = 'Company license is inactive';
            echo json_encode($response);
            exit;
        }

        if (empty($company['license_key'])) {
            $conn->close();

            header('HTTP/1.1 403 Forbidden');
            header('Content-Type: application/json');
            $response['message'] = 'No valid license key';
            echo json_encode($response);
            exit;
        }

        $conn->close();

        $_SESSION['api_company_id'] = $company['id'];
        $_SESSION['api_company_name'] = $company['name'];
        $_SESSION['api_license_key'] = $company['license_key'];
        $_SESSION['api_is_admin'] = false;

        return [
            'id' => $company['id'],
            'name' => $company['name'],
            'license_key' => $company['license_key'],
            'is_admin' => false,
            'auth_type' => 'token'
        ];
    } catch (Exception $e) {
        header('HTTP/1.1 500 Internal Server Error');
        header('Content-Type: application/json');
        $response['message'] = 'Authentication error: ' . $e->getMessage();
        echo json_encode($response);
        exit;
    }
}

function api_is_admin_session(array $company): bool {
    return !empty($company['is_admin']);
}

function api_require_company_match(array $company, $target_company_id, string $message = 'Unauthorized'): void {
    if (api_is_admin_session($company)) {
        return;
    }
    if (empty($target_company_id) || (int)$company['id'] !== (int)$target_company_id) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => $message]);
        exit;
    }
}

function api_require_group_company(mysqli $conn, array $company, int $group_id): void {
    if ($group_id <= 0 || api_is_admin_session($company)) {
        return;
    }
    $stmt = $conn->prepare("SELECT company_id FROM kiosk_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $row = $result->fetch_assoc();
    $stmt->close();
    if (!$row || (int)$row['company_id'] !== (int)$company['id']) {
        header('HTTP/1.1 403 Forbidden');
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Unauthorized']);
        exit;
    }
}
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
