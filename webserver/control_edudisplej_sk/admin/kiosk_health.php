<?php
/**
 * Kiosk Health Monitoring Dashboard
 * Shows all kiosks with health status, allows control operations
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../security_config.php';

// Check if user is logged in and is admin
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
    
    // Get all kiosks with latest health data
    $result = $conn->query("
        SELECT 
            k.id, k.device_id, k.name, k.status,
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
        ORDER BY k.status DESC, k.name ASC
    ");
    
    $online_count = 0;
    $warning_count = 0;
    $offline_count = 0;
    
    while ($row = $result->fetch_assoc()) {
        $kiosks[] = $row;
        
        switch($row['status']) {
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
    
} catch (Exception $e) {
    $error = 'Database error: ' . $e->getMessage();
}
?>
<!DOCTYPE html>
<html lang="sk">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Health Monitoring - EduDisplej Admin</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .health-dashboard {
            padding: 20px;
        }
        
        .statistics-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            border-left: 4px solid #007bff;
        }
        
        .stat-card.online {
            border-left-color: #28a745;
        }
        
        .stat-card.warning {
            border-left-color: #ffc107;
        }
        
        .stat-card.offline {
            border-left-color: #dc3545;
        }
        
        .stat-number {
            font-size: 28px;
            font-weight: bold;
            color: #333;
        }
        
        .stat-label {
            font-size: 14px;
            color: #666;
            margin-top: 5px;
        }
        
        .kiosks-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        
        .kiosk-card {
            background: white;
            border-radius: 8px;
            padding: 15px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            border-top: 3px solid #007bff;
        }
        
        .kiosk-card.online {
            border-top-color: #28a745;
        }
        
        .kiosk-card.warning {
            border-top-color: #ffc107;
        }
        
        .kiosk-card.offline {
            border-top-color: #dc3545;
        }
        
        .kiosk-header {
            display: flex;
            justify-content: space-between;
            align-items: start;
            margin-bottom: 10px;
        }
        
        .kiosk-title {
            font-size: 16px;
            font-weight: bold;
            color: #333;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: bold;
            color: white;
        }
        
        .status-badge.online {
            background-color: #28a745;
        }
        
        .status-badge.warning {
            background-color: #ffc107;
            color: #333;
        }
        
        .status-badge.offline {
            background-color: #dc3545;
        }
        
        .kiosk-info {
            font-size: 12px;
            color: #666;
            margin: 8px 0;
        }
        
        .health-stats {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
            margin: 10px 0;
            padding: 10px;
            background: #f5f5f5;
            border-radius: 5px;
            font-size: 12px;
        }
        
        .health-stat {
            display: flex;
            justify-content: space-between;
        }
        
        .health-stat-label {
            color: #666;
        }
        
        .health-stat-value {
            font-weight: bold;
            color: #333;
        }
        
        .kiosk-actions {
            display: flex;
            gap: 8px;
            margin-top: 10px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
            font-weight: bold;
            transition: all 0.3s;
        }
        
        .btn-primary {
            background-color: #007bff;
            color: white;
        }
        
        .btn-primary:hover {
            background-color: #0056b3;
        }
        
        .btn-danger {
            background-color: #dc3545;
            color: white;
        }
        
        .btn-danger:hover {
            background-color: #c82333;
        }
        
        .btn-warning {
            background-color: #ffc107;
            color: #333;
        }
        
        .btn-warning:hover {
            background-color: #e0a800;
        }
        
        .btn-info {
            background-color: #17a2b8;
            color: white;
        }
        
        .btn-info:hover {
            background-color: #138496;
        }
        
        .modal {
            display: none;
            position: fixed;
            z-index: 1000;
            left: 0;
            top: 0;
            width: 100%;
            height: 100%;
            background-color: rgba(0,0,0,0.5);
        }
        
        .modal-content {
            background-color: white;
            margin: 5% auto;
            padding: 20px;
            border-radius: 8px;
            width: 80%;
            max-width: 600px;
            max-height: 80vh;
            overflow-y: auto;
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
            padding-bottom: 10px;
            border-bottom: 1px solid #ddd;
        }
        
        .modal-title {
            font-size: 18px;
            font-weight: bold;
        }
        
        .close-modal {
            font-size: 28px;
            font-weight: bold;
            color: #999;
            cursor: pointer;
        }
        
        .close-modal:hover {
            color: #000;
        }
        
        .terminal-output {
            background: #1e1e1e;
            color: #00ff00;
            padding: 10px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-size: 12px;
            max-height: 300px;
            overflow-y: auto;
            margin: 10px 0;
        }
        
        .loading {
            display: inline-block;
            width: 12px;
            height: 12px;
            background: url('data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><circle cx="50" cy="50" r="40" fill="none" stroke="%23007bff" stroke-width="3" stroke-dasharray="60,40" stroke-dashoffset="0"><animateTransform attributeName="transform" type="rotate" from="0 50 50" to="360 50 50" dur="1s" repeatCount="indefinite"/></circle></svg>') no-repeat center;
            background-size: contain;
            margin-left: 5px;
        }
    </style>
</head>
<body>
    <?php include 'header.php'; ?>
    
    <div class="health-dashboard">
        <h1>Health Monitoring - Kiosk Status</h1>
        
        <?php if ($error): ?>
            <div style="color: #dc3545; background: #f8d7da; padding: 10px; border-radius: 4px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div style="color: #155724; background: #d4edda; padding: 10px; border-radius: 4px; margin-bottom: 20px;">
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <!-- Statistics Cards -->
        <div class="statistics-grid">
            <div class="stat-card">
                <div class="stat-number"><?php echo $statistics['total']; ?></div>
                <div class="stat-label">Total Kiosks</div>
            </div>
            <div class="stat-card online">
                <div class="stat-number"><?php echo $statistics['online']; ?></div>
                <div class="stat-label">Online</div>
            </div>
            <div class="stat-card warning">
                <div class="stat-number"><?php echo $statistics['warning']; ?></div>
                <div class="stat-label">Warning</div>
            </div>
            <div class="stat-card offline">
                <div class="stat-number"><?php echo $statistics['offline']; ?></div>
                <div class="stat-label">Offline</div>
            </div>
        </div>
        
        <!-- Kiosks Grid -->
        <h2>Kiosks</h2>
        <div class="kiosks-grid">
            <?php foreach ($kiosks as $kiosk): ?>
                <?php
                    $system = json_decode($kiosk['system_data'] ?? '{}', true);
                    $services = json_decode($kiosk['services_data'] ?? '{}', true);
                    $network = json_decode($kiosk['network_data'] ?? '{}', true);
                    $sync = json_decode($kiosk['sync_data'] ?? '{}', true);
                ?>
                <div class="kiosk-card <?php echo strtolower($kiosk['status']); ?>">
                    <div class="kiosk-header">
                        <div>
                            <div class="kiosk-title"><?php echo htmlspecialchars($kiosk['name']); ?></div>
                            <div class="kiosk-info">
                                <strong>Device ID:</strong> <?php echo htmlspecialchars($kiosk['device_id']); ?><br>
                                <strong>Company:</strong> <?php echo htmlspecialchars($kiosk['company_name'] ?? 'N/A'); ?>
                            </div>
                        </div>
                        <span class="status-badge <?php echo strtolower($kiosk['status']); ?>">
                            <?php echo strtoupper($kiosk['status'] ?? 'unknown'); ?>
                        </span>
                    </div>
                    
                    <?php if ($kiosk['timestamp']): ?>
                        <div class="kiosk-info">
                            <strong>Last Update:</strong> <?php echo htmlspecialchars($kiosk['timestamp']); ?>
                        </div>
                        
                        <!-- Health Stats -->
                        <div class="health-stats">
                            <div class="health-stat">
                                <span class="health-stat-label">CPU Temp:</span>
                                <span class="health-stat-value"><?php echo htmlspecialchars($system['cpu_temp'] ?? 'N/A'); ?>°C</span>
                            </div>
                            <div class="health-stat">
                                <span class="health-stat-label">CPU Usage:</span>
                                <span class="health-stat-value"><?php echo number_format($system['cpu_usage'] ?? 0, 1); ?>%</span>
                            </div>
                            <div class="health-stat">
                                <span class="health-stat-label">Memory:</span>
                                <span class="health-stat-value"><?php echo number_format($system['memory_usage'] ?? 0, 1); ?>%</span>
                            </div>
                            <div class="health-stat">
                                <span class="health-stat-label">Disk:</span>
                                <span class="health-stat-value"><?php echo htmlspecialchars($system['disk_usage'] ?? 0); ?>%</span>
                            </div>
                        </div>
                    <?php endif; ?>
                    
                    <!-- Actions -->
                    <div class="kiosk-actions">
                        <button class="btn btn-info" onclick="openTerminal(<?php echo $kiosk['id']; ?>, '<?php echo htmlspecialchars($kiosk['name']); ?>')">
                            🖥️ Terminal
                        </button>
                        <button class="btn btn-warning" onclick="toggleFastLoop(<?php echo $kiosk['id']; ?>, this)">
                            ⚡ Fast Loop
                        </button>
                        <button class="btn btn-danger" onclick="rebootKiosk(<?php echo $kiosk['id']; ?>, '<?php echo htmlspecialchars($kiosk['name']); ?>')">
                            🔄 Reboot
                        </button>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    
    <!-- Terminal Modal -->
    <div id="terminalModal" class="modal">
        <div class="modal-content">
            <div class="modal-header">
                <div class="modal-title">Terminal - <span id="terminalKioskName"></span></div>
                <span class="close-modal" onclick="closeTerminal()">&times;</span>
            </div>
            <div>
                <div style="margin-bottom: 10px;">
                    <input type="text" id="commandInput" placeholder="Enter command..." style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                </div>
                <button class="btn btn-primary" onclick="executeCommand()" style="width: 100%;">Execute</button>
                <div id="terminalOutput" class="terminal-output"></div>
            </div>
        </div>
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
            output.innerHTML += `<div style="color: #00ff00;">$ ${escapeHtml(command)}</div>`;
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
                    output.innerHTML += `<div style="color: #ffff00;">Command queued (ID: ${commandId})...</div>`;
                    
                    // Poll for result
                    pollCommandResult(commandId);
                } else {
                    output.innerHTML += `<div style="color: #ff0000;">Error: ${escapeHtml(data.message)}</div>`;
                }
                output.scrollTop = output.scrollHeight;
            });
        }
        
        function pollCommandResult(commandId) {
            setTimeout(() => {
                fetch(`/api/kiosk/get_command_result.php?command_id=${commandId}`)
                    .then(r => r.json())
                    .then(data => {
                        if (data.success) {
                            const cmd = data.command;
                            const output = document.getElementById('terminalOutput');
                            
                            if (cmd.status === 'executed') {
                                if (cmd.output) {
                                    output.innerHTML += `<div style="color: #00ff00;">${escapeHtml(cmd.output)}</div>`;
                                }
                                terminalCommands.delete(commandId);
                            } else if (cmd.status === 'failed') {
                                output.innerHTML += `<div style="color: #ff0000;">Error: ${escapeHtml(cmd.error)}</div>`;
                                terminalCommands.delete(commandId);
                            } else if (cmd.status === 'timeout') {
                                output.innerHTML += `<div style="color: #ff6600;">Timeout: ${escapeHtml(cmd.error)}</div>`;
                                terminalCommands.delete(commandId);
                            } else if (cmd.status === 'pending') {
                                output.innerHTML += `<div style="color: #ffff00;">Still executing...</div>`;
                                // Continue polling
                                if (terminalCommands.has(commandId)) {
                                    pollCommandResult(commandId);
                                }
                            }
                            output.scrollTop = output.scrollHeight;
                        }
                    });
            }, 1000);
        }
        
        function toggleFastLoop(kioskId, btn) {
            const enable = !btn.dataset.enabled;
            
            fetch('/api/kiosk/control_fast_loop.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    kiosk_id: kioskId,
                    enable: enable
                })
            })
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    btn.dataset.enabled = enable;
                    btn.textContent = enable ? '⚡ Fast Loop (ON)' : '⚡ Fast Loop (OFF)';
                    btn.style.backgroundColor = enable ? '#ffc107' : '';
                    alert(enable ? 'Fast loop enabled' : 'Fast loop disabled');
                } else {
                    alert('Error: ' + data.message);
                }
            });
        }
        
        function rebootKiosk(kioskId, kioskName) {
            if (confirm(`Reboot ${kioskName}?`)) {
                fetch('/api/kiosk/reboot.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        kiosk_id: kioskId,
                        delay: 0
                    })
                })
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        alert('Reboot command queued');
                    } else {
                        alert('Error: ' + data.message);
                    }
                });
            }
        }
        
        function escapeHtml(text) {
            const div = document.createElement('div');
            div.textContent = text;
            return div.innerHTML;
        }
        
        window.onclick = function(event) {
            const modal = document.getElementById('terminalModal');
            if (event.target == modal) {
                closeTerminal();
            }
        }
    </script>
    
    <?php include 'footer.php'; ?>
</body>
</html>
