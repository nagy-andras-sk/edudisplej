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

// Get groups for this company with loop info
$groups = [];
try {
    $query = "SELECT g.*,
              (SELECT COUNT(*) FROM kiosk_group_assignments WHERE group_id = g.id) as kiosk_count,
              (SELECT COUNT(*) FROM kiosk_group_modules WHERE group_id = g.id) as loop_count
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
<?php include '../admin/header.php'; ?>
        
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="success">✓ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <!-- Groups Table -->
        <div class="card">
            <h2 style="margin-bottom: 15px;">Csoportok (<?php echo count($groups); ?>)</h2>
            
            <?php if (empty($groups)): ?>
                <div style="text-align: center; padding: 40px; color: #999;">
                    <p>Nincsenek csoportok. Hozz létre egy új csoportot az alábbi formban.</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Csoport Neve</th>
                            <th>Leírás</th>
                            <th>Kijelzők</th>
                            <th>Loop</th>
                            <th>Műveletek</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($groups as $group): ?>
                            <tr>
                                <td>
                                    <div style="display: flex; align-items: center; gap: 8px;">
                                        <strong id="group-name-<?php echo $group['id']; ?>">
                                            <?php echo htmlspecialchars($group['name']); ?>
                                        </strong>
                                        <button onclick="renameGroup(<?php echo $group['id']; ?>, '<?php echo htmlspecialchars($group['name'], ENT_QUOTES); ?>')" 
                                                class="action-btn" 
                                                style="padding: 4px 8px; font-size: 12px; background: #1a3a52;" 
                                                title="Átnevezés">
                                            ✏️
                                        </button>
                                    </div>
                                </td>
                                <td style="color: #666; font-size: 13px;">
                                    <?php echo htmlspecialchars($group['description'] ?? '—'); ?>
                                </td>
                                <td>
                                    <span style="background: #e7f3ff; color: #0066cc; padding: 4px 8px; border-radius: 3px; font-size: 12px; cursor: pointer;" 
                                          onclick="showGroupKiosks(<?php echo $group['id']; ?>, '<?php echo htmlspecialchars($group['name'], ENT_QUOTES); ?>')">
                                        <?php echo $group['kiosk_count']; ?> kijelző
                                    </span>
                                </td>
                                <td>
                                    <?php if ($group['loop_count'] > 0): ?>
                                        <span style="background: #d4edda; color: #155724; padding: 4px 8px; border-radius: 3px; font-size: 12px; cursor: pointer;" 
                                              onclick="viewLoop(<?php echo $group['id']; ?>, '<?php echo htmlspecialchars($group['name'], ENT_QUOTES); ?>')">
                                            🔄 <?php echo $group['loop_count']; ?> elem
                                        </span>
                                    <?php else: ?>
                                        <span style="color: #999; font-size: 12px;">Nincs beállítva</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="display: flex; gap: 5px; align-items: center;">
                                        <!-- Primary action: Customize -->
                                        <a href="group_loop?id=<?php echo $group['id']; ?>" class="action-btn" style="background: #1a3a52; color: white; padding: 8px 16px; font-weight: bold;">⚙️ Testreszabás</a>
                                        <!-- Secondary actions -->
                                        <a href="group_kiosks?id=<?php echo $group['id']; ?>" class="action-btn action-btn-small" style="background: #6c757d;">🖥️ Kijelzők</a>
                                        <a href="?delete=<?php echo $group['id']; ?>" class="action-btn action-btn-small" style="background: #dc3545;" onclick="return confirm('Biztosan törölted ezt a csoportot?');">🗑️</a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
        
        <!-- Create Group Form - moved to bottom -->
        <div class="card" style="margin-top: 20px;">
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
    </div>
    
    <script>
        function renameGroup(groupId, currentName) {
            const newName = prompt('Új csoport név:', currentName);
            if (newName && newName !== currentName) {
                const formData = new FormData();
                formData.append('group_id', groupId);
                formData.append('new_name', newName);
                
                fetch('../api/rename_group.php', {
                    method: 'POST',
                    body: formData
                })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        document.getElementById('group-name-' + groupId).textContent = newName;
                        alert('✓ ' + data.message);
                    } else {
                        alert('⚠️ ' + data.message);
                    }
                })
                .catch(error => {
                    alert('⚠️ Hiba történt: ' + error);
                });
            }
        }
        
        function showGroupKiosks(groupId, groupName) {
            fetch('../api/get_group_kiosks.php?group_id=' + groupId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const kiosks = data.kiosks;
                        let html = '<div style="max-height: 400px; overflow-y: auto;">';
                        
                        if (kiosks.length === 0) {
                            html += '<p style="text-align: center; color: #999; padding: 20px;">Nincsenek kijelzők ebben a csoportban</p>';
                        } else {
                            html += '<table style="width: 100%; font-size: 13px;">';
                            html += '<thead><tr><th>Hostname</th><th>Státusz</th><th>Hely</th></tr></thead>';
                            html += '<tbody>';
                            kiosks.forEach(kiosk => {
                                const statusBadge = kiosk.status === 'online' 
                                    ? '<span style="color: #28a745;">🟢 Online</span>' 
                                    : '<span style="color: #dc3545;">🔴 Offline</span>';
                                html += `<tr>
                                    <td><strong>${kiosk.hostname || kiosk.friendly_name || 'N/A'}</strong></td>
                                    <td>${statusBadge}</td>
                                    <td>${kiosk.location || '-'}</td>
                                </tr>`;
                            });
                            html += '</tbody></table>';
                        }
                        html += '</div>';
                        
                        showModal('Kijelzők - ' + groupName, html);
                    } else {
                        alert('⚠️ ' + data.message);
                    }
                })
                .catch(error => {
                    alert('⚠️ Hiba történt: ' + error);
                });
        }
        
        function viewLoop(groupId, groupName) {
            fetch('../api/group_loop_config.php?group_id=' + groupId)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const loops = data.loops;
                        let html = '<div style="max-height: 400px; overflow-y: auto;">';
                        
                        if (loops.length === 0) {
                            html += '<p style="text-align: center; color: #999; padding: 20px;">Nincs beállított loop</p>';
                        } else {
                            html += '<div style="display: flex; flex-direction: column; gap: 10px;">';
                            loops.forEach((loop, index) => {
                                html += `<div style="
                                    background: linear-gradient(135deg, #0f2537 0%, #1a4d2e 100%);
                                    color: white;
                                    padding: 15px;
                                    border-radius: 8px;
                                    display: flex;
                                    align-items: center;
                                    gap: 15px;
                                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                                ">
                                    <div style="
                                        background: rgba(255,255,255,0.2);
                                        padding: 8px 12px;
                                        border-radius: 5px;
                                        font-weight: bold;
                                        font-size: 14px;
                                    ">${index + 1}</div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: bold; font-size: 14px;">${loop.module_name}</div>
                                        <div style="font-size: 12px; opacity: 0.9;">${loop.description || ''}</div>
                                    </div>
                                    <div style="
                                        background: rgba(255,255,255,0.2);
                                        padding: 8px 12px;
                                        border-radius: 5px;
                                        text-align: center;
                                    ">
                                        <div style="font-size: 18px; font-weight: bold;">${loop.duration_seconds}</div>
                                        <div style="font-size: 11px; opacity: 0.9;">sec</div>
                                    </div>
                                </div>`;
                            });
                            html += '</div>';
                            
                            // Add total duration
                            const totalDuration = loops.reduce((sum, loop) => sum + parseInt(loop.duration_seconds), 0);
                            html += `<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; text-align: center;">
                                <strong>Teljes loop időtartam:</strong> ${totalDuration} másodperc (${Math.floor(totalDuration / 60)} perc ${totalDuration % 60} mp)
                            </div>`;
                        }
                        html += '</div>';
                        
                        showModal('🔄 Loop Konfiguráció - ' + groupName, html);
                    } else {
                        alert('⚠️ ' + data.message);
                    }
                })
                .catch(error => {
                    alert('⚠️ Hiba történt: ' + error);
                });
        }
        
        function showModal(title, content) {
            const modal = document.createElement('div');
            modal.style.cssText = `
                display: flex;
                position: fixed;
                z-index: 1000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                align-items: center;
                justify-content: center;
            `;
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
            
            modal.innerHTML = `
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 12px;
                    max-width: 700px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin: 0;">${title}</h2>
                        <button onclick="this.closest('div').parentElement.parentElement.remove()" style="
                            background: #1a3a52;
                            color: white;
                            border: none;
                            font-size: 16px;
                            cursor: pointer;
                            width: 36px;
                            height: 36px;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            transition: background 0.2s;
                        " onmouseover="this.style.background='#0f2537'" onmouseout="this.style.background='#1a3a52'">✕</button>
                    </div>
                    <div>${content}</div>
                </div>
            `;
            
            document.body.appendChild(modal);
        }
    </script>
<?php include '../admin/footer.php'; ?>

