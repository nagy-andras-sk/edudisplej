<?php
/**
 * Kiosk Logs Viewer - Minimal Table
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

$kiosk_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$conn = getDbConnection();

$kiosk = null;
if ($kiosk_id > 0) {
    $stmt = $conn->prepare("SELECT k.id, k.device_id, k.hostname, k.mac, c.name as company_name FROM kiosks k LEFT JOIN companies c ON k.company_id = c.id WHERE k.id = ?");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $kiosk = $result->fetch_assoc();
    $stmt->close();
}

$logs = [];
if ($kiosk_id > 0) {
    $log_type = $_GET['type'] ?? 'all';
    $log_level = $_GET['level'] ?? 'all';
    $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 100;

    $sql = "SELECT * FROM kiosk_logs WHERE kiosk_id = ?";
    $params = [$kiosk_id];
    $types = "i";

    if ($log_type !== 'all') {
        $sql .= " AND log_type = ?";
        $params[] = $log_type;
        $types .= "s";
    }

    if ($log_level !== 'all') {
        $sql .= " AND log_level = ?";
        $params[] = $log_level;
        $types .= "s";
    }

    $sql .= " ORDER BY created_at DESC LIMIT ?";
    $params[] = $limit;
    $types .= "i";

    $stmt = $conn->prepare($sql);
    $stmt->bind_param($types, ...$params);
    $stmt->execute();
    $result = $stmt->get_result();

    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();
}

include 'header.php';
?>

<div class="panel">
    <div class="page-title">Kiosk Logok</div>
    <div class="muted"><?php echo $kiosk ? htmlspecialchars($kiosk['hostname']) : 'Ismeretlen kiosk'; ?></div>
</div>

<?php if ($kiosk): ?>
    <div class="panel">
        <div class="panel-title">Kiosk info</div>
        <div class="table-wrap">
            <table>
                <tbody>
                    <tr><th>ID</th><td><?php echo (int)$kiosk['id']; ?></td></tr>
                    <tr><th>Device ID</th><td class="mono"><?php echo htmlspecialchars($kiosk['device_id'] ?? '-'); ?></td></tr>
                    <tr><th>Hostname</th><td><?php echo htmlspecialchars($kiosk['hostname'] ?? '-'); ?></td></tr>
                    <tr><th>Company</th><td><?php echo htmlspecialchars($kiosk['company_name'] ?? '-'); ?></td></tr>
                    <tr><th>MAC</th><td class="mono"><?php echo htmlspecialchars($kiosk['mac'] ?? '-'); ?></td></tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="panel">
        <div class="panel-title">Szurok</div>
        <form method="get" class="toolbar">
            <input type="hidden" name="id" value="<?php echo $kiosk_id; ?>">
            <div class="form-field">
                <label for="type">Type</label>
                <select id="type" name="type">
                    <option value="all" <?php echo $log_type === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="sync" <?php echo $log_type === 'sync' ? 'selected' : ''; ?>>Sync</option>
                    <option value="systemd" <?php echo $log_type === 'systemd' ? 'selected' : ''; ?>>Systemd</option>
                    <option value="general" <?php echo $log_type === 'general' ? 'selected' : ''; ?>>General</option>
                </select>
            </div>
            <div class="form-field">
                <label for="level">Level</label>
                <select id="level" name="level">
                    <option value="all" <?php echo $log_level === 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="error" <?php echo $log_level === 'error' ? 'selected' : ''; ?>>Error</option>
                    <option value="warning" <?php echo $log_level === 'warning' ? 'selected' : ''; ?>>Warning</option>
                    <option value="info" <?php echo $log_level === 'info' ? 'selected' : ''; ?>>Info</option>
                </select>
            </div>
            <div class="form-field">
                <label for="limit">Limit</label>
                <select id="limit" name="limit">
                    <option value="50" <?php echo $limit === 50 ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo $limit === 100 ? 'selected' : ''; ?>>100</option>
                    <option value="200" <?php echo $limit === 200 ? 'selected' : ''; ?>>200</option>
                    <option value="500" <?php echo $limit === 500 ? 'selected' : ''; ?>>500</option>
                </select>
            </div>
            <div class="form-field">
                <button type="submit" class="btn btn-primary">Szures</button>
            </div>
            <div class="form-field">
                <a href="?id=<?php echo $kiosk_id; ?>" class="btn btn-secondary">Reset</a>
            </div>
        </form>
    </div>

    <div class="panel">
        <div class="panel-title">Logok (<?php echo count($logs); ?>)</div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>Time</th>
                        <th>Type</th>
                        <th>Level</th>
                        <th>Message</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (count($logs) > 0): ?>
                        <?php foreach ($logs as $log): ?>
                            <tr>
                                <td class="nowrap"><?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?></td>
                                <td><?php echo htmlspecialchars($log['log_type']); ?></td>
                                <td><?php echo htmlspecialchars($log['log_level']); ?></td>
                                <td class="mono">
                                    <?php echo htmlspecialchars($log['message']); ?>
                                    <?php if (!empty($log['details'])): ?>
                                        <div class="muted" style="margin-top: 6px;"><?php echo htmlspecialchars($log['details']); ?></div>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <tr>
                            <td colspan="4" class="muted">Nincs log.</td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php else: ?>
    <div class="panel">
        <div class="muted">Kiosk not found.</div>
    </div>
<?php endif; ?>

<?php include 'footer.php'; ?>
