<?php
/**
 * Modern Admin Dashboard
 * Full API Security, Token Management, OTP, Licenses
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

// Get comprehensive statistics
$stats = [
    'kiosks_total' => 0,
    'kiosks_online' => 0,
    'kiosks_offline' => 0,
    'companies_total' => 0,
    'companies_active' => 0,
    'users_total' => 0,
    'users_otp_enabled' => 0,
    'api_requests_today' => 0,
    'tokens_active' => 0,
    'licenses_total' => 0
];

$recent_activity = [];
$security_alerts = [];

try {
    $conn = getDbConnection();
    
    // Kiosk stats
    $result = $conn->query("SELECT COUNT(*) as total FROM kiosks");
    $stats['kiosks_total'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM kiosks WHERE status = 'online'");
    $stats['kiosks_online'] = $result->fetch_assoc()['total'];
    
    $stats['kiosks_offline'] = $stats['kiosks_total'] - $stats['kiosks_online'];
    
    // Company stats
    $result = $conn->query("SELECT COUNT(*) as total FROM companies");
    $stats['companies_total'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM companies WHERE is_active = 1");
    $stats['companies_active'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM companies WHERE api_token IS NOT NULL");
    $stats['tokens_active'] = $result->fetch_assoc()['total'];
    
    // User stats
    $result = $conn->query("SELECT COUNT(*) as total FROM users");
    $stats['users_total'] = $result->fetch_assoc()['total'];
    
    $result = $conn->query("SELECT COUNT(*) as total FROM users WHERE otp_enabled = 1 AND otp_verified = 1");
    $stats['users_otp_enabled'] = $result->fetch_assoc()['total'];
    
    // License stats
    $result = $conn->query("SELECT COUNT(*) as total FROM module_licenses");
    $stats['licenses_total'] = $result->fetch_assoc()['total'];
    
    // API activity today (if logs table exists)
    $table_check = $conn->query("SHOW TABLES LIKE 'api_logs'");
    if ($table_check->num_rows > 0) {
        $result = $conn->query("SELECT COUNT(*) as total FROM api_logs WHERE DATE(timestamp) = CURDATE()");
        $stats['api_requests_today'] = $result->fetch_assoc()['total'];
        
        // Recent API activity
        $result = $conn->query("
            SELECT l.*, c.name as company_name 
            FROM api_logs l 
            LEFT JOIN companies c ON l.company_id = c.id 
            ORDER BY l.timestamp DESC 
            LIMIT 10
        ");
        while ($row = $result->fetch_assoc()) {
            $recent_activity[] = $row;
        }
    }
    
    // Security alerts - failed logins in last 24h
    $table_check = $conn->query("SHOW TABLES LIKE 'security_logs'");
    if ($table_check->num_rows > 0) {
        $result = $conn->query("
            SELECT * FROM security_logs 
            WHERE event_type = 'failed_login' 
            AND timestamp > DATE_SUB(NOW(), INTERVAL 24 HOUR)
            ORDER BY timestamp DESC 
            LIMIT 5
        ");
        while ($row = $result->fetch_assoc()) {
            $security_alerts[] = $row;
        }
    }
    
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load statistics';
    error_log('Dashboard error: ' . $e->getMessage());
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Dashboard - EduDisplej</title>
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
            color: #333;
        }
        
        /* Header */
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
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .header-nav {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        
        .header-btn {
            padding: 8px 16px;
            background: rgba(255,255,255,0.2);
            color: white;
            text-decoration: none;
            border-radius: 5px;
            font-size: 13px;
            font-weight: 500;
            transition: all 0.2s;
            border: none;
            cursor: pointer;
        }
        
        .header-btn:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .header-btn.logout {
            background: rgba(239, 83, 80, 0.3);
        }
        
        .header-btn.logout:hover {
            background: #ef5350;
        }
        
        /* Container */
        .container {
            max-width: 1600px;
            margin: 0 auto;
            padding: 30px;
        }
        
        /* Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 25px;
            border-radius: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            transition: all 0.3s;
            border-left: 4px solid #1e40af;
        }
        
        .stat-card:hover {
            box-shadow: 0 4px 16px rgba(0,0,0,0.12);
            transform: translateY(-2px);
        }
        
        .stat-card.success {
            border-left-color: #4caf50;
        }
        
        .stat-card.warning {
            border-left-color: #ff9800;
        }
        
        .stat-card.danger {
            border-left-color: #f44336;
        }
        
        .stat-card.info {
            border-left-color: #2196f3;
        }
        
        .stat-card-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 15px;
        }
        
        .stat-card h3 {
            font-size: 14px;
            color: #666;
            font-weight: 500;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .stat-icon {
            font-size: 24px;
            opacity: 0.6;
        }
        
        .stat-value {
            font-size: 36px;
            font-weight: 700;
            color: #1e40af;
            margin-bottom: 5px;
        }
        
        .stat-label {
            font-size: 12px;
            color: #999;
        }
        
        /* Navigation Tabs */
        .nav-tabs {
            background: white;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 30px;
            display: flex;
            gap: 10px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
            flex-wrap: wrap;
        }
        
        .nav-tab {
            padding: 12px 24px;
            background: transparent;
            border: none;
            color: #666;
            font-weight: 500;
            cursor: pointer;
            border-radius: 6px;
            transition: all 0.2s;
            font-size: 14px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        .nav-tab:hover {
            background: #f5f7fa;
            color: #1e40af;
        }
        
        .nav-tab.active {
            background: linear-gradient(135deg, #1e40af 0%, #0369a1 100%);
            color: white;
        }
        
        /* Content Panels */
        .panel {
            display: none;
            background: white;
            border-radius: 10px;
            padding: 30px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.08);
        }
        
        .panel.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; transform: translateY(10px); }
            to { opacity: 1; transform: translateY(0); }
        }
        
        .panel h2 {
            font-size: 24px;
            margin-bottom: 20px;
            color: #333;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        /* Tables */
        .table-container {
            overflow-x: auto;
            margin-top: 20px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }
        
        thead {
            background: #f5f7fa;
        }
        
        th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            color: #666;
            text-transform: uppercase;
            font-size: 11px;
            letter-spacing: 0.5px;
        }
        
        td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        tr:hover {
            background: #f9fafb;
        }
        
        /* Badges */
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 12px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
        }
        
        .badge.success {
            background: #e8f5e9;
            color: #4caf50;
        }
        
        .badge.warning {
            background: #fff3e0;
            color: #ff9800;
        }
        
        .badge.danger {
            background: #ffebee;
            color: #f44336;
        }
        
        .badge.info {
            background: #e3f2fd;
            color: #2196f3;
        }
        
        /* Buttons */
        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: 6px;
            font-size: 14px;
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
        
        .btn-primary:hover {
            box-shadow: 0 4px 12px rgba(30, 64, 175, 0.3);
        }
        
        .btn-secondary {
            background: #6c757d;
            color: white;
        }
        
        .btn-success {
            background: #4caf50;
            color: white;
        }
        
        .btn-danger {
            background: #f44336;
            color: white;
        }
        
        .btn-sm {
            padding: 6px 12px;
            font-size: 12px;
        }
        
        /* Alert boxes */
        .alert {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
        }
        
        .alert.success {
            background: #e8f5e9;
            color: #2e7d32;
            border-left: 4px solid #4caf50;
        }
        
        .alert.warning {
            background: #fff3e0;
            color: #e65100;
            border-left: 4px solid #ff9800;
        }
        
        .alert.danger {
            background: #ffebee;
            color: #c62828;
            border-left: 4px solid #f44336;
        }
        
        .alert.info {
            background: #e3f2fd;
            color: #1565c0;
            border-left: 4px solid #2196f3;
        }
        
        /* Grid layouts */
        .grid-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 30px;
        }
        
        /* Activity list */
        .activity-list {
            list-style: none;
        }
        
        .activity-item {
            padding: 15px;
            border-bottom: 1px solid #eee;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-time {
            font-size: 12px;
            color: #999;
        }
        
        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 60px 20px;
            color: #999;
        }
        
        .empty-state-icon {
            font-size: 48px;
            margin-bottom: 15px;
            opacity: 0.3;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <h1>
            <span>🛡️</span>
            EduDisplej Admin Portal
        </h1>
        <div class="header-nav">
            <span style="opacity: 0.8; font-size: 13px;">
                Welcome, <strong><?php echo htmlspecialchars($_SESSION['username']); ?></strong>
            </span>
            <a href="../login.php?logout=1" class="header-btn logout">Logout</a>
        </div>
    </div>
    
    <!-- Container -->
    <div class="container">
        <?php if ($error): ?>
            <div class="alert danger">
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
        
        <!-- Stats Grid -->
        <div class="stats-grid">
            <div class="stat-card info">
                <div class="stat-card-header">
                    <h3>Total Kiosks</h3>
                    <span class="stat-icon">🖥️</span>
                </div>
                <div class="stat-value"><?php echo $stats['kiosks_total']; ?></div>
                <div class="stat-label">Registered devices</div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-card-header">
                    <h3>Online</h3>
                    <span class="stat-icon">✓</span>
                </div>
                <div class="stat-value"><?php echo $stats['kiosks_online']; ?></div>
                <div class="stat-label">Active now</div>
            </div>
            
            <div class="stat-card warning">
                <div class="stat-card-header">
                    <h3>Offline</h3>
                    <span class="stat-icon">⚠️</span>
                </div>
                <div class="stat-value"><?php echo $stats['kiosks_offline']; ?></div>
                <div class="stat-label">Need attention</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Companies</h3>
                    <span class="stat-icon">🏢</span>
                </div>
                <div class="stat-value"><?php echo $stats['companies_active']; ?></div>
                <div class="stat-label">of <?php echo $stats['companies_total']; ?> total</div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-card-header">
                    <h3>API Tokens</h3>
                    <span class="stat-icon">🔑</span>
                </div>
                <div class="stat-value"><?php echo $stats['tokens_active']; ?></div>
                <div class="stat-label">Active tokens</div>
            </div>
            
            <div class="stat-card success">
                <div class="stat-card-header">
                    <h3>2FA Users</h3>
                    <span class="stat-icon">🔐</span>
                </div>
                <div class="stat-value"><?php echo $stats['users_otp_enabled']; ?></div>
                <div class="stat-label">of <?php echo $stats['users_total']; ?> users</div>
            </div>
            
            <div class="stat-card">
                <div class="stat-card-header">
                    <h3>Licenses</h3>
                    <span class="stat-icon">📜</span>
                </div>
                <div class="stat-value"><?php echo $stats['licenses_total']; ?></div>
                <div class="stat-label">Module licenses</div>
            </div>
            
            <div class="stat-card info">
                <div class="stat-card-header">
                    <h3>API Requests</h3>
                    <span class="stat-icon">📊</span>
                </div>
                <div class="stat-value"><?php echo $stats['api_requests_today']; ?></div>
                <div class="stat-label">Today</div>
            </div>
        </div>
        
        <!-- Navigation Tabs -->
        <div class="nav-tabs">
            <button class="nav-tab active" onclick="showPanel('overview')">
                <span>📊</span> Overview
            </button>
            <button class="nav-tab" onclick="showPanel('tokens')">
                <span>🔑</span> API Tokens
            </button>
            <button class="nav-tab" onclick="showPanel('security')">
                <span>🔐</span> Security
            </button>
            <button class="nav-tab" onclick="showPanel('licenses')">
                <span>📜</span> Licenses
            </button>
            <button class="nav-tab" onclick="showPanel('activity')">
                <span>📈</span> Activity Log
            </button>
            <button class="nav-tab" onclick="showPanel('management')">
                <span>⚙️</span> Management
            </button>
        </div>
        
        <!-- Overview Panel -->
        <div id="overview" class="panel active">
            <h2>📊 System Overview</h2>
            
            <?php if (count($security_alerts) > 0): ?>
            <div class="alert warning">
                <span>⚠️</span>
                <strong><?php echo count($security_alerts); ?> security alert(s) detected</strong> - Multiple failed login attempts in the last 24 hours
            </div>
            <?php endif; ?>
            
            <div class="grid-2">
                <div>
                    <h3 style="margin-bottom: 15px; font-size: 18px;">Recent Activity</h3>
                    <?php if (count($recent_activity) > 0): ?>
                    <ul class="activity-list">
                        <?php foreach ($recent_activity as $activity): ?>
                        <li class="activity-item">
                            <div>
                                <strong><?php echo htmlspecialchars($activity['endpoint'] ?? 'Unknown'); ?></strong>
                                <?php if (isset($activity['company_name'])): ?>
                                <br><small style="color: #999;"><?php echo htmlspecialchars($activity['company_name']); ?></small>
                                <?php endif; ?>
                            </div>
                            <span class="activity-time">
                                <?php echo isset($activity['timestamp']) ? date('H:i:s', strtotime($activity['timestamp'])) : ''; ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">📭</div>
                        <p>No recent activity</p>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div>
                    <h3 style="margin-bottom: 15px; font-size: 18px;">Security Alerts</h3>
                    <?php if (count($security_alerts) > 0): ?>
                    <ul class="activity-list">
                        <?php foreach ($security_alerts as $alert): ?>
                        <li class="activity-item">
                            <div>
                                <strong style="color: #f44336;">Failed Login Attempt</strong>
                                <br><small style="color: #999;">
                                    <?php echo htmlspecialchars($alert['username'] ?? 'Unknown'); ?> 
                                    from <?php echo htmlspecialchars($alert['ip_address'] ?? 'Unknown IP'); ?>
                                </small>
                            </div>
                            <span class="activity-time">
                                <?php echo isset($alert['timestamp']) ? date('H:i:s', strtotime($alert['timestamp'])) : ''; ?>
                            </span>
                        </li>
                        <?php endforeach; ?>
                    </ul>
                    <?php else: ?>
                    <div class="empty-state">
                        <div class="empty-state-icon">✓</div>
                        <p>No security alerts</p>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <!-- API Tokens Panel -->
        <div id="tokens" class="panel">
            <h2>🔑 API Token Management</h2>
            <p style="color: #666; margin-bottom: 20px;">
                Manage API tokens for company authentication. Each company requires a unique token for API access.
            </p>
            
            <div style="margin-bottom: 20px;">
                <a href="companies.php" class="btn btn-primary">
                    <span>🔑</span> Manage Company Tokens
                </a>
            </div>
            
            <div class="alert info">
                <span>ℹ️</span>
                <div>
                    <strong>Bearer Token Authentication</strong><br>
                    All API requests must include: <code>Authorization: Bearer YOUR_TOKEN</code>
                </div>
            </div>
            
            <h3 style="margin: 30px 0 15px; font-size: 18px;">Token Security Best Practices</h3>
            <ul style="color: #666; line-height: 2;">
                <li>✓ Tokens are 64-character hex strings (256-bit security)</li>
                <li>✓ Store tokens securely - never commit to version control</li>
                <li>✓ Regenerate tokens if compromised</li>
                <li>✓ Monitor API activity for suspicious patterns</li>
                <li>✓ Rotate tokens periodically for enhanced security</li>
            </ul>
        </div>
        
        <!-- Security Panel -->
        <div id="security" class="panel">
            <h2>🔐 Security Settings</h2>
            
            <div style="margin-bottom: 30px;">
                <h3 style="margin-bottom: 15px; font-size: 18px;">Two-Factor Authentication (2FA)</h3>
                <p style="color: #666; margin-bottom: 15px;">
                    <?php echo $stats['users_otp_enabled']; ?> of <?php echo $stats['users_total']; ?> users have 2FA enabled.
                </p>
                <a href="users.php" class="btn btn-primary btn-sm">Manage User 2FA</a>
            </div>
            
            <div style="margin-bottom: 30px;">
                <h3 style="margin-bottom: 15px; font-size: 18px;">Session Security</h3>
                <div class="alert success">
                    <span>✓</span>
                    <div>
                        <strong>Enhanced session security enabled</strong><br>
                        HttpOnly cookies, Secure flag, SameSite=Strict
                    </div>
                </div>
            </div>
            
            <div style="margin-bottom: 30px;">
                <h3 style="margin-bottom: 15px; font-size: 18px;">Password Policies</h3>
                <ul style="color: #666; line-height: 2;">
                    <li>✓ Minimum 8 characters required</li>
                    <li>✓ Passwords hashed with bcrypt (PASSWORD_DEFAULT)</li>
                    <li>✓ No password expiration (as per NIST guidelines)</li>
                </ul>
            </div>
            
            <div>
                <h3 style="margin-bottom: 15px; font-size: 18px;">Encryption</h3>
                <div class="alert info">
                    <span>🔒</span>
                    <div>
                        <strong>AES-256-CBC encryption active</strong><br>
                        All sensitive data is encrypted at rest
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Licenses Panel -->
        <div id="licenses" class="panel">
            <h2>📜 License Management</h2>
            <p style="color: #666; margin-bottom: 20px;">
                Manage module licenses for companies. Control which modules are available to each company.
            </p>
            
            <div style="margin-bottom: 20px;">
                <a href="module_licenses.php" class="btn btn-primary">
                    <span>📜</span> Manage Module Licenses
                </a>
            </div>
            
            <div class="alert info">
                <span>ℹ️</span>
                <div>
                    <strong>License System</strong><br>
                    Each company can have different module licenses. Kiosks will only download licensed modules.
                </div>
            </div>
            
            <h3 style="margin: 30px 0 15px; font-size: 18px;">Current License Statistics</h3>
            <div class="stats-grid" style="margin-top: 20px;">
                <div class="stat-card">
                    <h3>Total Licenses</h3>
                    <div class="stat-value"><?php echo $stats['licenses_total']; ?></div>
                </div>
                <div class="stat-card info">
                    <h3>Companies</h3>
                    <div class="stat-value"><?php echo $stats['companies_total']; ?></div>
                </div>
            </div>
        </div>
        
        <!-- Activity Log Panel -->
        <div id="activity" class="panel">
            <h2>📈 API Activity Log</h2>
            
            <?php if (count($recent_activity) > 0): ?>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Timestamp</th>
                            <th>Company</th>
                            <th>Endpoint</th>
                            <th>Method</th>
                            <th>Status</th>
                            <th>IP Address</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($recent_activity as $log): ?>
                        <tr>
                            <td><?php echo isset($log['timestamp']) ? date('Y-m-d H:i:s', strtotime($log['timestamp'])) : 'N/A'; ?></td>
                            <td><?php echo htmlspecialchars($log['company_name'] ?? 'Unknown'); ?></td>
                            <td><code><?php echo htmlspecialchars($log['endpoint'] ?? ''); ?></code></td>
                            <td><span class="badge info"><?php echo htmlspecialchars($log['method'] ?? 'GET'); ?></span></td>
                            <td>
                                <?php
                                $status = $log['status_code'] ?? 200;
                                $badge_class = $status < 300 ? 'success' : ($status < 400 ? 'info' : 'danger');
                                ?>
                                <span class="badge <?php echo $badge_class; ?>"><?php echo $status; ?></span>
                            </td>
                            <td><?php echo htmlspecialchars($log['ip_address'] ?? 'N/A'); ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php else: ?>
            <div class="empty-state">
                <div class="empty-state-icon">📭</div>
                <p>No API activity logged yet</p>
                <small style="color: #999;">Activity will appear here once API endpoints are called</small>
            </div>
            <?php endif; ?>
            
            <?php if (count($recent_activity) > 0): ?>
            <div style="margin-top: 20px; text-align: center;">
                <a href="api_logs.php" class="btn btn-primary" style="display: inline-block;">
                    📊 View Full API Logs & Advanced Filters
                </a>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Management Panel -->
        <div id="management" class="panel">
            <h2>⚙️ System Management</h2>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-top: 20px;">
                <a href="companies.php" class="btn btn-primary" style="padding: 30px; text-align: center; display: block;">
                    <div style="font-size: 36px; margin-bottom: 10px;">🏢</div>
                    <strong>Companies</strong><br>
                    <small style="opacity: 0.8;">Manage companies and tokens</small>
                </a>
                
                <a href="users.php" class="btn btn-primary" style="padding: 30px; text-align: center; display: block;">
                    <div style="font-size: 36px; margin-bottom: 10px;">👥</div>
                    <strong>Users</strong><br>
                    <small style="opacity: 0.8;">User management & 2FA</small>
                </a>                
                <a href="api_logs.php" class="btn btn-primary" style="padding: 30px; text-align: center; display: block;">
                    <div style="font-size: 36px; margin-bottom: 10px;">📈</div>
                    <strong>API Activity Logs</strong><br>
                    <small style="opacity: 0.8;">Monitor API requests</small>
                </a>                
                <a href="dashboard.php" class="btn btn-primary" style="padding: 30px; text-align: center; display: block;">
                    <div style="font-size: 36px; margin-bottom: 10px;">🖥️</div>
                    <strong>Kiosks</strong><br>
                    <small style="opacity: 0.8;">View and manage kiosks</small>
                </a>
                
                <a href="kiosk_logs.php" class="btn btn-primary" style="padding: 30px; text-align: center; display: block;">
                    <div style="font-size: 36px; margin-bottom: 10px;">📋</div>
                    <strong>Logs</strong><br>
                    <small style="opacity: 0.8;">System and kiosk logs</small>
                </a>
                
                <a href="module_licenses.php" class="btn btn-primary" style="padding: 30px; text-align: center; display: block;">
                    <div style="font-size: 36px; margin-bottom: 10px;">📜</div>
                    <strong>Licenses</strong><br>
                    <small style="opacity: 0.8;">Module license management</small>
                </a>
                
                <a href="kiosk_modules_api.php" class="btn btn-primary" style="padding: 30px; text-align: center; display: block;">
                    <div style="font-size: 36px; margin-bottom: 10px;">📦</div>
                    <strong>Modules</strong><br>
                    <small style="opacity: 0.8;">Module management</small>
                </a>
            </div>
        </div>
    </div>
    
    <script>
        function showPanel(panelId) {
            // Hide all panels
            document.querySelectorAll('.panel').forEach(panel => {
                panel.classList.remove('active');
            });
            
            // Remove active from all tabs
            document.querySelectorAll('.nav-tab').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Show selected panel
            document.getElementById(panelId).classList.add('active');
            
            // Activate clicked tab
            event.target.closest('.nav-tab').classList.add('active');
        }
        
        // Auto-refresh stats every 30 seconds
        setInterval(() => {
            location.reload();
        }, 30000);
    </script>
</body>
</html>
