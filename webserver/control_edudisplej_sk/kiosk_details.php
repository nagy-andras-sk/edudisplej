<?php
/**
 * Kiosk Details and Screenshot Viewer
 * EduDisplej Control Panel
 */

session_start();
require_once 'dbkonfiguracia.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: admin.php');
    exit();
}

$kiosk_id = intval($_GET['id'] ?? 0);
$kiosk = null;
$logs = [];

if ($kiosk_id > 0) {
    try {
        $conn = getDbConnection();
        
        // Get kiosk details
        $stmt = $conn->prepare("SELECT k.*, c.name as company_name FROM kiosks k LEFT JOIN companies c ON k.company_id = c.id WHERE k.id = ?");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $kiosk = $result->fetch_assoc();
        $stmt->close();
        
        // Get sync logs
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
    header('Location: admin.php');
    exit();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Kiosk Details - <?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f5f5;
        }
        
        .navbar {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 30px;
            display: flex;
            justify-content: space-between;
            align-items: center;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        
        .navbar h1 {
            font-size: 24px;
        }
        
        .navbar a {
            color: white;
            text-decoration: none;
            padding: 8px 15px;
            border-radius: 5px;
            background: rgba(255,255,255,0.2);
            transition: background 0.3s;
        }
        
        .navbar a:hover {
            background: rgba(255,255,255,0.3);
        }
        
        .container {
            padding: 30px;
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .card {
            background: white;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        .card h2 {
            margin-bottom: 20px;
            color: #333;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: 200px 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
        }
        
        .info-value {
            color: #333;
        }
        
        .screenshot-container {
            text-align: center;
            padding: 20px;
            background: #f9f9f9;
            border-radius: 5px;
        }
        
        .screenshot-container img {
            max-width: 100%;
            height: auto;
            border: 2px solid #ddd;
            border-radius: 5px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        
        .no-screenshot {
            color: #999;
            font-style: italic;
            padding: 40px;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #eee;
        }
        
        th {
            background: #f9f9f9;
            font-weight: 600;
        }
        
        .status-badge {
            padding: 5px 10px;
            border-radius: 15px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .status-online {
            background: #d4edda;
            color: #155724;
        }
        
        .status-offline {
            background: #f8d7da;
            color: #721c24;
        }
        
        .status-pending {
            background: #fff3cd;
            color: #856404;
        }
        
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 3px;
            font-family: 'Courier New', monospace;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🖥️ Kiosk Details</h1>
        <a href="admin.php">← Back to Dashboard</a>
    </div>
    
    <div class="container">
        <div class="card">
            <h2><?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?></h2>
            
            <div class="info-grid">
                <div class="info-label">Status:</div>
                <div class="info-value">
                    <span class="status-badge status-<?php echo $kiosk['status']; ?>">
                        <?php echo ucfirst($kiosk['status']); ?>
                    </span>
                </div>
                
                <div class="info-label">MAC Address:</div>
                <div class="info-value"><code><?php echo htmlspecialchars($kiosk['mac']); ?></code></div>
                
                <div class="info-label">Company:</div>
                <div class="info-value"><?php echo htmlspecialchars($kiosk['company_name'] ?? 'Unassigned'); ?></div>
                
                <div class="info-label">Location:</div>
                <div class="info-value"><?php echo htmlspecialchars($kiosk['location'] ?? '-'); ?></div>
                
                <div class="info-label">Installed:</div>
                <div class="info-value"><?php echo date('Y-m-d H:i', strtotime($kiosk['installed'])); ?></div>
                
                <div class="info-label">Last Seen:</div>
                <div class="info-value"><?php echo $kiosk['last_seen'] ? date('Y-m-d H:i', strtotime($kiosk['last_seen'])) : 'Never'; ?></div>
                
                <div class="info-label">Sync Interval:</div>
                <div class="info-value"><?php echo $kiosk['sync_interval']; ?> seconds</div>
                
                <div class="info-label">Comment:</div>
                <div class="info-value"><?php echo htmlspecialchars($kiosk['comment'] ?? '-'); ?></div>
            </div>
            
            <?php if ($kiosk['hw_info']): ?>
                <h3>Hardware Information</h3>
                <pre style="background: #f4f4f4; padding: 15px; border-radius: 5px; overflow-x: auto;"><?php echo htmlspecialchars(json_encode(json_decode($kiosk['hw_info']), JSON_PRETTY_PRINT)); ?></pre>
            <?php endif; ?>
        </div>
        
        <div class="card">
            <h2>Screenshot</h2>
            <div class="screenshot-container">
                <?php if ($kiosk['screenshot_url'] && file_exists($kiosk['screenshot_url'])): ?>
                    <img src="<?php echo htmlspecialchars($kiosk['screenshot_url']); ?>" alt="Kiosk Screenshot">
                    <p style="margin-top: 10px; color: #666;">
                        <small>Last updated: <?php echo date('Y-m-d H:i', filemtime($kiosk['screenshot_url'])); ?></small>
                    </p>
                <?php else: ?>
                    <div class="no-screenshot">No screenshot available</div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (!empty($logs)): ?>
            <div class="card">
                <h2>Recent Activity</h2>
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
                                <td><?php echo date('Y-m-d H:i:s', strtotime($log['timestamp'])); ?></td>
                                <td><?php echo htmlspecialchars($log['action']); ?></td>
                                <td><code><?php echo htmlspecialchars(substr($log['details'] ?? '', 0, 100)); ?></code></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>
