<?php
/**
 * Admin - Email Templates (multilingual)
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once '../email_helper.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error   = '';
$success = '';

$allowed_keys  = ['password_reset', 'mfa_enabled', 'mfa_disabled', 'license_expiring', 'welcome'];
$allowed_langs = ['hu', 'en', 'sk'];

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'save';

    if ($action === 'save') {
        $tkey      = trim($_POST['template_key'] ?? '');
        $lang      = trim($_POST['lang']         ?? 'hu');
        $subject   = trim($_POST['subject']      ?? '');
        $body_html = $_POST['body_html']         ?? '';
        $body_text = $_POST['body_text']         ?? '';

        if (!in_array($tkey, $allowed_keys, true)) {
            $error = 'Invalid template key.';
        } elseif (!in_array($lang, $allowed_langs, true)) {
            $error = 'Invalid language.';
        } elseif (empty($subject)) {
            $error = 'Subject is required.';
        } else {
            try {
                $conn = getDbConnection();
                $stmt = $conn->prepare("INSERT INTO email_templates (template_key, lang, subject, body_html, body_text) VALUES (?,?,?,?,?) ON DUPLICATE KEY UPDATE subject=VALUES(subject), body_html=VALUES(body_html), body_text=VALUES(body_text)");
                $stmt->bind_param("sssss", $tkey, $lang, $subject, $body_html, $body_text);
                $stmt->execute();
                $stmt->close();
                closeDbConnection($conn);
                $success = 'Template saved.';
            } catch (Exception $e) {
                $error = 'Hiba: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'delete') {
        $tkey = trim($_POST['template_key'] ?? '');
        $lang = trim($_POST['lang']         ?? '');
        if (in_array($tkey, $allowed_keys, true) && in_array($lang, $allowed_langs, true)) {
            try {
                $conn = getDbConnection();
                $stmt = $conn->prepare("DELETE FROM email_templates WHERE template_key=? AND lang=?");
                $stmt->bind_param("ss", $tkey, $lang);
                $stmt->execute();
                $stmt->close();
                closeDbConnection($conn);
                $success = 'Template deleted.';
            } catch (Exception $e) {
                $error = 'Delete error: ' . htmlspecialchars($e->getMessage());
            }
        }
    } elseif ($action === 'test_send') {
        $tkey = trim($_POST['template_key'] ?? '');
        $lang = trim($_POST['lang']         ?? 'hu');
        // Send to admin's own email
        try {
            $conn = getDbConnection();
            $stmt = $conn->prepare("SELECT email FROM users WHERE id=?");
            $stmt->bind_param("i", $_SESSION['user_id']);
            $stmt->execute();
            $admin_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            closeDbConnection($conn);

            if ($admin_row && !empty($admin_row['email'])) {
                $ok = send_email_from_template($tkey, $admin_row['email'], 'Admin', [
                    'name'       => 'Admin',
                    'reset_link' => 'https://example.com/reset',
                    'site_name'  => 'EduDisplej',
                ], $lang);
                $success = $ok ? 'Test email sent: ' . htmlspecialchars($admin_row['email']) : 'Sending failed.';
            } else {
                $error = 'Admin email not found.';
            }
        } catch (Exception $e) {
            $error = 'Hiba: ' . htmlspecialchars($e->getMessage());
        }
    }
}

// Load existing template for edit (GET)
$edit_template = null;
if (isset($_GET['edit_key']) && isset($_GET['edit_lang'])) {
    $ek = $_GET['edit_key'];
    $el = $_GET['edit_lang'];
    if (in_array($ek, $allowed_keys, true) && in_array($el, $allowed_langs, true)) {
        $edit_template = get_email_template($ek, $el);
        if ($edit_template) {
            $edit_template['template_key'] = $ek;
            $edit_template['lang']         = $el;
        }
    }
}

// Load all templates
$all_templates = [];
try {
    $conn = getDbConnection();
    $res = $conn->query("SELECT template_key, lang, subject, updated_at FROM email_templates ORDER BY template_key, lang");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $all_templates[] = $row;
        }
    }
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'DB hiba: ' . htmlspecialchars($e->getMessage());
}

$title = 'Email Templates';
require_once 'header.php';
?>

<h2 class="page-title">Email Templates</h2>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<!-- Template list -->
<div class="panel">
    <div class="panel-title">Existing templates</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Key</th>
                    <th>Language</th>
                    <th>Subject</th>
                    <th>Updated</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($all_templates)): ?>
                    <tr><td colspan="5" class="muted">No templates.</td></tr>
                <?php else: ?>
                    <?php foreach ($all_templates as $t): ?>
                        <tr>
                            <td class="mono"><?php echo htmlspecialchars($t['template_key']); ?></td>
                            <td><?php echo htmlspecialchars(strtoupper($t['lang'])); ?></td>
                            <td><?php echo htmlspecialchars($t['subject']); ?></td>
                            <td class="nowrap muted"><?php echo htmlspecialchars($t['updated_at'] ?? '-'); ?></td>
                            <td class="nowrap">
                                <a href="email_templates.php?edit_key=<?php echo urlencode($t['template_key']); ?>&edit_lang=<?php echo urlencode($t['lang']); ?>" class="btn btn-small btn-secondary">Edit</a>
                                <form method="POST" action="email_templates.php" style="display:inline;" onsubmit="return confirm('Delete template?');">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="template_key" value="<?php echo htmlspecialchars($t['template_key']); ?>">
                                    <input type="hidden" name="lang" value="<?php echo htmlspecialchars($t['lang']); ?>">
                                    <button type="submit" class="btn btn-small btn-danger">Delete</button>
                                </form>
                                <form method="POST" action="email_templates.php" style="display:inline;">
                                    <input type="hidden" name="action" value="test_send">
                                    <input type="hidden" name="template_key" value="<?php echo htmlspecialchars($t['template_key']); ?>">
                                    <input type="hidden" name="lang" value="<?php echo htmlspecialchars($t['lang']); ?>">
                                    <button type="submit" class="btn btn-small">Send test</button>
                                </form>
                                <!-- Preview button -->
                                <button class="btn btn-small" onclick="showPreview(<?php echo htmlspecialchars(json_encode($t['template_key'])); ?>, <?php echo htmlspecialchars(json_encode($t['lang'])); ?>)">Preview</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Edit / Create form -->
<div class="panel" style="margin-top:20px;">
    <div class="panel-title"><?php echo $edit_template ? 'Edit template' : 'New template'; ?></div>
    <form method="POST" action="email_templates.php">
        <input type="hidden" name="action" value="save">
        <div class="form-row">
            <div class="form-field">
                <label>Template key</label>
                <select name="template_key">
                    <?php foreach ($allowed_keys as $k): ?>
                        <option value="<?php echo htmlspecialchars($k); ?>" <?php echo (($edit_template['template_key'] ?? '') === $k) ? 'selected' : ''; ?>><?php echo htmlspecialchars($k); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-field">
                <label>Language</label>
                <select name="lang">
                    <?php foreach ($allowed_langs as $l): ?>
                        <option value="<?php echo htmlspecialchars($l); ?>" <?php echo (($edit_template['lang'] ?? 'hu') === $l) ? 'selected' : ''; ?>><?php echo strtoupper(htmlspecialchars($l)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="form-field">
            <label>Subject</label>
            <input type="text" name="subject" value="<?php echo htmlspecialchars($edit_template['subject'] ?? ''); ?>" required>
        </div>
        <div class="form-field">
            <label>HTML body <span class="muted">({{name}}, {{reset_link}}, {{site_name}} variables)</span></label>
            <textarea name="body_html" rows="12" style="width:100%;font-family:monospace;font-size:13px;"><?php echo htmlspecialchars($edit_template['body_html'] ?? ''); ?></textarea>
        </div>
        <div class="form-field">
            <label>Text body <span class="muted">(optional)</span></label>
            <textarea name="body_text" rows="5" style="width:100%;font-family:monospace;font-size:13px;"><?php echo htmlspecialchars($edit_template['body_text'] ?? ''); ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
        <?php if ($edit_template): ?>
            <a href="email_templates.php" class="btn btn-secondary">Cancel</a>
        <?php endif; ?>
    </form>
</div>

<!-- Preview modal -->
<div id="previewModal" style="display:none;position:fixed;top:0;left:0;width:100%;height:100%;background:rgba(0,0,0,0.6);z-index:9999;align-items:center;justify-content:center;">
    <div style="background:#fff;width:90%;max-width:800px;height:80vh;border-radius:6px;display:flex;flex-direction:column;overflow:hidden;">
        <div style="padding:12px 16px;border-bottom:1px solid #ddd;display:flex;justify-content:space-between;align-items:center;">
            <strong>Preview</strong>
            <button onclick="document.getElementById('previewModal').style.display='none';" class="btn btn-small btn-danger">Close</button>
        </div>
        <iframe id="previewFrame" sandbox="allow-same-origin" style="flex:1;border:none;width:100%;"></iframe>
    </div>
</div>

<script>
function showPreview(key, lang) {
    fetch('../api/email_templates.php?action=preview&key=' + encodeURIComponent(key) + '&lang=' + encodeURIComponent(lang))
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const frame = document.getElementById('previewFrame');
                frame.srcdoc = data.html;
                document.getElementById('previewModal').style.display = 'flex';
            } else {
                alert('Error: ' + data.message);
            }
        })
            .catch(() => alert('Network error'));
}
</script>

<?php require_once 'footer.php'; ?>
