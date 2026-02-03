<?php
/**
 * Group Kiosks Management - Simplified Design
 * Manage kiosks assigned to a group
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
    
    // Handle kiosk assignment
    if (isset($_GET['assign']) && is_numeric($_GET['assign'])) {
        $kiosk_id = intval($_GET['assign']);
        
        // First, remove from any other group
        $stmt = $conn->prepare("DELETE FROM kiosk_group_assignments WHERE kiosk_id = ?");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $stmt->close();
        
        // Then add to current group
        $stmt = $conn->prepare("INSERT IGNORE INTO kiosk_group_assignments (kiosk_id, group_id) VALUES (?, ?)");
        $stmt->bind_param("ii", $kiosk_id, $group_id);
        
        if ($stmt->execute()) {
            $success = 'Kijelző sikeresen hozzáadva a csoporthoz';
        } else {
            $error = 'A kijelző hozzáadása sikertelen';
        }
        $stmt->close();
    }
    
    // Handle kiosk removal
    if (isset($_GET['remove']) && is_numeric($_GET['remove'])) {
        $kiosk_id = intval($_GET['remove']);
        
        $stmt = $conn->prepare("DELETE FROM kiosk_group_assignments WHERE kiosk_id = ? AND group_id = ?");
        $stmt->bind_param("ii", $kiosk_id, $group_id);
        
        if ($stmt->execute()) {
            $success = 'Kijelző sikeresen eltávolítva a csoportból';
        } else {
            $error = 'Az eltávolítás sikertelen';
        }
        $stmt->close();
    }
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $error = 'Adatbázis hiba';
    error_log($e->getMessage());
}

// Get kiosks in group and available kiosks
$kiosks_in_group = [];
$available_kiosks = [];

try {
    $conn = getDbConnection();
    
    // Kiosks in group
    $query = "SELECT k.* FROM kiosks k
              INNER JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
              WHERE kga.group_id = ?
              ORDER BY k.hostname";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("i", $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $kiosks_in_group[] = $row;
    }
    $stmt->close();
    
    // Available kiosks (not in any group or in different groups)
    $query = "SELECT k.*, kg.id as group_in, kg.name as group_name FROM kiosks k
              LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
              LEFT JOIN kiosk_groups kg ON kga.group_id = kg.id
              WHERE k.company_id = ? 
              AND k.id NOT IN (
                  SELECT kiosk_id FROM kiosk_group_assignments WHERE group_id = ?
              )
              ORDER BY k.hostname";
    $stmt = $conn->prepare($query);
    $stmt->bind_param("ii", $company_id, $group_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $available_kiosks[] = $row;
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
    <title>Csoport Kijelzői - EDUDISPLEJ</title>
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
        
        .two-column {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        .column h3 {
            margin-bottom: 15px;
            color: #333;
        }
        
        .kiosk-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
        }
        
        .kiosk-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #f9f9f9;
            border: 1px solid #eee;
            border-radius: 3px;
        }
        
        .kiosk-item:hover {
            background: #f0f0f0;
        }
        
        .kiosk-item.in-other-group {
            background: #ffe6e6;
            border-color: #ffcccc;
        }
        
        .kiosk-item.in-other-group:hover {
            background: #ffcccc;
        }
        
        .group-badge {
            display: inline-block;
            background: #dc3545;
            color: white;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 11px;
            font-weight: 600;
            margin-top: 5px;
        }
        
        .kiosk-info {
            display: flex;
            flex-direction: column;
        }
        
        .kiosk-name {
            font-weight: 600;
            color: #333;
        }
        
        .kiosk-detail {
            font-size: 12px;
            color: #666;
            margin-top: 3px;
        }
        
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 3px;
            cursor: pointer;
            font-size: 12px;
            font-weight: 600;
            transition: all 0.3s;
        }
        
        .btn-add {
            background: #28a745;
            color: white;
        }
        
        .btn-add:hover {
            background: #218838;
        }
        
        .btn-remove {
            background: #dc3545;
            color: white;
        }
        
        .btn-remove:hover {
            background: #c82333;
        }
        
        .no-data {
            text-align: center;
            padding: 20px;
            color: #999;
            font-size: 13px;
        }
    </style>
</head>
<body>
    <div class="header">
        <div class="header-content">
            <h1>EDUDISPLEJ - Csoport Kijelzői</h1>
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
                <h2 style="margin-bottom: 10px;"><?php echo htmlspecialchars($group['name']); ?></h2>
                <p style="color: #666; font-size: 13px;">
                    <?php echo htmlspecialchars($group['description'] ?? 'Nincs leírás'); ?>
                </p>
            </div>
            
            <div class="two-column">
                <!-- Kiosks in Group -->
                <div class="card">
                    <h3>🖥️ A csoportban lévő kijelzők (<?php echo count($kiosks_in_group); ?>)</h3>
                    
                    <?php if (empty($kiosks_in_group)): ?>
                        <div class="no-data">Nincsenek kijelzők ebben a csoportban</div>
                    <?php else: ?>
                        <div class="kiosk-list">
                            <?php foreach ($kiosks_in_group as $kiosk): ?>
                                <div class="kiosk-item">
                                    <div class="kiosk-info">
                                        <div class="kiosk-name"><?php echo htmlspecialchars($kiosk['hostname']); ?></div>
                                        <div class="kiosk-detail">
                                            📍 <?php echo htmlspecialchars($kiosk['location'] ?? '—'); ?>
                                        </div>
                                    </div>
                                    <a href="?id=<?php echo $group_id; ?>&remove=<?php echo $kiosk['id']; ?>" class="btn btn-remove" onclick="return confirm('Eltávolítod ezt a kijelzőt?');">✕</a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Available Kiosks -->
                <div class="card">
                    <h3>+ Elérhető kijelzők (<?php echo count($available_kiosks); ?>)</h3>
                    
                    <?php if (empty($available_kiosks)): ?>
                        <div class="no-data">Nincsenek elérhető kijelzők</div>
                    <?php else: ?>
                        <div class="kiosk-list">
                            <?php foreach ($available_kiosks as $kiosk): ?>
                                <div class="kiosk-item <?php echo ($kiosk['group_in'] ? 'in-other-group' : ''); ?>">
                                    <div class="kiosk-info">
                                        <div class="kiosk-name"><?php echo htmlspecialchars($kiosk['hostname']); ?></div>
                                        <div class="kiosk-detail">
                                            📍 <?php echo htmlspecialchars($kiosk['location'] ?? '—'); ?>
                                        </div>
                                        <?php if ($kiosk['group_in']): ?>
                                            <div class="group-badge">
                                                ⚠️ Jelenleg: <?php echo htmlspecialchars($kiosk['group_name']); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                    <a href="?id=<?php echo $group_id; ?>&assign=<?php echo $kiosk['id']; ?>" class="btn btn-add">
                                        <?php echo ($kiosk['group_in'] ? '↔️ Áthelyez' : '➕ Hozzáad'); ?>
                                    </a>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>

