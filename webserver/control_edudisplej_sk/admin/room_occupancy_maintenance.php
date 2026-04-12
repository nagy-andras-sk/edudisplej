<?php
session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';

if (!isset($_SESSION['user_id']) || empty($_SESSION['isadmin'])) {
    header('Location: index.php');
    exit();
}

if (!empty($_SESSION['admin_acting_company_id'])) {
    header('Location: dashboard.php');
    exit();
}

$error = '';
$companies = [];

try {
    $conn = getDbConnection();
    $result = $conn->query("SELECT id, name, api_token FROM companies ORDER BY name ASC");
    if ($result) {
        while ($row = $result->fetch_assoc()) {
            $companies[] = [
                'id' => (int)$row['id'],
                'name' => (string)$row['name'],
                'api_token' => (string)($row['api_token'] ?? ''),
            ];
        }
    }
    closeDbConnection($conn);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        closeDbConnection($conn);
    }
    $error = t_def('common.error_prefix', 'Hiba:') . ' ' . $e->getMessage();
    error_log('admin/room_occupancy_maintenance.php: ' . $e->getMessage());
}

$breadcrumb_items = [
    ['label' => '🏫 ' . t_def('room_occ.admin.breadcrumb', 'Teremfoglalás integráció'), 'current' => true],
];
$logout_url = '../login.php?logout=1';

$room_occ_admin_i18n = [
    'invalidServerResponse' => t_def('room_occ.error.invalid_response', 'Érvénytelen szerver válasz'),
    'errorGeneral' => t_def('room_occ.error.general', 'Hiba'),
    'statusServerSaved' => t_def('room_occ.admin.status.server_saved', 'Szerver mentve.'),
    'statusLinkSaved' => t_def('room_occ.admin.status.link_saved', 'Párosítás mentve.'),
    'errorServerRequired' => t_def('room_occ.admin.error.server_required', 'Szerver kulcs és név kötelező.'),
    'errorLinkRequired' => t_def('room_occ.admin.error.link_required', 'Szerver és cég kiválasztása kötelező.'),
    'active' => t_def('common.active', 'Aktív'),
    'inactive' => t_def('common.inactive', 'Inaktív'),
    'emptyServers' => t_def('room_occ.admin.empty.servers', 'Nincs szerver'),
    'emptyLinks' => t_def('room_occ.admin.empty.links', 'Nincs párosítás'),
    'editShort' => t_def('common.edit_short', 'Szerk.'),
];
include 'header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="panel" style="margin-bottom:12px;">
    <div class="panel-title"><?php echo htmlspecialchars(t_def('room_occ.admin.panel.title', 'Terem foglaltság – szerver integráció')); ?></div>
    <div class="muted"><?php echo htmlspecialchars(t_def('room_occ.admin.panel.desc', 'Itt kezelhető a külső szerverek listája és a cégekhez rendelés. A felhasználói oldalon már csak a megjelenítés testreszabása szükséges.')); ?></div>
</div>

<div style="display:grid; grid-template-columns:1fr 1fr; gap:12px; align-items:start;">
    <div class="panel">
        <div class="panel-title"><?php echo htmlspecialchars(t_def('room_occ.admin.servers.title', 'Szerverek')); ?></div>
        <div style="display:grid; gap:8px; margin-bottom:10px;">
            <input type="hidden" id="server-id" value="0">
            <input type="text" id="server-key" placeholder="<?php echo htmlspecialchars(t_def('room_occ.admin.server.key', 'server_key (pl. skolsky-sis)')); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            <input type="text" id="server-name" placeholder="<?php echo htmlspecialchars(t_def('room_occ.admin.server.name', 'Szerver neve')); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            <input type="url" id="server-url" placeholder="<?php echo htmlspecialchars(t_def('room_occ.admin.server.url_optional', 'Endpoint base URL (opcionális)')); ?>" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
            <label><input type="checkbox" id="server-active" checked> <?php echo htmlspecialchars(t_def('common.active', 'Aktív')); ?></label>
            <div style="display:flex; gap:8px;">
                <button class="btn btn-primary" id="server-save"><?php echo htmlspecialchars(t_def('common.save', 'Mentés')); ?></button>
                <button class="btn" id="server-new"><?php echo htmlspecialchars(t_def('common.new', 'Új')); ?></button>
            </div>
        </div>
        <div class="table-wrap"><table><thead><tr><th><?php echo htmlspecialchars(t_def('common.key', 'Kulcs')); ?></th><th><?php echo htmlspecialchars(t_def('common.name', 'Név')); ?></th><th><?php echo htmlspecialchars(t_def('common.status', 'Állapot')); ?></th><th><?php echo htmlspecialchars(t_def('common.action', 'Művelet')); ?></th></tr></thead><tbody id="servers-body"></tbody></table></div>
    </div>

    <div class="panel">
        <div class="panel-title"><?php echo htmlspecialchars(t_def('room_occ.admin.links.title', 'Cég párosítás')); ?></div>
        <div style="display:grid; gap:8px; margin-bottom:10px;">
            <input type="hidden" id="link-id" value="0">
            <select id="link-server" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;"></select>
            <select id="link-company" style="width:100%; padding:8px; border:1px solid #d1d5db; border-radius:6px;">
                <option value="0">-- <?php echo htmlspecialchars(t_def('common.choose_company', 'Válassz céget')); ?> --</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?php echo (int)$company['id']; ?>"><?php echo htmlspecialchars($company['name']); ?></option>
                <?php endforeach; ?>
            </select>
            <label><input type="checkbox" id="link-active" checked> <?php echo htmlspecialchars(t_def('room_occ.admin.link.active', 'Aktív párosítás')); ?></label>
            <div style="display:flex; gap:8px;">
                <button class="btn btn-primary" id="link-save"><?php echo htmlspecialchars(t_def('common.save', 'Mentés')); ?></button>
                <button class="btn" id="link-new"><?php echo htmlspecialchars(t_def('common.new', 'Új')); ?></button>
            </div>
        </div>
        <div class="table-wrap"><table><thead><tr><th><?php echo htmlspecialchars(t_def('common.server', 'Szerver')); ?></th><th><?php echo htmlspecialchars(t_def('common.company', 'Cég')); ?></th><th><?php echo htmlspecialchars(t_def('common.status', 'Állapot')); ?></th><th><?php echo htmlspecialchars(t_def('common.action', 'Művelet')); ?></th></tr></thead><tbody id="links-body"></tbody></table></div>
    </div>
</div>

<div class="panel" style="margin-top:12px;">
        <div class="panel-title"><?php echo htmlspecialchars(t_def('room_occ.admin.sync_example', 'Külső sync példa (termek + foglaltság)')); ?></div>
        <pre style="white-space:pre-wrap; overflow:auto; max-height:260px; background:#0f172a; color:#e2e8f0; padding:10px; border-radius:8px;">curl -X POST "../api/room_occupancy.php?action=external_sync" \
    -H "Content-Type: application/json" \
    -H "Authorization: Bearer COMPANY_API_TOKEN" \
    -d '{
        "server_key": "skolsky-sis",
        "rooms": [
            {
                "room_key": "a101",
                "room_name": "A101 Informatika",
                "capacity": 30,
                "is_active": true
            }
        ],
        "occupancies": [
            {
                "room_key": "a101",
                "room_name": "A101 Informatika",
                "event_date": "2026-02-22",
                "start_time": "08:00",
                "end_time": "09:30",
                "title": "10.A Matematika",
                "comment": "Tanár: Kovács"
            }
        ]
    }'</pre>
</div>

<div id="room-occ-admin-status" class="muted" style="margin-top:10px;"></div>

<script>
(function () {
    const api = '../api/room_occupancy.php';
    const I18N = <?php echo json_encode($room_occ_admin_i18n, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    const $ = (id) => document.getElementById(id);

    let servers = [];
    let links = [];

    function escapeHtml(value) {
        return String(value ?? '').replace(/[&<>"']/g, (m) => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' }[m]));
    }

    function normalizeServerKey(value) {
        return String(value || '').trim().toLowerCase().replace(/[^a-z0-9._-]/g, '');
    }

    function setStatus(message, isError = false) {
        const el = $('room-occ-admin-status');
        el.textContent = message || '';
        el.style.color = isError ? '#b42318' : '#475467';
    }

    async function fetchJson(url, opts) {
        const response = await fetch(url, opts);
        let payload;
        try {
            payload = await response.json();
        } catch (_) {
            throw new Error(I18N.invalidServerResponse);
        }
        if (!response.ok || !payload.success) {
            throw new Error(payload.message || I18N.errorGeneral);
        }
        return payload;
    }

    function renderServerSelect() {
        const options = [`<option value="0">-- ${escapeHtml(<?php echo json_encode(t_def('common.choose_server', 'Válassz szervert')); ?>)} --</option>`];
        servers.forEach((server) => {
            options.push(`<option value="${server.id}">${escapeHtml(server.server_name)} (${escapeHtml(server.server_key)})</option>`);
        });
        $('link-server').innerHTML = options.join('');
    }

    async function loadServers() {
        const payload = await fetchJson(`${api}?action=admin_servers`);
        servers = Array.isArray(payload.items) ? payload.items : [];
        renderServerSelect();

        $('servers-body').innerHTML = servers.map((server) => `
            <tr>
                <td>${escapeHtml(server.server_key)}</td>
                <td>${escapeHtml(server.server_name)}</td>
                <td>${Number(server.is_active) === 1 ? I18N.active : I18N.inactive}</td>
                <td><button class="btn btn-small" data-server-edit="${server.id}">${I18N.editShort}</button></td>
            </tr>
        `).join('') || `<tr><td colspan="4" class="muted">${I18N.emptyServers}</td></tr>`;

        document.querySelectorAll('[data-server-edit]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.getAttribute('data-server-edit') || '0', 10);
                const row = servers.find((server) => Number(server.id) === id);
                if (!row) return;
                $('server-id').value = String(row.id);
                $('server-key').value = row.server_key || '';
                $('server-name').value = row.server_name || '';
                $('server-url').value = row.endpoint_base_url || '';
                $('server-active').checked = Number(row.is_active || 0) === 1;
            });
        });
    }

    async function loadLinks() {
        const payload = await fetchJson(`${api}?action=admin_server_links`);
        links = Array.isArray(payload.items) ? payload.items : [];

        $('links-body').innerHTML = links.map((link) => `
            <tr>
                <td>${escapeHtml(link.server_name)} (${escapeHtml(link.server_key)})</td>
                <td>${escapeHtml(link.company_name)}</td>
                <td>${Number(link.is_active) === 1 ? I18N.active : I18N.inactive}</td>
                <td><button class="btn btn-small" data-link-edit="${link.id}">${I18N.editShort}</button></td>
            </tr>
        `).join('') || `<tr><td colspan="4" class="muted">${I18N.emptyLinks}</td></tr>`;

        document.querySelectorAll('[data-link-edit]').forEach((btn) => {
            btn.addEventListener('click', () => {
                const id = parseInt(btn.getAttribute('data-link-edit') || '0', 10);
                const row = links.find((link) => Number(link.id) === id);
                if (!row) return;
                $('link-id').value = String(row.id);
                $('link-server').value = String(row.server_id);
                $('link-company').value = String(row.company_id);
                $('link-active').checked = Number(row.is_active || 0) === 1;
            });
        });
    }

    async function saveServer() {
        const serverKey = normalizeServerKey($('server-key').value);
        const serverName = $('server-name').value.trim();
        if (!serverKey || !serverName) {
            throw new Error(I18N.errorServerRequired);
        }

        await fetchJson(`${api}?action=save_server`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: parseInt($('server-id').value || '0', 10) || 0,
                server_key: serverKey,
                server_name: serverName,
                endpoint_base_url: $('server-url').value.trim(),
                is_active: $('server-active').checked ? 1 : 0,
            })
        });

        $('server-id').value = '0';
        $('server-key').value = '';
        $('server-name').value = '';
        $('server-url').value = '';
        $('server-active').checked = true;

        await loadServers();
        await loadLinks();
        setStatus(I18N.statusServerSaved);
    }

    async function saveLink() {
        const serverId = parseInt($('link-server').value || '0', 10) || 0;
        const companyId = parseInt($('link-company').value || '0', 10) || 0;
        if (serverId <= 0 || companyId <= 0) {
            throw new Error(I18N.errorLinkRequired);
        }

        await fetchJson(`${api}?action=save_server_link`, {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                id: parseInt($('link-id').value || '0', 10) || 0,
                server_id: serverId,
                company_id: companyId,
                is_active: $('link-active').checked ? 1 : 0,
            })
        });

        $('link-id').value = '0';
        $('link-server').value = '0';
        $('link-company').value = '0';
        $('link-active').checked = true;

        await loadLinks();
        setStatus(I18N.statusLinkSaved);
    }

    $('server-save').addEventListener('click', () => saveServer().catch((error) => setStatus(error.message, true)));
    $('server-new').addEventListener('click', () => {
        $('server-id').value = '0';
        $('server-key').value = '';
        $('server-name').value = '';
        $('server-url').value = '';
        $('server-active').checked = true;
    });

    $('link-save').addEventListener('click', () => saveLink().catch((error) => setStatus(error.message, true)));
    $('link-new').addEventListener('click', () => {
        $('link-id').value = '0';
        $('link-server').value = '0';
        $('link-company').value = '0';
        $('link-active').checked = true;
    });

    loadServers()
        .then(() => loadLinks())
        .catch((error) => setStatus(error.message, true));
})();
</script>

<?php include 'footer.php'; ?>
