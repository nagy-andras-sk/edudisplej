<?php
/**
 * User Management - Minimal Table
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once '../security_config.php';
require_once '../auth_roles.php';
require_once '../user_archive.php';
require_once '../logging.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';

if (isset($_GET['disable_otp']) && is_numeric($_GET['disable_otp'])) {
    $user_id = (int)$_GET['disable_otp'];

    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("UPDATE users SET otp_enabled = 0, otp_verified = 0, otp_secret = NULL WHERE id = ?");
        $stmt->bind_param("i", $user_id);

        if ($stmt->execute()) {
            $success = '2FA disabled successfully for user';
        } else {
            $error = 'Failed to disable 2FA';
        }

        $stmt->close();
        closeDbConnection($conn);
    } catch (Exception $e) {
        $error = 'Database error occurred';
        error_log($e->getMessage());
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $isadmin = isset($_POST['isadmin']) && (string)$_POST['isadmin'] === '1' ? 1 : 0;
    $user_role = edudisplej_normalize_user_role($_POST['user_role'] ?? 'user', (bool)$isadmin);
    $company_id = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;
    $require_otp = isset($_POST['require_otp']) && (string)$_POST['require_otp'] === '1' ? 1 : 0;

    if ($username === '' || $password === '') {
        $error = 'Username and password are required';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } else {
        try {
            $conn = getDbConnection();
            edudisplej_ensure_user_role_column($conn);
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $error = 'Username already exists';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                if ($company_id) {
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password, isadmin, user_role, company_id, otp_enabled) VALUES (?, ?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssisii", $username, $email, $hashed_password, $isadmin, $user_role, $company_id, $require_otp);
                } else {
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password, isadmin, user_role, otp_enabled) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssisi", $username, $email, $hashed_password, $isadmin, $user_role, $require_otp);
                }

                if ($stmt->execute()) {
                    $success = 'User created successfully';
                    if ($require_otp) {
                        $success .= ' - User must setup 2FA on first login';
                    }
                } else {
                    $error = 'Failed to create user';
                }
            }

            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = 'Database error occurred';
            error_log($e->getMessage());
        }
    }
}

if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $user_id = (int)$_GET['delete'];

    if ($user_id === (int)$_SESSION['user_id']) {
        $error = 'Cannot delete your own account';
    } else {
        try {
            $conn = getDbConnection();
            edudisplej_ensure_user_role_column($conn);
            $archive_result = edudisplej_archive_user(
                $conn,
                $user_id,
                (int)$_SESSION['user_id'],
                'admin_users_delete',
                'Archived from admin users page'
            );

            if (!empty($archive_result['success'])) {
                $success = 'User archived successfully';
                log_security_event(
                    'user_archived',
                    (int)$_SESSION['user_id'],
                    (string)($_SESSION['username'] ?? 'admin'),
                    get_client_ip(),
                    get_user_agent(),
                    [
                        'target_user_id' => $user_id,
                        'target_username' => $archive_result['username'] ?? '',
                        'archive_reason' => 'admin_users_delete',
                        'archive_id' => $archive_result['archive_id'] ?? null,
                    ]
                );
            } else {
                $error = $archive_result['message'] ?? 'Failed to archive user';
            }
            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = 'Database error occurred';
            error_log($e->getMessage());
        }
    }
}

$edit_user = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $conn = getDbConnection();
        edudisplej_ensure_user_role_column($conn);
        $user_id = (int)$_GET['edit'];
        $stmt = $conn->prepare("SELECT * FROM users WHERE id = ?");
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $result = $stmt->get_result();

        if ($result->num_rows > 0) {
            $edit_user = $result->fetch_assoc();
        }

        $stmt->close();
        closeDbConnection($conn);
    } catch (Exception $e) {
        $error = 'Failed to load user data';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_user'])) {
    $user_id = (int)($_POST['user_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $isadmin = isset($_POST['isadmin']) && (string)$_POST['isadmin'] === '1' ? 1 : 0;
    $user_role = edudisplej_normalize_user_role($_POST['user_role'] ?? 'user', (bool)$isadmin);
    $company_id = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;

    if ($username === '') {
        $error = 'Username is required';
    } else {
        try {
            $conn = getDbConnection();
            edudisplej_ensure_user_role_column($conn);

            if ($password !== '') {
                if (strlen($password) < 8) {
                    $error = 'Password must be at least 8 characters long';
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    if ($company_id) {
                        $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, password = ?, isadmin = ?, user_role = ?, company_id = ? WHERE id = ?");
                        $stmt->bind_param("sssisii", $username, $email, $hashed_password, $isadmin, $user_role, $company_id, $user_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, password = ?, isadmin = ?, user_role = ?, company_id = NULL WHERE id = ?");
                        $stmt->bind_param("sssisi", $username, $email, $hashed_password, $isadmin, $user_role, $user_id);
                    }
                }
            } else {
                if ($company_id) {
                    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, isadmin = ?, user_role = ?, company_id = ? WHERE id = ?");
                    $stmt->bind_param("ssisii", $username, $email, $isadmin, $user_role, $company_id, $user_id);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, isadmin = ?, user_role = ?, company_id = NULL WHERE id = ?");
                    $stmt->bind_param("ssisi", $username, $email, $isadmin, $user_role, $user_id);
                }
            }

            if (!$error && $stmt->execute()) {
                $success = 'User updated successfully';
                $edit_user = null;
            } else {
                $error = $error ?: 'Failed to update user';
            }

            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = 'Database error occurred';
            error_log($e->getMessage());
        }
    }
}

$users = [];
$companies = [];

try {
    $conn = getDbConnection();
    edudisplej_ensure_user_role_column($conn);

    $result = $conn->query("SELECT * FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }

    $result = $conn->query("
        SELECT u.*, c.name as company_name
        FROM users u
        LEFT JOIN companies c ON u.company_id = c.id
        ORDER BY u.username
    ");

    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }

    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load users';
    error_log($e->getMessage());
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
    <div class="panel-title"><?php echo $edit_user ? 'Felhasznalo szerkesztes' : 'Uj felhasznalo'; ?></div>
    <form method="post" class="form-row">
        <?php if ($edit_user): ?>
            <input type="hidden" name="user_id" value="<?php echo (int)$edit_user['id']; ?>">
        <?php endif; ?>
        <div class="form-field">
            <label for="username">Felhasznalonev</label>
            <input id="username" name="username" type="text" value="<?php echo htmlspecialchars($edit_user['username'] ?? ''); ?>" required>
        </div>
        <div class="form-field">
            <label for="email">Email</label>
            <input id="email" name="email" type="email" value="<?php echo htmlspecialchars($edit_user['email'] ?? ''); ?>">
        </div>
        <div class="form-field">
            <label for="password">Jelszo<?php echo $edit_user ? ' (csak ha valtozik)' : ''; ?></label>
            <input id="password" name="password" type="password">
        </div>
        <div class="form-field">
            <label for="company_id">Institution</label>
            <select id="company_id" name="company_id">
                <option value="">None</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?php echo (int)$company['id']; ?>" <?php echo $edit_user && (int)$edit_user['company_id'] === (int)$company['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($company['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="isadmin">Admin</label>
            <select id="isadmin" name="isadmin">
                <option value="1" <?php echo $edit_user && (int)$edit_user['isadmin'] === 1 ? 'selected' : ''; ?>>Igen</option>
                <option value="0" <?php echo !$edit_user || (int)$edit_user['isadmin'] === 0 ? 'selected' : ''; ?>>Nem</option>
            </select>
        </div>
        <div class="form-field">
            <label for="user_role">Szerepkör</label>
            <select id="user_role" name="user_role">
                <?php $selected_role = $edit_user ? edudisplej_normalize_user_role($edit_user['user_role'] ?? 'user', !empty($edit_user['isadmin'])) : 'user'; ?>
                <option value="user" <?php echo $selected_role === 'user' ? 'selected' : ''; ?>>Felhasználó</option>
                <option value="easy_user" <?php echo $selected_role === 'easy_user' ? 'selected' : ''; ?>>Egyszerű felhasználó</option>
            </select>
        </div>
        <?php if (!$edit_user): ?>
            <div class="form-field">
                <label for="require_otp">2FA kotelezo</label>
                <select id="require_otp" name="require_otp">
                    <option value="0" selected>Nem</option>
                    <option value="1">Igen</option>
                </select>
            </div>
        <?php endif; ?>
        <div class="form-field">
            <?php if ($edit_user): ?>
                <button type="submit" name="edit_user" class="btn btn-primary">Ment</button>
                <a class="btn btn-secondary" href="users.php">Megse</a>
            <?php else: ?>
                <button type="submit" name="create_user" class="btn btn-primary">Letrehoz</button>
            <?php endif; ?>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Felhasznalo lista</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Felhasznalo</th>
                    <th>Email</th>
                    <th>Szerepkör</th>
                    <th>Institution</th>
                    <th>2FA</th>
                    <th>Letrehozva</th>
                    <th>Last login</th>
                    <th>Muvelet</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($users)): ?>
                    <tr>
                        <td colspan="9" class="muted">Nincs felhasznalo.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($users as $user): ?>
                        <tr>
                            <td><?php echo (int)$user['id']; ?></td>
                            <td><?php echo htmlspecialchars($user['username']); ?></td>
                            <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                            <td><?php
                                $role_label = 'Felhasználó';
                                if ((int)$user['isadmin'] === 1) {
                                    $role_label = 'Admin';
                                } else {
                                    $role_value = edudisplej_normalize_user_role($user['user_role'] ?? 'user', false);
                                    if ($role_value === 'easy_user') {
                                        $role_label = 'Egyszerű felhasználó';
                                    }
                                }
                                echo htmlspecialchars($role_label);
                            ?></td>
                            <td><?php echo htmlspecialchars($user['company_name'] ?? '-'); ?></td>
                            <td>
                                <?php if ((int)$user['otp_enabled'] === 1 && (int)$user['otp_verified'] === 1): ?>
                                    On
                                <?php elseif ((int)$user['otp_enabled'] === 1): ?>
                                    Pending
                                <?php else: ?>
                                    Off
                                <?php endif; ?>
                            </td>
                            <td class="nowrap"><?php echo $user['created_at'] ? date('Y-m-d H:i:s', strtotime($user['created_at'])) : '-'; ?></td>
                            <td class="nowrap"><?php echo $user['last_login'] ? date('Y-m-d H:i:s', strtotime($user['last_login'])) : '-'; ?></td>
                            <td class="nowrap">
                                <a class="btn btn-small" href="users.php?edit=<?php echo (int)$user['id']; ?>">Szerkeszt</a>
                                <a class="btn btn-small btn-danger" href="users.php?delete=<?php echo (int)$user['id']; ?>" onclick="return confirm('Archivaljuk a felhasznalot?')">Archival</a>
                                <?php if ((int)$user['otp_enabled'] === 1): ?>
                                    <a class="btn btn-small" href="users.php?disable_otp=<?php echo (int)$user['id']; ?>" onclick="return confirm('Kikapcsoljuk a 2FA-t?')">2FA Off</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var usernameInput = document.getElementById('username');
    var emailInput = document.getElementById('email');

    if (!usernameInput || !emailInput) {
        return;
    }

    function mirrorValue(source, target) {
        target.value = source.value;
    }

    usernameInput.addEventListener('input', function () {
        mirrorValue(usernameInput, emailInput);
    });

    emailInput.addEventListener('input', function () {
        mirrorValue(emailInput, usernameInput);
    });
});
</script>

<?php include 'footer.php'; ?>
