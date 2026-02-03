<?php
/**
 * Security Logs Viewer
 * Monitor security events and failed logins
 */

session_start();
require_once '../dbkonfiguracia.php';

// Generate CSRF token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = 50;
$offset = ($page - 1) * $per_page;

// Filters
$filter_event = $_GET['event'] ?? '';
$filter_username = $_GET['username'] ?? '';
$filter_date = $_GET['date'] ?? '';

// Get logs
$logs = [];
$total_logs = 0;
$event_types = [];

try {
    $conn = getDbConnection();
    
    // Check if security_logs table exists, if not create it
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
    
    // Get unique event types
    $result = $conn->query("SELECT DISTINCT event_type FROM security_logs ORDER BY event_type");
    while ($row = $result->fetch_assoc()) {
        $event_types[] = $row['event_type'];
    }
    
    // Build query with filters
    $where_clauses = [];
    $params = [];
    $types = '';
    
    if (!empty($filter_event)) {
        $where_clauses[] = "event_type = ?";
        $params[] = $filter_event;
        $types .= 's';
    }
    
    if (!empty($filter_username)) {
        $where_clauses[] = "username LIKE ?";
        $params[] = "%$filter_username%";
        $types .= 's';
    }
    
    if (!empty($filter_date)) {
        $where_clauses[] = "DATE(timestamp) = ?";
        $params[] = $filter_date;
        $types .= 's';
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM security_logs $where_sql";
    
    if (!empty($params)) {
        $stmt = $conn->prepare($count_query);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        $total_logs = $result->fetch_assoc()['total'];
        $stmt->close();
    } else {
        $result = $conn->query($count_query);
        $total_logs = $result->fetch_assoc()['total'];
    }
    
    // Get logs with pagination
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
    
    // Get statistics
    $stats = [
        'failed_logins_24h' => 0,
        'failed_logins_7d' => 0,
        'password_changes' => 0,
        'otp_setups' => 0
    ];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM security_logs WHERE event_type = 'failed_login' AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)");
    $stats['failed_logins_24h'] = $result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM security_logs WHERE event_type = 'failed_login' AND timestamp > DATE_SUB(NOW(), INTERVAL 7 DAY)");
    $stats['failed_logins_7d'] = $result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM security_logs WHERE event_type = 'password_change'");
    $stats['password_changes'] = $result->fetch_assoc()['count'];
    
    $result = $conn->query("SELECT COUNT(*) as count FROM security_logs WHERE event_type = 'otp_setup'");
    $stats['otp_setups'] = $result->fetch_assoc()['count'];
    
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load logs: ' . $e->getMessage();
    error_log('Security logs error: ' . $e->getMessage());
}

$total_pages = ceil($total_logs / $per_page);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Security Logs - EduDisplej</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <link rel="stylesheet" href="style.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
        }
        
        .header {
            background: linear-gradient(135deg, #c62828 0%, #b71c1c 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .header h1 {
            font-size: 24px;
            font-weight: 600;
        }
        
        .header-btn {
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 13px;
            transition: all 0.2s;
        }
        
        .header-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 30px;
        }
        
        .card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            margin-bottom: 30px;
        }
        
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
        }
        
        .alert.success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #4caf50;
        }
        
        .alert.error {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #f44336;
        }
        
        .alert.warning {
            background: #fff3e0;
            color: #e65100;
            border-left: 4px solid #ff9800;
        }
        
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            padding: 20px;
            background: #f5f7fa;
            border-radius: 8px;
            border-left: 4px solid #c62828;
        }
        
        .stat-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 8px;
        }
        
        .stat-value {
            font-size: 28px;
            font-weight: 700;
            color: #c62828;
        }
        
        .filters {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .filter-group {
            display: flex;
            flex-direction: column;
            gap: 5px;
        }
        
        .filter-group label {
            font-size: 12px;
            color: #666;
            font-weight: 500;
        }
        
        .filter-group input,
        .filter-group select {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }
        
        .btn {
            padding: 8px 16px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            transition: all 0.2s;
            text-decoration: none;
            display: inline-block;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #c62828 0%, #b71c1c 100%);
            color: white;
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 12px;
        }
        
        thead {
            background: #f5f7fa;
        }
        
        th {
            padding: 10px 12px;
            text-align: left;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            font-size: 10px;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 10px 12px;
            border-bottom: 1px solid #eee;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        tr.critical {
            background: #ffebee;
        }
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-danger {
            background: #ffebee;
            color: #c62828;
        }
        
        .badge-warning {
            background: #fff3e0;
            color: #e65100;
        }
        
        .badge-success {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .badge-info {
            background: #e3f2fd;
            color: #1565c0;
        }
        
        .pagination {
            display: flex;
            justify-content: center;
            align-items: center;
            gap: 10px;
            margin-top: 20px;
        }
        
        .pagination a,
        .pagination span {
            padding: 8px 12px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-decoration: none;
            color: #666;
        }
        
        .pagination a:hover {
            background: #f5f7fa;
        }
        
        .pagination .active {
            background: linear-gradient(135deg, #c62828 0%, #b71c1c 100%);
            color: white;
            border-color: #c62828;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>🔐 Security Logs</h1>
        <a href="dashboard.php" class="header-btn">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <?php if ($error): ?>
            <div class="alert error">
                <span>⚠️</span>
                <?php echo htmlspecialchars($error); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($success): ?>
            <div class="alert success">
                <span>✓</span>
                <?php echo htmlspecialchars($success); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($stats['failed_logins_24h'] > 10): ?>
            <div class="alert warning">
                <span>⚠️</span>
                <strong>High number of failed login attempts detected!</strong>
                <?php echo $stats['failed_logins_24h']; ?> failed logins in the last 24 hours.
            </div>
        <?php endif; ?>
        
        <div class="stats-grid">
            <div class="stat-card">
                <div class="stat-label">Failed Logins (24h)</div>
                <div class="stat-value"><?php echo $stats['failed_logins_24h']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Failed Logins (7d)</div>
                <div class="stat-value"><?php echo $stats['failed_logins_7d']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">Password Changes</div>
                <div class="stat-value"><?php echo $stats['password_changes']; ?></div>
            </div>
            <div class="stat-card">
                <div class="stat-label">2FA Setups</div>
                <div class="stat-value"><?php echo $stats['otp_setups']; ?></div>
            </div>
        </div>
        
        <div class="card">
            <h2 style="margin-bottom: 20px;">🔍 Filters</h2>
            <form method="GET" action="">
                <div class="filters">
                    <div class="filter-group">
                        <label>Event Type</label>
                        <select name="event">
                            <option value="">All Events</option>
                            <?php foreach ($event_types as $type): ?>
                                <option value="<?php echo htmlspecialchars($type); ?>" 
                                    <?php echo $filter_event === $type ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($type); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Username</label>
                        <input type="text" name="username" placeholder="Search username" 
                               value="<?php echo htmlspecialchars($filter_username); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label>Date</label>
                        <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="security_logs.php" class="btn btn-secondary">Clear Filters</a>
                </div>
            </form>
        </div>
        
        <div class="card">
            <h2 style="margin-bottom: 20px;">📋 Security Events</h2>
            
            <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 60px 20px; color: #999;">
                    <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.3;">🔒</div>
                    <p>No security logs found</p>
                    <small>Security events will appear here</small>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Event Type</th>
                                <th>Username</th>
                                <th>IP Address</th>
                                <th>Details</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr <?php echo $log['event_type'] === 'failed_login' ? 'class="critical"' : ''; ?>>
                                    <td>
                                        <?php 
                                        $time = strtotime($log['timestamp']);
                                        echo date('H:i:s', $time);
                                        ?><br>
                                        <small style="color: #999;"><?php echo date('Y-m-d', $time); ?></small>
                                    </td>
                                    <td>
                                        <?php
                                        $event_badges = [
                                            'failed_login' => 'danger',
                                            'successful_login' => 'success',
                                            'password_change' => 'warning',
                                            'otp_setup' => 'info',
                                            'otp_disabled' => 'warning',
                                            'user_created' => 'success',
                                            'user_deleted' => 'danger'
                                        ];
                                        $badge_class = 'badge-' . ($event_badges[$log['event_type']] ?? 'info');
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>">
                                            <?php echo htmlspecialchars($log['event_type']); ?>
                                        </span>
                                    </td>
                                    <td><strong><?php echo htmlspecialchars($log['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($log['ip_address']); ?></td>
                                    <td>
                                        <?php 
                                        if ($log['details']) {
                                            $details = json_decode($log['details'], true);
                                            if ($details) {
                                                echo '<small style="color: #666;">';
                                                foreach ($details as $key => $value) {
                                                    echo htmlspecialchars($key) . ': ' . htmlspecialchars($value) . '<br>';
                                                }
                                                echo '</small>';
                                            }
                                        } else {
                                            echo '-';
                                        }
                                        ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page - 1; ?><?php echo $filter_event ? '&event=' . urlencode($filter_event) : ''; ?><?php echo $filter_username ? '&username=' . urlencode($filter_username) : ''; ?><?php echo $filter_date ? '&date=' . $filter_date : ''; ?>">
                            ← Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?><?php echo $filter_event ? '&event=' . urlencode($filter_event) : ''; ?><?php echo $filter_username ? '&username=' . urlencode($filter_username) : ''; ?><?php echo $filter_date ? '&date=' . $filter_date : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $filter_event ? '&event=' . urlencode($filter_event) : ''; ?><?php echo $filter_username ? '&username=' . urlencode($filter_username) : ''; ?><?php echo $filter_date ? '&date=' . $filter_date : ''; ?>">
                            Next →
                        </a>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>
