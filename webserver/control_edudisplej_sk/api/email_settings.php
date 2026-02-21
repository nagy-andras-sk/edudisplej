<?php
/**
 * API - Email Settings (save / test)
 * Requires active admin session.
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../security_config.php';
require_once '../logging.php';
require_once '../email_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || empty($_SESSION['isadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'save':
            $fields = [
                'smtp_host'       => trim($_POST['smtp_host']       ?? ''),
                'smtp_port'       => (int)($_POST['smtp_port']       ?? 587),
                'smtp_encryption' => trim($_POST['smtp_encryption']  ?? 'tls'),
                'smtp_user'       => trim($_POST['smtp_user']        ?? ''),
                'from_name'       => trim($_POST['from_name']        ?? ''),
                'from_email'      => trim($_POST['from_email']       ?? ''),
                'reply_to'        => trim($_POST['reply_to']         ?? ''),
                'mail_timeout'    => (int)($_POST['mail_timeout']    ?? 30),
            ];

            if (!in_array($fields['smtp_encryption'], ['none', 'tls', 'ssl'], true)) {
                $fields['smtp_encryption'] = 'tls';
            }

            $conn = getDbConnection();
            foreach ($fields as $key => $value) {
                $val = (string)$value;
                $enc = 0;
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), is_encrypted=VALUES(is_encrypted)");
                $stmt->bind_param("ssi", $key, $val, $enc);
                $stmt->execute();
                $stmt->close();
            }

            $smtp_pass = $_POST['smtp_pass'] ?? '';
            if (!empty($smtp_pass)) {
                $encrypted = encrypt_data($smtp_pass);
                $enc = 1;
                $k = 'smtp_pass';
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), is_encrypted=VALUES(is_encrypted)");
                $stmt->bind_param("ssi", $k, $encrypted, $enc);
                $stmt->execute();
                $stmt->close();
            }

            closeDbConnection($conn);
            log_security_event('settings_change', $_SESSION['user_id'], $_SESSION['username'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', ['section' => 'email_settings']);
            echo json_encode(['success' => true, 'message' => 'Saved']);
            break;

        case 'test':
            $test_to = trim($_POST['to'] ?? '');
            if (!filter_var($test_to, FILTER_VALIDATE_EMAIL)) {
                echo json_encode(['success' => false, 'message' => 'Invalid email address']);
                break;
            }
            $ok = send_raw_email(
                ['email' => $test_to, 'name' => 'Test'],
                'EduDisplej SMTP Test',
                '<p>This is a test email from EduDisplej.</p>',
                'This is a test email from EduDisplej.',
                null
            );
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Test email sent' : 'Send failed – check email_logs']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('api/email_settings: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
