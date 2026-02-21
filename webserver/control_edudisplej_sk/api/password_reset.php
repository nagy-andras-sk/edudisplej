<?php
/**
 * API - Password Reset
 * Actions: request_reset, apply_reset
 */

require_once '../dbkonfiguracia.php';
require_once '../security_config.php';
require_once '../logging.php';
require_once '../email_helper.php';

header('Content-Type: application/json');

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'request_reset':
            $email = trim($_POST['email'] ?? '');
            if (empty($email) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Valid email required']);
                exit();
            }

            $conn = getDbConnection();

            $stmt = $conn->prepare("SELECT id, username FROM users WHERE email = ?");
            $stmt->bind_param("s", $email);
            $stmt->execute();
            $user = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if ($user) {
                // Rate limit: max 3 per hour
                $stmt = $conn->prepare("SELECT COUNT(*) as cnt FROM password_reset_tokens WHERE user_id = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR) AND used_at IS NULL");
                $stmt->bind_param("i", $user['id']);
                $stmt->execute();
                $cnt = $stmt->get_result()->fetch_assoc()['cnt'];
                $stmt->close();

                if ($cnt < 3) {
                    $plain_token = bin2hex(random_bytes(32));
                    $token_hash  = hash('sha256', $plain_token);
                    $expires_at  = date('Y-m-d H:i:s', time() + 3600);
                    $ip          = $_SERVER['REMOTE_ADDR'] ?? '';

                    $stmt = $conn->prepare("INSERT INTO password_reset_tokens (user_id, token_hash, expires_at, ip_address) VALUES (?,?,?,?)");
                    $stmt->bind_param("isss", $user['id'], $token_hash, $expires_at, $ip);
                    $stmt->execute();
                    $stmt->close();

                    $reset_link = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
                        . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost')
                        . '/password_reset.php?token=' . urlencode($plain_token);

                    send_email_from_template('password_reset', $email, $user['username'], [
                        'name'       => $user['username'],
                        'reset_link' => $reset_link,
                        'site_name'  => 'EduDisplej',
                    ], 'hu');

                    log_security_event('password_reset_request', $user['id'], $user['username'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', ['email' => $email]);
                }
            }

            closeDbConnection($conn);
            // Always success to prevent email enumeration
            echo json_encode(['success' => true, 'message' => 'If the email is registered, a reset link has been sent']);
            break;

        case 'apply_reset':
            $token_val    = trim($_POST['token']            ?? '');
            $new_pass     = $_POST['new_password']          ?? '';
            $confirm_pass = $_POST['confirm_password']      ?? '';

            if (empty($token_val)) {
                echo json_encode(['success' => false, 'message' => 'Token required']);
                exit();
            }
            if (strlen($new_pass) < 8) {
                echo json_encode(['success' => false, 'message' => 'Password must be at least 8 characters']);
                exit();
            }
            if ($new_pass !== $confirm_pass) {
                echo json_encode(['success' => false, 'message' => 'Passwords do not match']);
                exit();
            }

            $conn = getDbConnection();
            $token_hash = hash('sha256', $token_val);

            $stmt = $conn->prepare("SELECT prt.id, prt.user_id, prt.expires_at, prt.used_at, u.username FROM password_reset_tokens prt JOIN users u ON u.id = prt.user_id WHERE prt.token_hash = ?");
            $stmt->bind_param("s", $token_hash);
            $stmt->execute();
            $token_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            if (!$token_row) {
                closeDbConnection($conn);
                echo json_encode(['success' => false, 'message' => 'Invalid or expired token']);
                exit();
            }
            if ($token_row['used_at'] !== null) {
                closeDbConnection($conn);
                echo json_encode(['success' => false, 'message' => 'Token already used']);
                exit();
            }
            if (strtotime($token_row['expires_at']) < time()) {
                closeDbConnection($conn);
                echo json_encode(['success' => false, 'message' => 'Token expired']);
                exit();
            }

            $hashed_pass = password_hash($new_pass, PASSWORD_BCRYPT);
            $uid = (int)$token_row['user_id'];

            $stmt = $conn->prepare("UPDATE users SET password = ? WHERE id = ?");
            $stmt->bind_param("si", $hashed_pass, $uid);
            $stmt->execute();
            $stmt->close();

            $used_now = date('Y-m-d H:i:s');
            $tid = (int)$token_row['id'];
            $stmt = $conn->prepare("UPDATE password_reset_tokens SET used_at = ? WHERE id = ?");
            $stmt->bind_param("si", $used_now, $tid);
            $stmt->execute();
            $stmt->close();

            closeDbConnection($conn);

            log_security_event('password_reset_success', $uid, $token_row['username'], $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', []);

            echo json_encode(['success' => true, 'message' => 'Password updated']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('api/password_reset: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
