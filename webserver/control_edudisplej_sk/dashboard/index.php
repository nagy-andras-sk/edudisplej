<?php
/**
 * Company Dashboard
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';

$error = '';
$success = '';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../admin/index.php');
    exit();
}

// Get user info
$user_id = $_SESSION['user_id'];
$company_id = null;
$company_name = '';
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];

try {
    $conn = getDbConnection();
    
    // Get user and company info
    $stmt = $conn->prepare("SELECT u.*, c.name as company_name FROM users u LEFT JOIN companies c ON u.company_id = c.id WHERE u.id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        header('Location: ../admin/index.php');
        exit();
    }
    
    $company_id = $user['company_id'];
    $company_name = $user['company_name'] ?? 'No Company';
    
    // Non-admin users must have a company assigned
    if (!$is_admin && !$company_id) {
        $error = 'You are not assigned to any company. Please contact an administrator.';
    }
    
} catch (Exception $e) {
    $error = 'Database error';
    error_log($e->getMessage());
}

// Get company kiosks
$kiosks = [];
if ($company_id) {
    try {
        $query = "SELECT k.* FROM kiosks k WHERE k.company_id = ? ORDER BY k.hostname";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $kiosks[] = $row;
        }
        
        $stmt->close();
    } catch (Exception $e) {
        error_log($e->getMessage());
    }
}

// Get available modules for this company
$available_modules = [];
if ($company_id) {
    try {
        $query = "SELECT m.*, ml.quantity 
                  FROM modules m 
                  LEFT JOIN module_licenses ml ON m.id = ml.module_id AND ml.company_id = ?
                  WHERE m.is_active = 1
                  ORDER BY m.name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $available_modules[] = $row;
        }
        
        $stmt->close();
    } catch (Exception $e) {
        error_log($e->getMessage());
    }
}

if (isset($conn)) {
    closeDbConnection($conn);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Company Dashboard - EduDisplej</title>
    <link rel="stylesheet" href="../admin/style.css">
    <style>
        .module-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .module-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .module-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-available {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-unavailable {
            background: #f8d7da;
            color: #721c24;
        }
        
        .kiosk-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(250px, 1fr));
            gap: 15px;
        }
        
        .kiosk-card {
            border: 1px solid #eee;
            padding: 15px;
            border-radius: 5px;
            background: #f9f9f9;
        }
        
        .configure-btn {
            padding: 8px 15px;
            background: #667eea;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 12px;
            text-decoration: none;
            display: inline-block;
        }
        
        .configure-btn:hover {
            background: #5568d3;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🏢 Company Dashboard</h1>
        <div class="user-info">
            <?php if ($is_admin): ?>
                <a href="../admin/index.php">Admin Panel</a>
            <?php endif; ?>
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?> (<?php echo htmlspecialchars($company_name); ?>)</span>
            <a href="../admin/index.php?logout=1">Logout</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if ($company_id): ?>
            <div class="stats">
                <div class="stat-card">
                    <h3>Total Kiosks</h3>
                    <div class="number"><?php echo count($kiosks); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Online Kiosks</h3>
                    <div class="number"><?php echo count(array_filter($kiosks, fn($k) => $k['status'] == 'online')); ?></div>
                </div>
                <div class="stat-card">
                    <h3>Available Modules</h3>
                    <div class="number"><?php echo count(array_filter($available_modules, fn($m) => $m['quantity'] > 0)); ?></div>
                </div>
            </div>
            
            <h2>Your Kiosks</h2>
            
            <?php if (empty($kiosks)): ?>
                <div style="text-align: center; padding: 40px; color: #999; background: white; border-radius: 10px;">
                    No kiosks assigned to your company yet.
                </div>
            <?php else: ?>
                <div class="kiosk-grid">
                    <?php foreach ($kiosks as $kiosk): ?>
                        <div class="kiosk-card">
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
                                <a href="kiosk_modules.php?id=<?php echo $kiosk['id']; ?>" class="configure-btn">⚙️ Configure Modules</a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            
            <h2 style="margin-top: 40px;">Available Modules</h2>
            
            <?php if (empty($available_modules)): ?>
                <div style="text-align: center; padding: 40px; color: #999; background: white; border-radius: 10px;">
                    No modules available.
                </div>
            <?php else: ?>
                <?php foreach ($available_modules as $module): 
                    $has_license = $module['quantity'] > 0;
                ?>
                    <div class="module-card">
                        <div class="module-header">
                            <div>
                                <h3 style="margin: 0;"><?php echo htmlspecialchars($module['name']); ?></h3>
                                <small style="color: #999;"><?php echo htmlspecialchars($module['module_key']); ?></small>
                            </div>
                            <span class="module-badge <?php echo $has_license ? 'badge-available' : 'badge-unavailable'; ?>">
                                <?php echo $has_license ? "Licensed ({$module['quantity']} units)" : 'Not Licensed'; ?>
                            </span>
                        </div>
                        <p style="color: #666; margin: 0;">
                            <?php echo htmlspecialchars($module['description'] ?? 'No description'); ?>
                        </p>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</body>
</html>
