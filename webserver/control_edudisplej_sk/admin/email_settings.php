<?php
/**
 * Admin - Email / SMTP Settings
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once '../security_config.php';
require_once '../logging.php';
require_once '../email_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error   = '';
$success = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
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

        try {
            $conn = getDbConnection();

            foreach ($fields as $key => $value) {
                $val = (string)$value;
                $enc = 0;
                $stmt = $conn->prepare("INSERT INTO system_settings (setting_key, setting_value, is_encrypted) VALUES (?,?,?) ON DUPLICATE KEY UPDATE setting_value=VALUES(setting_value), is_encrypted=VALUES(is_encrypted)");
                $stmt->bind_param("ssi", $key, $val, $enc);
                $stmt->execute();
                $stmt->close();
            }

            // Handle password separately (only update if non-empty)
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
            $success = 'Email settings saved.';
        } catch (Exception $e) {
            $error = 'Save failed: ' . htmlspecialchars($e->getMessage());
        }

    } elseif ($action === 'test_email') {
        $test_to = trim($_POST['test_to'] ?? '');
        if (empty($test_to) || !filter_var($test_to, FILTER_VALIDATE_EMAIL)) {
            $error = 'Invalid email address.';
        } else {
            $ok = send_raw_email(
                ['email' => $test_to, 'name' => 'Test'],
                'EduDisplej SMTP teszt',
                '<p>This is a test email from EduDisplej.</p>',
                'This is a test email from EduDisplej.',
                null
            );
            if ($ok) {
                $success = 'Test email sent successfully: ' . htmlspecialchars($test_to);
            } else {
                $error = 'Test email send failed. Check SMTP settings and logs.';
            }
        }
    }
}

// Load current settings
$settings = get_smtp_settings();

$title = 'Email Settings';
require_once 'header.php';
?>

<h2 class="page-title">Email Settings</h2>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title">SMTP Configuration</div>
    <form method="POST" action="email_settings.php">
        <input type="hidden" name="action" value="save">
        <div class="form-row">
            <div class="form-field">
                <label>SMTP Host</label>
                <input type="text" name="smtp_host" value="<?php echo htmlspecialchars($settings['host']); ?>" placeholder="smtp.example.com">
            </div>
            <div class="form-field">
                <label>SMTP Port</label>
                <input type="number" name="smtp_port" value="<?php echo (int)$settings['port'] ?: 587; ?>" min="1" max="65535">
            </div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <label>Encryption</label>
                <select name="smtp_encryption">
                    <option value="tls"  <?php echo $settings['encryption'] === 'tls'  ? 'selected' : ''; ?>>TLS (STARTTLS, port 587)</option>
                    <option value="ssl"  <?php echo $settings['encryption'] === 'ssl'  ? 'selected' : ''; ?>>SSL (port 465)</option>
                    <option value="none" <?php echo $settings['encryption'] === 'none' ? 'selected' : ''; ?>>None</option>
                </select>
            </div>
            <div class="form-field">
                <label>Timeout (sec)</label>
                <input type="number" name="mail_timeout" value="<?php echo (int)$settings['timeout'] ?: 30; ?>" min="5" max="120">
            </div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <label>SMTP Username</label>
                <input type="text" name="smtp_user" value="<?php echo htmlspecialchars($settings['user']); ?>" autocomplete="off">
            </div>
            <div class="form-field">
                <label>SMTP Password <span class="muted">(leave empty to keep current)</span></label>
                <input type="password" name="smtp_pass" placeholder="••••••••" autocomplete="new-password">
            </div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <label>From name</label>
                <input type="text" name="from_name" value="<?php echo htmlspecialchars($settings['from_name']); ?>">
            </div>
            <div class="form-field">
                <label>From email</label>
                <input type="email" name="from_email" value="<?php echo htmlspecialchars($settings['from_email']); ?>">
            </div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <label>Reply-To <span class="muted">(optional)</span></label>
                <input type="email" name="reply_to" value="<?php echo htmlspecialchars($settings['reply_to']); ?>">
            </div>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>

<div class="panel" style="margin-top:20px;">
    <div class="panel-title">Test Email</div>
    <form method="POST" action="email_settings.php">
        <input type="hidden" name="action" value="test_email">
        <div class="form-row">
            <div class="form-field">
                <label>Test email address</label>
                <input type="email" name="test_to" placeholder="you@example.com" required>
            </div>
        </div>
        <button type="submit" class="btn btn-secondary">Send Test Email</button>
    </form>
</div>

<?php require_once 'footer.php'; ?>
