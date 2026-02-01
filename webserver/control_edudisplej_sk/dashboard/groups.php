<?php
/**
 * Kiosk Group Management
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../admin/index.php');
    exit();
}

$error = '';
$success = '';
$user_id = $_SESSION['user_id'];
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];

// Get user's company
$company_id = null;
try {
    $conn = getDbConnection();
    $stmt = $conn->prepare("SELECT company_id FROM users WHERE id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $company_id = $user['company_id'];
    $stmt->close();
} catch (Exception $e) {
    $error = 'Database error';
    error_log($e->getMessage());
}

// Handle group creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $name = trim($_POST['group_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($name)) {
        $error = 'Group name is required';
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO kiosk_groups (name, company_id, description) VALUES (?, ?, ?)");
            $stmt->bind_param("sis", $name, $company_id, $description);
            
            if ($stmt->execute()) {
                $success = 'Group created successfully';
            } else {
                $error = 'Failed to create group';
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $error = 'Database error occurred';
            error_log($e->getMessage());
        }
    }
}

// Handle group deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $group_id = intval($_GET['delete']);
    
    try {
        // Check ownership
        $stmt = $conn->prepare("SELECT company_id FROM kiosk_groups WHERE id = ?");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $group = $result->fetch_assoc();
        
        if ($group && ($is_admin || $group['company_id'] == $company_id)) {
            $stmt = $conn->prepare("DELETE FROM kiosk_groups WHERE id = ?");
            $stmt->bind_param("i", $group_id);
            
            if ($stmt->execute()) {
                $success = 'Group deleted successfully';
            } else {
                $error = 'Failed to delete group';
            }
        } else {
            $error = 'Permission denied';
        }
        
        $stmt->close();
    } catch (Exception $e) {
        $error = 'Database error occurred';
        error_log($e->getMessage());
    }
}

// Get groups
$groups = [];
try {
    if ($is_admin) {
        $query = "SELECT g.*, c.name as company_name,
                  (SELECT COUNT(*) FROM kiosk_group_assignments WHERE group_id = g.id) as kiosk_count
                  FROM kiosk_groups g
                  LEFT JOIN companies c ON g.company_id = c.id
                  ORDER BY c.name, g.name";
        $result = $conn->query($query);
    } else {
        $query = "SELECT g.*, c.name as company_name,
                  (SELECT COUNT(*) FROM kiosk_group_assignments WHERE group_id = g.id) as kiosk_count
                  FROM kiosk_groups g
                  LEFT JOIN companies c ON g.company_id = c.id
                  WHERE g.company_id = ?
                  ORDER BY g.name";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }
    
    if (isset($stmt)) $stmt->close();
} catch (Exception $e) {
    error_log($e->getMessage());
}

// Get available kiosks for assignment
$kiosks = [];
try {
    if ($is_admin) {
        $query = "SELECT k.*, c.name as company_name FROM kiosks k 
                  LEFT JOIN companies c ON k.company_id = c.id 
                  ORDER BY c.name, k.hostname";
        $result = $conn->query($query);
    } else {
        $query = "SELECT k.* FROM kiosks k WHERE k.company_id = ? ORDER BY k.hostname";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
    }
    
    while ($row = $result->fetch_assoc()) {
        $kiosks[] = $row;
    }
    
    if (isset($stmt)) $stmt->close();
} catch (Exception $e) {
    error_log($e->getMessage());
}

closeDbConnection($conn);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiosk Groups - EduDisplej</title>
    <link rel="stylesheet" href="../admin/style.css">
    <style>
        .group-card {
            background: white;
            padding: 20px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .group-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .group-actions {
            display: flex;
            gap: 10px;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>📁 Kiosk Groups</h1>
        <a href="index.php">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <h2>Create New Group</h2>
            <form method="POST">
                <div class="form-group">
                    <label for="group_name">Group Name:</label>
                    <input type="text" id="group_name" name="group_name" required>
                </div>
                
                <div class="form-group">
                    <label for="description">Description:</label>
                    <input type="text" id="description" name="description">
                </div>
                
                <button type="submit" name="create_group">Create Group</button>
            </form>
        </div>
        
        <h2>Your Groups</h2>
        
        <?php if (empty($groups)): ?>
            <div style="text-align: center; padding: 40px; color: #999; background: white; border-radius: 10px;">
                No groups created yet.
            </div>
        <?php else: ?>
            <?php foreach ($groups as $group): ?>
                <div class="group-card">
                    <div class="group-header">
                        <div>
                            <h3 style="margin: 0 0 5px 0;"><?php echo htmlspecialchars($group['name']); ?></h3>
                            <small style="color: #999;">
                                <?php echo htmlspecialchars($group['description'] ?? 'No description'); ?>
                                <?php if ($is_admin): ?>
                                    | <?php echo htmlspecialchars($group['company_name'] ?? 'No company'); ?>
                                <?php endif; ?>
                            </small>
                            <div style="margin-top: 10px;">
                                <span style="color: #667eea; font-weight: 600;"><?php echo $group['kiosk_count']; ?> kiosk(s)</span>
                            </div>
                        </div>
                        <div class="group-actions">
                            <a href="group_kiosks.php?id=<?php echo $group['id']; ?>" class="btn btn-sm">👥 Manage Kiosks</a>
                            <a href="group_modules.php?id=<?php echo $group['id']; ?>" class="btn btn-sm btn-success">⚙️ Configure Modules</a>
                            <a href="?delete=<?php echo $group['id']; ?>" class="btn btn-sm btn-warning" onclick="return confirm('Delete this group?')">🗑️ Delete</a>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        <?php endif; ?>
    </div>
</body>
</html>
