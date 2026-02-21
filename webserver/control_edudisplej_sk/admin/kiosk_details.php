<?php
/**
 * Kiosk Details - Minimal
 */

session_start();
require_once '../dbkonfiguracia.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: index.php');
    exit();
}

$kiosk_id = (int)($_GET['id'] ?? 0);
$kiosk = null;
$logs = [];

if ($kiosk_id > 0) {
    try {
        $conn = getDbConnection();

        $stmt = $conn->prepare("SELECT k.*, c.name as company_name FROM kiosks k LEFT JOIN companies c ON k.company_id = c.id WHERE k.id = ?");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $kiosk = $result->fetch_assoc();
        $stmt->close();

        if ($kiosk) {
            $stmt = $conn->prepare("SELECT * FROM sync_logs WHERE kiosk_id = ? ORDER BY timestamp DESC LIMIT 20");
            $stmt->bind_param("i", $kiosk_id);
            $stmt->execute();
            $result = $stmt->get_result();
            while ($row = $result->fetch_assoc()) {
                $logs[] = $row;
            }
            $stmt->close();
        }

        closeDbConnection($conn);
    } catch (Exception $e) {
        error_log($e->getMessage());
    }
}

if (!$kiosk) {
    header('Location: dashboard.php');
    exit();
}

// Load latest system version from versions.json
$latest_system_version = '1.0.0';
$versions_file = dirname(__DIR__) . '/install/init/versions.json';
if (file_exists($versions_file)) {
    $versions_data = json_decode(file_get_contents($versions_file), true);
    if (!empty($versions_data['system_version'])) {
        $latest_system_version = $versions_data['system_version'];
    }
}

$kiosk_version = $kiosk['version'] ?? null;
$update_available = $kiosk_version !== null && version_compare($kiosk_version, $latest_system_version, '<');

include 'header.php';
?>

<div class="panel">
    <div class="page-title">Kiosk reszletek</div>
    <div class="muted"><?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk'); ?></div>
</div>

<div class="panel">
    <div class="panel-title">Alap adatok</div>
    <div class="table-wrap">
        <table>
            <tbody>
                <tr><th>ID</th><td><?php echo (int)$kiosk['id']; ?></td></tr>
                <tr><th>Hostname</th><td><?php echo htmlspecialchars($kiosk['hostname'] ?? '-'); ?></td></tr>
                <tr><th>Device ID</th><td class="mono"><?php echo htmlspecialchars($kiosk['device_id'] ?? '-'); ?></td></tr>
                <tr><th>MAC</th><td class="mono"><?php echo htmlspecialchars($kiosk['mac'] ?? '-'); ?></td></tr>
                <tr><th>Public IP</th><td><?php echo htmlspecialchars($kiosk['public_ip'] ?? '-'); ?></td></tr>
                <tr><th>Company</th><td><?php echo htmlspecialchars($kiosk['company_name'] ?? '-'); ?></td></tr>
                <tr><th>Location</th><td><?php echo htmlspecialchars($kiosk['location'] ?? '-'); ?></td></tr>
                <tr><th>Installed</th><td><?php echo $kiosk['installed'] ? date('Y-m-d H:i:s', strtotime($kiosk['installed'])) : '-'; ?></td></tr>
                <tr><th>Last seen</th><td><?php echo $kiosk['last_seen'] ? date('Y-m-d H:i:s', strtotime($kiosk['last_seen'])) : '-'; ?></td></tr>
                <tr><th>Sync interval</th><td><?php echo htmlspecialchars((string)$kiosk['sync_interval']); ?> sec</td></tr>
                <tr><th>Status</th><td><?php echo htmlspecialchars($kiosk['status'] ?? '-'); ?></td></tr>
                <tr><th>Comment</th><td><?php echo htmlspecialchars($kiosk['comment'] ?? '-'); ?></td></tr>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-title">Verzió és frissítés</div>
    <div class="table-wrap">
        <table>
            <tbody>
                <tr>
                    <th>Jelenlegi verzió</th>
                    <td><?php echo $kiosk_version ? htmlspecialchars($kiosk_version) : '<span class="muted">Ismeretlen</span>'; ?></td>
                </tr>
                <tr>
                    <th>Legújabb verzió</th>
                    <td><?php echo htmlspecialchars($latest_system_version); ?></td>
                </tr>
                <tr>
                    <th>Státusz</th>
                    <td>
                        <?php if ($kiosk_version === null): ?>
                            <span style="color:#888;">Ismeretlen</span>
                        <?php elseif ($update_available): ?>
                            <span style="color:#b45309;font-weight:600;">⬆ Frissítés elérhető</span>
                        <?php else: ?>
                            <span style="color:#166534;font-weight:600;">✓ Naprakész</span>
                        <?php endif; ?>
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
    <?php if ($update_available): ?>
        <div style="margin-top:12px;">
            <button id="btn-full-update" onclick="queueFullUpdate(<?php echo (int)$kiosk['id']; ?>)"
                style="background:#b45309;color:white;border:none;padding:8px 18px;border-radius:4px;cursor:pointer;font-size:14px;">
                ⬆ Frissítés indítása
            </button>
            <span id="update-msg" style="margin-left:10px;font-size:13px;color:#555;"></span>
        </div>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-title">Fast loop / gyors szinkron</div>
    <p style="font-size:13px;color:#555;margin-bottom:12px;">
        Alap szinkron: 5 perc. Fast loop módban: 30 másodperc.<br>
        Hasznos ha a control panelen aktív a felhasználó (pl. screenshot megtekintése).
    </p>
    <div>
        <button id="btn-fast-loop-on" onclick="setFastLoop(<?php echo (int)$kiosk['id']; ?>, true)"
            style="background:#1e40af;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-size:13px;margin-right:8px;">
            ⚡ Fast loop BE
        </button>
        <button id="btn-fast-loop-off" onclick="setFastLoop(<?php echo (int)$kiosk['id']; ?>, false)"
            style="background:#6b7280;color:white;border:none;padding:8px 16px;border-radius:4px;cursor:pointer;font-size:13px;">
            ⏸ Fast loop KI
        </button>
        <span id="fast-loop-msg" style="margin-left:10px;font-size:13px;color:#555;"></span>
    </div>
</div>

<div class="panel">
    <div class="panel-title">Hardware info</div>
    <?php if (!empty($kiosk['hw_info'])): ?>
        <pre class="mono" style="white-space: pre-wrap;"><?php echo htmlspecialchars(json_encode(json_decode($kiosk['hw_info']), JSON_PRETTY_PRINT)); ?></pre>
    <?php else: ?>
        <div class="muted">Nincs adat.</div>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-title">Screenshot</div>
    <?php if (!empty($kiosk['screenshot_url']) && file_exists($kiosk['screenshot_url'])): ?>
        <div>
            <img src="<?php echo htmlspecialchars($kiosk['screenshot_url']); ?>" alt="Kiosk Screenshot" style="max-width: 100%; border: 1px solid #ccc;">
        </div>
        <div class="muted" style="margin-top: 6px;">
            Last updated: <?php echo date('Y-m-d H:i', filemtime($kiosk['screenshot_url'])); ?>
        </div>
    <?php else: ?>
        <div class="muted">Nincs screenshot.</div>
    <?php endif; ?>
</div>

<?php if (!empty($logs)): ?>
    <div class="panel">
        <div class="panel-title">Recent sync logs</div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Timestamp</th>
                        <th>Action</th>
                        <th>Details</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td class="nowrap"><?php echo date('Y-m-d H:i:s', strtotime($log['timestamp'])); ?></td>
                            <td><?php echo htmlspecialchars($log['action'] ?? '-'); ?></td>
                            <td class="mono"><?php echo htmlspecialchars(substr($log['details'] ?? '', 0, 200)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<script>
function queueFullUpdate(kioskId) {
    if (!confirm('Elindítja a teljes frissítést ezen a kioskon? A frissítés a következő parancskezelő ciklusban fut le.')) return;
    var btn = document.getElementById('btn-full-update');
    var msg = document.getElementById('update-msg');
    btn.disabled = true;
    msg.textContent = 'Küldés...';
    fetch('../api/kiosk/queue_full_update.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({kiosk_id: kioskId})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            msg.textContent = '✓ Frissítési parancs elküldve (ID: ' + data.command_id + ')';
            msg.style.color = '#166534';
        } else {
            msg.textContent = '⚠ Hiba: ' + data.message;
            msg.style.color = '#b91c1c';
            btn.disabled = false;
        }
    })
    .catch(function(e) {
        msg.textContent = '⚠ Hálózati hiba';
        msg.style.color = '#b91c1c';
        btn.disabled = false;
    });
}

function setFastLoop(kioskId, enable) {
    var msg = document.getElementById('fast-loop-msg');
    msg.textContent = 'Küldés...';
    msg.style.color = '#555';
    fetch('../api/kiosk/control_fast_loop.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/json'},
        body: JSON.stringify({kiosk_id: kioskId, enable: enable})
    })
    .then(function(r) { return r.json(); })
    .then(function(data) {
        if (data.success) {
            msg.textContent = enable ? '✓ Fast loop bekapcsolva' : '✓ Fast loop kikapcsolva';
            msg.style.color = '#166534';
        } else {
            msg.textContent = '⚠ Hiba: ' + data.message;
            msg.style.color = '#b91c1c';
        }
    })
    .catch(function(e) {
        msg.textContent = '⚠ Hálózati hiba';
        msg.style.color = '#b91c1c';
    });
}
</script>

<?php include 'footer.php'; ?>

