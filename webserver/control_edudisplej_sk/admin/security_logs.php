<?php
/**
 * Security Logs - Minimal Table
 */

session_start();
require_once '../dbkonfiguracia.php';

if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';

$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

$filter_event = $_GET['event'] ?? '';
$filter_username = $_GET['username'] ?? '';
$filter_date = $_GET['date'] ?? '';

$logs = [];
$total_logs = 0;
$event_types = [];
$stats = [
    'failed_logins_24h' => 0,
    'failed_logins_7d' => 0,
    'password_changes' => 0,
    'otp_setups' => 0
];

try {
    $conn = getDbConnection();

    $table_check = $conn->query("SHOW TABLES LIKE 'security_logs'");
    if ($table_check->num_rows === 0) {
        $create_table = "
        CREATE TABLE IF NOT EXISTS security_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            event_type VARCHAR(50) NOT NULL,
            user_id INT NULL,
            username VARCHAR(100) NOT NULL,
            ip_address VARCHAR(45) NOT NULL,
            user_agent TEXT NULL,
            details TEXT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_event_type (event_type),
            INDEX idx_user (user_id),
            INDEX idx_timestamp (timestamp),
            INDEX idx_username (username),
            FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";

        if ($conn->query($create_table)) {
            $success = 'Security logs table created successfully';
        }
    }

    $result = $conn->query("SELECT DISTINCT event_type FROM security_logs ORDER BY event_type");
    while ($row = $result->fetch_assoc()) {
        $event_types[] = $row['event_type'];
    }

    $where_clauses = [];
    $params = [];
    $types = '';

    if ($filter_event !== '') {
        $where_clauses[] = "event_type = ?";
        $params[] = $filter_event;
        $types .= 's';
    }

    if ($filter_username !== '') {
        $where_clauses[] = "username LIKE ?";
        $params[] = "%$filter_username%";
        $types .= 's';
    }

    if ($filter_date !== '') {
        $where_clauses[] = "DATE(timestamp) = ?";
        $params[] = $filter_date;
        $types .= 's';
    }

    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

    $count_query = "SELECT COUNT(*) as total FROM security_logs $where_sql";
    if (!empty($params)) {
        $stmt = $conn->prepare($count_query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $total_logs = $stmt->get_result()->fetch_assoc()['total'];
        $stmt->close();
    } else {
        $total_logs = $conn->query($count_query)->fetch_assoc()['total'];
    }

    $query = "
        SELECT * FROM security_logs
        $where_sql
        ORDER BY timestamp DESC
        LIMIT ? OFFSET ?
    ";

    $stmt = $conn->prepare($query);
    if (!empty($params)) {
        $params[] = $per_page;
        $params[] = $offset;
        $types .= 'ii';
        $stmt->bind_param($types, ...$params);
    } else {
        $stmt->bind_param('ii', $per_page, $offset);
    }

    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $logs[] = $row;
    }
    $stmt->close();

    $stats['failed_logins_24h'] = $conn->query("SELECT COUNT(*) as count FROM security_logs WHERE event_type = 'failed_login' AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)")->fetch_assoc()['count'];
    $stats['failed_logins_7d'] = $conn->query("SELECT COUNT(*) as count FROM security_logs WHERE event_type = 'failed_login' AND timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)")->fetch_assoc()['count'];
    $stats['password_changes'] = $conn->query("SELECT COUNT(*) as count FROM security_logs WHERE event_type = 'password_change'")->fetch_assoc()['count'];
    $stats['otp_setups'] = $conn->query("SELECT COUNT(*) as count FROM security_logs WHERE event_type = 'otp_setup'")->fetch_assoc()['count'];

    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load logs: ' . $e->getMessage();
    error_log('Security logs error: ' . $e->getMessage());
}

$total_pages = max(1, (int)ceil($total_logs / $per_page));

include 'header.php';
?>

<div class="panel">
    <div class="page-title">Security Logok</div>
    <div class="muted">Esemnyek, sikertelen loginok, 2FA.</div>
</div>

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
                    <th>Failed 24h</th>
                    <th>Failed 7d</th>
                    <th>Password change</th>
                    <th>OTP setup</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td><?php echo (int)$stats['failed_logins_24h']; ?></td>
                    <td><?php echo (int)$stats['failed_logins_7d']; ?></td>
                    <td><?php echo (int)$stats['password_changes']; ?></td>
                    <td><?php echo (int)$stats['otp_setups']; ?></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<div class="panel">
    <div class="panel-title">Szurok</div>
    <form method="get" class="toolbar">
        <div class="form-field">
            <label for="event">Event</label>
            <select id="event" name="event">
                <option value="">Osszes</option>
                <?php foreach ($event_types as $event_type): ?>
                    <option value="<?php echo htmlspecialchars($event_type); ?>" <?php echo $filter_event === $event_type ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($event_type); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="username">Username</label>
            <input id="username" name="username" type="text" value="<?php echo htmlspecialchars($filter_username); ?>">
        </div>
        <div class="form-field">
            <label for="date">Datum</label>
            <input id="date" name="date" type="date" value="<?php echo htmlspecialchars($filter_date); ?>">
        </div>
        <div class="form-field">
            <button class="btn btn-primary" type="submit">Szures</button>
        </div>
        <div class="form-field">
            <a class="btn btn-secondary" href="security_logs.php">Reset</a>
        </div>
    </form>
</div>

<div class="panel">
    <div class="panel-title">Logok (<?php echo (int)$total_logs; ?>)</div>
    <div class="table-wrap">
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Timestamp</th>
                    <th>Event</th>
                    <th>Username</th>
                    <th>IP</th>
                    <th>Details</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="6" class="muted">Nincs log.</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?php echo (int)$log['id']; ?></td>
                            <td class="nowrap"><?php echo date('Y-m-d H:i:s', strtotime($log['timestamp'])); ?></td>
                            <td><?php echo htmlspecialchars($log['event_type']); ?></td>
                            <td><?php echo htmlspecialchars($log['username']); ?></td>
                            <td class="mono"><?php echo htmlspecialchars($log['ip_address']); ?></td>
                            <td class="mono"><?php echo htmlspecialchars(substr($log['details'] ?? '', 0, 200)); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    <div style="margin-top: 10px;">
        <?php if ($total_pages > 1): ?>
            <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                <?php
                    $params = $_GET;
                    $params['page'] = $i;
                    $url = 'security_logs.php?' . http_build_query($params);
                ?>
                <a class="btn btn-small <?php echo $i === $page ? 'btn-primary' : ''; ?>" href="<?php echo htmlspecialchars($url); ?>"><?php echo $i; ?></a>
            <?php endfor; ?>
        <?php endif; ?>
    </div>
</div>

<?php include 'footer.php'; ?>
