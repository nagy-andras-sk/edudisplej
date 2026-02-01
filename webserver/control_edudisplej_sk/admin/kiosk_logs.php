<?php
/**
 * Kiosk Logs Viewer
 * EduDisplej Admin Panel
 */

session_start();
require_once '../dbkonfiguracia.php';

// Check if user is logged in and is admin
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header('Location: ../login.php');
    exit;
}

// Get kiosk ID from URL
$kiosk_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

$conn = getDbConnection();

// Get kiosk info
$kiosk = null;
if ($kiosk_id > 0) {
    $stmt = $conn->prepare("SELECT k.*, c.name as company_name FROM kiosks k LEFT JOIN companies c ON k.company_id = c.id WHERE k.id = ?");
    $stmt->bind_param("i", $kiosk_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $kiosk = $result->fetch_assoc();
}

// Get logs
$logs = [];
if ($kiosk_id > 0) {
    $log_type = isset($_GET['type']) ? $_GET['type'] : 'all';
    $log_level = isset($_GET['level']) ? $_GET['level'] : 'all';
    $limit = isset($_GET['limit']) ? intval($_GET['limit']) : 100;
    
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
}

include 'header.php';
?>

<div class="container" style="max-width: 1400px; margin: 30px auto; padding: 0 20px;">
    <?php if ($kiosk): ?>
        <div style="margin-bottom: 20px;">
            <a href="index.php" style="color: #1a3a52; text-decoration: none;">&larr; Back to Kiosks</a>
        </div>
        
        <div style="background: white; border-radius: 8px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <h2 style="margin: 0 0 10px 0;">Kiosk Logs: <?php echo htmlspecialchars($kiosk['device_id']); ?></h2>
            <div style="color: #666; font-size: 14px;">
                <strong>Hostname:</strong> <?php echo htmlspecialchars($kiosk['hostname']); ?> | 
                <strong>Company:</strong> <?php echo htmlspecialchars($kiosk['company_name'] ?? 'Not assigned'); ?> | 
                <strong>MAC:</strong> <?php echo htmlspecialchars($kiosk['mac']); ?>
            </div>
        </div>
        
        <!-- Filters -->
        <div style="background: white; border-radius: 8px; padding: 15px; margin-bottom: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <form method="GET" action="" style="display: flex; gap: 10px; align-items: center; flex-wrap: wrap;">
                <input type="hidden" name="id" value="<?php echo $kiosk_id; ?>">
                
                <label style="font-weight: 600;">Type:</label>
                <select name="type" style="padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="all" <?php echo (!isset($_GET['type']) || $_GET['type'] === 'all') ? 'selected' : ''; ?>>All</option>
                    <option value="sync" <?php echo (isset($_GET['type']) && $_GET['type'] === 'sync') ? 'selected' : ''; ?>>Sync</option>
                    <option value="systemd" <?php echo (isset($_GET['type']) && $_GET['type'] === 'systemd') ? 'selected' : ''; ?>>Systemd</option>
                    <option value="general" <?php echo (isset($_GET['type']) && $_GET['type'] === 'general') ? 'selected' : ''; ?>>General</option>
                </select>
                
                <label style="font-weight: 600; margin-left: 15px;">Level:</label>
                <select name="level" style="padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="all" <?php echo (!isset($_GET['level']) || $_GET['level'] === 'all') ? 'selected' : ''; ?>>All</option>
                    <option value="error" <?php echo (isset($_GET['level']) && $_GET['level'] === 'error') ? 'selected' : ''; ?>>Error</option>
                    <option value="warning" <?php echo (isset($_GET['level']) && $_GET['level'] === 'warning') ? 'selected' : ''; ?>>Warning</option>
                    <option value="info" <?php echo (isset($_GET['level']) && $_GET['level'] === 'info') ? 'selected' : ''; ?>>Info</option>
                </select>
                
                <label style="font-weight: 600; margin-left: 15px;">Limit:</label>
                <select name="limit" style="padding: 5px 10px; border: 1px solid #ddd; border-radius: 4px;">
                    <option value="50" <?php echo (isset($_GET['limit']) && $_GET['limit'] == '50') ? 'selected' : ''; ?>>50</option>
                    <option value="100" <?php echo (!isset($_GET['limit']) || $_GET['limit'] == '100') ? 'selected' : ''; ?>>100</option>
                    <option value="200" <?php echo (isset($_GET['limit']) && $_GET['limit'] == '200') ? 'selected' : ''; ?>>200</option>
                    <option value="500" <?php echo (isset($_GET['limit']) && $_GET['limit'] == '500') ? 'selected' : ''; ?>>500</option>
                </select>
                
                <button type="submit" style="padding: 5px 15px; background: #1a3a52; color: white; border: none; border-radius: 4px; cursor: pointer; margin-left: 10px;">Filter</button>
                <a href="?id=<?php echo $kiosk_id; ?>" style="padding: 5px 15px; background: #ddd; color: #333; text-decoration: none; border-radius: 4px;">Reset</a>
            </form>
        </div>
        
        <!-- Logs Table -->
        <div style="background: white; border-radius: 8px; padding: 20px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <?php if (count($logs) > 0): ?>
                <div style="overflow-x: auto;">
                    <table style="width: 100%; border-collapse: collapse;">
                        <thead>
                            <tr style="background: #f5f5f5; border-bottom: 2px solid #ddd;">
                                <th style="padding: 10px; text-align: left; font-weight: 600; width: 150px;">Time</th>
                                <th style="padding: 10px; text-align: left; font-weight: 600; width: 80px;">Type</th>
                                <th style="padding: 10px; text-align: left; font-weight: 600; width: 80px;">Level</th>
                                <th style="padding: 10px; text-align: left; font-weight: 600;">Message</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($logs as $log): 
                                $level_color = 'inherit';
                                if ($log['log_level'] === 'error') $level_color = '#d32f2f';
                                elseif ($log['log_level'] === 'warning') $level_color = '#f57c00';
                                elseif ($log['log_level'] === 'info') $level_color = '#1976d2';
                            ?>
                                <tr style="border-bottom: 1px solid #eee;">
                                    <td style="padding: 10px; font-size: 12px; color: #666;">
                                        <?php echo date('Y-m-d H:i:s', strtotime($log['created_at'])); ?>
                                    </td>
                                    <td style="padding: 10px;">
                                        <span style="padding: 3px 8px; background: #e3f2fd; color: #1976d2; border-radius: 3px; font-size: 12px;">
                                            <?php echo htmlspecialchars($log['log_type']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px;">
                                        <span style="padding: 3px 8px; background: <?php echo $level_color; ?>15; color: <?php echo $level_color; ?>; border-radius: 3px; font-size: 12px; font-weight: 600;">
                                            <?php echo strtoupper($log['log_level']); ?>
                                        </span>
                                    </td>
                                    <td style="padding: 10px; font-family: monospace; font-size: 13px; word-break: break-word;">
                                        <?php echo htmlspecialchars($log['message']); ?>
                                        <?php if ($log['details']): ?>
                                            <details style="margin-top: 5px;">
                                                <summary style="cursor: pointer; color: #666; font-size: 12px;">Show details</summary>
                                                <pre style="margin-top: 5px; padding: 10px; background: #f5f5f5; border-radius: 4px; overflow-x: auto; font-size: 11px;"><?php echo htmlspecialchars($log['details']); ?></pre>
                                            </details>
                                        <?php endif; ?>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="margin-top: 20px; color: #666; font-size: 14px;">
                    Showing <?php echo count($logs); ?> log entries
                </div>
            <?php else: ?>
                <div style="text-align: center; padding: 40px; color: #999;">
                    <p style="font-size: 18px; margin-bottom: 10px;">📋 No logs found</p>
                    <p>Logs will appear here when the kiosk reports errors or warnings</p>
                </div>
            <?php endif; ?>
        </div>
        
    <?php else: ?>
        <div style="background: white; border-radius: 8px; padding: 40px; text-align: center; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
            <p style="color: #d32f2f; font-size: 18px; margin-bottom: 10px;">❌ Kiosk not found</p>
            <a href="index.php" style="color: #1a3a52; text-decoration: none;">← Back to Kiosks</a>
        </div>
    <?php endif; ?>
</div>

<?php include 'footer.php'; ?>
