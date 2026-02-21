<?php
/**
 * API - Email Templates CRUD
 * Actions: save, delete, preview, test_send
 * Requires admin session.
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../email_helper.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || empty($_SESSION['isadmin'])) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Forbidden']);
    exit();
}

$allowed_keys  = ['password_reset', 'mfa_enabled', 'mfa_disabled', 'license_expiring', 'welcome'];
$allowed_langs = ['hu', 'en', 'sk'];

$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    switch ($action) {
        case 'save':
            $tkey      = trim($_POST['template_key'] ?? '');
            $lang      = trim($_POST['lang']         ?? 'hu');
            $subject   = trim($_POST['subject']      ?? '');
            $body_html = $_POST['body_html']         ?? '';
            $body_text = $_POST['body_text']         ?? '';

            if (!in_array($tkey, $allowed_keys, true) || !in_array($lang, $allowed_langs, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid key or lang']);
                break;
            }
            if (empty($subject)) {
                echo json_encode(['success' => false, 'message' => 'Subject required']);
                break;
            }

            $conn = getDbConnection();
            $stmt = $conn->prepare("INSERT INTO email_templates (template_key, lang, subject, body_html, body_text) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE subject=VALUES(subject), body_html=VALUES(body_html), body_text=VALUES(body_text)");
            $stmt->bind_param("sssss", $tkey, $lang, $subject, $body_html, $body_text);
            $stmt->execute();
            $stmt->close();
            closeDbConnection($conn);
            echo json_encode(['success' => true, 'message' => 'Saved']);
            break;

        case 'delete':
            $tkey = trim($_POST['template_key'] ?? '');
            $lang = trim($_POST['lang']         ?? '');

            if (!in_array($tkey, $allowed_keys, true) || !in_array($lang, $allowed_langs, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid key or lang']);
                break;
            }

            $conn = getDbConnection();
            $stmt = $conn->prepare("DELETE FROM email_templates WHERE template_key=? AND lang=?");
            $stmt->bind_param("ss", $tkey, $lang);
            $stmt->execute();
            $stmt->close();
            closeDbConnection($conn);
            echo json_encode(['success' => true, 'message' => 'Deleted']);
            break;

        case 'preview':
            $tkey = trim($_GET['key']  ?? '');
            $lang = trim($_GET['lang'] ?? 'hu');

            if (!in_array($tkey, $allowed_keys, true) || !in_array($lang, $allowed_langs, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid key or lang']);
                break;
            }

            $tpl = get_email_template($tkey, $lang);
            if (!$tpl) {
                echo json_encode(['success' => false, 'message' => 'Template not found']);
                break;
            }

            // Replace sample variables
            $sample = ['name' => 'Minta Felhasználó', 'reset_link' => 'https://example.com/reset?token=sample', 'site_name' => 'EduDisplej'];
            $html = $tpl['body_html'];
            foreach ($sample as $k => $v) {
                $html = str_replace('{{' . $k . '}}', htmlspecialchars($v), $html);
            }

            echo json_encode(['success' => true, 'html' => $html, 'subject' => $tpl['subject']]);
            break;

        case 'test_send':
            $tkey = trim($_POST['template_key'] ?? '');
            $lang = trim($_POST['lang']         ?? 'hu');

            if (!in_array($tkey, $allowed_keys, true) || !in_array($lang, $allowed_langs, true)) {
                echo json_encode(['success' => false, 'message' => 'Invalid key or lang']);
                break;
            }

            $conn = getDbConnection();
            $stmt = $conn->prepare("SELECT email FROM users WHERE id=?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $admin_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            closeDbConnection($conn);

            if (empty($admin_row['email'])) {
                echo json_encode(['success' => false, 'message' => 'Admin email not found']);
                break;
            }

            $ok = send_email_from_template($tkey, $admin_row['email'], 'Admin', [
                'name'       => 'Admin',
                'reset_link' => 'https://example.com/reset',
                'site_name'  => 'EduDisplej',
            ], $lang);
            echo json_encode(['success' => $ok, 'message' => $ok ? 'Test email sent to ' . $admin_row['email'] : 'Send failed']);
            break;

        default:
            http_response_code(400);
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (Exception $e) {
    error_log('api/email_templates: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Server error']);
}
