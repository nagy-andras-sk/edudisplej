<?php
session_start();
require_once '../dbkonfiguracia.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: index.php');
    exit();
}

$error = '';
$success = '';

// Handle user creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_user'])) {
    $username = trim($_POST['username'] ?? '');
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $isadmin = isset($_POST['isadmin']) ? 1 : 0;
    $company_id = !empty($_POST['company_id']) ? intval($_POST['company_id']) : null;
    
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
            
            // Check if username exists for other users
            $stmt = $conn->prepare("SELECT id FROM users WHERE username = ? AND id != ?");
            $stmt->bind_param("si", $username, $user_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows > 0) {
                $error = 'Username already exists';
            } else {
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
                } elseif (!$error) {
                    $error = 'Failed to update user';
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

// Get users data
$users = [];
$companies = [];

try {
    $conn = getDbConnection();
    
    // Get companies
    $result = $conn->query("SELECT * FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }
    
    // Get users with company info
    $query = "SELECT u.*, c.name as company_name 
              FROM users u 
              LEFT JOIN companies c ON u.company_id = c.id 
              ORDER BY u.username";
    $result = $conn->query($query);
    
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load users data';
    error_log($e->getMessage());
}

// Get user for editing if edit mode
$edit_user = null;
if (isset($_GET['edit']) && is_numeric($_GET['edit'])) {
    $edit_id = intval($_GET['edit']);
    foreach ($users as $user) {
        if ($user['id'] == $edit_id) {
            $edit_user = $user;
            break;
        }
    }
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
        .card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .card h2 {
            margin-bottom: 20px;
            color: #333;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        input[type="email"],
        input[type="password"],
        select {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        input[type="checkbox"] {
            margin-right: 8px;
        }
        
        .btn-danger {
            background: linear-gradient(135deg, #10b981 0%, #059669 100%);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        
        .badge {
            padding: 4px 8px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .badge-admin {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-user {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .checkbox-label {
            display: flex;
            align-items: center;
            cursor: pointer;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>👥 User Management</h1>
        <a href="index.php">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <div class="card">
            <h2><?php echo $edit_user ? 'Edit User' : 'Create New User'; ?></h2>
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
                        <label for="password">Password <?php echo $edit_user ? '(leave blank to keep current)' : '*'; ?></label>
                        <input type="password" id="password" name="password" 
                               minlength="8" 
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
                        Administrator privileges
                    </label>
                </div>
                
                <button type="submit" name="<?php echo $edit_user ? 'edit_user' : 'create_user'; ?>">
                    <?php echo $edit_user ? 'Update User' : 'Create User'; ?>
                </button>
                
                <?php if ($edit_user): ?>
                    <a href="users.php" class="btn btn-warning">Cancel</a>
                <?php endif; ?>
            </form>
        </div>
        
        <div class="card">
            <h2>All Users</h2>
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Username</th>
                        <th>Email</th>
                        <th>Company</th>
                        <th>Role</th>
                        <th>Created</th>
                        <th>Last Login</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($users)): ?>
                        <tr>
                            <td colspan="8" style="text-align: center; color: #999;">No users found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($user['id']); ?></td>
                                <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                <td><?php echo htmlspecialchars($user['email'] ?? '-'); ?></td>
                                <td><?php echo htmlspecialchars($user['company_name'] ?? 'Unassigned'); ?></td>
                                <td>
                                    <span class="badge <?php echo $user['isadmin'] ? 'badge-admin' : 'badge-user'; ?>">
                                        <?php echo $user['isadmin'] ? 'Admin' : 'User'; ?>
                                    </span>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($user['created_at'])); ?></td>
                                <td><?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Never'; ?></td>
                                <td>
                                    <a href="?edit=<?php echo $user['id']; ?>" class="btn btn-sm">✏️ Edit</a>
                                    <?php if ($user['id'] != $_SESSION['user_id']): ?>
                                        <a href="?delete=<?php echo $user['id']; ?>" 
                                           class="btn btn-sm btn-danger" 
                                           onclick="return confirm('Are you sure you want to delete this user?')">
                                            🗑️ Delete
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</body>
</html>

