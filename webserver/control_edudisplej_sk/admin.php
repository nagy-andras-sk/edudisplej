<?php
/**
 * Admin Panel
 * EduDisplej Control Panel
 */

session_start();
require_once 'dbkonfiguracia.php';

$error = '';
$login_error = '';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: admin.php');
    exit();
}

// Handle login
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['login'])) {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    
    if (empty($username) || empty($password)) {
        $login_error = 'Username and password are required';
    } else {
        try {
            $conn = getDbConnection();
            $stmt = $conn->prepare("SELECT id, username, password, isadmin FROM users WHERE username = ?");
            $stmt->bind_param("s", $username);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows === 1) {
                $user = $result->fetch_assoc();
                
                if (password_verify($password, $user['password'])) {
                    if ($user['isadmin'] == 1) {
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['username'] = $user['username'];
                        $_SESSION['isadmin'] = true;
                        
                        // Update last login
                        $update_stmt = $conn->prepare("UPDATE users SET last_login = NOW() WHERE id = ?");
                        $update_stmt->bind_param("i", $user['id']);
                        $update_stmt->execute();
                        $update_stmt->close();
                        
                        header('Location: admin.php');
                        exit();
                    } else {
                        $login_error = 'Access denied. Admin privileges required.';
                    }
                } else {
                    $login_error = 'Invalid username or password';
                }
            } else {
                $login_error = 'Invalid username or password';
            }
            
            $stmt->close();
            closeDbConnection($conn);
        } catch (Exception $e) {
            $login_error = 'Login failed. Please try again.';
            error_log($e->getMessage());
        }
    }
}

// Check if user is logged in
$is_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['isadmin']) && $_SESSION['isadmin'];

// Handle screenshot request
if ($is_logged_in && isset($_GET['screenshot']) && is_numeric($_GET['screenshot'])) {
    try {
        $conn = getDbConnection();
        $kiosk_id = intval($_GET['screenshot']);
        $stmt = $conn->prepare("UPDATE kiosks SET screenshot_requested = 1 WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $stmt->close();
        closeDbConnection($conn);
        header('Location: admin.php?msg=screenshot_requested');
        exit();
    } catch (Exception $e) {
        $error = 'Failed to request screenshot';
    }
}

// Handle ping interval toggle
if ($is_logged_in && isset($_GET['toggle_ping']) && is_numeric($_GET['toggle_ping'])) {
    try {
        $conn = getDbConnection();
        $kiosk_id = intval($_GET['toggle_ping']);
        
        // Get current interval
        $stmt = $conn->prepare("SELECT sync_interval FROM kiosks WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $kiosk = $result->fetch_assoc();
        
        // Toggle between 20s and 300s (5 min)
        $new_interval = ($kiosk['sync_interval'] == 20) ? 300 : 20;
        
        $stmt = $conn->prepare("UPDATE kiosks SET sync_interval = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_interval, $kiosk_id);
        $stmt->execute();
        $stmt->close();
        closeDbConnection($conn);
        header('Location: admin.php?msg=interval_updated');
        exit();
    } catch (Exception $e) {
        $error = 'Failed to update ping interval';
    }
}

// Get kiosks data if logged in
$kiosks = [];
$companies = [];
if ($is_logged_in) {
    try {
        $conn = getDbConnection();
        
        // Get companies
        $result = $conn->query("SELECT * FROM companies ORDER BY name");
        while ($row = $result->fetch_assoc()) {
            $companies[] = $row;
        }
        
        // Get kiosks with company info
        $query = "SELECT k.*, c.name as company_name 
                  FROM kiosks k 
                  LEFT JOIN companies c ON k.company_id = c.id 
                  ORDER BY k.last_seen DESC";
        $result = $conn->query($query);
        
        while ($row = $result->fetch_assoc()) {
            $kiosks[] = $row;
        }
        
        closeDbConnection($conn);
    } catch (Exception $e) {
        $error = 'Failed to load kiosks data';
        error_log($e->getMessage());
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Panel - EduDisplej Control</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        
        .login-container {
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            padding: 20px;
        }
        
        .login-box {
            background: white;
            padding: 40px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0,0,0,0.2);
            max-width: 400px;
            width: 100%;
        }
        
        .login-box h1 {
            color: #333;
            margin-bottom: 30px;
            text-align: center;
        }
        
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .navbar h1 {
            font-size: 24px;
        }
        
        .navbar .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .navbar a {
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            background: rgba(255,255,255,0.2);
            transition: background 0.3s;
        }
        
        .navbar a:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            color: #555;
            font-weight: 500;
        }
        
        input[type="text"],
        input[type="password"] {
            width: 100%;
            padding: 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 14px;
        }
        
        input:focus {
            outline: none;
            border-color: #667eea;
        }
        
        button, .btn {
            padding: 12px 24px;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border: none;
            border-radius: 5px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: opacity 0.3s;
        }
        
        button:hover, .btn:hover {
            opacity: 0.9;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        .btn-success {
            background: linear-gradient(135deg, #11998e 0%, #38ef7d 100%);
        }
        
        .btn-warning {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        
        .error, .success {
            padding: 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .error {
            background: #fee;
            color: #c33;
            border: 1px solid #fcc;
        }
        
        .success {
            background: #efe;
            color: #3c3;
            border: 1px solid #cfc;
        }
        
        table {
            width: 100%;
            background: white;
            border-radius: 10px;
            overflow: hidden;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            font-weight: 600;
        }
        
        tr:last-child td {
            border-bottom: none;
        }
        
        tr:hover {
            background: #f9f9f9;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-online {
            background: #d4edda;
            color: #155724;
        }
        
        .status-offline {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            color: #666;
            font-size: 14px;
            margin-bottom: 10px;
        }
        
        .stat-card .number {
            font-size: 36px;
            font-weight: bold;
            color: #667eea;
        }
        
        .register-link {
            text-align: center;
            margin-top: 20px;
            color: #666;
        }
        
        .register-link a {
            color: #667eea;
            text-decoration: none;
            font-weight: 600;
        }
    </style>
</head>
<body>
    <?php if (!$is_logged_in): ?>
        <div class="login-container">
            <div class="login-box">
                <h1>EduDisplej Admin Login</h1>
                
                <?php if ($login_error): ?>
                    <div class="error"><?php echo htmlspecialchars($login_error); ?></div>
                <?php endif; ?>
                
                <form method="POST" action="">
                    <div class="form-group">
                        <label for="username">Username</label>
                        <input type="text" id="username" name="username" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="password">Password</label>
                        <input type="password" id="password" name="password" required>
                    </div>
                    
                    <button type="submit" name="login">Login</button>
                </form>
                
                <div class="register-link">
                    Don't have an account? <a href="userregistration.php">Register here</a>
                </div>
            </div>
        </div>
    <?php else: ?>
        <div class="navbar">
            <h1>🖥️ EduDisplej Control Panel</h1>
            <div class="user-info">
                <a href="users.php">👥 Users</a>
                <a href="companies.php">🏢 Companies</a>
                <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="?logout=1">Logout</a>
            </div>
        </div>
        
        <div class="container">
            <?php if (isset($_GET['msg'])): ?>
                <div class="success">
                    <?php 
                    if ($_GET['msg'] == 'screenshot_requested') {
                        echo 'Screenshot requested successfully!';
                    } elseif ($_GET['msg'] == 'interval_updated') {
                        echo 'Ping interval updated successfully!';
                    }
                    ?>
                </div>
            <?php endif; ?>
            
            <?php if ($error): ?>
                <div class="error"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>
            
            <div class="stats">
                <div class="stat-card">
                    <h3>Total Kiosks</h3>
                    <div class="number"><?php echo count($kiosks); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Online</h3>
                    <div class="number"><?php echo count(array_filter($kiosks, fn($k) => $k['status'] == 'online')); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Offline</h3>
                    <div class="number"><?php echo count(array_filter($kiosks, fn($k) => $k['status'] == 'offline')); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Companies</h3>
                    <div class="number"><?php echo count($companies); ?></div>
                </div>
            </div>
            
            <h2 style="margin-bottom: 20px;">Kiosk Management</h2>
            
            <?php if (!empty($companies)): ?>
                <div style="margin-bottom: 30px;">
                    <h3 style="margin-bottom: 15px; color: #667eea;">Kiosks by Company</h3>
                    <?php foreach ($companies as $company): ?>
                        <?php 
                        $company_kiosks = array_filter($kiosks, fn($k) => $k['company_id'] == $company['id']);
                        if (!empty($company_kiosks)):
                        ?>
                        <div style="background: white; padding: 20px; margin-bottom: 15px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                            <h4 style="color: #667eea; margin-bottom: 10px;">
                                🏢 <?php echo htmlspecialchars($company['name']); ?>
                                <span style="color: #999; font-size: 14px; font-weight: normal;">
                                    (<?php echo count($company_kiosks); ?> kiosk<?php echo count($company_kiosks) != 1 ? 's' : ''; ?>)
                                </span>
                            </h4>
                            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; margin-top: 15px;">
                                <?php foreach ($company_kiosks as $kiosk): ?>
                                    <div style="border: 1px solid #eee; padding: 15px; border-radius: 5px; background: #f9f9f9;">
                                        <div style="font-weight: 600; margin-bottom: 5px;">
                                            <?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?>
                                        </div>
                                        <div style="font-size: 12px; color: #666; margin-bottom: 8px;">
                                            📍 <?php echo htmlspecialchars($kiosk['location'] ?? 'No location'); ?>
                                        </div>
                                        <span class="status-badge status-<?php echo $kiosk['status']; ?>">
                                            <?php echo ucfirst($kiosk['status']); ?>
                                        </span>
                                        <div style="margin-top: 10px;">
                                            <a href="kiosk_details.php?id=<?php echo $kiosk['id']; ?>" style="font-size: 11px; color: #667eea;">View details →</a>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                    
                    <?php 
                    $unassigned_kiosks = array_filter($kiosks, fn($k) => empty($k['company_id']));
                    if (!empty($unassigned_kiosks)):
                    ?>
                    <div style="background: white; padding: 20px; margin-bottom: 15px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1);">
                        <h4 style="color: #999; margin-bottom: 10px;">
                            ⚠️ Unassigned Kiosks
                            <span style="color: #999; font-size: 14px; font-weight: normal;">
                                (<?php echo count($unassigned_kiosks); ?> kiosk<?php echo count($unassigned_kiosks) != 1 ? 's' : ''; ?>)
                            </span>
                        </h4>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px; margin-top: 15px;">
                            <?php foreach ($unassigned_kiosks as $kiosk): ?>
                                <div style="border: 1px solid #eee; padding: 15px; border-radius: 5px; background: #f9f9f9;">
                                    <div style="font-weight: 600; margin-bottom: 5px;">
                                        <?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?>
                                    </div>
                                    <div style="font-size: 12px; color: #666; margin-bottom: 8px;">
                                        📍 <?php echo htmlspecialchars($kiosk['location'] ?? 'No location'); ?>
                                    </div>
                                    <span class="status-badge status-<?php echo $kiosk['status']; ?>">
                                        <?php echo ucfirst($kiosk['status']); ?>
                                    </span>
                                    <div style="margin-top: 10px;">
                                        <a href="kiosk_details.php?id=<?php echo $kiosk['id']; ?>" style="font-size: 11px; color: #667eea;">View details →</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
            
            <h2 style="margin-bottom: 20px;">All Kiosks - Detailed View</h2>
            
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Hostname</th>
                        <th>MAC Address</th>
                        <th>Company</th>
                        <th>Status</th>
                        <th>Last Seen</th>
                        <th>Sync Interval</th>
                        <th>Location</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($kiosks)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; color: #999;">No kiosks registered yet</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($kiosks as $kiosk): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($kiosk['id']); ?></td>
                                <td><?php echo htmlspecialchars($kiosk['hostname'] ?? 'N/A'); ?></td>
                                <td><code><?php echo htmlspecialchars(substr($kiosk['mac'], 0, 17)); ?></code></td>
                                <td><?php echo htmlspecialchars($kiosk['company_name'] ?? 'Unassigned'); ?></td>
                                <td>
                                    <span class="status-badge status-<?php echo $kiosk['status']; ?>">
                                        <?php echo ucfirst($kiosk['status']); ?>
                                    </span>
                                </td>
                                <td><?php echo $kiosk['last_seen'] ? date('Y-m-d H:i', strtotime($kiosk['last_seen'])) : 'Never'; ?></td>
                                <td><?php echo $kiosk['sync_interval']; ?>s</td>
                                <td><?php echo htmlspecialchars($kiosk['location'] ?? '-'); ?></td>
                                <td>
                                    <a href="kiosk_details.php?id=<?php echo $kiosk['id']; ?>" class="btn btn-sm">👁️ View</a>
                                    <a href="?screenshot=<?php echo $kiosk['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Request screenshot?')">📸 Screenshot</a>
                                    <a href="?toggle_ping=<?php echo $kiosk['id']; ?>" class="btn btn-sm btn-warning">
                                        <?php echo ($kiosk['sync_interval'] == 20) ? '🐌 Slow' : '⚡ Fast'; ?> Ping
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</body>
</html>
