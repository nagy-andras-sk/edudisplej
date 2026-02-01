<?php
/**
 * Group Management - Simplified Design
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
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
    
    // Ensure kiosk_groups table exists
    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_groups (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(255) NOT NULL,
        company_id INT(11) DEFAULT NULL,
        description TEXT DEFAULT NULL,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Ensure kiosk_group_assignments table exists
    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_assignments (
        kiosk_id INT(11) NOT NULL,
        group_id INT(11) NOT NULL,
        PRIMARY KEY (kiosk_id, group_id),
        FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE,
        FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
} catch (Exception $e) {
    $error = 'Database error';
    error_log($e->getMessage());
}

// Handle group creation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['create_group'])) {
    $name = trim($_POST['group_name'] ?? '');
    $description = trim($_POST['description'] ?? '');
    
    if (empty($name)) {
        $error = 'A csoport neve kötelező';
    } else {
        try {
            $stmt = $conn->prepare("INSERT INTO kiosk_groups (name, company_id, description) VALUES (?, ?, ?)");
            $stmt->bind_param("sis", $name, $company_id, $description);
            
            if ($stmt->execute()) {
                $success = 'Csoport sikeresen létrehozva';
            } else {
                $error = 'A csoport létrehozása sikertelen';
            }
            
            $stmt->close();
        } catch (Exception $e) {
            $error = 'Adatbázis hiba';
            error_log($e->getMessage());
        }
    }
}

// Handle group deletion
if (isset($_GET['delete']) && is_numeric($_GET['delete'])) {
    $group_id = intval($_GET['delete']);
    
    try {
        $stmt = $conn->prepare("SELECT company_id FROM kiosk_groups WHERE id = ?");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $group = $result->fetch_assoc();
        
        if ($group && ($is_admin || $group['company_id'] == $company_id)) {
            $stmt = $conn->prepare("DELETE FROM kiosk_groups WHERE id = ?");
            $stmt->bind_param("i", $group_id);
            
            if ($stmt->execute()) {
                $success = 'Csoport sikeresen törölve';
            } else {
                $error = 'A csoport törlése sikertelen';
            }
        } else {
            $error = 'Hozzáférés megtagadva';
        }
        
        $stmt->close();
    } catch (Exception $e) {
        $error = 'Adatbázis hiba';
        error_log($e->getMessage());
    }
}

// Get groups for this company
$groups = [];
try {
    $query = "SELECT g.*,
              (SELECT COUNT(*) FROM kiosk_group_assignments WHERE group_id = g.id) as kiosk_count
              FROM kiosk_groups g 
              WHERE g.company_id = ? 
              ORDER BY g.name";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
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
    <title>Csoportok - EDUDISPLEJ</title>
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
        
        .header {
            background: #1a1a1a;
            color: white;
            padding: 20px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        .card {
            background: white;
            padding: 20px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
            margin-bottom: 20px;
        }
        
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 15px;
        }
        
        label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
        }
        
        input, textarea {
            width: 100%;
            padding: 8px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 13px;
        }
        
        input:focus, textarea:focus {
            outline: none;
            border-color: #1e40af;
            box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
        }
        
        .btn {
            display: inline-block;
            padding: 8px 16px;
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-weight: 600;
            font-size: 13px;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: #0369a1;
        }
        
        .btn-danger {
            background: #dc3545;
        }
        
        .btn-danger:hover {
            background: #c82333;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table thead {
            background: #f8f9fa;
        }
        
        table th, table td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        table th {
            font-weight: 600;
            font-size: 13px;
            border-bottom: 2px solid #ddd;
        }
        
        table tr:hover {
            background: #f9f9f9;
        }
        
        .actions {
            display: flex;
            gap: 10px;
        }
        
        .actions a, .actions button {
            padding: 4px 8px;
            font-size: 11px;
            text-decoration: none;
            color: white;
            border-radius: 2px;
            cursor: pointer;
            border: none;
        }
        
        .actions a.edit {
            background: #17a2b8;
        }
        
        .actions a.delete {
            background: #dc3545;
        }
        
        .actions a:hover, .actions button:hover {
            opacity: 0.9;
        }
        
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>EDUDISPLEJ - Csoportok</h1>
            <a href="index.php" style="color: #1e40af; text-decoration: none;">← Vissza</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">✓ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Create Group Form -->
        <div class="card">
            <h2 style="margin-bottom: 15px;">Új Csoport Létrehozása</h2>
            <form method="POST" action="">
                <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 15px; margin-bottom: 15px;">
                    <div class="form-group">
                        <label for="group_name">Csoport neve *</label>
                        <input type="text" id="group_name" name="group_name" required>
                    </div>
                    <div class="form-group">
                        <label for="description">Leírás</label>
                        <input type="text" id="description" name="description" placeholder="pl. Emelet 1, Épület A">
                    </div>
                </div>
                <button type="submit" name="create_group" class="btn">+ Csoport Létrehozása</button>
            </form>
        </div>
        
        <!-- Groups Table -->
        <div class="card">
            <h2 style="margin-bottom: 15px;">Csoportok (<?php echo count($groups); ?>)</h2>
            
            <?php if (empty($groups)): ?>
                <div class="no-data">
                    <p>Nincsenek csoportok. Hozz létre egy új csoportot az fenti formban.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Csoport Neve</th>
                            <th>Leírás</th>
                            <th>Kijelzők</th>
                            <th>Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $group): ?>
                            <tr>
                                <td>
                                    <strong><?php echo htmlspecialchars($group['name']); ?></strong>
                                </td>
                                <td style="color: #666; font-size: 13px;">
                                    <?php echo htmlspecialchars($group['description'] ?? '—'); ?>
                                </td>
                                <td>
                                    <span style="background: #e7f3ff; color: #0066cc; padding: 4px 8px; border-radius: 3px; font-size: 12px;">
                                        <?php echo $group['kiosk_count']; ?> kijelző
                                    </span>
                                </td>
                                <td>
                                    <div class="actions">
                                        <a href="group_kiosks.php?id=<?php echo $group['id']; ?>" class="edit">🖥️ Kijelzők</a>
                                        <a href="group_modules.php?id=<?php echo $group['id']; ?>" class="edit">🔌 Modulok</a>
                                        <a href="?delete=<?php echo $group['id']; ?>" class="delete" onclick="return confirm('Biztosan törölted ezt a csoportot?');">🗑️ Törlés</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

