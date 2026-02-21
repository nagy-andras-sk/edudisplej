<?php
/**
 * User Dashboard Portal - Main View
 * For end users to manage their assigned kiosks
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../kiosk_status.php';

// Check if user is logged in and is NOT admin
if (!isset($_SESSION['user_id']) || !isset($_SESSION['username'])) {
    header('Location: ../login.php');
    exit();
}

if (isset($_SESSION['isadmin']) && $_SESSION['isadmin']) {
    header('Location: ../admin/dashboard.php');
    exit();
}

$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$error = '';
$success = '';

// Get kiosks data
$kiosks = [];
$company = null;
try {
    $conn = getDbConnection();
    
    // Get company info
    if ($company_id) {
        $stmt = $conn->prepare("SELECT * FROM companies WHERE id = ?");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $company = $stmt->get_result()->fetch_assoc();
        $stmt->close();
    }
    
    // Get kiosks
    if ($company_id) {
        $stmt = $conn->prepare("SELECT k.* FROM kiosks k WHERE k.company_id = ? ORDER BY k.hostname");
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            kiosk_apply_effective_status($row);
            $kiosks[] = $row;
        }
        $stmt->close();
    }
    
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load data: ' . $e->getMessage();
}

$online_count = count(array_filter($kiosks, fn($k) => $k['status'] == 'online'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>EDUDISPLEJ - Irányítási Panel</title>
    <link rel="icon" type="image/svg+xml" href="../favicon.svg">
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
        
        /* Header */
        .header {
            background: #1a1a1a;
            color: white;
            padding: 20px 30px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.2);
        }
        
        .header-content {
            max-width: 1200px;
            margin: 0 auto;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        
        .header-title {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        
        .header-title h1 {
            font-size: 32px;
            font-weight: 700;
            letter-spacing: 2px;
        }
        
        .header-subtitle {
            color: #aaa;
            font-size: 14px;
        }
        
        .header-user {
            display: flex;
            gap: 15px;
            align-items: center;
            color: #aaa;
            font-size: 13px;
        }
        
        .header-user a {
            color: #1e40af;
            text-decoration: none;
            padding: 8px 15px;
            border: 1px solid #1e40af;
            border-radius: 3px;
            transition: all 0.3s;
        }
        
        .header-user a:hover {
            background: #1e40af;
            color: white;
        }
        
        /* Container */
        .container {
            max-width: 1200px;
            margin: 30px auto;
            padding: 0 20px;
        }
        
        /* Stats */
        .stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 15px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: white;
            padding: 20px;
            border-radius: 5px;
            text-align: center;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .stat-card h3 {
            color: #666;
            font-size: 12px;
            font-weight: 600;
            margin-bottom: 10px;
            text-transform: uppercase;
        }
        
        .stat-card .number {
            font-size: 32px;
            font-weight: 700;
            color: #1e40af;
        }
        
        /* Messages */
        .error {
            background: #f8d7da;
            border: 1px solid #f5c6cb;
            color: #721c24;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        .success {
            background: #d4edda;
            border: 1px solid #c3e6cb;
            color: #155724;
            padding: 12px 15px;
            border-radius: 5px;
            margin-bottom: 20px;
        }
        
        /* Tabs */
        .nav-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 20px;
            border-bottom: 2px solid #eee;
            background: white;
            padding: 0 20px;
            border-radius: 5px 5px 0 0;
        }
        
        .nav-tabs a {
            padding: 15px 20px;
            text-decoration: none;
            color: #666;
            font-weight: 500;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
            display: block;
        }
        
        .nav-tabs a.active {
            color: #1e40af;
            border-bottom-color: #1e40af;
        }
        
        .nav-tabs a:hover {
            color: #1e40af;
        }
        
        /* Content */
        .content {
            background: white;
            padding: 30px;
            border-radius: 5px;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        
        .content h2 {
            margin-bottom: 20px;
            color: #333;
            font-size: 22px;
        }
        
        /* Table */
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
        }
        
        table thead {
            background: #f8f9fa;
            border-bottom: 2px solid #eee;
        }
        
        table th {
            padding: 12px;
            text-align: left;
            font-weight: 600;
            font-size: 13px;
            color: #666;
        }
        
        table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        table tr:hover {
            background: #f9f9f9;
        }
        
        .status-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .status-online {
            background: #d4edda;
            color: #28a745;
        }
        
        .status-offline {
            background: #f8d7da;
            color: #dc3545;
        }
        
        .btn {
            display: inline-block;
            padding: 6px 12px;
            background: #1e40af;
            color: white;
            border: none;
            border-radius: 3px;
            text-decoration: none;
            font-size: 12px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .btn:hover {
            background: #0369a1;
        }
        
        .btn-small {
            padding: 4px 8px;
            font-size: 11px;
        }
        
        /* No data message */
        .no-data {
            text-align: center;
            padding: 40px;
            color: #999;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-content">
            <div class="header-title">
                <div>
                    <h1>EDUDISPLEJ</h1>
                    <div class="header-subtitle">Irányítási panel</div>
                </div>
            </div>
            <div class="header-user">
                <?php if ($company): ?>
                    <span><?php echo htmlspecialchars($company['name']); ?></span>
                <?php endif; ?>
                <span><?php echo htmlspecialchars($_SESSION['username']); ?></span>
                <a href="../login.php?logout=1">Kilépés</a>
            </div>
        </div>
    </div>
    
    <!-- Main Content -->
    <div class="container">
        <?php if ($success): ?>
            <div class="success">✓ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Stats -->
        <div class="stats">
            <div class="stat-card">
                <h3>Kijelzők</h3>
                <div class="number"><?php echo count($kiosks); ?></div>
            </div>
            <div class="stat-card">
                <h3>Online</h3>
                <div class="number"><?php echo $online_count; ?></div>
            </div>
            <div class="stat-card">
                <h3>Offline</h3>
                <div class="number"><?php echo count($kiosks) - $online_count; ?></div>
            </div>
        </div>
        
        <!-- Navigation & Content -->
        <div class="nav-tabs">
            <a href="#kiosks" class="active" onclick="switchTab('kiosks', event)">🖥️ Kijelzők</a>
            <a href="#edit" onclick="switchTab('edit', event)">⚙️ Szerkesztés</a>
        </div>
        
        <div class="content">
            <!-- Kiosks Tab -->
            <div id="kiosks-tab">
                <h2>📺 Kijelzők Listája</h2>
                
                <?php if (empty($kiosks)): ?>
                    <div class="no-data">
                        <p>Nincsenek kijelzők hozzárendelve a fiókodhoz.</p>
                    </div>
                <?php else: ?>
                    <div class="table-container">
                        <table>
                            <thead>
                                <tr>
                                    <th>Kijelző Neve</th>
                                    <th>Státusz</th>
                                    <th>Hely</th>
                                    <th>Utolsó Szinkronizálás</th>
                                    <th>Modulok / Csoport</th>
                                    <th>Loop</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($kiosks as $kiosk): ?>
                                    <tr>
                                        <td>
                                            <strong><?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?></strong>
                                            <br><small style="color: #666;">ID: <?php echo $kiosk['id']; ?></small>
                                        </td>
                                        <td>
                                            <span class="status-badge status-<?php echo $kiosk['status']; ?>">
                                                ● <?php echo ucfirst($kiosk['status']); ?>
                                            </span>
                                        </td>
                                        <td><?php echo htmlspecialchars($kiosk['location'] ?? '—'); ?></td>
                                        <td style="font-size: 12px;">
                                            <?php echo $kiosk['last_seen'] ? date('Y-m-d H:i', strtotime($kiosk['last_seen'])) : 'Soha'; ?>
                                        </td>
                                        <td>—</td>
                                        <td>—</td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            
            <!-- Edit Tab -->
            <div id="edit-tab" style="display: none;">
                <h2>⚙️ Kijelzők Szerkesztése</h2>
                <p style="color: #666; margin-bottom: 20px;">
                    Válassz egy kijelzőt az alábbiakból a módosításhoz.
                </p>
                
                <?php if (empty($kiosks)): ?>
                    <div class="no-data">
                        <p>Nincsenek kijelzők.</p>
                    </div>
                <?php else: ?>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px;">
                        <?php foreach ($kiosks as $kiosk): ?>
                            <div style="border: 1px solid #eee; padding: 15px; border-radius: 5px; cursor: pointer; transition: all 0.3s;" 
                                 onmouseover="this.style.boxShadow='0 2px 8px rgba(0,0,0,0.1)'" 
                                 onmouseout="this.style.boxShadow='none'">
                                <div style="font-weight: 600; margin-bottom: 8px;">
                                    <?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?>
                                </div>
                                <div style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                    📍 <?php echo htmlspecialchars($kiosk['location'] ?? '—'); ?><br>
                                    💻 <?php echo htmlspecialchars($kiosk['device_id'] ?? '—'); ?>
                                </div>
                                <a href="kiosk_edit.php?id=<?php echo $kiosk['id']; ?>" class="btn btn-small">Szerkesztés →</a>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <script>
        function switchTab(tab, event) {
            event.preventDefault();
            
            // Hide all tabs
            document.getElementById('kiosks-tab').style.display = 'none';
            document.getElementById('edit-tab').style.display = 'none';
            
            // Remove active class
            document.querySelectorAll('.nav-tabs a').forEach(a => a.classList.remove('active'));
            
            // Show selected tab
            const tabId = tab === 'kiosks' ? 'kiosks-tab' : 'edit-tab';
            document.getElementById(tabId).style.display = 'block';
            event.target.classList.add('active');
        }
    </script>
</body>
</html>

