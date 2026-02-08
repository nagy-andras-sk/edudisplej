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

$focus_group_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];
$error = '';
$success = '';
$groups = [];
$kiosks_by_group = [];
$unassigned_kiosks = [];

if (!$company_id) {
    header('Location: groups.php');
    exit();
}

try {
    $conn = getDbConnection();

    $stmt = $conn->prepare("SELECT id, name, description, priority, is_default FROM kiosk_groups WHERE company_id = ? ORDER BY priority DESC, name");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
        $kiosks_by_group[$row['id']] = [];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT k.id, k.hostname, k.friendly_name, k.status, k.location, kga.group_id
                            FROM kiosks k
                            LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
                            WHERE k.company_id = ?
                            ORDER BY k.hostname");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $group_id = $row['group_id'];
        if ($group_id && isset($kiosks_by_group[$group_id])) {
            $kiosks_by_group[$group_id][] = $row;
        } else {
            $unassigned_kiosks[] = $row;
        }
    }
    $stmt->close();

    closeDbConnection($conn);

} catch (Exception $e) {
    $error = 'Adatbázis hiba';
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
        
        .groups-board {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(260px, 1fr));
            gap: 20px;
        }
        
        .column h3 {
            margin-bottom: 15px;
            color: #333;
        }
        
        .kiosk-list {
            display: flex;
            flex-direction: column;
            gap: 10px;
            min-height: 60px;
        }

        .kiosk-list.drop-target {
            outline: 2px dashed #1a3a52;
            background: #f0f7ff;
        }

        .kiosk-card {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 12px;
            background: #f9f9f9;
            border: 1px solid #eee;
            border-radius: 3px;
            cursor: grab;
        }

        .kiosk-card.dragging {
            opacity: 0.5;
        }

        .kiosk-card:hover {
            background: #f0f0f0;
        }

        .group-header {
            display: flex;
            flex-direction: column;
            gap: 6px;
            margin-bottom: 12px;
        }

        .group-title {
            font-weight: 700;
            color: #1a3a52;
            font-size: 16px;
        }

        .group-meta {
            font-size: 12px;
            color: #666;
        }

        .group-count {
            font-size: 12px;
            color: #0f5132;
            font-weight: 600;
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
        
        <div class="card">
            <h2 style="margin-bottom: 10px;">Kijelzők csoportok szerint</h2>
            <p style="color: #666; font-size: 13px; margin-bottom: 10px;">Húzd a kijelzőket a csoportok között a hozzárendeléshez.</p>
        </div>

        <?php if (empty($groups)): ?>
            <div class="card">
                <div class="no-data">Nincsenek csoportok</div>
            </div>
        <?php else: ?>
            <div class="groups-board">
                <?php foreach ($groups as $group): ?>
                    <div class="card group-column" data-group-id="<?php echo $group['id']; ?>">
                        <div class="group-header">
                            <div class="group-title">
                                <?php echo htmlspecialchars($group['name']); ?>
                                <?php if (!empty($group['is_default'])): ?>
                                    <span style="background: #fff3cd; color: #856404; padding: 2px 6px; border-radius: 3px; font-size: 11px; font-weight: 600; margin-left: 6px;">Alapértelmezett</span>
                                <?php endif; ?>
                            </div>
                            <div class="group-meta">Prioritás: <?php echo (int)$group['priority']; ?></div>
                            <div class="group-count" id="group-count-<?php echo $group['id']; ?>">Kijelzők: <?php echo count($kiosks_by_group[$group['id']] ?? []); ?></div>
                        </div>
                        <div class="kiosk-list" data-group-id="<?php echo $group['id']; ?>">
                            <?php if (empty($kiosks_by_group[$group['id']])): ?>
                                <div class="no-data">Nincs kijelző</div>
                            <?php else: ?>
                                <?php foreach ($kiosks_by_group[$group['id']] as $kiosk): ?>
                                    <div class="kiosk-card" draggable="true" data-kiosk-id="<?php echo $kiosk['id']; ?>" data-group-id="<?php echo $group['id']; ?>">
                                        <div class="kiosk-info">
                                            <div class="kiosk-name"><?php echo htmlspecialchars($kiosk['hostname'] ?? $kiosk['friendly_name'] ?? 'N/A'); ?></div>
                                            <div class="kiosk-detail">📍 <?php echo htmlspecialchars($kiosk['location'] ?? '—'); ?></div>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endforeach; ?>

                <?php if (!empty($unassigned_kiosks)): ?>
                    <div class="card group-column" data-group-id="0">
                        <div class="group-header">
                            <div class="group-title">Nincs csoport</div>
                            <div class="group-meta">Húzd át egy csoportba</div>
                            <div class="group-count" id="group-count-0">Kijelzők: <?php echo count($unassigned_kiosks); ?></div>
                        </div>
                        <div class="kiosk-list" data-group-id="0">
                            <?php foreach ($unassigned_kiosks as $kiosk): ?>
                                <div class="kiosk-card" draggable="true" data-kiosk-id="<?php echo $kiosk['id']; ?>" data-group-id="0">
                                    <div class="kiosk-info">
                                        <div class="kiosk-name"><?php echo htmlspecialchars($kiosk['hostname'] ?? $kiosk['friendly_name'] ?? 'N/A'); ?></div>
                                        <div class="kiosk-detail">📍 <?php echo htmlspecialchars($kiosk['location'] ?? '—'); ?></div>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        <?php endif; ?>
    </div>
    <script>
        let draggedCard = null;

        function updateCounts() {
            document.querySelectorAll('.kiosk-list').forEach(list => {
                const groupId = list.getAttribute('data-group-id');
                const countLabel = document.getElementById('group-count-' + groupId);
                if (countLabel) {
                    const count = list.querySelectorAll('.kiosk-card').length;
                    countLabel.textContent = 'Kijelzők: ' + count;
                }
            });
        }

        function assignKioskToGroup(kioskId, targetGroupId, card) {
            if (targetGroupId === '0') {
                return;
            }

            fetch(`../api/assign_kiosk_group.php?kiosk_id=${kioskId}&group_id=${targetGroupId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const previousList = card.closest('.kiosk-list');
                        const targetList = document.querySelector(`.kiosk-list[data-group-id="${targetGroupId}"]`);
                        if (targetList) {
                            const emptyState = targetList.querySelector('.no-data');
                            if (emptyState) {
                                emptyState.remove();
                            }
                            card.dataset.groupId = targetGroupId;
                            targetList.appendChild(card);
                            if (previousList && previousList.querySelectorAll('.kiosk-card').length === 0) {
                                if (!previousList.querySelector('.no-data')) {
                                    const empty = document.createElement('div');
                                    empty.className = 'no-data';
                                    empty.textContent = 'Nincs kijelző';
                                    previousList.appendChild(empty);
                                }
                            }
                            updateCounts();
                        }
                    } else {
                        alert('⚠️ ' + data.message);
                    }
                })
                .catch(error => {
                    alert('⚠️ Hiba történt: ' + error);
                });
        }

        function initDragAndDrop() {
            document.querySelectorAll('.kiosk-card').forEach(card => {
                card.addEventListener('dragstart', () => {
                    draggedCard = card;
                    card.classList.add('dragging');
                });
                card.addEventListener('dragend', () => {
                    card.classList.remove('dragging');
                    draggedCard = null;
                    document.querySelectorAll('.kiosk-list').forEach(list => list.classList.remove('drop-target'));
                });
            });

            document.querySelectorAll('.kiosk-list').forEach(list => {
                list.addEventListener('dragover', event => {
                    if (!draggedCard) return;
                    const targetGroupId = list.getAttribute('data-group-id');
                    if (targetGroupId === '0') return;
                    event.preventDefault();
                    list.classList.add('drop-target');
                });

                list.addEventListener('dragleave', () => {
                    list.classList.remove('drop-target');
                });

                list.addEventListener('drop', event => {
                    if (!draggedCard) return;
                    event.preventDefault();

                    const targetGroupId = list.getAttribute('data-group-id');
                    const currentGroupId = draggedCard.getAttribute('data-group-id');

                    if (targetGroupId === currentGroupId || targetGroupId === '0') {
                        list.classList.remove('drop-target');
                        return;
                    }

                    assignKioskToGroup(draggedCard.getAttribute('data-kiosk-id'), targetGroupId, draggedCard);
                    list.classList.remove('drop-target');
                });
            });
        }

        initDragAndDrop();
        updateCounts();

        const focusGroupId = <?php echo (int)$focus_group_id; ?>;
        if (focusGroupId) {
            const focusColumn = document.querySelector(`.group-column[data-group-id="${focusGroupId}"]`);
            if (focusColumn) {
                focusColumn.scrollIntoView({ behavior: 'smooth', block: 'start' });
            }
        }
    </script>
</body>
</html>

