<?php
/**
 * Kiosk Health Monitoring - Minimal Table
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once '../security_config.php';
require_once '../kiosk_status.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';
$kiosks = [];
$statistics = [];

function pick_value($data, $keys) {
    if (!is_array($data)) {
        return null;
    }
    foreach ($keys as $key) {
        if (isset($data[$key]) && $data[$key] !== '' && $data[$key] !== null) {
            return $data[$key];
        }
    }
    return null;
}

function format_percent($value) {
    if ($value === null || $value === '') {
        return '-';
    }
    if (is_numeric($value)) {
        return number_format((float)$value, 1) . '%';
    }
    $text = trim((string)$value);
    return $text === '' ? '-' : $text;
}

function format_temperature($value) {
    if ($value === null || $value === '') {
        return '-';
    }
    if (is_numeric($value)) {
        return number_format((float)$value, 1) . '°C';
    }
    $text = trim((string)$value);
    return $text === '' ? '-' : $text;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['queue_full_update_all'])) {
    try {
        $conn = getDbConnection();

        $result = $conn->query("SELECT id FROM kiosks ORDER BY id ASC");
        if (!$result) {
            throw new Exception('Kiosks lekérdezése sikertelen.');
        }

        $pending_stmt = $conn->prepare("SELECT id FROM kiosk_command_queue WHERE kiosk_id = ? AND command_type = 'full_update' AND status = 'pending' LIMIT 1");
        $insert_stmt = $conn->prepare("INSERT INTO kiosk_command_queue (kiosk_id, command_type, command, status, created_at) VALUES (?, 'full_update', '', 'pending', NOW())");
        $log_stmt = $conn->prepare("INSERT INTO kiosk_command_logs (kiosk_id, command_id, action, details) VALUES (?, ?, 'full_update_queued_bulk', ?)");

        if (!$pending_stmt || !$insert_stmt || !$log_stmt) {
            throw new Exception('Parancs-előkészítés sikertelen.');
        }

        $total = 0;
        $queued = 0;
        $skipped_pending = 0;

        while ($row = $result->fetch_assoc()) {
            $kiosk_id = (int)($row['id'] ?? 0);
            if ($kiosk_id <= 0) {
                continue;
            }

            $total++;

            $pending_stmt->bind_param('i', $kiosk_id);
            $pending_stmt->execute();
            $pending_result = $pending_stmt->get_result();
            if ($pending_result && $pending_result->num_rows > 0) {
                $skipped_pending++;
                continue;
            }

            $insert_stmt->bind_param('i', $kiosk_id);
            if (!$insert_stmt->execute()) {
                continue;
            }

            $command_id = (int)$insert_stmt->insert_id;
            $details = json_encode([
                'requested_by_user_id' => $_SESSION['user_id'] ?? null,
                'queued_at' => date('Y-m-d H:i:s'),
                'mode' => 'bulk'
            ]);
            $log_stmt->bind_param('iis', $kiosk_id, $command_id, $details);
            $log_stmt->execute();
            $queued++;
        }

        $pending_stmt->close();
        $insert_stmt->close();
        $log_stmt->close();
        closeDbConnection($conn);

        $success = 'Teljes frissítési parancsok sorba állítva. Összes kioszk: ' . $total
            . ', új parancs: ' . $queued
            . ', már függőben volt: ' . $skipped_pending . '.';
    } catch (Exception $e) {
        $error = 'Bulk full update hiba: ' . $e->getMessage();
    }
}

try {
    $conn = getDbConnection();

    $result = $conn->query("
        SELECT 
            k.id, k.device_id, k.hostname, k.status,
            k.last_sync, k.last_seen, k.last_heartbeat, k.upgrade_started_at,
            k.company_id, c.name as company_name,
            k.hw_info,
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
        kiosk_apply_effective_status($row);
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

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>
<?php if ($success): ?>
    <div class="alert success"><?php echo htmlspecialchars($success); ?></div>
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
    <div class="panel-title">Rendszer frissítés</div>
    <form method="post" onsubmit="return confirm('Biztosan teljes frissítési parancsot küld minden kioszkra?');" style="display:flex; gap:10px; align-items:center;">
        <button type="submit" name="queue_full_update_all" class="btn btn-warning">Teljes frissítés küldése minden kioszkra</button>
        <span class="muted">A már függőben lévő full update parancsokat a rendszer kihagyja.</span>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Kiosks</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Hostname</th>
                    <th>Device ID</th>
                    <th>Institution</th>
                    <th>Status</th>
                    <th>Health status</th>
                    <th>Last update</th>
                    <th>CPU temp</th>
                    <th>CPU %</th>
                    <th>RAM %</th>
                    <th>Disk %</th>
                    <th>Last reboot</th>
                    <th>Action</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($kiosks)): ?>
                    <tr>
                        <td colspan="13" class="muted">No data.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($kiosks as $kiosk): ?>
                        <?php
                            $system = json_decode($kiosk['system_data'] ?? '{}', true) ?: [];
                            $hw_info = json_decode($kiosk['hw_info'] ?? '{}', true) ?: [];

                            $cpu_temp_value = pick_value($system, ['cpu_temp', 'temperature', 'temp']);
                            if ($cpu_temp_value === null) {
                                $cpu_temp_value = pick_value($hw_info, ['cpu_temp', 'temperature', 'temp']);
                            }

                            $cpu_usage_value = pick_value($system, ['cpu_usage']);
                            if ($cpu_usage_value === null) {
                                $cpu_usage_value = pick_value($hw_info, ['cpu_usage']);
                            }

                            $memory_usage_value = pick_value($system, ['memory_usage']);
                            if ($memory_usage_value === null) {
                                $memory_usage_value = pick_value($hw_info, ['memory_usage']);
                            }

                            $disk_usage_value = pick_value($system, ['disk_usage']);
                            if ($disk_usage_value === null) {
                                $disk_usage_value = pick_value($hw_info, ['disk_usage']);
                            }

                            $uptime_value = pick_value($system, ['uptime', 'uptime_seconds']);
                            if ($uptime_value === null) {
                                $uptime_value = pick_value($hw_info, ['uptime_seconds']);
                            }

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
                            <td><?php echo htmlspecialchars(format_temperature($cpu_temp_value)); ?></td>
                            <td><?php echo htmlspecialchars(format_percent($cpu_usage_value)); ?></td>
                            <td><?php echo htmlspecialchars(format_percent($memory_usage_value)); ?></td>
                            <td><?php echo htmlspecialchars(format_percent($disk_usage_value)); ?></td>
                            <td class="nowrap"><?php echo htmlspecialchars(format_last_reboot($uptime_value)); ?></td>
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
                    if (cmd.status === 'completed' || cmd.status === 'executed' || cmd.status === 'failed' || cmd.status === 'timeout') {
                        terminalCommands.delete(commandId);
                        const result = cmd.output || cmd.error || '(no output)';
                        output.innerHTML += `<div>[${escapeHtml(String(cmd.status || 'unknown'))}] ${escapeHtml(result)}</div>`;
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
