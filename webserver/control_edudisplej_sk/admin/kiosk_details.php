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

<?php include 'footer.php'; ?>
