<?php
/**
 * Institution Management - Minimal Table
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once '../user_archive.php';
require_once '../logging.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

const ADMIN_FIXED_DEFAULT_COMPANY_NAME = 'Default Company';

function admin_get_default_company_id(mysqli $conn): int {
    $stmt = $conn->prepare('SELECT id FROM companies WHERE name = ? ORDER BY id ASC LIMIT 1');
    $name = ADMIN_FIXED_DEFAULT_COMPANY_NAME;
    $stmt->bind_param('s', $name);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!empty($row['id'])) {
        return (int)$row['id'];
    }

    $is_active = 1;
    $create_stmt = $conn->prepare('INSERT INTO companies (name, is_active) VALUES (?, ?)');
    $create_stmt->bind_param('si', $name, $is_active);
    $create_stmt->execute();
    $new_id = (int)$conn->insert_id;
    $create_stmt->close();

    return $new_id;
}

function admin_set_default_company_id(mysqli $conn, int $company_id): void {
    // Default company is fixed by name (ADMIN_FIXED_DEFAULT_COMPANY_NAME).
    // Intentionally no-op.
}

function admin_ensure_company_profile_columns(mysqli $conn): void {
    $existing_columns = [];
    $columns_result = $conn->query('SHOW COLUMNS FROM companies');
    if ($columns_result) {
        while ($column_row = $columns_result->fetch_assoc()) {
            $existing_columns[(string)($column_row['Field'] ?? '')] = true;
        }
    }

    if (!isset($existing_columns['address'])) {
        $conn->query('ALTER TABLE companies ADD COLUMN address TEXT DEFAULT NULL AFTER name');
    }

    if (!isset($existing_columns['tax_number'])) {
        $conn->query('ALTER TABLE companies ADD COLUMN tax_number VARCHAR(64) DEFAULT NULL AFTER address');
    }
}

function admin_ensure_company_token_columns(mysqli $conn): void {
    $existing_columns = [];
    $columns_result = $conn->query('SHOW COLUMNS FROM companies');
    if ($columns_result) {
        while ($column_row = $columns_result->fetch_assoc()) {
            $existing_columns[(string)($column_row['Field'] ?? '')] = true;
        }
    }

    if (!isset($existing_columns['license_key'])) {
        $conn->query('ALTER TABLE companies ADD COLUMN license_key VARCHAR(255) DEFAULT NULL');
    }
    if (!isset($existing_columns['api_token'])) {
        $conn->query('ALTER TABLE companies ADD COLUMN api_token VARCHAR(255) DEFAULT NULL');
    }
    if (!isset($existing_columns['token_created_at'])) {
        $conn->query('ALTER TABLE companies ADD COLUMN token_created_at TIMESTAMP NULL DEFAULT NULL');
    }
}

function admin_generate_unique_company_api_token(mysqli $conn): string {
    for ($i = 0; $i < 10; $i++) {
        $candidate = bin2hex(random_bytes(32));
        $check_stmt = $conn->prepare('SELECT id FROM companies WHERE api_token = ? LIMIT 1');
        $check_stmt->bind_param('s', $candidate);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();
        if (!$exists) {
            return $candidate;
        }
    }
    throw new RuntimeException('Failed to generate unique API token');
}

function admin_generate_unique_company_license_key(mysqli $conn): string {
    for ($i = 0; $i < 10; $i++) {
        $candidate = strtoupper(bin2hex(random_bytes(16)));
        $check_stmt = $conn->prepare('SELECT id FROM companies WHERE license_key = ? LIMIT 1');
        $check_stmt->bind_param('s', $candidate);
        $check_stmt->execute();
        $exists = $check_stmt->get_result()->num_rows > 0;
        $check_stmt->close();
        if (!$exists) {
            return $candidate;
        }
    }
    throw new RuntimeException('Failed to generate unique license key');
}

function admin_assign_default_unconfigured_license(mysqli $conn, int $company_id): void {
    if ($company_id <= 0) {
        return;
    }

    $module_stmt = $conn->prepare("SELECT id FROM modules WHERE module_key = 'unconfigured' LIMIT 1");
    $module_stmt->execute();
    $module_row = $module_stmt->get_result()->fetch_assoc();
    $module_stmt->close();

    $module_id = (int)($module_row['id'] ?? 0);
    if ($module_id <= 0) {
        return;
    }

    $quantity = 1;
    $existing_stmt = $conn->prepare('SELECT id, quantity FROM module_licenses WHERE company_id = ? AND module_id = ? ORDER BY id ASC LIMIT 1');
    $existing_stmt->bind_param('ii', $company_id, $module_id);
    $existing_stmt->execute();
    $existing_row = $existing_stmt->get_result()->fetch_assoc();
    $existing_stmt->close();

    if ($existing_row) {
        $existing_id = (int)$existing_row['id'];
        $existing_quantity = (int)($existing_row['quantity'] ?? 0);
        if ($existing_quantity < 1) {
            $update_stmt = $conn->prepare('UPDATE module_licenses SET quantity = 1 WHERE id = ?');
            $update_stmt->bind_param('i', $existing_id);
            $update_stmt->execute();
            $update_stmt->close();
        }
    } else {
        $insert_stmt = $conn->prepare('INSERT INTO module_licenses (company_id, module_id, quantity) VALUES (?, ?, ?)');
        $insert_stmt->bind_param('iii', $company_id, $module_id, $quantity);
        $insert_stmt->execute();
        $insert_stmt->close();
    }
}

function admin_resolve_fallback_company_id(mysqli $conn, int $exclude_company_id): int {
    $default_company_id = admin_get_default_company_id($conn);
    if ($default_company_id > 0 && $default_company_id !== $exclude_company_id) {
        return $default_company_id;
    }

    $pick_stmt = $conn->prepare("SELECT id FROM companies WHERE id <> ? ORDER BY id ASC LIMIT 1");
    $pick_stmt->bind_param('i', $exclude_company_id);
    $pick_stmt->execute();
    $row = $pick_stmt->get_result()->fetch_assoc();
    $pick_stmt->close();
    if (!empty($row['id'])) {
        return (int)$row['id'];
    }

    $name = ADMIN_FIXED_DEFAULT_COMPANY_NAME;
    $is_active = 1;
    $create_stmt = $conn->prepare("INSERT INTO companies (name, is_active) VALUES (?, ?)");
    $create_stmt->bind_param('si', $name, $is_active);
    $create_stmt->execute();
    $new_id = (int)$conn->insert_id;
    $create_stmt->close();

    return $new_id;
}

if (isset($_GET['act_as']) && is_numeric($_GET['act_as'])) {
    $act_as_company_id = (int)$_GET['act_as'];
    try {
        $conn = getDbConnection();
        $verify_stmt = $conn->prepare("SELECT id, name FROM companies WHERE id = ? LIMIT 1");
        $verify_stmt->bind_param('i', $act_as_company_id);
        $verify_stmt->execute();
        $company_row = $verify_stmt->get_result()->fetch_assoc();
        $verify_stmt->close();
        closeDbConnection($conn);

        if (!$company_row) {
            $error = 'Institution not found';
        } else {
            $_SESSION['admin_acting_company_id'] = $act_as_company_id;
            $_SESSION['company_id'] = $act_as_company_id;
            $_SESSION['company_name'] = (string)$company_row['name'];

            log_security_event(
                'admin_act_as_company',
                (int)$_SESSION['user_id'],
                (string)($_SESSION['username'] ?? 'admin'),
                get_client_ip(),
                get_user_agent(),
                [
                    'company_id' => $act_as_company_id,
                    'company_name' => (string)$company_row['name'],
                ]
            );

            header('Location: ../dashboard/index.php');
            exit();
        }
    } catch (Exception $e) {
        $error = 'Failed to switch company context';
        error_log($e->getMessage());
    }
}

if (isset($_GET['act_as_default']) && (int)$_GET['act_as_default'] === 1) {
    try {
        $conn = getDbConnection();
        $default_id = admin_get_default_company_id($conn);
        if ($default_id <= 0) {
            $default_id = admin_resolve_fallback_company_id($conn, 0);
        }
        closeDbConnection($conn);

        header('Location: companies.php?act_as=' . $default_id);
        exit();
    } catch (Exception $e) {
        $error = 'Failed to switch to default institution';
        error_log($e->getMessage());
    }
}

if (isset($_GET['stop_act_as']) && (int)$_GET['stop_act_as'] === 1) {
    unset($_SESSION['admin_acting_company_id']);
    unset($_SESSION['company_id']);
    unset($_SESSION['company_name']);
    $success = 'Admin company context cleared';
}

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $company_id = (int)$_GET['delete'];

    try {
        $conn = getDbConnection();

        $company_stmt = $conn->prepare("SELECT id, name FROM companies WHERE id = ? LIMIT 1");
        $company_stmt->bind_param('i', $company_id);
        $company_stmt->execute();
        $company_row = $company_stmt->get_result()->fetch_assoc();
        $company_stmt->close();

        if (!$company_row) {
            $error = 'Institution not found';
            closeDbConnection($conn);
            throw new Exception('Institution not found');
        }

        $default_company_id = admin_get_default_company_id($conn);
        if ($default_company_id === $company_id) {
            $error = 'Cannot delete the default institution. Set another default first.';
            closeDbConnection($conn);
            throw new Exception('Cannot delete default institution');
        }

        $fallback_company_id = admin_resolve_fallback_company_id($conn, $company_id);

        $fallback_name_stmt = $conn->prepare("SELECT name FROM companies WHERE id = ? LIMIT 1");
        $fallback_name_stmt->bind_param('i', $fallback_company_id);
        $fallback_name_stmt->execute();
        $fallback_row = $fallback_name_stmt->get_result()->fetch_assoc();
        $fallback_name_stmt->close();
        $fallback_company_name = (string)($fallback_row['name'] ?? ('#' . $fallback_company_id));

        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM kiosks WHERE company_id = ?");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $kiosk_count = $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        $stmt = $conn->prepare("SELECT COUNT(*) as count FROM users WHERE company_id = ?");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $user_count = $stmt->get_result()->fetch_assoc()['count'];
        $stmt->close();

        $conn->begin_transaction();
        try {
            $users_stmt = $conn->prepare("SELECT id FROM users WHERE company_id = ? ORDER BY id ASC");
            $users_stmt->bind_param('i', $company_id);
            $users_stmt->execute();
            $users_result = $users_stmt->get_result();
            $user_ids = [];
            while ($user_row = $users_result->fetch_assoc()) {
                $user_ids[] = (int)$user_row['id'];
            }
            $users_stmt->close();

            $archived_users_count = 0;
            foreach ($user_ids as $target_user_id) {
                if ($target_user_id === (int)$_SESSION['user_id']) {
                    $self_move_stmt = $conn->prepare("UPDATE users SET company_id = ? WHERE id = ?");
                    $self_move_stmt->bind_param('ii', $fallback_company_id, $target_user_id);
                    $self_move_stmt->execute();
                    $self_move_stmt->close();
                    continue;
                }

                $archive_result = edudisplej_archive_user(
                    $conn,
                    $target_user_id,
                    (int)$_SESSION['user_id'],
                    'company_deleted',
                    'Archived during company deletion #' . $company_id,
                    false
                );

                if (empty($archive_result['success'])) {
                    throw new Exception($archive_result['message'] ?? 'Failed to archive company user');
                }
                $archived_users_count++;
            }

            $move_kiosks_stmt = $conn->prepare("UPDATE kiosks SET company_id = ? WHERE company_id = ?");
            $move_kiosks_stmt->bind_param('ii', $fallback_company_id, $company_id);
            $move_kiosks_stmt->execute();
            $moved_kiosk_count = (int)$move_kiosks_stmt->affected_rows;
            $move_kiosks_stmt->close();

            $delete_stmt = $conn->prepare("DELETE FROM companies WHERE id = ?");
            $delete_stmt->bind_param('i', $company_id);
            if (!$delete_stmt->execute()) {
                $delete_stmt->close();
                throw new Exception('Failed to delete institution');
            }
            $delete_stmt->close();

            $conn->commit();

            if (!empty($_SESSION['admin_acting_company_id']) && (int)$_SESSION['admin_acting_company_id'] === $company_id) {
                $_SESSION['admin_acting_company_id'] = $fallback_company_id;
                $_SESSION['company_id'] = $fallback_company_id;
                $_SESSION['company_name'] = $fallback_company_name;
            }

            $success = "Institution deleted. Archived users: $archived_users_count. Moved kiosks: $moved_kiosk_count to $fallback_company_name.";

            log_security_event(
                'company_deleted_with_reassign',
                (int)$_SESSION['user_id'],
                (string)($_SESSION['username'] ?? 'admin'),
                get_client_ip(),
                get_user_agent(),
                [
                    'deleted_company_id' => $company_id,
                    'deleted_company_name' => (string)$company_row['name'],
                    'fallback_company_id' => $fallback_company_id,
                    'fallback_company_name' => $fallback_company_name,
                    'archived_users' => $archived_users_count,
                    'moved_kiosks' => $moved_kiosk_count,
                    'users_before_delete' => (int)$user_count,
                    'kiosks_before_delete' => (int)$kiosk_count,
                ]
            );
        } catch (Exception $txe) {
            $conn->rollback();
            $error = $txe->getMessage();
        }

        closeDbConnection($conn);
    } catch (Exception $e) {
        if ($error === '') {
            $error = 'Database error occurred';
        }
        error_log($e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_company'])) {
    $company_id = isset($_POST['company_id']) ? (int)$_POST['company_id'] : 0;
    $name = trim($_POST['company_name'] ?? '');
    $address = trim($_POST['company_address'] ?? '');
    $tax_number = trim($_POST['tax_number'] ?? '');
    $is_active = isset($_POST['is_active']) ? (int)$_POST['is_active'] : 1;
    $is_active = $is_active === 0 ? 0 : 1;

    if ($name === '') {
        $error = 'Institution name is required';
    } else {
        try {
            $conn = getDbConnection();
            admin_ensure_company_profile_columns($conn);
            admin_ensure_company_token_columns($conn);

            $default_company_id = admin_get_default_company_id($conn);

            if ($company_id > 0) {
                if ($company_id === $default_company_id) {
                    $name = ADMIN_FIXED_DEFAULT_COMPANY_NAME;
                    $is_active = 1;
                }
                $stmt = $conn->prepare("UPDATE companies SET name = ?, address = ?, tax_number = ?, is_active = ? WHERE id = ?");
                $stmt->bind_param("sssii", $name, $address, $tax_number, $is_active, $company_id);
                $success = 'Institution updated successfully';
                $stmt->execute();
                $stmt->close();
            } else {
                $conn->begin_transaction();

                $generated_api_token = admin_generate_unique_company_api_token($conn);
                $generated_license_key = admin_generate_unique_company_license_key($conn);

                $stmt = $conn->prepare("INSERT INTO companies (name, address, tax_number, is_active, license_key, api_token, token_created_at) VALUES (?, ?, ?, ?, ?, ?, NOW())");
                $stmt->bind_param("sssiss", $name, $address, $tax_number, $is_active, $generated_license_key, $generated_api_token);
                $stmt->execute();
                $new_company_id = (int)$conn->insert_id;
                $stmt->close();

                admin_assign_default_unconfigured_license($conn, $new_company_id);
                $conn->commit();

                $success = 'Institution created successfully';
            }
            closeDbConnection($conn);
        } catch (Exception $e) {
            if (isset($conn) && $conn instanceof mysqli) {
                $conn->rollback();
                closeDbConnection($conn);
            }
            $error = 'Failed to save institution';
            error_log('admin companies save_company: ' . $e->getMessage());
        }
    }
}

$companies = [];
$default_company_id = 0;
try {
    $conn = getDbConnection();
    admin_ensure_company_profile_columns($conn);
    $default_company_id = admin_get_default_company_id($conn);
    $result = $conn->query("
        SELECT c.*, 
               COUNT(DISTINCT k.id) as kiosk_count,
               COUNT(DISTINCT u.id) as user_count
        FROM companies c
        LEFT JOIN kiosks k ON c.id = k.company_id
        LEFT JOIN users u ON c.id = u.company_id
        GROUP BY c.id
        ORDER BY c.name
    ");

    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }

    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load institutions';
}

$edit_company = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    foreach ($companies as $company) {
        if ((int)$company['id'] === (int)$_GET['edit']) {
            $edit_company = $company;
            break;
        }
    }
}

include 'header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title">Admin company context</div>
    <div class="toolbar" style="align-items:flex-end; gap:10px;">
        <div class="form-field">
            <div style="font-size:12px; color:#60788f;">Default institution ID: <strong><?php echo (int)$default_company_id; ?></strong></div>
            <?php if (!empty($_SESSION['admin_acting_company_id']) && !empty($_SESSION['company_name'])): ?>
                <div style="font-size:12px; color:#1f4d7a; margin-top:4px;">Impersonating: <strong><?php echo htmlspecialchars((string)$_SESSION['company_name']); ?></strong> (ID: <?php echo (int)$_SESSION['admin_acting_company_id']; ?>)</div>
            <?php endif; ?>
        </div>
        <div class="form-field">
            <a class="btn btn-primary" href="companies.php?act_as_default=1">Open default company dashboard</a>
        </div>
        <?php if (!empty($_SESSION['admin_acting_company_id'])): ?>
            <div class="form-field">
                <a class="btn btn-secondary" href="companies.php?stop_act_as=1">Stop company context</a>
            </div>
        <?php endif; ?>
    </div>
</div>

<div class="panel">
    <div class="panel-title"><?php echo $edit_company ? 'Edit institution' : 'New institution'; ?></div>
    <form method="post" class="form-row">
        <input type="hidden" name="company_id" value="<?php echo $edit_company ? (int)$edit_company['id'] : 0; ?>">
        <div class="form-field" style="min-width: 260px;">
            <label for="company_name">Institution name</label>
            <input id="company_name" name="company_name" type="text" value="<?php echo htmlspecialchars($edit_company['name'] ?? ''); ?>" required>
        </div>
        <div class="form-field" style="min-width: 260px;">
            <label for="company_address">Address</label>
            <textarea id="company_address" name="company_address" rows="3" style="width:100%;"><?php echo htmlspecialchars($edit_company['address'] ?? ''); ?></textarea>
        </div>
        <div class="form-field" style="min-width: 220px;">
            <label for="tax_number">Tax number</label>
            <input id="tax_number" name="tax_number" type="text" value="<?php echo htmlspecialchars($edit_company['tax_number'] ?? ''); ?>">
        </div>
        <div class="form-field">
            <label for="is_active">Active</label>
            <select id="is_active" name="is_active">
                <option value="1" <?php echo ($edit_company && (int)$edit_company['is_active'] === 1) || !$edit_company ? 'selected' : ''; ?>>Yes</option>
                <option value="0" <?php echo $edit_company && (int)$edit_company['is_active'] === 0 ? 'selected' : ''; ?>>No</option>
            </select>
        </div>
        <div class="form-field">
            <button type="submit" name="save_company" class="btn btn-primary">Save</button>
        </div>
        <?php if ($edit_company): ?>
            <div class="form-field">
                <a class="btn btn-secondary" href="companies.php">Cancel</a>
            </div>
        <?php endif; ?>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Institution list</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Name</th>
                    <th>Address</th>
                    <th>Tax number</th>
                    <th>Active</th>
                    <th>Kiosks</th>
                    <th>Users</th>
                    <th>License Key</th>
                    <th>API Token</th>
                    <th>Install command</th>
                    <th>Token created</th>
                    <th>Created at</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($companies)): ?>
                    <tr>
                        <td colspan="13" class="muted">No institutions.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($companies as $company): ?>
                        <tr>
                            <td><?php echo (int)$company['id']; ?></td>
                            <td><?php echo htmlspecialchars($company['name']); ?></td>
                            <td><?php echo htmlspecialchars($company['address'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($company['tax_number'] ?? '-'); ?></td>
                            <td><?php echo (int)$company['is_active'] === 1 ? 'Yes' : 'No'; ?></td>
                            <td><?php echo (int)$company['kiosk_count']; ?></td>
                            <td><?php echo (int)$company['user_count']; ?></td>
                            <td class="mono"><?php echo htmlspecialchars($company['license_key'] ?? '-'); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($company['api_token'] ?? '-'); ?></td>
                            <td class="mono">
                                <?php if (!empty($company['api_token'])): ?>
                                    <?php $install_command = 'curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash -s -- --token=' . (string)$company['api_token']; ?>
                                    <button type="button" class="btn btn-small" data-copy="<?php echo htmlspecialchars($install_command, ENT_QUOTES, 'UTF-8'); ?>" onclick="copyToClipboard(event)">Copy install</button>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td class="nowrap"><?php echo $company['token_created_at'] ? date('Y-m-d H:i:s', strtotime($company['token_created_at'])) : '-'; ?></td>
                            <td class="nowrap"><?php echo $company['created_at'] ? date('Y-m-d H:i:s', strtotime($company['created_at'])) : '-'; ?></td>
                            <td class="nowrap">
                                <a class="btn btn-small btn-primary" href="companies.php?act_as=<?php echo (int)$company['id']; ?>">Impersonate</a>
                                <a class="btn btn-small" href="companies.php?edit=<?php echo (int)$company['id']; ?>">Edit</a>
                                <a class="btn btn-small btn-danger" href="companies.php?delete=<?php echo (int)$company['id']; ?>" onclick="return confirm('Delete institution? Users will be archived and kiosks moved to default company.')">Delete</a>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function copyToClipboard(event) {
    var target = event && event.currentTarget ? event.currentTarget : null;
    if (!target) {
        return;
    }

    var text = target.getAttribute('data-copy') || '';
    if (!text) {
        return;
    }

    if (navigator.clipboard && navigator.clipboard.writeText) {
        navigator.clipboard.writeText(text).then(function () {
            var prev = target.textContent;
            target.textContent = 'Copied';
            setTimeout(function () { target.textContent = prev; }, 1200);
        }).catch(function () {});
    }
}
</script>

<?php include 'footer.php'; ?>
