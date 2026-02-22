<?php
/**
 * Admin - Institution License Management
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once '../logging.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

if (isset($_GET['new_license'])) {
    $target_company_id = (int)($_GET['new_license'] ?? 0);
    if ($target_company_id > 0) {
        header('Location: module_licenses.php?company_id=' . $target_company_id);
    } else {
        header('Location: module_licenses.php');
    }
    exit();
}

$error   = '';
$success = '';

// Handle POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'save_license') {
        $company_id   = (int)($_POST['company_id']   ?? 0);
        $valid_from   = trim($_POST['valid_from']    ?? '');
        $valid_until  = trim($_POST['valid_until']   ?? '');
        $device_limit = (int)($_POST['device_limit'] ?? 10);
        $notes        = trim($_POST['notes']         ?? '');
        $status       = trim($_POST['status']        ?? 'active');

        if (!in_array($status, ['active', 'suspended', 'expired'], true)) {
            $status = 'active';
        }

        if ($company_id <= 0 || empty($valid_from) || empty($valid_until)) {
            $error = 'Institution, valid from and valid until are required.';
        } else {
            try {
                $conn = getDbConnection();
                $lid = (int)($_POST['license_id'] ?? 0);
                if ($lid > 0) {
                    $stmt = $conn->prepare("UPDATE company_licenses SET valid_from=?, valid_until=?, device_limit=?, status=?, notes=? WHERE id=? AND company_id=?");
                    $stmt->bind_param("ssissii", $valid_from, $valid_until, $device_limit, $status, $notes, $lid, $company_id);
                    $stmt->execute();
                    $stmt->close();
                    $success = 'License updated.';
                } else {
                    $stmt = $conn->prepare("INSERT INTO company_licenses (company_id, valid_from, valid_until, device_limit, status, notes) VALUES (?,?,?,?,?,?)");
                    $stmt->bind_param("ississ", $company_id, $valid_from, $valid_until, $device_limit, $status, $notes);
                    $stmt->execute();
                    $stmt->close();
                    $success = 'License created.';
                }

                log_security_event('license_change', $_SESSION['user_id'], $_SESSION['username'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', ['company_id' => $company_id, 'action' => $lid > 0 ? 'update' : 'create']);
                closeDbConnection($conn);
            } catch (Exception $e) {
                $error = 'Hiba: ' . htmlspecialchars($e->getMessage());
            }
        }

    } elseif ($action === 'deactivate_device') {
        $kiosk_id = (int)($_POST['kiosk_id'] ?? 0);
        if ($kiosk_id > 0) {
            try {
                $conn = getDbConnection();
                $stmt = $conn->prepare("UPDATE kiosks SET license_active = 0 WHERE id = ?");
                $stmt->bind_param("i", $kiosk_id);
                $stmt->execute();
                $stmt->close();
                closeDbConnection($conn);
                log_security_event('license_change', $_SESSION['user_id'], $_SESSION['username'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', ['kiosk_id' => $kiosk_id, 'action' => 'deactivate']);
                $success = 'Device deactivated.';
            } catch (Exception $e) {
                $error = 'Hiba: ' . htmlspecialchars($e->getMessage());
            }
        }

    } elseif ($action === 'activate_device') {
        $kiosk_id = (int)($_POST['kiosk_id'] ?? 0);
        if ($kiosk_id > 0) {
            try {
                $conn = getDbConnection();
                $stmt = $conn->prepare("UPDATE kiosks SET license_active = 1 WHERE id = ?");
                $stmt->bind_param("i", $kiosk_id);
                $stmt->execute();
                $stmt->close();
                log_security_event('license_change', $_SESSION['user_id'], $_SESSION['username'] ?? '', $_SERVER['REMOTE_ADDR'] ?? '', $_SERVER['HTTP_USER_AGENT'] ?? '', ['kiosk_id' => $kiosk_id, 'action' => 'activate']);
                $success = 'Device activated.';
                closeDbConnection($conn);
            } catch (Exception $e) {
                $error = 'Hiba: ' . htmlspecialchars($e->getMessage());
            }
        }
    }
}

// Determine which company to show devices for
$selected_company = (int)($_GET['company_id'] ?? 0);

// Load companies with license info
$companies = [];
try {
    $conn = getDbConnection();
    $res = $conn->query("
        SELECT c.id, c.name,
            cl.id        AS license_id,
            cl.valid_from,
            cl.valid_until,
            cl.device_limit,
            cl.status    AS license_status,
            cl.notes,
            (SELECT COUNT(*) FROM kiosks k WHERE k.company_id=c.id AND k.license_active=1) AS used_slots
        FROM companies c
        LEFT JOIN company_licenses cl ON cl.company_id = c.id AND cl.status = 'active'
        ORDER BY c.name
    ");
    if ($res) {
        while ($row = $res->fetch_assoc()) {
            $companies[] = $row;
        }
    }
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'DB hiba: ' . htmlspecialchars($e->getMessage());
}

// Load devices for selected company
$devices = [];
if ($selected_company > 0) {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT id, hostname, device_id, last_seen, activated_at, license_active FROM kiosks WHERE company_id = ? ORDER BY hostname");
        $stmt->bind_param("i", $selected_company);
        $stmt->execute();
        $res = $stmt->get_result();
        while ($row = $res->fetch_assoc()) {
            $devices[] = $row;
        }
        $stmt->close();
        closeDbConnection($conn);
    } catch (Exception $e) {
        $error = 'DB hiba: ' . htmlspecialchars($e->getMessage());
    }
}

// Load company for edit form
$edit_license = null;
if (isset($_GET['edit_license'])) {
    $eid = (int)$_GET['edit_license'];
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT * FROM company_licenses WHERE id = ?");
        $stmt->bind_param("i", $eid);
        $stmt->execute();
        $edit_license = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        closeDbConnection($conn);
    } catch (Exception $e) {}
}

$title = 'Licenses';
require_once 'header.php';
?>

<h2 class="page-title">Institution Licenses</h2>

<div class="alert" style="background:#eef4fb;color:#1f3f5b;border-left:4px solid #3b82f6;">
    Modul licensz kiosztáshoz a <a href="module_licenses.php" style="font-weight:700;">Module Licenses</a> oldalt használd.
</div>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<!-- Company license list -->
<div class="panel">
    <div class="panel-title">Institutions and Licenses</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Institution</th>
                    <th>Valid from</th>
                    <th>Valid until</th>
                    <th>Limit</th>
                    <th>Used</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($companies as $c): ?>
                    <?php
                        $valid_until_ts = $c['valid_until'] ? strtotime($c['valid_until']) : 0;
                        $expired  = $valid_until_ts && $valid_until_ts < time();
                        $expiring = $valid_until_ts && $valid_until_ts < strtotime('+30 days') && !$expired;
                        $over_limit = ($c['device_limit'] > 0) && ((int)$c['used_slots'] > (int)$c['device_limit']);
                    ?>
                    <tr>
                        <td><?php echo htmlspecialchars($c['name']); ?></td>
                        <td class="nowrap"><?php echo htmlspecialchars($c['valid_from'] ?? '-'); ?></td>
                        <td class="nowrap">
                            <?php echo htmlspecialchars($c['valid_until'] ?? '-'); ?>
                            <?php if ($expired): ?>
                                <span class="badge" style="background:#b23b3b;color:#fff;">Expired</span>
                            <?php elseif ($expiring): ?>
                                <span class="badge" style="background:#b36a00;color:#fff;">Expiring soon</span>
                            <?php endif; ?>
                        </td>
                        <td><?php echo $c['device_limit'] !== null ? (int)$c['device_limit'] : '-'; ?></td>
                        <td>
                            <?php echo (int)$c['used_slots']; ?>
                            <?php if ($over_limit): ?>
                                <span class="badge" style="background:#b23b3b;color:#fff;">Exceeded!</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($c['license_status']): ?>
                                <span class="badge"><?php echo htmlspecialchars($c['license_status']); ?></span>
                            <?php else: ?>
                                <span class="muted">-</span>
                            <?php endif; ?>
                        </td>
                        <td class="nowrap">
                            <a href="module_licenses.php?company_id=<?php echo (int)$c['id']; ?>" class="btn btn-small btn-primary">Module licenses</a>
                            <a href="licenses.php?company_id=<?php echo (int)$c['id']; ?>" class="btn btn-small">Devices</a>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- License create/edit form -->
<?php
$form_company_id = (int)($_GET['new_license'] ?? $edit_license['company_id'] ?? 0);
if ($form_company_id > 0 || $edit_license):
?>
<div class="panel" style="margin-top:20px;">
    <div class="panel-title"><?php echo $edit_license ? 'Edit license' : 'New license'; ?></div>
    <form method="POST" action="licenses.php">
        <input type="hidden" name="action" value="save_license">
        <input type="hidden" name="license_id" value="<?php echo (int)($edit_license['id'] ?? 0); ?>">
        <input type="hidden" name="company_id" value="<?php echo $form_company_id ?: (int)($edit_license['company_id'] ?? 0); ?>">
        <div class="form-row">
            <div class="form-field">
                <label>Valid from</label>
                <input type="date" name="valid_from" value="<?php echo htmlspecialchars($edit_license['valid_from'] ?? date('Y-m-d')); ?>" required>
            </div>
            <div class="form-field">
                <label>Valid until</label>
                <input type="date" name="valid_until" value="<?php echo htmlspecialchars($edit_license['valid_until'] ?? ''); ?>" required>
            </div>
        </div>
        <div class="form-row">
            <div class="form-field">
                <label>Device limit</label>
                <input type="number" name="device_limit" value="<?php echo (int)($edit_license['device_limit'] ?? 10); ?>" min="1">
            </div>
            <div class="form-field">
                <label>Status</label>
                <select name="status">
                    <option value="active"    <?php echo ($edit_license['status'] ?? '') === 'active'    ? 'selected' : ''; ?>>Active</option>
                    <option value="suspended" <?php echo ($edit_license['status'] ?? '') === 'suspended' ? 'selected' : ''; ?>>Suspended</option>
                    <option value="expired"   <?php echo ($edit_license['status'] ?? '') === 'expired'   ? 'selected' : ''; ?>>Expired</option>
                </select>
            </div>
        </div>
        <div class="form-field">
            <label>Notes</label>
            <textarea name="notes" rows="3" style="width:100%;"><?php echo htmlspecialchars($edit_license['notes'] ?? ''); ?></textarea>
        </div>
        <button type="submit" class="btn btn-primary">Save</button>
        <a href="licenses.php" class="btn btn-secondary">Cancel</a>
    </form>
</div>
<?php endif; ?>

<!-- Device list for selected company -->
<?php if ($selected_company > 0): ?>
<?php
    $sel_comp = null;
    foreach ($companies as $c) {
        if ((int)$c['id'] === $selected_company) { $sel_comp = $c; break; }
    }
    $limit_info = $sel_comp ? ' (limit: ' . (int)$sel_comp['device_limit'] . ', felhasznált: ' . (int)$sel_comp['used_slots'] . ')' : '';
?>
<div class="panel" style="margin-top:20px;">
    <div class="panel-title">Devices<?php echo htmlspecialchars($limit_info); ?></div>
    <?php if ($sel_comp && (int)$sel_comp['used_slots'] > (int)$sel_comp['device_limit'] && $sel_comp['device_limit']): ?>
        <div class="alert error" style="margin:10px 0;">⚠️ Túlfelhasználás: used nagyobb mint purchased.</div>
    <?php endif; ?>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Hostname</th>
                    <th>Device ID</th>
                    <th>Last activity</th>
                    <th>Activated at</th>
                    <th>License status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($devices)): ?>
                    <tr><td colspan="6" class="muted">No devices.</td></tr>
                <?php else: ?>
                    <?php foreach ($devices as $d): ?>
                        <tr>
                            <td class="mono"><?php echo htmlspecialchars($d['hostname'] ?? '-'); ?></td>
                            <td class="mono muted"><?php echo htmlspecialchars($d['device_id'] ?? '-'); ?></td>
                            <td class="nowrap muted"><?php echo htmlspecialchars($d['last_seen'] ?? '-'); ?></td>
                            <td class="nowrap muted"><?php echo htmlspecialchars($d['activated_at'] ?? '-'); ?></td>
                            <td>
                                <?php if ($d['license_active']): ?>
                                    <span class="badge" style="background:#1f7a39;color:#fff;">Active</span>
                                <?php else: ?>
                                    <span class="badge" style="background:#888;color:#fff;">Inactive</span>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap">
                                <?php if ($d['license_active']): ?>
                                    <form method="POST" action="licenses.php" style="display:inline;" onsubmit="return confirm('Deactivate?');">
                                        <input type="hidden" name="action" value="deactivate_device">
                                        <input type="hidden" name="kiosk_id" value="<?php echo (int)$d['id']; ?>">
                                        <input type="hidden" name="redirect_company" value="<?php echo $selected_company; ?>">
                                        <button type="submit" class="btn btn-small btn-danger">Deactivate</button>
                                    </form>
                                <?php else: ?>
                                    <form method="POST" action="licenses.php" style="display:inline;">
                                        <input type="hidden" name="action" value="activate_device">
                                        <input type="hidden" name="kiosk_id" value="<?php echo (int)$d['id']; ?>">
                                        <input type="hidden" name="redirect_company" value="<?php echo $selected_company; ?>">
                                        <button type="submit" class="btn btn-small btn-primary">Activate</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <p style="margin-top:10px;"><a href="licenses.php" class="btn btn-secondary btn-small">← Back</a></p>
</div>
<?php endif; ?>

<?php require_once 'footer.php'; ?>
