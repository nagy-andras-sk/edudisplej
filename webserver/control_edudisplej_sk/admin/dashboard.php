<?php
/**
 * Admin Portal New Design
 * Restructured admin panel with new dashboard
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
        $success = 'Screenshot requested!';
    } catch (Exception $e) {
        $error = 'Failed to request screenshot';
    }
}

// Handle ping interval toggle (10s fast / 300s slow)
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
        
        // Toggle between 10s and 300s
        $new_interval = ($kiosk['sync_interval'] == 10) ? 300 : 10;
        
        $stmt = $conn->prepare("UPDATE kiosks SET sync_interval = ? WHERE id = ?");
        $stmt->bind_param("ii", $new_interval, $kiosk_id);
        $stmt->execute();
        $stmt->close();
        closeDbConnection($conn);
        $success = 'Ping interval updated!';
    } catch (Exception $e) {
        $error = 'Failed to update interval';
    }
}

// Get data
$kiosks = [];
$companies = [];
try {
    $conn = getDbConnection();
    
    // Companies
    $result = $conn->query("SELECT * FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }
    
    // Kiosks
    $query = "SELECT k.*, c.name as company_name FROM kiosks k 
              LEFT JOIN companies c ON k.company_id = c.id 
              ORDER BY k.last_seen DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $kiosks[] = $row;
    }
    
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load data';
}

// Stats
$unassigned = count(array_filter($kiosks, fn($k) => empty($k['company_id'])));
$online = count(array_filter($kiosks, fn($k) => $k['status'] == 'online'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Admin Portal - EduDisplej</title>
    <link rel="stylesheet" href="style.css">
    <style>
        .admin-nav {
            display: flex;
            gap: 0;
            background: white;
            border-bottom: 2px solid #eee;
            margin-bottom: 30px;
        }
        
        .nav-btn {
            padding: 15px 25px;
            background: none;
            border: none;
            color: #666;
            font-weight: 500;
            cursor: pointer;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .nav-btn.active {
            color: #1e40af;
            border-bottom-color: #1e40af;
        }
        
        .nav-btn:hover {
            color: #1e40af;
        }
        
        .view { display: none; }
        .view.active { display: block; }
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
            <div class="success" style="margin-bottom: 20px;">✓ <?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="error" style="margin-bottom: 20px;">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <!-- Stats Cards -->
        <div class="stats">
            <div class="stat-card">
                <h3>Total Kiosks</h3>
                <div class="number"><?php echo count($kiosks); ?></div>
            </div>
            <div class="stat-card">
                <h3>Online</h3>
                <div class="number"><?php echo $online; ?></div>
            </div>
            <div class="stat-card">
                <h3>Offline</h3>
                <div class="number"><?php echo count($kiosks) - $online; ?></div>
            </div>
            <div class="stat-card">
                <h3>Unassigned</h3>
                <div class="number"><?php echo $unassigned; ?></div>
            </div>
            <div class="stat-card">
                <h3>Companies</h3>
                <div class="number"><?php echo count($companies); ?></div>
            </div>
        </div>
        
        <!-- Navigation -->
        <div class="admin-nav">
            <button class="nav-btn active" onclick="showView('dashboard')">📊 Dashboard</button>
            <button class="nav-btn" onclick="showView('kiosks')">🖥️ Kiosks</button>
            <button class="nav-btn" onclick="showView('hardware')">⚙️ Hardware</button>
            <button class="nav-btn" onclick="showView('management')">⚡ Management</button>
        </div>
        
        <!-- Dashboard View -->
        <div id="dashboard" class="view active" style="background: white; padding: 20px; border-radius: 10px;">
            <h2>Welcome to Admin Portal</h2>
            <p style="color: #666; margin: 15px 0;">
                Use the navigation above to manage your EduDisplej kiosk network.
            </p>
            
            <?php if ($unassigned > 0): ?>
                <div style="background: #fff3cd; border: 1px solid #ffeaa7; padding: 15px; border-radius: 5px; margin-top: 20px;">
                    <strong style="color: #856404;">⚠️ {{$unassigned}} Unassigned Kiosk<?php echo $unassigned > 1 ? 's' : ''; ?></strong>
                    <p style="color: #856404; margin: 5px 0;">Go to Kiosks tab to assign them to companies.</p>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Kiosks View -->
        <div id="kiosks" class="view" style="background: white; padding: 20px; border-radius: 10px;">
            <h2>Kiosk Management</h2>
            
            <!-- Unassigned -->
            <?php if ($unassigned > 0): ?>
                <div style="background: #f0f4ff; padding: 20px; border-radius: 5px; margin-bottom: 30px;">
                    <h3 style="color: #1e40af; margin-bottom: 15px;">⚠️ Unassigned (<?php echo $unassigned; ?>)</h3>
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 15px;">
                        <?php foreach (array_filter($kiosks, fn($k) => empty($k['company_id'])) as $k): ?>
                            <div style="border: 2px solid #1e40af; padding: 15px; border-radius: 5px; background: white;">
                                <div style="font-weight: 600; margin-bottom: 8px;">
                                    <?php echo htmlspecialchars($k['hostname'] ?? 'Kiosk #' . $k['id']); ?>
                                </div>
                                <div style="font-size: 12px; color: #666; margin-bottom: 10px;">
                                    📍 <?php echo htmlspecialchars($k['location'] ?? '—'); ?><br>
                                    💻 <?php echo htmlspecialchars($k['device_id'] ?? '—'); ?><br>
                                    <span style="color: <?php echo $k['status'] == 'online' ? '#28a745' : '#dc3545'; ?>;">
                                        ● <?php echo ucfirst($k['status']); ?>
                                    </span>
                                </div>
                                <select onchange="assignCompany(<?php echo $k['id']; ?>, this.value)">
                                    <option value="">Assign to company...</option>
                                    <?php foreach ($companies as $c): ?>
                                        <option value="<?php echo $c['id']; ?>"><?php echo htmlspecialchars($c['name']); ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            <?php endif; ?>
            
            <!-- All Kiosks Table -->
            <h3 style="margin-bottom: 15px;">All Kiosks</h3>
            <div style="overflow-x: auto;">
                <table style="width: 100%; border-collapse: collapse;">
                    <thead style="background: #f8f9fa;">
                        <tr>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #eee;">Hostname</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #eee;">Company</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #eee;">Status</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #eee;">Location</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #eee;">Last Seen</th>
                            <th style="padding: 12px; text-align: left; border-bottom: 2px solid #eee;">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($kiosks as $k): ?>
                            <tr style="border-bottom: 1px solid #eee;">
                                <td style="padding: 12px;">
                                    <strong><?php echo htmlspecialchars($k['hostname'] ?? 'Kiosk #' . $k['id']); ?></strong>
                                </td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($k['company_name'] ?? '—'); ?></td>
                                <td style="padding: 12px;">
                                    <span style="padding: 4px 8px; border-radius: 3px; font-size: 12px; background: <?php echo $k['status'] == 'online' ? '#d4edda; color: #28a745;' : '#f8d7da; color: #dc3545;'; ?>">
                                        ● <?php echo ucfirst($k['status']); ?>
                                    </span>
                                </td>
                                <td style="padding: 12px;"><?php echo htmlspecialchars($k['location'] ?? '—'); ?></td>
                                <td style="padding: 12px; font-size: 12px;">
                                    <?php echo $k['last_seen'] ? date('Y-m-d H:i', strtotime($k['last_seen'])) : 'Never'; ?>
                                </td>
                                <td style="padding: 12px; font-size: 12px;">
                                    <a href="kiosk_details.php?id=<?php echo $k['id']; ?>" style="color: #1e40af; margin-right: 10px;">📋 View</a>
                                    <a href="?screenshot=<?php echo $k['id']; ?>" style="color: #1e40af; margin-right: 10px;" onclick="return confirm('Request screenshot?');">📸 Screenshot</a>
                                    <a href="?toggle_ping=<?php echo $k['id']; ?>" style="color: #1e40af;">
                                        <?php echo $k['sync_interval'] == 10 ? '🐌 Slow' : '⚡ Fast'; ?>
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Hardware View -->
        <div id="hardware" class="view" style="background: white; padding: 20px; border-radius: 10px;">
            <h2>Hardware Information & Ping Control</h2>
            <p style="color: #666; margin-bottom: 20px;">View and manage hardware details for each kiosk.</p>
            
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(380px, 1fr)); gap: 20px;">
                <?php foreach (array_filter($kiosks, fn($k) => !empty($k['hw_info'])) as $k): ?>
                    <div style="border: 1px solid #eee; padding: 15px; border-radius: 5px; background: #f9f9f9;">
                        <div style="font-weight: 600; margin-bottom: 10px;">
                            <?php echo htmlspecialchars($k['hostname'] ?? 'Kiosk #' . $k['id']); ?>
                            <span style="font-size: 12px; color: #666; font-weight: normal;">
                                (<?php echo $k['sync_interval']; ?>s interval)
                            </span>
                        </div>
                        <div style="background: white; padding: 10px; border-radius: 3px; margin-bottom: 10px; font-size: 11px; overflow-x: auto;">
                            <pre style="margin: 0;"><?php echo htmlspecialchars(json_encode(json_decode($k['hw_info']), JSON_PRETTY_PRINT)); ?></pre>
                        </div>
                        <div>
                            <a href="?toggle_ping=<?php echo $k['id']; ?>" style="display: inline-block; padding: 8px 12px; background: #ffc107; color: white; border-radius: 3px; text-decoration: none; font-size: 12px;">
                                <?php echo $k['sync_interval'] == 10 ? '🐌 Slow (300s)' : '⚡ Fast (10s)'; ?>
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
            
            <?php if (empty(array_filter($kiosks, fn($k) => !empty($k['hw_info'])))): ?>
                <div style="text-align: center; color: #999; padding: 40px;">
                    No hardware data available yet.
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Management View -->
        <div id="management" class="view" style="background: white; padding: 20px; border-radius: 10px;">
            <h2>System Management</h2>
            <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 15px; margin-top: 20px;">
                <a href="users.php" style="display: block; padding: 20px; background: #f0f4ff; border: 1px solid #1e40af; border-radius: 5px; color: #1e40af; text-decoration: none; text-align: center; font-weight: 600;">
                    👥 Users
                </a>
                <a href="companies.php" style="display: block; padding: 20px; background: #f0f4ff; border: 1px solid #1e40af; border-radius: 5px; color: #1e40af; text-decoration: none; text-align: center; font-weight: 600;">
                    🏢 Companies
                </a>
                <a href="module_licenses.php" style="display: block; padding: 20px; background: #f0f4ff; border: 1px solid #1e40af; border-radius: 5px; color: #1e40af; text-decoration: none; text-align: center; font-weight: 600;">
                    🔑 Modules
                </a>
            </div>
        </div>
    </div>
    
    <script>
        function showView(view) {
            // Hide all views
            document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
            document.querySelectorAll('.nav-btn').forEach(b => b.classList.remove('active'));
            
            // Show selected view
            document.getElementById(view).classList.add('active');
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
                        company_id: parseInt(companyId)
                    })
                });
                
                const data = await response.json();
                if (data.success) {
                    alert('Kiosk assigned successfully!');
                    location.reload();
                } else {
                    alert('Error: ' + data.message);
                }
            } catch (error) {
                console.error('Error:', error);
                alert('Assignment failed');
            }
        }
    </script>
</body>
</html>

