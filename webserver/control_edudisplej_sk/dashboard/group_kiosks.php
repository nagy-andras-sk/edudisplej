<?php
/**
 * Group Kiosks Management - table/panel style
 * Manage kiosks assigned to groups with drag-and-drop
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';
require_once '../kiosk_status.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$focus_group_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$error = '';
$success = '';
$groups = [];
$kiosks_by_group = [];
$unassigned_kiosks = [];
$company_name = $_SESSION['company_name'] ?? '';

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
        $kiosks_by_group[(int)$row['id']] = [];
    }
    $stmt->close();

    $stmt = $conn->prepare("SELECT k.id, k.hostname, k.friendly_name, k.status, k.location, k.last_seen, kga.group_id
                            FROM kiosks k
                            LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
                            WHERE k.company_id = ?
                            ORDER BY COALESCE(NULLIF(k.friendly_name, ''), k.hostname)");
    $stmt->bind_param("i", $company_id);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        kiosk_apply_effective_status($row);
        $group_id = (int)($row['group_id'] ?? 0);
        if ($group_id > 0 && isset($kiosks_by_group[$group_id])) {
            $kiosks_by_group[$group_id][] = $row;
        } else {
            $unassigned_kiosks[] = $row;
        }
    }
    $stmt->close();

    closeDbConnection($conn);

} catch (Exception $e) {
    $error = t_def('group_kiosks.error.db', 'Adatbázis hiba');
    error_log($e->getMessage());
}

$focus_group_name = '';
if ($focus_group_id > 0) {
    foreach ($groups as $group_row) {
        if ((int)$group_row['id'] === $focus_group_id) {
            $focus_group_name = (string)($group_row['name'] ?? '');
            break;
        }
    }
}

$breadcrumb_items = [
    ['label' => '📁 ' . t('nav.groups'), 'href' => 'groups.php'],
];

if ($focus_group_name !== '') {
    $breadcrumb_items[] = ['label' => '👥 ' . $focus_group_name, 'href' => 'group_kiosks.php?id=' . $focus_group_id];
}

$breadcrumb_items[] = ['label' => '🖥️ ' . t_def('group_kiosks.kiosks', 'Kijelzők'), 'current' => true];

$logout_url = '../login.php?logout=1';

include '../admin/header.php';
?>

<style>
    .groups-board {
        display: grid;
        grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
        gap: 16px;
    }

    .group-panel {
        border: 1px solid var(--border);
        background: var(--panel);
        box-shadow: 2px 2px 0 var(--shadow);
    }

    .group-panel-header {
        padding: 12px 14px;
        border-bottom: 1px solid var(--border);
        background: #f7f7f2;
    }

    .group-title {
        font-size: 14px;
        font-weight: 700;
        color: var(--ink);
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 4px;
    }

    .group-meta {
        font-size: 12px;
        color: var(--muted);
    }

    .default-badge {
        font-size: 11px;
        border: 1px solid #d8bf74;
        background: #fff6dd;
        color: #7c5b0a;
        padding: 2px 6px;
    }

    .kiosk-table-wrap {
        overflow-x: auto;
    }

    .kiosk-list.drop-target tr td {
        background: #edf6ff !important;
    }

    .kiosk-row {
        cursor: grab;
    }

    .kiosk-row.dragging {
        opacity: 0.55;
    }

    .kiosk-name {
        font-weight: 600;
        color: var(--ink);
    }

    .status-badge {
        display: inline-block;
        font-size: 11px;
        padding: 3px 7px;
        border: 1px solid var(--border);
    }

    .status-online {
        color: var(--success);
        border-color: var(--success);
    }

    .status-offline {
        color: var(--danger);
        border-color: var(--danger);
    }

    .focus-group {
        outline: 2px solid var(--accent);
        outline-offset: 2px;
    }
</style>

<?php if ($error): ?>
    <div class="alert error">⚠️ <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if ($success): ?>
    <div class="alert success">✓ <?php echo htmlspecialchars($success); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title"><?php echo htmlspecialchars(t_def('group_kiosks.title', 'Csoport kioszk hozzárendelés')); ?></div>
    <p class="muted"><?php echo htmlspecialchars(t_def('group_kiosks.help', 'Húzd a kijelzőket a csoportok között. A „Nincs csoport” oszlop csak forrásként működik.')); ?></p>
</div>

<?php if (empty($groups)): ?>
    <div class="panel">
        <div class="muted"><?php echo htmlspecialchars(t_def('group_kiosks.no_groups', 'Nincsenek csoportok.')); ?></div>
    </div>
<?php else: ?>
    <div class="groups-board">
        <?php foreach ($groups as $group): ?>
            <?php
                $group_id = (int)$group['id'];
                $group_kiosks = $kiosks_by_group[$group_id] ?? [];
                $is_focus_group = ($focus_group_id > 0 && $focus_group_id === $group_id);
            ?>
            <div class="group-panel<?php echo $is_focus_group ? ' focus-group' : ''; ?>" data-group-id="<?php echo $group_id; ?>">
                <div class="group-panel-header">
                    <div class="group-title">
                        👥 <?php echo htmlspecialchars($group['name']); ?>
                        <?php if (!empty($group['is_default'])): ?>
                            <span class="default-badge"><?php echo htmlspecialchars(t_def('group_kiosks.default_badge', 'Alapértelmezett')); ?></span>
                        <?php endif; ?>
                    </div>
                    <div class="group-meta">
                        <?php echo htmlspecialchars(t_def('group_kiosks.priority', 'Prioritás')); ?>: <?php echo (int)$group['priority']; ?> · <?php echo htmlspecialchars(t_def('group_kiosks.kiosks', 'Kijelzők')); ?>: <span id="group-count-<?php echo $group_id; ?>"><?php echo count($group_kiosks); ?></span>
                    </div>
                </div>

                <div class="kiosk-table-wrap">
                    <table class="minimal-table">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t_def('dashboard.col.name', 'Megnevezés')); ?></th>
                                <th><?php echo htmlspecialchars(t_def('group_kiosks.status', 'Státusz')); ?></th>
                                <th><?php echo htmlspecialchars(t_def('group_kiosks.location', 'Hely')); ?></th>
                            </tr>
                        </thead>
                        <tbody class="kiosk-list" data-group-id="<?php echo $group_id; ?>">
                            <?php if (empty($group_kiosks)): ?>
                                <tr class="no-data-row"><td colspan="3" class="muted"><?php echo htmlspecialchars(t_def('group_kiosks.no_kiosk', 'Nincs kijelző')); ?></td></tr>
                            <?php else: ?>
                                <?php foreach ($group_kiosks as $kiosk): ?>
                                    <?php
                                        $kiosk_name = trim((string)($kiosk['friendly_name'] ?? ''));
                                        if ($kiosk_name === '') {
                                            $kiosk_name = $kiosk['hostname'] ?? t_def('common.na', 'N/A');
                                        }
                                        $is_online = ($kiosk['status'] ?? '') === 'online';
                                    ?>
                                    <tr class="kiosk-row" draggable="true" data-kiosk-id="<?php echo (int)$kiosk['id']; ?>" data-group-id="<?php echo $group_id; ?>">
                                        <td class="kiosk-name"><?php echo htmlspecialchars($kiosk_name); ?></td>
                                        <td>
                                            <span class="status-badge <?php echo $is_online ? 'status-online' : 'status-offline'; ?>">
                                                <?php echo $is_online ? htmlspecialchars(t_def('common.status.online_dot', '🟢 online')) : htmlspecialchars(t_def('common.status.offline_dot', '🔴 offline')); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($kiosk['location'] ?? t_def('common.unavailable', '—')); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endforeach; ?>

        <?php if (!empty($unassigned_kiosks)): ?>
            <div class="group-panel" data-group-id="0">
                <div class="group-panel-header">
                    <div class="group-title">📦 <?php echo htmlspecialchars(t_def('group_kiosks.no_group', 'Nincs csoport')); ?></div>
                    <div class="group-meta"><?php echo htmlspecialchars(t_def('group_kiosks.kiosks', 'Kijelzők')); ?>: <span id="group-count-0"><?php echo count($unassigned_kiosks); ?></span></div>
                </div>

                <div class="kiosk-table-wrap">
                    <table class="minimal-table">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t_def('dashboard.col.name', 'Megnevezés')); ?></th>
                                <th><?php echo htmlspecialchars(t_def('group_kiosks.status', 'Státusz')); ?></th>
                                <th><?php echo htmlspecialchars(t_def('group_kiosks.location', 'Hely')); ?></th>
                            </tr>
                        </thead>
                        <tbody class="kiosk-list" data-group-id="0">
                            <?php foreach ($unassigned_kiosks as $kiosk): ?>
                                <?php
                                    $kiosk_name = trim((string)($kiosk['friendly_name'] ?? ''));
                                    if ($kiosk_name === '') {
                                        $kiosk_name = $kiosk['hostname'] ?? t_def('common.na', 'N/A');
                                    }
                                    $is_online = ($kiosk['status'] ?? '') === 'online';
                                ?>
                                <tr class="kiosk-row" draggable="true" data-kiosk-id="<?php echo (int)$kiosk['id']; ?>" data-group-id="0">
                                    <td class="kiosk-name"><?php echo htmlspecialchars($kiosk_name); ?></td>
                                    <td>
                                        <span class="status-badge <?php echo $is_online ? 'status-online' : 'status-offline'; ?>">
                                            <?php echo $is_online ? htmlspecialchars(t_def('common.status.online_dot', '🟢 online')) : htmlspecialchars(t_def('common.status.offline_dot', '🔴 offline')); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($kiosk['location'] ?? t_def('common.unavailable', '—')); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        <?php endif; ?>
    </div>
<?php endif; ?>

<script>
    const i18n = <?php echo json_encode([
        'noKiosk' => t_def('group_kiosks.no_kiosk', 'Nincs kijelző'),
        'assignFailed' => t_def('group_kiosks.assign_failed', 'Sikertelen hozzárendelés'),
        'errorOccurred' => t_def('group_kiosks.error_occurred', 'Hiba történt: {error}'),
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    var draggedRow = null;

    function updateCounts() {
        document.querySelectorAll('.kiosk-list').forEach(function (list) {
            var groupId = list.getAttribute('data-group-id');
            var countLabel = document.getElementById('group-count-' + groupId);
            if (countLabel) {
                var count = list.querySelectorAll('.kiosk-row').length;
                countLabel.textContent = String(count);
            }
        });
    }

    function ensureEmptyState(list) {
        if (!list) {
            return;
        }

        var rowCount = list.querySelectorAll('.kiosk-row').length;
        var emptyRow = list.querySelector('.no-data-row');

        if (rowCount === 0 && !emptyRow) {
            var tr = document.createElement('tr');
            tr.className = 'no-data-row';
            var td = document.createElement('td');
            td.colSpan = 3;
            td.className = 'muted';
            td.textContent = i18n.noKiosk;
            tr.appendChild(td);
            list.appendChild(tr);
        }

        if (rowCount > 0 && emptyRow) {
            emptyRow.remove();
        }
    }

    function assignKioskToGroup(kioskId, targetGroupId, row) {
        if (targetGroupId === '0') {
            return;
        }

        fetch('../api/assign_kiosk_group.php?kiosk_id=' + encodeURIComponent(kioskId) + '&group_id=' + encodeURIComponent(targetGroupId))
            .then(function (response) {
                return response.json();
            })
            .then(function (data) {
                if (!data.success) {
                    alert('⚠️ ' + (data.message || i18n.assignFailed));
                    return;
                }

                var previousList = row.closest('.kiosk-list');
                var targetList = document.querySelector('.kiosk-list[data-group-id="' + targetGroupId + '"]');
                if (!targetList) {
                    return;
                }

                row.dataset.groupId = targetGroupId;
                targetList.appendChild(row);
                ensureEmptyState(previousList);
                ensureEmptyState(targetList);
                updateCounts();
            })
            .catch(function (error) {
                alert('⚠️ ' + i18n.errorOccurred.replace('{error}', String(error)));
            });
    }

    function initDragAndDrop() {
        document.querySelectorAll('.kiosk-row').forEach(function (row) {
            row.addEventListener('dragstart', function () {
                draggedRow = row;
                row.classList.add('dragging');
            });

            row.addEventListener('dragend', function () {
                row.classList.remove('dragging');
                draggedRow = null;
                document.querySelectorAll('.kiosk-list').forEach(function (list) {
                    list.classList.remove('drop-target');
                });
            });
        });

        document.querySelectorAll('.kiosk-list').forEach(function (list) {
            list.addEventListener('dragover', function (event) {
                if (!draggedRow) {
                    return;
                }
                var targetGroupId = list.getAttribute('data-group-id');
                if (targetGroupId === '0') {
                    return;
                }
                event.preventDefault();
                list.classList.add('drop-target');
            });

            list.addEventListener('dragleave', function () {
                list.classList.remove('drop-target');
            });

            list.addEventListener('drop', function (event) {
                if (!draggedRow) {
                    return;
                }
                event.preventDefault();

                var targetGroupId = list.getAttribute('data-group-id');
                var currentGroupId = draggedRow.getAttribute('data-group-id');

                list.classList.remove('drop-target');

                if (targetGroupId === '0' || targetGroupId === currentGroupId) {
                    return;
                }

                assignKioskToGroup(draggedRow.getAttribute('data-kiosk-id'), targetGroupId, draggedRow);
            });
        });
    }

    initDragAndDrop();
    updateCounts();

    var focusGroupId = <?php echo (int)$focus_group_id; ?>;
    if (focusGroupId) {
        var focusColumn = document.querySelector('.group-panel[data-group-id="' + focusGroupId + '"]');
        if (focusColumn) {
            focusColumn.scrollIntoView({ behavior: 'smooth', block: 'start' });
        }
    }
</script>

</div>
</body>
</html>
