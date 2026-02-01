<?php
/**
 * Group Kiosks Management
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';

$error = '';
$success = '';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../admin/index.php');
    exit();
}

$group_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];

// Get user and group info
$group = null;
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
    
    $stmt = $conn->prepare("SELECT * FROM kiosk_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $group = $result->fetch_assoc();
    $stmt->close();
    
    if (!$group || (!$is_admin && $group['company_id'] != $company_id)) {
        header('Location: groups.php');
        exit();
    }
    
} catch (Exception $e) {
    $error = 'Database error';
    error_log($e->getMessage());
}

// Handle kiosk assignment
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['assign_kiosk'])) {
    $kiosk_id = intval($_POST['kiosk_id'] ?? 0);
    
    if ($kiosk_id > 0) {
        try {
            // Check if already assigned
            $stmt = $conn->prepare("SELECT * FROM kiosk_group_assignments WHERE kiosk_id = ? AND group_id = ?");
            $stmt->bind_param("ii", $kiosk_id, $group_id);
            $stmt->execute();
            $result = $stmt->get_result();
            
            if ($result->num_rows == 0) {
                $stmt = $conn->prepare("INSERT INTO kiosk_group_assignments (kiosk_id, group_id) VALUES (?, ?)");
                $stmt->bind_param("ii", $kiosk_id, $group_id);
                $stmt->execute();
                $success = 'Kiosk assigned to group';
            } else {
                $error = 'Kiosk already in this group';
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $error = 'Failed to assign kiosk';
            error_log($e->getMessage());
        }
    }
}

// Handle kiosk removal
if (isset($_GET['remove']) && is_numeric($_GET['remove'])) {
    $kiosk_id = intval($_GET['remove']);
    
    try {
        $stmt = $conn->prepare("DELETE FROM kiosk_group_assignments WHERE kiosk_id = ? AND group_id = ?");
        $stmt->bind_param("ii", $kiosk_id, $group_id);
        $stmt->execute();
        $stmt->close();
        $success = 'Kiosk removed from group';
    } catch (Exception $e) {
        $error = 'Failed to remove kiosk';
        error_log($e->getMessage());
    }
}

// Get kiosks in group
$group_kiosks = [];
try {
    $query = "SELECT k.* FROM kiosks k
              JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
              WHERE kga.group_id = ?
              ORDER BY k.hostname";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $group_kiosks[] = $row;
    }
    
    $stmt->close();
} catch (Exception $e) {
    error_log($e->getMessage());
}

// Get available kiosks (not in this group, but in same company)
$available_kiosks = [];
try {
    $query = "SELECT k.* FROM kiosks k
              WHERE k.company_id = ? 
              AND k.id NOT IN (SELECT kiosk_id FROM kiosk_group_assignments WHERE group_id = ?)
              ORDER BY k.hostname";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $company_id, $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $available_kiosks[] = $row;
    }
    
    $stmt->close();
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
    <title>Manage Group Kiosks - EduDisplej</title>
    <link rel="stylesheet" href="../admin/style.css">
</head>
<body>
    <div class="navbar">
        <h1>👥 Manage Group Kiosks</h1>
        <a href="groups.php">← Back to Groups</a>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
            <h2>Group: <?php echo htmlspecialchars($group['name']); ?></h2>
            <p style="color: #666; margin-top: 10px;">
                Manage which kiosks belong to this group. Kiosks in this group will inherit the group's module configuration.
            </p>
        </div>
        
        <?php if (!empty($available_kiosks)): ?>
            <div style="background: white; padding: 20px; border-radius: 10px; box-shadow: 0 2px 10px rgba(0,0,0,0.1); margin-bottom: 30px;">
                <h3>Add Kiosk to Group</h3>
                <form method="POST" style="display: flex; gap: 10px; align-items: end;">
                    <div class="form-group" style="flex: 1; margin: 0;">
                        <label for="kiosk_id">Select Kiosk:</label>
                        <select id="kiosk_id" name="kiosk_id" class="company-select" required>
                            <option value="">Choose a kiosk...</option>
                            <?php foreach ($available_kiosks as $kiosk): ?>
                                <option value="<?php echo $kiosk['id']; ?>">
                                    <?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?>
                                    (ID: <?php echo $kiosk['id']; ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <button type="submit" name="assign_kiosk" class="btn">Add to Group</button>
                </form>
            </div>
        <?php endif; ?>
        
        <h3>Kiosks in this Group (<?php echo count($group_kiosks); ?>)</h3>
        
        <?php if (empty($group_kiosks)): ?>
            <div style="text-align: center; padding: 40px; color: #999; background: white; border-radius: 10px;">
                No kiosks in this group yet.
            </div>
        <?php else: ?>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(250px, 1fr)); gap: 15px;">
                <?php foreach ($group_kiosks as $kiosk): ?>
                    <div style="border: 1px solid #eee; padding: 15px; border-radius: 5px; background: white;">
                        <div style="font-weight: 600; margin-bottom: 5px;">
                            <?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?>
                        </div>
                        <div style="font-size: 12px; color: #666; margin-bottom: 8px;">
                            ID: <?php echo $kiosk['id']; ?><br>
                            📍 <?php echo htmlspecialchars($kiosk['location'] ?? 'No location'); ?>
                        </div>
                        <span class="status-badge status-<?php echo $kiosk['status']; ?>">
                            <?php echo ucfirst($kiosk['status']); ?>
                        </span>
                        <div style="margin-top: 10px;">
                            <a href="?id=<?php echo $group_id; ?>&remove=<?php echo $kiosk['id']; ?>" 
                               style="font-size: 11px; color: #c33;" 
                               onclick="return confirm('Remove this kiosk from the group?')">
                                Remove from group
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
