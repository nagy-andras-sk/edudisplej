<?php
/**
 * User Management - Minimal Table
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once '../security_config.php';

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
    $isadmin = isset($_POST['isadmin']) ? 1 : 0;
    $company_id = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;
    $require_otp = isset($_POST['require_otp']) ? 1 : 0;

    if ($username === '' || $password === '') {
        $error = 'Username and password are required';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } else {
        try {
            $conn = getDbConnection();
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();

            if ($result->num_rows > 0) {
                $error = 'Username already exists';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);

                if ($company_id) {
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password, isadmin, company_id, otp_enabled) VALUES (?, ?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssiii", $username, $email, $hashed_password, $isadmin, $company_id, $require_otp);
                } else {
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password, isadmin, otp_enabled) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssii", $username, $email, $hashed_password, $isadmin, $require_otp);
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
            $stmt = $conn->prepare("DELETE FROM users WHERE id = ?");
            $stmt->bind_param("i", $user_id);

            if ($stmt->execute()) {
                $success = 'User deleted successfully';
            } else {
                $error = 'Failed to delete user';
            }

            $stmt->close();
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
    $isadmin = isset($_POST['isadmin']) ? 1 : 0;
    $company_id = !empty($_POST['company_id']) ? (int)$_POST['company_id'] : null;

    if ($username === '') {
        $error = 'Username is required';
    } else {
        try {
            $conn = getDbConnection();

            if ($password !== '') {
                if (strlen($password) < 8) {
                    $error = 'Password must be at least 8 characters long';
                } else {
                    $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                    if ($company_id) {
                        $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, password = ?, isadmin = ?, company_id = ? WHERE id = ?");
                        $stmt->bind_param("sssiii", $username, $email, $hashed_password, $isadmin, $company_id, $user_id);
                    } else {
                        $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, password = ?, isadmin = ?, company_id = NULL WHERE id = ?");
                        $stmt->bind_param("sssii", $username, $email, $hashed_password, $isadmin, $user_id);
                    }
                }
            } else {
                if ($company_id) {
                    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, isadmin = ?, company_id = ? WHERE id = ?");
                    $stmt->bind_param("ssiii", $username, $email, $isadmin, $company_id, $user_id);
                } else {
                    $stmt = $conn->prepare("UPDATE users SET username = ?, email = ?, isadmin = ?, company_id = NULL WHERE id = ?");
                    $stmt->bind_param("ssii", $username, $email, $isadmin, $user_id);
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
                    <th>Admin</th>
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
                            <td><?php echo (int)$user['isadmin'] === 1 ? 'Igen' : 'Nem'; ?></td>
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
                                <a class="btn btn-small btn-danger" href="users.php?delete=<?php echo (int)$user['id']; ?>" onclick="return confirm('Toroljuk a felhasznalot?')">Torol</a>
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

<?php include 'footer.php'; ?>
