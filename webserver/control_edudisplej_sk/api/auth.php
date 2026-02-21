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
    $token_source = '';
    if (preg_match('/Bearer\s+(.+)$/i', $auth_header, $matches)) {
        $token = trim($matches[1]);
        $token_source = 'bearer';
    } elseif (!empty($token_from_header)) {
        $token = trim($token_from_header);
        $token_source = 'x-api-token';
    } elseif (!empty($token_from_query)) {
        $token = trim($token_from_query);
        $token_source = 'query';
        // Deprecation warning: query-param tokens will be removed in a future version
        header('X-EDU-Deprecation-Warning: token query parameter is deprecated; use Authorization: Bearer <token> header instead');
        error_log('EDU-API: Deprecated token query parameter used from IP ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
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

/**
 * Validate optional request signing headers (X-EDU-Timestamp, X-EDU-Nonce, X-EDU-Signature).
 *
 * Signing scheme (HMAC-SHA256):
 *   canonical_string = METHOD\nURI_PATH\nTIMESTAMP\nNONCE\nHEX(SHA256(request_body))
 *   signature        = HMAC-SHA256(canonical_string, signing_secret)
 *
 * The signing_secret is stored in the `companies.signing_secret` column.
 * Timestamp drift tolerance: ±300 seconds.
 * Nonces are cached in the DB table `api_nonces` with a 10-minute TTL.
 *
 * @param array  $company       Company data returned by validate_api_token()
 * @param string $request_body  Raw request body
 * @param bool   $required      If true, missing headers cause a 401 response
 * @return bool  True when signature is valid (or signing is not configured/required)
 */
function validate_request_signature(array $company, string $request_body, bool $required = false): bool {
    $headers = getallheaders();
    $timestamp = $headers['X-EDU-Timestamp'] ?? $headers['x-edu-timestamp'] ?? '';
    $nonce     = $headers['X-EDU-Nonce']     ?? $headers['x-edu-nonce']     ?? '';
    $signature = $headers['X-EDU-Signature'] ?? $headers['x-edu-signature'] ?? '';

    // If no signing headers present, honour the $required flag
    if ($timestamp === '' && $nonce === '' && $signature === '') {
        if ($required) {
            header('HTTP/1.1 401 Unauthorized');
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Request signing headers required']);
            exit;
        }
        return true;
    }

    // Validate timestamp drift (±300 s)
    $ts = (int)$timestamp;
    if (abs(time() - $ts) > 300) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Request timestamp out of range']);
        exit;
    }

    // Validate nonce format
    if (!preg_match('/^[a-zA-Z0-9_\-]{8,128}$/', $nonce)) {
        header('HTTP/1.1 401 Unauthorized');
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid nonce format']);
        exit;
    }

    // Look up signing secret for company
    require_once __DIR__ . '/../dbkonfiguracia.php';
    try {
        $conn = getDbConnection();

        // Purge expired nonces
        $conn->query("DELETE FROM api_nonces WHERE expires_at < NOW()");

        // Check nonce replay
        $ns = $conn->prepare("SELECT id FROM api_nonces WHERE nonce = ? AND company_id = ?");
        $ns->bind_param("si", $nonce, $company['id']);
        $ns->execute();
        if ($ns->get_result()->num_rows > 0) {
            $ns->close();
            $conn->close();
            header('HTTP/1.1 401 Unauthorized');
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Nonce already used']);
            exit;
        }
        $ns->close();

        // Get signing secret
        $ss = $conn->prepare("SELECT signing_secret FROM companies WHERE id = ?");
        $ss->bind_param("i", $company['id']);
        $ss->execute();
        $row = $ss->get_result()->fetch_assoc();
        $ss->close();

        if (empty($row['signing_secret'])) {
            // Signing secret not configured – skip validation unless required
            $conn->close();
            if ($required) {
                header('HTTP/1.1 401 Unauthorized');
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Request signing not configured for this company']);
                exit;
            }
            return true;
        }

        // Build canonical string
        $method       = strtoupper($_SERVER['REQUEST_METHOD'] ?? 'POST');
        $uri_path     = strtok($_SERVER['REQUEST_URI'] ?? '', '?');
        $body_hash    = hash('sha256', $request_body);
        $canonical    = implode("\n", [$method, $uri_path, $timestamp, $nonce, $body_hash]);

        $expected_sig = hash_hmac('sha256', $canonical, $row['signing_secret']);

        if (!hash_equals($expected_sig, strtolower($signature))) {
            $conn->close();
            header('HTTP/1.1 401 Unauthorized');
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Invalid request signature']);
            exit;
        }

        // Store nonce (TTL: 10 minutes)
        $ni = $conn->prepare("INSERT IGNORE INTO api_nonces (nonce, company_id, expires_at) VALUES (?, ?, DATE_ADD(NOW(), INTERVAL 10 MINUTE))");
        $ni->bind_param("si", $nonce, $company['id']);
        $ni->execute();
        $ni->close();
        $conn->close();

    } catch (Exception $e) {
        error_log('EDU-API signature validation error: ' . $e->getMessage());
        // On DB error, fail open (don't block devices) unless required
        if ($required) {
            header('HTTP/1.1 500 Internal Server Error');
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Signature validation error']);
            exit;
        }
    }

    return true;
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

/**
 * Verify a TOTP code against a secret (±1 time window tolerance)
 */
function verify_otp_code($secret, $code) {
    if (empty($secret) || !preg_match('/^\d{6}$/', $code)) {
        return false;
    }
    $time_step = floor(time() / 30);
    for ($i = -1; $i <= 1; $i++) {
        if (hash_equals(generate_otp_code($secret, $time_step + $i), $code)) {
            return true;
        }
    }
    return false;
}

/**
 * Generate random backup codes
 */
function generate_backup_codes($count = 10) {
    $codes = [];
    for ($i = 0; $i < $count; $i++) {
        $codes[] = strtoupper(bin2hex(random_bytes(4)));
    }
    return $codes;
}

/**
 * Hash a backup code for storage
 */
function hash_backup_code($code) {
    return hash('sha256', strtoupper(trim($code)));
}

/**
 * Verify a plain backup code against stored hashes
 */
function verify_backup_code($plain_code, array $hashed_codes) {
    $hash = hash_backup_code($plain_code);
    foreach ($hashed_codes as $stored) {
        if (hash_equals($stored, $hash)) {
            return true;
        }
    }
    return false;
}

// Auto-start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
