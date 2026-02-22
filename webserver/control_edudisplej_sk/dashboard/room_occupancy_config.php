<?php
session_start();
require_once '../auth_roles.php';
require_once '../i18n.php';
require_once '../dbkonfiguracia.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

if (!edudisplej_can_edit_module_content()) {
    header('Location: index.php');
    exit();
}

function edudisplej_dashboard_has_module_license(string $module_key): bool {
    $module_key = strtolower(trim($module_key));
    if ($module_key === '') {
        return false;
    }

    if (!empty($_SESSION['isadmin']) && empty($_SESSION['admin_acting_company_id'])) {
        return true;
    }

    $company_id = (int)($_SESSION['admin_acting_company_id'] ?? 0);
    if ($company_id <= 0) {
        $company_id = (int)($_SESSION['company_id'] ?? 0);
    }
    if ($company_id <= 0) {
        return false;
    }

    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare("SELECT m.id
                                FROM modules m
                                INNER JOIN module_licenses ml ON ml.module_id = m.id
                                WHERE m.module_key = ? AND m.is_active = 1
                                  AND ml.company_id = ? AND ml.quantity > 0
                                LIMIT 1");
        $stmt->bind_param('si', $module_key, $company_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        closeDbConnection($conn);
        return !empty($row);
    } catch (Throwable $e) {
        error_log('room_occupancy_config license check failed: ' . $e->getMessage());
        return false;
    }
}

if (!edudisplej_dashboard_has_module_license('room-occupancy')) {
    header('Location: index.php');
    exit();
}

$companyId = (int)($_SESSION['admin_acting_company_id'] ?? 0);
if ($companyId <= 0) {
    $companyId = (int)($_SESSION['company_id'] ?? 0);
}

$apiToken = '';
if ($companyId > 0) {
    try {
        $conn = getDbConnection();
        $stmt = $conn->prepare('SELECT api_token FROM companies WHERE id = ? LIMIT 1');
        $stmt->bind_param('i', $companyId);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        closeDbConnection($conn);
        $apiToken = (string)($row['api_token'] ?? '');
    } catch (Throwable $e) {
        error_log('room_occupancy_config token load failed: ' . $e->getMessage());
    }
}

$breadcrumb_items = [
    ['label' => '🏫 ' . t_def('room_occ.title', 'Terem foglaltság'), 'current' => true],
];
$logout_url = '../login.php?logout=1';

$room_occ_i18n = [
    'invalidServerResponse' => t_def('room_occ.error.invalid_response', 'Érvénytelen szerver válasz'),
    'errorGeneral' => t_def('room_occ.error.general', 'Hiba'),
    'emptyRooms' => t_def('room_occ.empty.rooms', 'Nincs terem'),
    'emptyEvents' => t_def('room_occ.empty.events', 'Nincs esemény'),
    'chooseRoom' => t_def('room_occ.choose.room', 'Válassz termet'),
    'readonlyExternal' => t_def('room_occ.external.readonly', 'Külső (readonly)'),
    'editShort' => t_def('common.edit_short', 'Szerk.'),
    'statusRoomSaved' => t_def('room_occ.status.room_saved', 'Terem mentve'),
    'statusEventSaved' => t_def('room_occ.status.event_saved', 'Esemény mentve'),
    'statusEventDeleted' => t_def('room_occ.status.event_deleted', 'Esemény törölve'),
    'confirmDelete' => t_def('room_occ.confirm.delete', 'Biztosan törlöd az eseményt?'),
    'errChooseRoom' => t_def('room_occ.error.choose_room', 'Válassz termet.'),
    'errChooseDate' => t_def('room_occ.error.choose_date', 'Válassz dátumot.'),
    'errInvalidTime' => t_def('room_occ.error.invalid_time', 'Adj meg érvényes időpontot 24 órás formátumban (HH:MM).'),
    'errTimeOrder' => t_def('room_occ.error.time_order', 'A kezdési időnek korábbinak kell lennie mint a befejezési idő.'),
    'errTitleRequired' => t_def('room_occ.error.title_required', 'Az esemény cím megadása kötelező.'),
    'manual' => t_def('common.manual', 'manual'),
];
include '../admin/header.php';
?>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title"><?php echo htmlspecialchars(t_def('room_occ.panel.title', 'Terem foglaltság modul')); ?></div>
    <div class="muted"><?php echo htmlspecialchars(t_def('room_occ.panel.desc', 'Termek és napi idősávok kezelése (kézi). A külső szerver-integráció admin portálon konfigurálható.')); ?></div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; align-items:start;">
    <div class="panel">
        <div class="panel-title"><?php echo htmlspecialchars(t_def('room_occ.rooms.title', 'Termek')); ?></div>
        <div style="display:grid; gap:8px; margin-bottom:10px;">
            <input type="hidden" id="room-id" value="0">
            <input type="text" id="room-key" placeholder="<?php echo htmlspecialchars(t_def('room_occ.room.key', 'terem_kulcs (pl. a101)')); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            <input type="text" id="room-name" placeholder="<?php echo htmlspecialchars(t_def('room_occ.room.name', 'Terem neve (pl. A101 Informatika)')); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            <input type="number" id="room-capacity" min="0" max="100000" placeholder="<?php echo htmlspecialchars(t_def('room_occ.room.capacity', 'Kapacitás')); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            <label><input type="checkbox" id="room-active" checked> <?php echo htmlspecialchars(t_def('common.active', 'Aktív')); ?></label>
            <div style="display:flex; gap:8px;">
                <button class="btn btn-primary" id="room-save"><?php echo htmlspecialchars(t_def('common.save', 'Mentés')); ?></button>
                <button class="btn" id="room-new"><?php echo htmlspecialchars(t_def('common.new', 'Új')); ?></button>
            </div>
        </div>
        <div class="table-wrap"><table><thead><tr><th><?php echo htmlspecialchars(t_def('common.key', 'Kulcs')); ?></th><th><?php echo htmlspecialchars(t_def('common.name', 'Név')); ?></th><th><?php echo htmlspecialchars(t_def('common.action', 'Művelet')); ?></th></tr></thead><tbody id="rooms-body"></tbody></table></div>
    </div>

    <div class="panel">
        <div class="panel-title"><?php echo htmlspecialchars(t_def('room_occ.daily.title', 'Napi foglaltság')); ?></div>
        <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px; margin-bottom:8px;">
            <select id="event-room" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;"></select>
            <input type="date" id="event-date" lang="hu-HU" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;">
        </div>

        <div style="display:grid; gap:8px; margin-bottom:10px;">
            <input type="hidden" id="event-id" value="0">
            <div style="display:grid; grid-template-columns:1fr 1fr; gap:8px;">
                <input type="time" id="event-start" lang="hu-HU" step="60" placeholder="HH:MM" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                <input type="time" id="event-end" lang="hu-HU" step="60" placeholder="HH:MM" style="padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            </div>
            <input type="text" id="event-title" placeholder="<?php echo htmlspecialchars(t_def('room_occ.event.title', 'Esemény címe')); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            <textarea id="event-note" rows="3" placeholder="<?php echo htmlspecialchars(t_def('common.note_optional', 'Megjegyzés (opcionális)')); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;"></textarea>
            <div style="display:flex; gap:8px;">
                <button class="btn btn-primary" id="event-save"><?php echo htmlspecialchars(t_def('room_occ.event.save', 'Esemény mentése')); ?></button>
                <button class="btn" id="event-new"><?php echo htmlspecialchars(t_def('common.new', 'Új')); ?></button>
                <button class="btn btn-danger" id="event-delete" style="display:none;"><?php echo htmlspecialchars(t_def('common.delete', 'Törlés')); ?></button>
            </div>
        </div>

        <div class="table-wrap"><table><thead><tr><th><?php echo htmlspecialchars(t_def('common.time', 'Idő')); ?></th><th><?php echo htmlspecialchars(t_def('common.event', 'Esemény')); ?></th><th><?php echo htmlspecialchars(t_def('common.source', 'Forrás')); ?></th><th><?php echo htmlspecialchars(t_def('common.action', 'Művelet')); ?></th></tr></thead><tbody id="events-body"></tbody></table></div>
    </div>
</div>

<div id="room-occ-status" class="muted" style="margin-top:10px;"></div>

<script>
(function () {
    const api = '../api/room_occupancy.php';
    const I18N = <?php echo json_encode($room_occ_i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const today = new Date().toISOString().slice(0, 10);
    const $ = (id) => document.getElementById(id);

    let rooms = [];
    let events = [];

    $('event-date').value = today;

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    }

    function setStatus(message, isError = false) {
        const el = $('room-occ-status');
        el.textContent = message || '';
        el.style.color = isError ? '#b42318' : '#475467';
    }

    function normalizeTimeValue(value) {
        const raw = String(value || '').trim();
        const match = raw.match(/^([01]?\d|2[0-3]):([0-5]\d)$/);
        if (!match) {
            return '';
        }
        const hh = String(parseInt(match[1], 10)).padStart(2, '0');
        return `${hh}:${match[2]}`;
    }

    async function fetchJson(url, opts) {
        const r = await fetch(url, opts);
        let payload;
        try {
            payload = await r.json();
        } catch (_) {
            throw new Error(I18N.invalidServerResponse);
        }
        if (!r.ok || !payload.success) {
            throw new Error(payload.message || I18N.errorGeneral);
        }
        return payload;
    }

    function renderRoomSelect() {
        $('event-room').innerHTML = rooms.map((room) => `<option value="${room.id}">${escapeHtml(room.room_name)}</option>`).join('');
    }

    async function loadRooms() {
        const payload = await fetchJson(`${api}?action=admin_rooms`);
        rooms = Array.isArray(payload.items) ? payload.items : [];
        renderRoomSelect();

        $('rooms-body').innerHTML = rooms.map((room) => `
            <tr>
                <td>${escapeHtml(room.room_key)}</td>
                <td>${escapeHtml(room.room_name)}</td>
                <td><button class="btn btn-small" data-room-edit="${room.id}">${I18N.editShort}</button></td>
            </tr>
        `).join('') || `<tr><td colspan="3" class="muted">${I18N.emptyRooms}</td></tr>`;

        document.querySelectorAll('[data-room-edit]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.getAttribute('data-room-edit') || '0', 10);
                const row = rooms.find((room) => Number(room.id) === id);
                if (!row) return;
                $('room-id').value = row.id;
                $('room-key').value = row.room_key || '';
                $('room-name').value = row.room_name || '';
                $('room-capacity').value = row.capacity || 0;
                $('room-active').checked = Number(row.is_active || 0) === 1;
                $('event-room').value = String(row.id);
                loadEvents().catch((error) => setStatus(error.message, true));
            });
        });
    }

    async function saveRoom() {
        await fetchJson(`${api}?action=save_room`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: parseInt($('room-id').value || '0', 10) || 0,
                room_key: $('room-key').value,
                room_name: $('room-name').value,
                capacity: parseInt($('room-capacity').value || '0', 10) || 0,
                is_active: $('room-active').checked ? 1 : 0,
            })
        });

        $('room-id').value = '0';
        $('room-key').value = '';
        $('room-name').value = '';
        $('room-capacity').value = '';
        $('room-active').checked = true;

        await loadRooms();
        await loadEvents();
        setStatus(I18N.statusRoomSaved);
    }

    async function loadEvents() {
        const roomId = parseInt($('event-room').value || '0', 10) || 0;
        const date = $('event-date').value || today;
        if (roomId <= 0) {
            $('events-body').innerHTML = `<tr><td colspan="4" class="muted">${I18N.chooseRoom}</td></tr>`;
            return;
        }

        const payload = await fetchJson(`${api}?action=admin_events&room_id=${roomId}&date=${encodeURIComponent(date)}`);
        events = Array.isArray(payload.items) ? payload.items : [];

        $('events-body').innerHTML = events.map((event) => `
            <tr>
                <td>${escapeHtml(event.start_time)} - ${escapeHtml(event.end_time)}</td>
                <td>${escapeHtml(event.event_title)}</td>
                <td>${escapeHtml(event.source_type || I18N.manual)}</td>
                <td>${(event.source_type || 'manual') === 'manual'
                    ? `<button class="btn btn-small" data-event-edit="${event.id}">${I18N.editShort}</button>`
                    : `<span class="muted">${I18N.readonlyExternal}</span>`}</td>
            </tr>
        `).join('') || `<tr><td colspan="4" class="muted">${I18N.emptyEvents}</td></tr>`;

        document.querySelectorAll('[data-event-edit]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.getAttribute('data-event-edit') || '0', 10);
                const row = events.find((event) => Number(event.id) === id);
                if (!row) return;
                $('event-id').value = row.id;
                $('event-start').value = row.start_time || '';
                $('event-end').value = row.end_time || '';
                $('event-title').value = row.event_title || '';
                $('event-note').value = row.event_note || '';
                $('event-delete').style.display = (row.source_type === 'manual') ? '' : 'none';
            });
        });
    }

    async function saveEvent() {
        const roomId = parseInt($('event-room').value || '0', 10) || 0;
        const eventDate = $('event-date').value || today;
        const startTime = normalizeTimeValue($('event-start').value);
        const endTime = normalizeTimeValue($('event-end').value);
        const eventTitle = $('event-title').value.trim();

        if (roomId <= 0) {
            throw new Error(I18N.errChooseRoom);
        }
        if (!eventDate) {
            throw new Error(I18N.errChooseDate);
        }
        if (!startTime || !endTime) {
            throw new Error(I18N.errInvalidTime);
        }
        if (startTime >= endTime) {
            throw new Error(I18N.errTimeOrder);
        }
        if (!eventTitle) {
            throw new Error(I18N.errTitleRequired);
        }

        await fetchJson(`${api}?action=save_event`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: parseInt($('event-id').value || '0', 10) || 0,
                room_id: roomId,
                event_date: eventDate,
                start_time: startTime,
                end_time: endTime,
                event_title: eventTitle,
                event_note: $('event-note').value,
            })
        });

        $('event-id').value = '0';
        $('event-start').value = '';
        $('event-end').value = '';
        $('event-title').value = '';
        $('event-note').value = '';
        $('event-delete').style.display = 'none';

        await loadEvents();
        setStatus(I18N.statusEventSaved);
    }

    async function deleteEvent() {
        const id = parseInt($('event-id').value || '0', 10) || 0;
        if (id <= 0) return;
        if (!window.confirm(I18N.confirmDelete)) return;

        await fetchJson(`${api}?action=delete_event`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ id })
        });

        $('event-id').value = '0';
        $('event-start').value = '';
        $('event-end').value = '';
        $('event-title').value = '';
        $('event-note').value = '';
        $('event-delete').style.display = 'none';

        await loadEvents();
        setStatus(I18N.statusEventDeleted);
    }

    $('room-save').addEventListener('click', () => saveRoom().catch((error) => setStatus(error.message, true)));
    $('room-new').addEventListener('click', () => {
        $('room-id').value = '0';
        $('room-key').value = '';
        $('room-name').value = '';
        $('room-capacity').value = '';
        $('room-active').checked = true;
    });

    $('event-save').addEventListener('click', () => saveEvent().catch((error) => setStatus(error.message, true)));
    $('event-new').addEventListener('click', () => {
        $('event-id').value = '0';
        $('event-start').value = '';
        $('event-end').value = '';
        $('event-title').value = '';
        $('event-note').value = '';
        $('event-delete').style.display = 'none';
    });
    $('event-delete').addEventListener('click', () => deleteEvent().catch((error) => setStatus(error.message, true)));

    $('event-room').addEventListener('change', () => loadEvents().catch((error) => setStatus(error.message, true)));
    $('event-date').addEventListener('change', () => loadEvents().catch((error) => setStatus(error.message, true)));

    loadRooms()
        .then(() => loadEvents())
        .catch((error) => setStatus(error.message, true));
})();
</script>

<?php include '../admin/footer.php'; ?>
