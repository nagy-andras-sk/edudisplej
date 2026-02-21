<?php
/**
 * Group Assignment Board (Drag & Drop)
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../kiosk_status.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$user_id = (int)$_SESSION['user_id'];
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];
$company_id = isset($_SESSION['company_id']) ? (int)$_SESSION['company_id'] : null;

$groups = [];
$kiosks = [];

try {
    $conn = getDbConnection();

    if (!$company_id) {
        $company_stmt = $conn->prepare("SELECT company_id FROM users WHERE id = ? LIMIT 1");
        $company_stmt->bind_param("i", $user_id);
        $company_stmt->execute();
        $company_row = $company_stmt->get_result()->fetch_assoc();
        $company_stmt->close();

        if ($company_row && isset($company_row['company_id'])) {
            $company_id = (int)$company_row['company_id'];
        }
    }

    if (!$company_id && !$is_admin) {
        throw new Exception('Company context not found');
    }

    $groups_query = "SELECT g.id, g.name, g.description, g.priority, g.is_default,
                            (SELECT COUNT(*) FROM kiosk_group_assignments kga WHERE kga.group_id = g.id) AS kiosk_count
                     FROM kiosk_groups g
                     WHERE g.company_id = ?
                     ORDER BY g.priority DESC, g.name";
    $groups_stmt = $conn->prepare($groups_query);
    $groups_stmt->bind_param("i", $company_id);
    $groups_stmt->execute();
    $groups_result = $groups_stmt->get_result();

    while ($row = $groups_result->fetch_assoc()) {
        $groups[] = [
            'id' => (int)$row['id'],
            'name' => $row['name'],
            'description' => $row['description'],
            'priority' => (int)$row['priority'],
            'is_default' => (int)$row['is_default'],
            'kiosk_count' => (int)$row['kiosk_count']
        ];
    }
    $groups_stmt->close();

    $kiosks_query = "SELECT k.id, k.hostname, k.friendly_name, k.status, k.location,
                            kg.id AS group_id, kg.name AS group_name
                     FROM kiosks k
                     LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
                     LEFT JOIN kiosk_groups kg ON kg.id = kga.group_id
                     WHERE k.company_id = ?
                     ORDER BY COALESCE(NULLIF(k.friendly_name, ''), k.hostname), k.id";
    $kiosks_stmt = $conn->prepare($kiosks_query);
    $kiosks_stmt->bind_param("i", $company_id);
    $kiosks_stmt->execute();
    $kiosks_result = $kiosks_stmt->get_result();

    while ($row = $kiosks_result->fetch_assoc()) {
        kiosk_apply_effective_status($row);
        $kiosks[] = [
            'id' => (int)$row['id'],
            'hostname' => $row['hostname'],
            'friendly_name' => $row['friendly_name'],
            'status' => $row['status'],
            'location' => $row['location'],
            'group_id' => $row['group_id'] !== null ? (int)$row['group_id'] : null,
            'group_name' => $row['group_name']
        ];
    }
    $kiosks_stmt->close();

    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Adatbázis hiba';
    error_log($e->getMessage());
}

$default_group_id = 0;
foreach ($groups as $group) {
    if (!empty($group['is_default'])) {
        $default_group_id = (int)$group['id'];
        break;
    }
}

$unassigned_kiosks = [];
if ($default_group_id > 0) {
    foreach ($kiosks as &$kiosk_ref) {
        if (empty($kiosk_ref['group_id'])) {
            $kiosk_ref['group_id'] = $default_group_id;
            $kiosk_ref['group_name'] = 'default';
        }
    }
    unset($kiosk_ref);
} else {
    foreach ($kiosks as $kiosk) {
        if (empty($kiosk['group_id'])) {
            $unassigned_kiosks[] = $kiosk;
        }
    }
}
?>
<?php include '../admin/header.php'; ?>
<style>
    .assignment-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
        margin-bottom: 12px;
    }

    .board-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(290px, 1fr));
        gap: 12px;
    }

    .group-column {
        border: 1px solid #d9e2ea;
        border-radius: 8px;
        overflow: hidden;
        background: #fff;
        min-height: 220px;
    }

    .group-column-header {
        padding: 10px 12px;
        background: #f5f8fb;
        border-bottom: 1px solid #d9e2ea;
    }

    .group-title {
        font-size: 14px;
        font-weight: 700;
        color: #1f2d3d;
        margin-bottom: 2px;
    }

    .group-meta {
        font-size: 12px;
        color: #60788f;
        display: flex;
        align-items: center;
        gap: 6px;
        flex-wrap: wrap;
    }

    .group-dropzone {
        min-height: 170px;
        max-height: 60vh;
        overflow-y: auto;
        padding: 10px;
        background: #fbfdff;
        transition: background-color 0.2s ease;
    }

    .group-dropzone.drag-over {
        background: #eaf3ff;
    }

    .kiosk-card {
        border: 1px solid #d7e0ea;
        background: #fff;
        border-radius: 6px;
        padding: 9px;
        margin-bottom: 8px;
        cursor: grab;
    }

    .kiosk-card:active {
        cursor: grabbing;
    }

    .kiosk-name {
        font-size: 13px;
        font-weight: 700;
        color: #23384d;
    }

    .kiosk-detail {
        font-size: 12px;
        color: #6f8092;
        margin-top: 3px;
    }

    .kiosk-status-online {
        color: #1b7e3a;
        font-weight: 700;
    }

    .kiosk-status-offline {
        color: #b12a2a;
        font-weight: 700;
    }

    .dropzone-empty {
        color: #8192a3;
        font-size: 12px;
        padding: 8px;
        border: 1px dashed #d1dce7;
        border-radius: 6px;
        text-align: center;
        background: #f8fbff;
    }
</style>

<?php if ($error): ?>
    <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="card">
    <div class="assignment-header">
        <div style="font-size:14px; font-weight:700; color:#1f2d3d;">Grafikus hozzárendelés (drag & drop)</div>
        <div style="display:flex; gap:8px; align-items:center; flex-wrap:wrap;">
            <button type="button" class="btn" onclick="window.location.reload()">Frissítés</button>
            <a href="groups.php" class="btn btn-primary">← Vissza a csoportokhoz</a>
        </div>
    </div>
    <div style="font-size:12px; color:#60788f; margin-bottom:10px;">Húzd át a kijelzőt egyik csoport oszlopából a másikba. Mentés automatikusan történik.</div>

    <div class="board-grid" id="boardGrid">
        <?php foreach ($groups as $group): ?>
            <div class="group-column" data-group-id="<?php echo (int)$group['id']; ?>">
                <div class="group-column-header">
                    <div class="group-title">
                        <?php echo htmlspecialchars($group['name']); ?>
                        <?php if (!empty($group['is_default'])): ?>
                            <span style="font-size:11px; background:#fff3cd; color:#856404; padding:2px 5px; border-radius:3px; margin-left:6px;">alap</span>
                        <?php endif; ?>
                    </div>
                    <div class="group-meta">
                        <span>Prioritás: <?php echo (int)$group['priority']; ?></span>
                        <span>•</span>
                        <span>Kijelzők: <strong id="group-count-<?php echo (int)$group['id']; ?>"><?php echo (int)$group['kiosk_count']; ?></strong></span>
                    </div>
                </div>
                <div class="group-dropzone" data-drop-group-id="<?php echo (int)$group['id']; ?>"></div>
            </div>
        <?php endforeach; ?>
    </div>

    <?php if (!empty($unassigned_kiosks)): ?>
        <div style="margin-top:12px; border:1px solid #e5d4be; background:#fffaf2; border-radius:8px; padding:10px;">
            <div style="font-size:12px; font-weight:700; color:#7b5e34; margin-bottom:6px;">Figyelem: vannak csoport nélküli kijelzők</div>
            <?php foreach ($unassigned_kiosks as $kiosk): ?>
                <div style="font-size:12px; color:#6f8092; margin-bottom:2px;">#<?php echo (int)$kiosk['id']; ?> - <?php echo htmlspecialchars($kiosk['friendly_name'] ?: $kiosk['hostname'] ?: 'N/A'); ?></div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script>
    const groupsData = <?php echo json_encode($groups, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const kiosksData = <?php echo json_encode($kiosks, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

    let draggedKioskId = null;
    let draggedFromGroupId = null;

    function getKioskDisplayName(kiosk) {
        return kiosk.friendly_name || kiosk.hostname || ('Kijelző #' + kiosk.id);
    }

    function renderBoard() {
        groupsData.forEach(group => {
            const dropzone = document.querySelector('.group-dropzone[data-drop-group-id="' + group.id + '"]');
            if (!dropzone) return;

            dropzone.innerHTML = '';
            const groupKiosks = kiosksData.filter(kiosk => Number(kiosk.group_id) === Number(group.id));
            const countEl = document.getElementById('group-count-' + group.id);
            if (countEl) {
                countEl.textContent = String(groupKiosks.length);
            }

            if (groupKiosks.length === 0) {
                const empty = document.createElement('div');
                empty.className = 'dropzone-empty';
                empty.textContent = 'Nincs kijelző ebben a csoportban';
                dropzone.appendChild(empty);
                return;
            }

            groupKiosks.forEach(kiosk => {
                const card = document.createElement('div');
                card.className = 'kiosk-card';
                card.draggable = true;
                card.dataset.kioskId = String(kiosk.id);
                card.dataset.groupId = String(group.id);

                const statusClass = kiosk.status === 'online' ? 'kiosk-status-online' : 'kiosk-status-offline';
                const statusText = kiosk.status === 'online' ? '🟢 online' : '🔴 offline';

                card.innerHTML = `
                    <div class="kiosk-name">${escapeHtml(getKioskDisplayName(kiosk))}</div>
                    <div class="kiosk-detail">${escapeHtml(kiosk.hostname || 'N/A')}</div>
                    <div class="kiosk-detail ${statusClass}">${statusText}</div>
                    <div class="kiosk-detail">📍 ${escapeHtml(kiosk.location || '—')}</div>
                `;

                card.addEventListener('dragstart', onDragStart);
                card.addEventListener('dragend', onDragEnd);
                dropzone.appendChild(card);
            });
        });
    }

    async function assignKiosk(kioskId, targetGroupId) {
        const response = await fetch('../api/assign_kiosk_group.php?kiosk_id=' + encodeURIComponent(kioskId) + '&group_id=' + encodeURIComponent(targetGroupId));
        const data = await response.json();
        if (!data.success) {
            throw new Error(data.message || 'Sikertelen hozzárendelés');
        }
    }

    function onDragStart(event) {
        draggedKioskId = Number(event.currentTarget.dataset.kioskId);
        draggedFromGroupId = Number(event.currentTarget.dataset.groupId);
        event.dataTransfer.effectAllowed = 'move';
    }

    function onDragEnd() {
        draggedKioskId = null;
        draggedFromGroupId = null;
        document.querySelectorAll('.group-dropzone').forEach(zone => zone.classList.remove('drag-over'));
    }

    function initDropzones() {
        document.querySelectorAll('.group-dropzone').forEach(dropzone => {
            dropzone.addEventListener('dragover', (event) => {
                event.preventDefault();
                dropzone.classList.add('drag-over');
                event.dataTransfer.dropEffect = 'move';
            });

            dropzone.addEventListener('dragleave', () => {
                dropzone.classList.remove('drag-over');
            });

            dropzone.addEventListener('drop', async (event) => {
                event.preventDefault();
                dropzone.classList.remove('drag-over');

                const targetGroupId = Number(dropzone.dataset.dropGroupId);
                if (!draggedKioskId || !targetGroupId || Number(draggedFromGroupId) === Number(targetGroupId)) {
                    return;
                }

                try {
                    await assignKiosk(draggedKioskId, targetGroupId);
                    const kiosk = kiosksData.find(item => Number(item.id) === Number(draggedKioskId));
                    if (kiosk) {
                        kiosk.group_id = targetGroupId;
                    }
                    renderBoard();
                } catch (error) {
                    alert('⚠️ ' + error.message);
                }
            });
        });
    }

    function escapeHtml(value) {
        const div = document.createElement('div');
        div.textContent = value == null ? '' : String(value);
        return div.innerHTML;
    }

    renderBoard();
    initDropzones();
</script>

<?php include '../admin/footer.php'; ?>
