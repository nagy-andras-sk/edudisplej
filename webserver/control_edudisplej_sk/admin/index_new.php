<?php
/**
 * Admin Portal - Main Dashboard
 * EduDisplej Control Panel
 * 
 * Admin features:
 * - View and manage unassigned kiosks
 * - Assign kiosks to companies
 * - Configure company module settings
 * - View Raspberry Pi hardware data
 * - Toggle fast/slow ping intervals
 * - Manage users and reset passwords
 */

session_start();
require_once '../dbkonfiguracia.php';

// Check if user is logged in and is admin
$is_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['isadmin']) && $_SESSION['isadmin'];

// If not logged in, redirect to login page
if (!$is_logged_in) {
    header('Location: ../login.php');
    exit();
}

$error = '';
$success = '';

// Handle screenshot request
if (isset($_GET['screenshot']) && is_numeric($_GET['screenshot'])) {
    try {
        $conn = getDbConnection();
        $kiosk_id = intval($_GET['screenshot']);
        $stmt = $conn->prepare("UPDATE kiosks SET screenshot_requested = 1 WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $stmt->close();
        closeDbConnection($conn);
        $success = 'Screenshot requested successfully!';
    } catch (Exception $e) {
        $error = 'Failed to request screenshot';
    }
}

// Handle ping interval toggle
if (isset($_GET['toggle_ping']) && is_numeric($_GET['toggle_ping'])) {
    try {
        $conn = getDbConnection();
        $kiosk_id = intval($_GET['toggle_ping']);
        
        // Get current interval
        $stmt = $conn->prepare("SELECT sync_interval FROM kiosks WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $kiosk = $result->fetch_assoc();
        
        // Toggle between 10s (fast) and 300s (slow)
        $new_interval = ($kiosk['sync_interval'] == 10) ? 300 : 10;
        
        $stmt = $conn->prepare("UPDATE kiosks SET sync_interval = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_interval, $kiosk_id);
        $stmt->execute();
        $stmt->close();
        closeDbConnection($conn);
        $success = 'Ping interval updated successfully!';
    } catch (Exception $e) {
        $error = 'Failed to update ping interval';
    }
}

// Get kiosks data
$kiosks = [];
$companies = [];
try {
    $conn = getDbConnection();
    
    // Get companies
    $result = $conn->query("SELECT * FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }
    
    // Get kiosks with company info
    $query = "SELECT k.*, c.name as company_name 
              FROM kiosks k 
              LEFT JOIN companies c ON k.company_id = c.id 
              ORDER BY k.last_seen DESC";
    $result = $conn->query($query);
    
    while ($row = $result->fetch_assoc()) {
        $kiosks[] = $row;
    }
    
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load data: ' . $e->getMessage();
}

// Count unassigned kiosks
$unassigned_count = count(array_filter($kiosks, fn($k) => empty($k['company_id'])));
$online_count = count(array_filter($kiosks, fn($k) => $k['status'] == 'online'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal - EduDisplej Control</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            border-bottom: 2px solid #eee;
        }
        
        .admin-tab {
            padding: 12px 20px;
            background: none;
            border: none;
            color: #666;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s ease;
        }
        
        .admin-tab.active {
            color: #1e40af;
            border-bottom-color: #1e40af;
        }
        
        .admin-tab:hover {
            color: #1e40af;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
            animation: fadeIn 0.3s ease;
        }
        
        @keyframes fadeIn {
            from { opacity: 0; }
            to { opacity: 1; }
        }
        
        .status-online {
            color: #28a745;
            background: #d4edda;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
        }
        
        .status-offline {
            color: #dc3545;
            background: #f8d7da;
            padding: 4px 8px;
            border-radius: 3px;
            font-size: 12px;
        }
        
        .hw-table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 5px;
            overflow: hidden;
        }
        
        .hw-table th {
            background: #f8f9fa;
            padding: 12px;
            text-align: left;
            font-weight: 600;
            border-bottom: 2px solid #eee;
        }
        
        .hw-table td {
            padding: 12px;
            border-bottom: 1px solid #eee;
        }
        
        .hw-table tr:hover {
            background: #f9f9f9;
        }
    </style>
</head>
<body>
    <div class="navbar">
        <h1>🖥️ EduDisplej Admin Portal</h1>
        <div class="user-info">
            <span>Welcome, <?php echo htmlspecialchars($_SESSION['username']); ?></span>
            <a href="../login.php?logout=1">Logout</a>
        </div>
    </div>
    
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
                <h3>Total Kiosks</h3>
                <div class="number"><?php echo count($kiosks); ?></div>
            </div>
            <div class="stat-card">
                <h3>Online</h3>
                <div class="number" style="color: #28a745;"><?php echo $online_count; ?></div>
            </div>
            <div class="stat-card">
                <h3>Offline</h3>
                <div class="number" style="color: #dc3545;"><?php echo count($kiosks) - $online_count; ?></div>
            </div>
            <div class="stat-card">
                <h3>Unassigned</h3>
                <div class="number" style="color: #ffc107;"><?php echo $unassigned_count; ?></div>
            </div>
            <div class="stat-card">
                <h3>Companies</h3>
                <div class="number"><?php echo count($companies); ?></div>
            </div>
        </div>
        
        <!-- Tabs -->
        <div class="admin-tabs">
            <button class="admin-tab active" onclick="switchTab('dashboard')">📊 Dashboard</button>
            <button class="admin-tab" onclick="switchTab('kiosks')">🖥️ Kiosks</button>
            <button class="admin-tab" onclick="switchTab('hardware')">⚙️ Hardware</button>
            <button class="admin-tab" onclick="switchTab('users')">👥 Users</button>
            <button class="admin-tab" onclick="switchTab('companies')">🏢 Companies</button>
            <button class="admin-tab" onclick="switchTab('modules')">🔌 Modules</button>
        </div>
        
        <!-- Dashboard Tab -->
        <div id="dashboard" class="tab-content active">
            <div style="background: white; padding: 20px; border-radius: 10px;">
                <h2>Quick Overview</h2>
                <p style="color: #666; margin: 15px 0;">
                    This admin portal gives you complete control over your EduDisplej kiosk network.
                    Use the tabs above to manage different aspects of your system.
                </p>
                
                <?php if ($unassigned_count > 0): ?>
                    <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin: 20px 0;">
                        <strong style="color: #856404;">⚠️ <?php echo $unassigned_count; ?> Unassigned Kiosk<?php echo $unassigned_count != 1 ? 's' : ''; ?></strong>
                        <p style="color: #856404; margin: 5px 0;">
                            Please assign these kiosks to companies. Go to the <strong>Kiosks</strong> tab to manage assignments.
                        </p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Kiosks Tab -->
        <div id="kiosks" class="tab-content">
            <div style="background: white; padding: 20px; border-radius: 10px;">
                <h2>Kiosk Management</h2>
                
                <!-- Unassigned Kiosks -->
                <?php if (!empty(array_filter($kiosks, fn($k) => empty($k['company_id'])))): ?>
                    <div style="background: #f0f4ff; padding: 20px; border-radius: 5px; margin-bottom: 30px;">
                        <h3 style="color: #1e40af; margin-bottom: 15px;">
                            ⚠️ Unassigned Kiosks (<?php echo $unassigned_count; ?>)
                        </h3>
                        <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 15px;">
                            <?php foreach (array_filter($kiosks, fn($k) => empty($k['company_id'])) as $kiosk): ?>
                                <div style="border: 2px solid #1e40af; padding: 15px; border-radius: 5px; background: white;">
                                    <div style="font-weight: 600; margin-bottom: 8px;">
                                        <?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?>
                                    </div>
                                    <div style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                        📍 <?php echo htmlspecialchars($kiosk['location'] ?? 'No location'); ?><br>
                                        💻 <?php echo htmlspecialchars($kiosk['device_id'] ?? 'N/A'); ?><br>
                                        <span class="status-<?php echo $kiosk['status']; ?>">
                                            ● <?php echo ucfirst($kiosk['status']); ?>
                                        </span>
                                    </div>
                                    <select onchange="assignCompany(<?php echo $kiosk['id']; ?>, this.value)">
                                        <option value="">Assign to company...</option>
                                        <?php foreach ($companies as $company): ?>
                                            <option value="<?php echo $company['id']; ?>"><?php echo htmlspecialchars($company['name']); ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                    <div style="margin-top: 10px;">
                                        <a href="kiosk_details.php?id=<?php echo $kiosk['id']; ?>" style="color: #1e40af; font-size: 12px;">View details →</a>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                <?php endif; ?>
                
                <!-- All Kiosks Table -->
                <h3 style="margin-bottom: 15px;">All Kiosks</h3>
                <div style="overflow-x: auto;">
                    <table class="hw-table">
                        <thead>
                            <tr>
                                <th>Hostname</th>
                                <th>Company</th>
                                <th>Status</th>
                                <th>Location</th>
                                <th>Last Seen</th>
                                <th>Sync Interval</th>
                                <th>Actions</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kiosks as $kiosk): ?>
                                <tr>
                                    <td>
                                        <strong><?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?></strong><br>
                                        <small style="color: #666;">ID: <?php echo $kiosk['id']; ?></small>
                                    </td>
                                    <td><?php echo htmlspecialchars($kiosk['company_name'] ?? '—'); ?></td>
                                    <td>
                                        <?php if ($kiosk['status'] == 'online'): ?>
                                            <span class="status-online">● Online</span>
                                        <?php else: ?>
                                            <span class="status-offline">● Offline</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($kiosk['location'] ?? '—'); ?></td>
                                    <td style="font-size: 12px;">
                                        <?php echo $kiosk['last_seen'] ? date('Y-m-d H:i', strtotime($kiosk['last_seen'])) : 'Never'; ?>
                                    </td>
                                    <td><?php echo $kiosk['sync_interval']; ?>s</td>
                                    <td style="font-size: 12px;">
                                        <a href="kiosk_details.php?id=<?php echo $kiosk['id']; ?>" style="color: #1e40af;">📋 View</a>
                                        <a href="?screenshot=<?php echo $kiosk['id']; ?>" style="color: #1e40af; margin-left: 10px;" onclick="return confirm('Request screenshot?')">📸 Screenshot</a>
                                        <a href="?toggle_ping=<?php echo $kiosk['id']; ?>" style="color: #1e40af; margin-left: 10px;">
                                            <?php echo $kiosk['sync_interval'] == 10 ? '🐌 Slow' : '⚡ Fast'; ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        
        <!-- Hardware Tab -->
        <div id="hardware" class="tab-content">
            <div style="background: white; padding: 20px; border-radius: 10px;">
                <h2>Hardware Information & Ping Control</h2>
                <p style="color: #666; margin-bottom: 20px;">
                    View detailed hardware information for each kiosk and control ping intervals.
                </p>
                
                <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(400px, 1fr)); gap: 20px;">
                    <?php foreach (array_filter($kiosks, fn($k) => !empty($k['hw_info'])) as $kiosk): ?>
                        <div style="border: 1px solid #eee; padding: 15px; border-radius: 5px; background: #f9f9f9;">
                            <div style="font-weight: 600; margin-bottom: 10px;">
                                <?php echo htmlspecialchars($kiosk['hostname'] ?? 'Kiosk #' . $kiosk['id']); ?>
                                <span style="font-size: 12px; color: #666;">
                                    (<?php echo $kiosk['sync_interval'] ?>s)
                                </span>
                            </div>
                            <div style="background: white; padding: 10px; border-radius: 3px; margin-bottom: 10px; font-size: 12px;">
                                <pre style="margin: 0; white-space: pre-wrap; word-wrap: break-word;">
<?php echo htmlspecialchars(json_encode(json_decode($kiosk['hw_info']), JSON_PRETTY_PRINT)); ?>
                                </pre>
                            </div>
                            <div>
                                <a href="?toggle_ping=<?php echo $kiosk['id']; ?>" class="btn btn-sm btn-warning" style="display: inline-block; margin-bottom: 5px;">
                                    <?php echo $kiosk['sync_interval'] == 10 ? '🐌 Switch to Slow Ping (300s)' : '⚡ Switch to Fast Ping (10s)'; ?>
                                </a>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
                
                <?php if (empty(array_filter($kiosks, fn($k) => !empty($k['hw_info'])))): ?>
                    <div style="text-align: center; color: #999; padding: 40px;">
                        No hardware information available yet. Kiosks will send their data upon connection.
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Users Tab -->
        <div id="users" class="tab-content">
            <div style="background: white; padding: 20px; border-radius: 10px;">
                <h2>User Management</h2>
                <p style="color: #666; margin-bottom: 20px;">
                    Create, modify, and manage user accounts here.
                </p>
                <a href="users.php" class="btn btn-primary">Manage Users</a>
            </div>
        </div>
        
        <!-- Companies Tab -->
        <div id="companies" class="tab-content">
            <div style="background: white; padding: 20px; border-radius: 10px;">
                <h2>Company Management</h2>
                <p style="color: #666; margin-bottom: 20px;">
                    Create and manage companies, assign kiosks to companies.
                </p>
                <a href="companies.php" class="btn btn-primary">Manage Companies</a>
            </div>
        </div>
        
        <!-- Modules Tab -->
        <div id="modules" class="tab-content">
            <div style="background: white; padding: 20px; border-radius: 10px;">
                <h2>Module & License Management</h2>
                <p style="color: #666; margin-bottom: 20px;">
                    Configure module licenses for companies and assign modules to kiosks.
                </p>
                <a href="module_licenses.php" class="btn btn-primary">Manage Modules</a>
            </div>
        </div>
    </div>
    
    <script>
        function switchTab(tabName) {
            // Hide all tabs
            const tabs = document.querySelectorAll('.tab-content');
            tabs.forEach(tab => tab.classList.remove('active'));
            
            // Remove active class from all buttons
            const buttons = document.querySelectorAll('.admin-tab');
            buttons.forEach(btn => btn.classList.remove('active'));
            
            // Show selected tab
            document.getElementById(tabName).classList.add('active');
            
            // Add active class to clicked button
            event.target.classList.add('active');
        }
        
        async function assignCompany(kioskId, companyId) {
            if (!companyId) return;
            
            try {
                const response = await fetch('../api/assign_company.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        kiosk_id: kioskId,
                        company_id: companyId === '' ? null : parseInt(companyId)
                    })
                });
                
                const data = await response.json();
                
                if (data.success) {
                    // Show success message and reload
                    alert('Kiosk assigned successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Failed to assign kiosk');
            }
        }
    </script>
</body>
</html>

