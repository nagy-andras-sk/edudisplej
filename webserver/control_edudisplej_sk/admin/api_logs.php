<?php
/**
 * API Activity Logs Viewer
 * Real-time monitoring of API requests
 */

session_start();
require_once '../dbkonfiguracia.php';

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
$filter_company = $_GET['company'] ?? '';
$filter_endpoint = $_GET['endpoint'] ?? '';
$filter_status = $_GET['status'] ?? '';
$filter_date = $_GET['date'] ?? '';

// Get logs
$logs = [];
$total_logs = 0;
$companies = [];

try {
    $conn = getDbConnection();
    
    // Check if api_logs table exists, if not create it
    $table_check = $conn->query("SHOW TABLES LIKE 'api_logs'");
    if ($table_check->num_rows === 0) {
        // Create api_logs table
        $create_table = "
        CREATE TABLE IF NOT EXISTS api_logs (
            id INT AUTO_INCREMENT PRIMARY KEY,
            company_id INT NULL,
            kiosk_id INT NULL,
            endpoint VARCHAR(255) NOT NULL,
            method VARCHAR(10) NOT NULL DEFAULT 'GET',
            status_code INT NOT NULL DEFAULT 200,
            ip_address VARCHAR(45) NULL,
            user_agent TEXT NULL,
            request_data TEXT NULL,
            response_data TEXT NULL,
            execution_time FLOAT NULL,
            timestamp DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_company (company_id),
            INDEX idx_endpoint (endpoint),
            INDEX idx_timestamp (timestamp),
            INDEX idx_status (status_code),
            FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL,
            FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE SET NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ";
        
        if ($conn->query($create_table)) {
            $success = 'API logs table created successfully';
        }
    }
    
    // Get companies for filter
    $result = $conn->query("SELECT id, name FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }
    
    // Build query with filters
    $where_clauses = [];
    $params = [];
    $types = '';
    
    if (!empty($filter_company)) {
        $where_clauses[] = "l.company_id = ?";
        $params[] = $filter_company;
        $types .= 'i';
    }
    
    if (!empty($filter_endpoint)) {
        $where_clauses[] = "l.endpoint LIKE ?";
        $params[] = "%$filter_endpoint%";
        $types .= 's';
    }
    
    if (!empty($filter_status)) {
        if ($filter_status === 'success') {
            $where_clauses[] = "l.status_code < 300";
        } elseif ($filter_status === 'error') {
            $where_clauses[] = "l.status_code >= 400";
        }
    }
    
    if (!empty($filter_date)) {
        $where_clauses[] = "DATE(l.timestamp) = ?";
        $params[] = $filter_date;
        $types .= 's';
    }
    
    $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';
    
    // Get total count
    $count_query = "SELECT COUNT(*) as total FROM api_logs l $where_sql";
    
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
        SELECT l.*, c.name as company_name, k.name as kiosk_name
        FROM api_logs l
        LEFT JOIN companies c ON l.company_id = c.id
        LEFT JOIN kiosks k ON l.kiosk_id = k.id
        $where_sql
        ORDER BY l.timestamp DESC
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
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load logs: ' . $e->getMessage();
    error_log('API logs error: ' . $e->getMessage());
}

$total_pages = ceil($total_logs / $per_page);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>API Activity Logs - EduDisplej</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
    <link rel="stylesheet" href="style.css">
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
        }
        
        .header {
            background: linear-gradient(135deg, #1e40af 0%, #0369a1 100%);
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
            background: linear-gradient(135deg, #1e40af 0%, #0369a1 100%);
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
        
        .badge {
            display: inline-block;
            padding: 3px 8px;
            border-radius: 10px;
            font-size: 10px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge-success {
            background: #e8f5e9;
            color: #2e7d32;
        }
        
        .badge-warning {
            background: #fff3e0;
            color: #e65100;
        }
        
        .badge-danger {
            background: #ffebee;
            color: #c62828;
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
            background: linear-gradient(135deg, #1e40af 0%, #0369a1 100%);
            color: white;
            border-color: #1e40af;
        }
        
        .stats-mini {
            display: flex;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .stat-mini {
            padding: 15px 20px;
            background: #f5f7fa;
            border-radius: 8px;
            border-left: 4px solid #1e40af;
        }
        
        .stat-mini-label {
            font-size: 11px;
            color: #666;
            text-transform: uppercase;
            margin-bottom: 5px;
        }
        
        .stat-mini-value {
            font-size: 24px;
            font-weight: 700;
            color: #1e40af;
        }
        
        code {
            background: #f5f7fa;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 11px;
            color: #666;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>📈 API Activity Logs</h1>
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
        
        <div class="card">
            <h2 style="margin-bottom: 20px;">📊 Statistics</h2>
            <div class="stats-mini">
                <div class="stat-mini">
                    <div class="stat-mini-label">Total Requests</div>
                    <div class="stat-mini-value"><?php echo number_format($total_logs); ?></div>
                </div>
                <div class="stat-mini">
                    <div class="stat-mini-label">Current Page</div>
                    <div class="stat-mini-value"><?php echo $page; ?> / <?php echo max(1, $total_pages); ?></div>
                </div>
                <div class="stat-mini">
                    <div class="stat-mini-label">Per Page</div>
                    <div class="stat-mini-value"><?php echo $per_page; ?></div>
                </div>
            </div>
        </div>
        
        <div class="card">
            <h2 style="margin-bottom: 20px;">🔍 Filters</h2>
            <form method="GET" action="">
                <div class="filters">
                    <div class="filter-group">
                        <label>Company</label>
                        <select name="company">
                            <option value="">All Companies</option>
                            <?php foreach ($companies as $company): ?>
                                <option value="<?php echo $company['id']; ?>" 
                                    <?php echo $filter_company == $company['id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($company['name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Endpoint</label>
                        <input type="text" name="endpoint" placeholder="e.g., /api/health" 
                               value="<?php echo htmlspecialchars($filter_endpoint); ?>">
                    </div>
                    
                    <div class="filter-group">
                        <label>Status</label>
                        <select name="status">
                            <option value="">All Status</option>
                            <option value="success" <?php echo $filter_status === 'success' ? 'selected' : ''; ?>>Success (2xx)</option>
                            <option value="error" <?php echo $filter_status === 'error' ? 'selected' : ''; ?>>Error (4xx/5xx)</option>
                        </select>
                    </div>
                    
                    <div class="filter-group">
                        <label>Date</label>
                        <input type="date" name="date" value="<?php echo htmlspecialchars($filter_date); ?>">
                    </div>
                </div>
                
                <div style="display: flex; gap: 10px; margin-top: 10px;">
                    <button type="submit" class="btn btn-primary">Apply Filters</button>
                    <a href="api_logs.php" class="btn btn-secondary">Clear Filters</a>
                </div>
            </form>
        </div>
        
        <div class="card">
            <h2 style="margin-bottom: 20px;">📋 Request Log</h2>
            
            <?php if (empty($logs)): ?>
                <div style="text-align: center; padding: 60px 20px; color: #999;">
                    <div style="font-size: 48px; margin-bottom: 15px; opacity: 0.3;">📭</div>
                    <p>No API logs found</p>
                    <small>Logs will appear here as API requests are made</small>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table>
                        <thead>
                            <tr>
                                <th>Time</th>
                                <th>Company</th>
                                <th>Kiosk</th>
                                <th>Endpoint</th>
                                <th>Method</th>
                                <th>Status</th>
                                <th>IP Address</th>
                                <th>Duration</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): ?>
                                <tr>
                                    <td>
                                        <?php 
                                        $time = strtotime($log['timestamp']);
                                        echo date('H:i:s', $time);
                                        ?><br>
                                        <small style="color: #999;"><?php echo date('Y-m-d', $time); ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['company_name'] ?? 'Unknown'); ?></td>
                                    <td><?php echo htmlspecialchars($log['kiosk_name'] ?? '-'); ?></td>
                                    <td><code><?php echo htmlspecialchars($log['endpoint']); ?></code></td>
                                    <td>
                                        <?php
                                        $method_colors = [
                                            'GET' => 'info',
                                            'POST' => 'success',
                                            'PUT' => 'warning',
                                            'DELETE' => 'danger'
                                        ];
                                        $method = $log['method'];
                                        $badge_class = 'badge-' . ($method_colors[$method] ?? 'info');
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo $method; ?></span>
                                    </td>
                                    <td>
                                        <?php
                                        $status = $log['status_code'];
                                        if ($status < 300) {
                                            $badge_class = 'badge-success';
                                        } elseif ($status < 400) {
                                            $badge_class = 'badge-info';
                                        } else {
                                            $badge_class = 'badge-danger';
                                        }
                                        ?>
                                        <span class="badge <?php echo $badge_class; ?>"><?php echo $status; ?></span>
                                    </td>
                                    <td><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></td>
                                    <td>
                                        <?php 
                                        if ($log['execution_time']) {
                                            echo number_format($log['execution_time'], 3) . 's';
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
                        <a href="?page=<?php echo $page - 1; ?><?php echo $filter_company ? '&company=' . $filter_company : ''; ?><?php echo $filter_endpoint ? '&endpoint=' . urlencode($filter_endpoint) : ''; ?><?php echo $filter_status ? '&status=' . $filter_status : ''; ?><?php echo $filter_date ? '&date=' . $filter_date : ''; ?>">
                            ← Previous
                        </a>
                    <?php endif; ?>
                    
                    <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                        <?php if ($i === $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?><?php echo $filter_company ? '&company=' . $filter_company : ''; ?><?php echo $filter_endpoint ? '&endpoint=' . urlencode($filter_endpoint) : ''; ?><?php echo $filter_status ? '&status=' . $filter_status : ''; ?><?php echo $filter_date ? '&date=' . $filter_date : ''; ?>">
                                <?php echo $i; ?>
                            </a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page + 1; ?><?php echo $filter_company ? '&company=' . $filter_company : ''; ?><?php echo $filter_endpoint ? '&endpoint=' . urlencode($filter_endpoint) : ''; ?><?php echo $filter_status ? '&status=' . $filter_status : ''; ?><?php echo $filter_date ? '&date=' . $filter_date : ''; ?>">
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
