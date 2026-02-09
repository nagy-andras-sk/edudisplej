<?php
/**
 * Kiosk Health Monitoring - Minimal Table
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../security_config.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';
$kiosks = [];
$statistics = [];

try {
    $conn = getDbConnection();

    $result = $conn->query("
        SELECT 
            k.id, k.device_id, k.hostname, k.status,
            k.company_id, c.name as company_name,
            h.status as health_status,
            h.system_data,
            h.services_data,
            h.network_data,
            h.sync_data,
            h.timestamp
        FROM kiosks k
        LEFT JOIN companies c ON k.company_id = c.id
        LEFT JOIN kiosk_health h ON k.id = h.kiosk_id AND h.timestamp = (
            SELECT MAX(timestamp) FROM kiosk_health WHERE kiosk_id = k.id
        )
        ORDER BY k.status DESC, k.hostname ASC
    ");

    $online_count = 0;
    $warning_count = 0;
    $offline_count = 0;

    while ($row = $result->fetch_assoc()) {
        $kiosks[] = $row;
        switch ($row['status']) {
            case 'online':
                $online_count++;
                break;
            case 'warning':
                $warning_count++;
                break;
            case 'offline':
                $offline_count++;
                break;
        }
    }

    $statistics = [
        'total' => count($kiosks),
        'online' => $online_count,
        'warning' => $warning_count,
        'offline' => $offline_count
    ];

    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Database error: ' . $e->getMessage();
}

function format_last_reboot($uptime_seconds) {
    if ($uptime_seconds === null || $uptime_seconds === '') {
        return '-';
    }
    $uptime_seconds = (int)$uptime_seconds;
    if ($uptime_seconds <= 0) {
        return '-';
    }
    return date('Y-m-d H:i:s', time() - $uptime_seconds);
}

include 'header.php';
?>

<div class="panel">
    <div class="page-title">Kiosk Health</div>
    <div class="muted">Allapot, terheles, halozat, sync adatok.</div>
</div>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title">Statisztika</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>Osszes</th>
                    <th>Online</th>
                    <th>Warning</th>
                    <th>Offline</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo (int)$statistics['total']; ?></td>
                    <td><?php echo (int)$statistics['online']; ?></td>
                    <td><?php echo (int)$statistics['warning']; ?></td>
                    <td><?php echo (int)$statistics['offline']; ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-title">Kioskok</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Hostname</th>
                    <th>Device ID</th>
                    <th>Ceg</th>
                    <th>Statusz</th>
                    <th>Health status</th>
                    <th>Last update</th>
                    <th>CPU temp</th>
                    <th>CPU %</th>
                    <th>RAM %</th>
                    <th>Disk %</th>
                    <th>Last reboot</th>
                    <th>Muvelet</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($kiosks)): ?>
                    <tr>
                        <td colspan="13" class="muted">Nincs adat.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($kiosks as $kiosk): ?>
                        <?php
                            $system = json_decode($kiosk['system_data'] ?? '{}', true) ?: [];
                            $status = $kiosk['status'] ?? 'unknown';
                            $badge_class = 'info';
                            if ($status === 'online') {
                                $badge_class = 'success';
                            } elseif ($status === 'warning') {
                                $badge_class = 'warning';
                            } elseif ($status === 'offline') {
                                $badge_class = 'danger';
                            }
                        ?>
                        <tr>
                            <td><?php echo (int)$kiosk['id']; ?></td>
                            <td><?php echo htmlspecialchars($kiosk['hostname'] ?? '-'); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($kiosk['device_id'] ?? '-'); ?></td>
                            <td><?php echo htmlspecialchars($kiosk['company_name'] ?? '-'); ?></td>
                            <td><span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                            <td><?php echo htmlspecialchars($kiosk['health_status'] ?? '-'); ?></td>
                            <td class="nowrap"><?php echo $kiosk['timestamp'] ? date('Y-m-d H:i:s', strtotime($kiosk['timestamp'])) : '-'; ?></td>
                            <td><?php echo htmlspecialchars($system['cpu_temp'] ?? '-'); ?></td>
                            <td><?php echo isset($system['cpu_usage']) ? number_format((float)$system['cpu_usage'], 1) . '%' : '-'; ?></td>
                            <td><?php echo isset($system['memory_usage']) ? number_format((float)$system['memory_usage'], 1) . '%' : '-'; ?></td>
                            <td><?php echo isset($system['disk_usage']) ? htmlspecialchars((string)$system['disk_usage']) . '%' : '-'; ?></td>
                            <td class="nowrap"><?php echo format_last_reboot($system['uptime'] ?? null); ?></td>
                            <td class="nowrap">
                                <button class="btn btn-small" onclick="openTerminal(<?php echo (int)$kiosk['id']; ?>, '<?php echo htmlspecialchars($kiosk['hostname'] ?? 'kiosk'); ?>')">Terminal</button>
                                <button class="btn btn-small btn-warning" onclick="toggleFastLoop(<?php echo (int)$kiosk['id']; ?>, this)">Fast Loop</button>
                                <button class="btn btn-small btn-danger" onclick="rebootKiosk(<?php echo (int)$kiosk['id']; ?>, '<?php echo htmlspecialchars($kiosk['hostname'] ?? 'kiosk'); ?>')">Reboot</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<div id="terminalModal" class="panel" style="display:none; position: fixed; top: 10%; left: 50%; transform: translateX(-50%); width: 90%; max-width: 800px; z-index: 9999;">
    <div class="panel-title">Terminal - <span id="terminalKioskName"></span></div>
    <div class="form-row" style="margin-bottom: 8px;">
        <div class="form-field" style="flex: 1;">
            <label for="commandInput">Parancs</label>
            <input type="text" id="commandInput" placeholder="command">
        </div>
        <div class="form-field">
            <button class="btn btn-primary" onclick="executeCommand()">Execute</button>
            <button class="btn btn-secondary" onclick="closeTerminal()">Close</button>
        </div>
    </div>
    <div id="terminalOutput" class="mono" style="background: #101010; color: #d6f5d6; padding: 10px; height: 300px; overflow: auto;"></div>
</div>

<script>
    let currentKioskId = null;
    let terminalCommands = new Map();

    function openTerminal(kioskId, kioskName) {
        currentKioskId = kioskId;
        document.getElementById('terminalKioskName').textContent = kioskName;
        document.getElementById('commandInput').value = '';
        document.getElementById('terminalOutput').innerHTML = '';
        document.getElementById('terminalModal').style.display = 'block';
    }

    function closeTerminal() {
        document.getElementById('terminalModal').style.display = 'none';
    }

    function executeCommand() {
        if (!currentKioskId) return;

        const command = document.getElementById('commandInput').value.trim();
        if (!command) return;

        const output = document.getElementById('terminalOutput');
        output.innerHTML += `<div>$ ${escapeHtml(command)}</div>`;
        document.getElementById('commandInput').value = '';

        fetch('/api/kiosk/execute_command.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                kiosk_id: currentKioskId,
                command: command,
                command_type: 'custom'
            })
        })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                const commandId = data.command_id;
                terminalCommands.set(commandId, true);
                output.innerHTML += `<div>Queued (ID: ${commandId})...</div>`;
                pollCommandResult(commandId);
            } else {
                output.innerHTML += `<div>Error: ${escapeHtml(data.message)}</div>`;
            }
            output.scrollTop = output.scrollHeight;
        });
    }

    function pollCommandResult(commandId) {
        setTimeout(() => {
            fetch(`/api/kiosk/get_command_result.php?command_id=${commandId}`)
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        return;
                    }

                    const output = document.getElementById('terminalOutput');
                    const cmd = data.command;
                    if (cmd.status === 'completed' || cmd.status === 'failed') {
                        terminalCommands.delete(commandId);
                        const result = cmd.output || cmd.error || '(no output)';
                        output.innerHTML += `<div>${escapeHtml(result)}</div>`;
                        output.scrollTop = output.scrollHeight;
                    } else if (terminalCommands.has(commandId)) {
                        pollCommandResult(commandId);
                    }
                });
        }, 2000);
    }

    function toggleFastLoop(kioskId, btn) {
        fetch('/api/kiosk/control_fast_loop.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ kiosk_id: kioskId })
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Failed: ' + data.message);
                return;
            }
            const enable = data.enabled;
            btn.textContent = enable ? 'Fast Loop ON' : 'Fast Loop OFF';
        })
        .catch(() => alert('Network error'));
    }

    function rebootKiosk(kioskId, kioskName) {
        if (!confirm(`Reboot ${kioskName}?`)) {
            return;
        }
        fetch('/api/kiosk/reboot.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ kiosk_id: kioskId })
        })
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                alert('Failed: ' + data.message);
            }
        })
        .catch(() => alert('Network error'));
    }

    function escapeHtml(str) {
        return str.replace(/[&<>"]/g, function (tag) {
            const chars = {
                '&': '&amp;',
                '<': '&lt;',
                '>': '&gt;',
                '"': '&quot;'
            };
            return chars[tag] || tag;
        });
    }
</script>

<?php include 'footer.php'; ?>
