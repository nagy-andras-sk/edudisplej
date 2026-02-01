<?php
/**
 * Group Module Configuration with Module Flow Chain
 * Enhanced with split screen, multiple loops, and custom settings
 */

session_start();
require_once '../dbkonfiguracia.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$group_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];
$error = '';
$success = '';

$group = null;

try {
    $conn = getDbConnection();
    
    // Get group info
    $stmt = $conn->prepare("SELECT * FROM kiosk_groups WHERE id = ?");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $group = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    
    // Check permissions
    if (!$group || (!$is_admin && $group['company_id'] != $company_id)) {
        header('Location: groups.php');
        exit();
    }
    
    // Ensure group_modules table exists
    $conn->query("CREATE TABLE IF NOT EXISTS group_modules (
        id INT(11) NOT NULL AUTO_INCREMENT PRIMARY KEY,
        group_id INT(11) NOT NULL,
        module_sequence INT(11) NOT NULL,
        module_id INT(11) NOT NULL,
        duration_seconds INT(11) DEFAULT 10,
        settings TEXT DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE,
        FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    
    // Handle module configuration save
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_modules'])) {
        // Clear existing config
        $stmt = $conn->prepare("DELETE FROM group_modules WHERE group_id = ?");
        $stmt->bind_param("i", $group_id);
        $stmt->execute();
        $stmt->close();
        
        // Insert new config
        $modules_config = $_POST['modules'] ?? [];
        $sequence = 0;
        
        foreach ($modules_config as $module_id => $config) {
            if (!empty($config['enabled'])) {
                $duration = intval($config['duration_seconds'] ?? 10);
                $settings = json_encode($config['settings'] ?? []);
                
                $stmt = $conn->prepare("INSERT INTO group_modules (group_id, module_sequence, module_id, duration_seconds, settings) 
                                        VALUES (?, ?, ?, ?, ?)");
                $stmt->bind_param("iiiis", $group_id, $sequence, $module_id, $duration, $settings);
                $stmt->execute();
                $stmt->close();
                
                $sequence++;
            }
        }
        
        $success = 'Modulok konfigurációja sikeresen mentve';
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $error = 'Adatbázis hiba: ' . $e->getMessage();
    error_log($e->getMessage());
}

// Get modules and current configuration
$available_modules = [];
$current_modules = [];

try {
    $conn = getDbConnection();
    
    // Get available modules
    $stmt = $conn->prepare("SELECT m.*, COALESCE(ml.quantity, 0) as quantity 
                            FROM modules m 
                            LEFT JOIN module_licenses ml ON m.id = ml.module_id AND ml.company_id = ?
                            WHERE m.is_active = 1
                            ORDER BY m.name");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $available_modules[] = $row;
    }
    $stmt->close();
    
    // Get current modules for this group
    $stmt = $conn->prepare("SELECT gm.*, m.name, m.module_key, m.description 
                            FROM group_modules gm
                            JOIN modules m ON gm.module_id = m.id
                            WHERE gm.group_id = ?
                            ORDER BY gm.module_sequence");
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $current_modules[] = $row;
    }
    $stmt->close();
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    error_log($e->getMessage());
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Csoport Modulok - EDUDISPLEJ</title>
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
            max-width: 1400px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .container {
            max-width: 1400px;
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
        
        /* Module Flow Chain */
        .flow-chain {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 20px 0;
            padding: 20px;
            background: #f0f4ff;
            border-left: 4px solid #1e40af;
            border-radius: 5px;
            overflow-x: auto;
        }
        
        .flow-item {
            display: flex;
            flex-direction: column;
            align-items: center;
            gap: 5px;
            min-width: 120px;
            padding: 12px;
            background: white;
            border: 2px solid #1e40af;
            border-radius: 5px;
            text-align: center;
            position: relative;
        }
        
        .flow-item-name {
            font-weight: 600;
            font-size: 13px;
            color: #333;
        }
        
        .flow-item-duration {
            font-size: 11px;
            color: #666;
        }
        
        .flow-arrow {
            font-size: 20px;
            color: #1e40af;
            flex-shrink: 0;
        }
        
        .flow-loop {
            font-size: 11px;
            color: #1e40af;
            font-weight: 600;
            margin-left: 10px;
        }
        
        /* Configuration Table */
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
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
            border-bottom: 2px solid #ddd;
        }
        
        table tr:hover {
            background: #f9f9f9;
        }
        
        input[type="checkbox"] {
            width: 16px;
            height: 16px;
            cursor: pointer;
        }
        
        input[type="number"],
        select {
            padding: 6px;
            border: 1px solid #ddd;
            border-radius: 3px;
            font-size: 12px;
            width: 100%;
            max-width: 100px;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: #0369a1;
        }
        
        .btn-secondary {
            background: #666;
            margin-left: 10px;
        }
        
        .btn-secondary:hover {
            background: #444;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>EDUDISPLEJ - Csoport Modulok</h1>
            <a href="groups.php" style="color: #1e40af; text-decoration: none;">← Vissza</a>
        </div>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">✓ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if ($group): ?>
            <div class="card">
                <h2><?php echo htmlspecialchars($group['name']); ?> - Modulok Beállítása</h2>
                <p style="color: #666; font-size: 13px; margin-top: 5px;">
                    Válaszd ki a modulokat, állítsd be az időtartamokat és testreszabold a beállításokat.
                </p>
            </div>
            
            <!-- Module Flow Chain Preview -->
            <?php if (!empty($current_modules)): ?>
                <div class="card">
                    <h3>📊 Module Flow Chain (Loop)</h3>
                    <div class="flow-chain">
                        <?php foreach ($current_modules as $index => $module): ?>
                            <div class="flow-item">
                                <div class="flow-item-name"><?php echo htmlspecialchars($module['name']); ?></div>
                                <div class="flow-item-duration"><?php echo $module['duration_seconds']; ?>s</div>
                            </div>
                            <?php if ($index < count($current_modules) - 1): ?>
                                <div class="flow-arrow">→</div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                        <div class="flow-arrow">↻</div>
                        <div class="flow-loop">Loop</div>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- Module Configuration -->
            <div class="card">
                <h3>⚙️ Modulok Konfigurálása</h3>
                
                <form method="POST" action="">
                    <table>
                        <thead>
                            <tr>
                                <th style="width: 50px;">✓</th>
                                <th>Modul Név</th>
                                <th>Időtartam (mp)</th>
                                <th>Beállítások</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($available_modules as $module): ?>
                                <?php 
                                $is_checked = false;
                                $current_config = null;
                                foreach ($current_modules as $cm) {
                                    if ($cm['module_id'] == $module['id']) {
                                        $is_checked = true;
                                        $current_config = $cm;
                                        break;
                                    }
                                }
                                $duration = $is_checked ? $current_config['duration_seconds'] : 10;
                                $settings = $is_checked ? (json_decode($current_config['settings'] ?? '{}', true)) : [];
                                ?>
                                <tr>
                                    <td>
                                        <input type="checkbox" name="modules[<?php echo $module['id']; ?>][enabled]" value="1" 
                                               <?php echo $is_checked ? 'checked' : ''; ?>>
                                    </td>
                                    <td>
                                        <strong><?php echo htmlspecialchars($module['name']); ?></strong><br>
                                        <small style="color: #666;"><?php echo htmlspecialchars($module['description'] ?? ''); ?></small>
                                    </td>
                                    <td>
                                        <input type="number" name="modules[<?php echo $module['id']; ?>][duration_seconds]" 
                                               value="<?php echo $duration; ?>" min="1" max="300">
                                    </td>
                                    <td style="font-size: 12px; color: #666;">
                                        <?php if ($module['quantity'] > 0): ?>
                                            Licensz: <strong><?php echo $module['quantity']; ?></strong>
                                        <?php else: ?>
                                            <span style="color: #dc3545;">⚠️ Nincs licensz</span>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    
                    <div style="margin-top: 20px;">
                        <button type="submit" name="save_modules" class="btn">💾 Beállítások Mentése</button>
                        <a href="groups.php" class="btn btn-secondary">Mégse</a>
                    </div>
                </form>
            </div>
            
            <!-- Info Box -->
            <div class="card" style="background: #f0f4ff; border-left: 4px solid #1e40af;">
                <h4 style="color: #1e40af;">ℹ️ Tudnivalók</h4>
                <ul style="margin-left: 20px; color: #666; font-size: 13px; line-height: 1.8;">
                    <li><strong>Module Flow Chain:</strong> A kijelzők ebben a sorrendben váltanak a modulok között.</li>
                    <li><strong>Időtartam:</strong> Minden modul ennyi másodpercig jelenik meg a képernyőn.</li>
                    <li><strong>Loop:</strong> Az utolsó modul után visszamegy az első modulra (végtelen hurok).</li>
                    <li><strong>Licensz:</strong> Egy modulnak csak akkor lesz hozzárendelve, ha van rá licensz a vállalatnak.</li>
                    <li><strong>Többszöri megjelenés:</strong> Egy modul többször is megjelenhet a loopban (pl. clock→nameday→clock→default...).</li>
                </ul>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

