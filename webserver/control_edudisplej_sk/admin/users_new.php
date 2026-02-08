<?php
/**
 * User Management with OTP/2FA Support
 * EduDisplej Admin Panel
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../security_config.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';

// Handle OTP disable for user
if (isset($_GET['disable_otp']) && is_numeric($_GET['disable_otp'])) {
    $user_id = intval($_GET['disable_otp']);
    
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

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $isadmin = isset($_POST['isadmin']) ? 1 : 0;
    $company_id = !empty($_POST['company_id']) ? intval($_POST['company_id']) : null;
    $require_otp = isset($_POST['require_otp']) ? 1 : 0;
    
    if (empty($username) || empty($password)) {
        $error = 'Username and password are required';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters long';
    } else {
        try {
            $conn = getDbConnection();
            
            // Check if username exists
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = 'Username already exists';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                if ($company_id) {
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password, isadmin, company_id) VALUES (?, ?, ?, ?, ?)");
                    $stmt->bind_param("sssii", $username, $email, $hashed_password, $isadmin, $company_id);
                } else {
                    $stmt = $conn->prepare("INSERT INTO users (username, email, password, isadmin) VALUES (?, ?, ?, ?)");
                    $stmt->bind_param("sssi", $username, $email, $hashed_password, $isadmin);
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

// Handle user deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $user_id = intval($_GET['delete']);
    
    // Prevent deleting yourself
    if ($user_id == $_SESSION['user_id']) {
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

// Handle user modification
$edit_user = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    try {
        $conn = getDbConnection();
        $user_id = intval($_GET['edit']);
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
    $user_id = intval($_POST['user_id'] ?? 0);
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $isadmin = isset($_POST['isadmin']) ? 1 : 0;
    $company_id = !empty($_POST['company_id']) ? intval($_POST['company_id']) : null;
    
    if (empty($username)) {
        $error = 'Username is required';
    } else {
        try {
            $conn = getDbConnection();
            
            if (!empty($password)) {
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
                $error = 'Failed to update user';
            }
            
            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            $error = 'Database error occurred';
            error_log($e->getMessage());
        }
    }
}

// Get all users with company names and OTP status
$users = [];
$companies = [];

try {
    $conn = getDbConnection();
    
    // Get companies for dropdown
    $result = $conn->query("SELECT * FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }
    
    // Get users with OTP information
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>User Management - EduDisplej</title>
    <link rel="stylesheet" href="style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
        }
        
        .header {
            background: linear-gradient(135deg, #1e40af 0%, #0369a1 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
        }
        
        .header-btn {
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .header-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .card h2 {
            margin-bottom: 20px;
            color: #333;
            font-size: 20px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert.success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #4caf50;
        }
        
        .alert.error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #f44336;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            color: #333;
            font-weight: 500;
            font-size: 14px;
        }
        
        input[type="text"],
        input[type="email"],
        input[type="password"],
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
            transition: all 0.3s;
        }
        
        input:focus,
        select:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(30, 64, 175, 0.1);
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
            font-weight: 400;
        }
        
        input[type="checkbox"] {
            margin-right: 10px;
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #1e40af 0%, #0369a1 100%);
            color: white;
        }
        
        .btn-primary:hover {
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-danger {
            background: #f44336;
            color: white;
        }
        
        .btn-warning {
            background: #ff9800;
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        thead {
            background: #f5f7fa;
        }
        
        th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-admin {
            background: #e3f2fd;
            color: #1976d2;
        }
        
        .badge-user {
            background: #f3e5f5;
            color: #7b1fa2;
        }
        
        .badge-otp-enabled {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .badge-otp-disabled {
            background: #fff3e0;
            color: #e65100;
        }
        
        .otp-indicator {
            display: inline-flex;
            align-items: center;
            gap: 5px;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>👥 User Management</h1>
        <a href="dashboard.php" class="header-btn">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="alert error">
                <span>⚠️</span>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert success">
                <span>✓</span>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <div class="card">
            <h2><?php echo $edit_user ? '✏️ Edit User' : '➕ Create New User'; ?></h2>
            <form method="POST">
                <?php if ($edit_user): ?>
                    <input type="hidden" name="user_id" value="<?php echo $edit_user['id']; ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="username">Username *</label>
                        <input type="text" id="username" name="username" 
                               value="<?php echo $edit_user ? htmlspecialchars($edit_user['username']) : ''; ?>" 
                               required>
                    </div>
                    
                    <div class="form-group">
                        <label for="email">Email</label>
                        <input type="email" id="email" name="email" 
                               value="<?php echo $edit_user ? htmlspecialchars($edit_user['email']) : ''; ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="password">
                            Password <?php echo $edit_user ? '(leave blank to keep current)' : '*'; ?>
                        </label>
                        <input type="password" id="password" name="password" 
                               minlength="8" 
                               placeholder="Minimum 8 characters"
                               <?php echo !$edit_user ? 'required' : ''; ?>>
                    </div>
                    
                    <div class="form-group">
                        <label for="company_id">Assign to Company</label>
                        <select id="company_id" name="company_id">
                            <option value="">-- Unassigned --</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?php echo $company['id']; ?>"
                                    <?php echo ($edit_user && $edit_user['company_id'] == $company['id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($company['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="isadmin" 
                               <?php echo ($edit_user && $edit_user['isadmin']) ? 'checked' : ''; ?>>
                        🔑 Administrator privileges
                    </label>
                </div>
                
                <?php if (!$edit_user): ?>
                <div class="form-group">
                    <label class="checkbox-label">
                        <input type="checkbox" name="require_otp">
                        🔐 Require 2FA setup on first login (recommended for admins)
                    </label>
                </div>
                <?php endif; ?>
                
                <div style="display: flex; gap: 10px;">
                    <button type="submit" name="<?php echo $edit_user ? 'edit_user' : 'create_user'; ?>" class="btn btn-primary">
                        <?php echo $edit_user ? '💾 Update User' : '➕ Create User'; ?>
                    </button>
                    
                    <?php if ($edit_user): ?>
                        <a href="users_new.php" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                </div>
            </form>
        </div>
        
        <div class="card">
            <h2>📋 All Users</h2>
            <div style="overflow-x: auto;">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Username</th>
                            <th>Email</th>
                            <th>Company</th>
                            <th>Role</th>
                            <th>2FA Status</th>
                            <th>Created</th>
                            <th>Last Login</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="9" style="text-align: center; color: #999; padding: 40px;">
                                    No users found
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($user['id']); ?></td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($user['username']); ?></strong>
                                        <?php if ($user['id'] == $_SESSION['user_id']): ?>
                                            <span class="badge" style="background: #fce4ec; color: #c2185b;">YOU</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                    <td><?php echo htmlspecialchars($user['company_name'] ?? 'Unassigned'); ?></td>
                                    <td>
                                        <span class="badge <?php echo $user['isadmin'] ? 'badge-admin' : 'badge-user'; ?>">
                                            <?php echo $user['isadmin'] ? '🔑 Admin' : '👤 User'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <?php if ($user['otp_enabled'] && $user['otp_verified']): ?>
                                            <div class="otp-indicator">
                                                <span class="badge badge-otp-enabled">🔐 Enabled</span>
                                            </div>
                                        <?php else: ?>
                                            <span class="badge badge-otp-disabled">Disabled</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                    <td>
                                        <?php 
                                        if ($user['last_login']) {
                                            $last_login = strtotime($user['last_login']);
                                            $diff = time() - $last_login;
                                            
                                            if ($diff < 3600) {
                                                echo '<span style="color: #4caf50;">Just now</span>';
                                            } elseif ($diff < 86400) {
                                                echo '<span style="color: #4caf50;">Today</span>';
                                            } else {
                                                echo date('Y-m-d H:i', $last_login);
                                            }
                                        } else {
                                            echo '<span style="color: #999;">Never</span>';
                                        }
                                        ?>
                                    </td>
                                    <td>
                                        <div style="display: flex; gap: 5px; flex-wrap: wrap;">
                                            <a href="?edit=<?php echo $user['id']; ?>" class="btn btn-sm btn-primary">
                                                ✏️ Edit
                                            </a>
                                            
                                            <?php if ($user['otp_enabled'] && $user['otp_verified']): ?>
                                                <a href="?disable_otp=<?php echo $user['id']; ?>" 
                                                   class="btn btn-sm btn-warning" 
                                                   onclick="return confirm('Disable 2FA for this user?')">
                                                    🔓 Disable 2FA
                                                </a>
                                            <?php endif; ?>
                                            
                                            <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                                <a href="?delete=<?php echo $user['id']; ?>" 
                                                   class="btn btn-sm btn-danger" 
                                                   onclick="return confirm('Are you sure you want to delete this user? This action cannot be undone.')">
                                                    🗑️ Delete
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <div class="card">
            <h2>🔐 Two-Factor Authentication (2FA) Information</h2>
            <div style="color: #666; line-height: 1.8;">
                <p style="margin-bottom: 15px;">
                    <strong>Current Status:</strong> 
                    <?php 
                    $otp_count = count(array_filter($users, fn($u) => $u['otp_enabled'] && $u['otp_verified']));
                    echo "$otp_count of " . count($users) . " users have 2FA enabled"; 
                    ?>
                </p>
                
                <h3 style="font-size: 16px; margin: 20px 0 10px; color: #333;">How 2FA Works:</h3>
                <ul style="padding-left: 20px;">
                    <li>Users can enable 2FA from their profile settings</li>
                    <li>Requires authenticator app (Google Authenticator, Authy, etc.)</li>
                    <li>Adds extra security layer beyond password</li>
                    <li>Admin can disable 2FA for users if needed (e.g., lost phone)</li>
                </ul>
                
                <h3 style="font-size: 16px; margin: 20px 0 10px; color: #333;">Security Recommendations:</h3>
                <ul style="padding-left: 20px;">
                    <li>✓ All admin accounts should have 2FA enabled</li>
                    <li>✓ Encourage users to enable 2FA for enhanced security</li>
                    <li>✓ Backup codes should be saved securely</li>
                    <li>✓ Regularly review user access and permissions</li>
                </ul>
            </div>
        </div>
    </div>
</body>
</html>
