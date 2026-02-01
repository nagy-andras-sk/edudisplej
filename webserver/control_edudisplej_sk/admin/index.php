<?php
/**
 * Admin Panel - EduDisplej Control System
 * Complete admin control with tabular interface
 */

session_start();
require_once '../dbkonfiguracia.php';

$error = '';
$success = '';

// Handle logout
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ../login.php');
    exit();
}

// Check if user is logged in and is admin
$is_logged_in = isset($_SESSION['user_id']) && isset($_SESSION['isadmin']) && $_SESSION['isadmin'];

if (!$is_logged_in) {
    header('Location: ../login.php');
    exit();
}

// Handle screenshot request
if ($is_logged_in && isset($_GET['screenshot']) && is_numeric($_GET['screenshot'])) {
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
if ($is_logged_in && isset($_GET['toggle_ping']) && is_numeric($_GET['toggle_ping'])) {
    try {
        $conn = getDbConnection();
        $kiosk_id = intval($_GET['toggle_ping']);
        
        $stmt = $conn->prepare("SELECT sync_interval FROM kiosks WHERE id = ?");
        $stmt->bind_param("i", $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        $kiosk = $result->fetch_assoc();
        
        $new_interval = ($kiosk['sync_interval'] == 20) ? 300 : 20;
        
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

// Get all data
$kiosks = [];
$companies = [];
$users = [];
$modules = [];

try {
    $conn = getDbConnection();
    
    // Get companies
    $result = $conn->query("SELECT * FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }
    
    // Get all kiosks
    $query = "SELECT k.*, c.name as company_name 
              FROM kiosks k 
              LEFT JOIN companies c ON k.company_id = c.id 
              ORDER BY k.last_seen DESC";
    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        $kiosks[] = $row;
    }
    
    // Get users
    $result = $conn->query("SELECT u.*, c.name as company_name FROM users u LEFT JOIN companies c ON u.company_id = c.id ORDER BY u.username");
    while ($row = $result->fetch_assoc()) {
        $users[] = $row;
    }
    
    // Get modules
    $result = $conn->query("SELECT * FROM modules ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $modules[] = $row;
    }
    
    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load data';
    error_log($e->getMessage());
}

// Get current active tab
$tab = isset($_GET['tab']) ? $_GET['tab'] : 'kiosks';
?>
<?php include 'header.php'; ?>
        
        <div class="stats">
            <div class="stat-card">
                <h3>📊 Kijelzők</h3>
                <div class="number"><?php echo count($kiosks); ?></div>
            </div>
            <div class="stat-card">
                <h3>🟢 Online</h3>
                <div class="number"><?php echo count(array_filter($kiosks, fn($k) => $k['status'] == 'online')); ?></div>
            </div>
            <div class="stat-card">
                <h3>🔴 Offline</h3>
                <div class="number"><?php echo count(array_filter($kiosks, fn($k) => $k['status'] == 'offline')); ?></div>
            </div>
            <div class="stat-card">
                <h3>🏢 Cégek</h3>
                <div class="number"><?php echo count($companies); ?></div>
            </div>
            <div class="stat-card">
                <h3>👥 Felhasználók</h3>
                <div class="number"><?php echo count($users); ?></div>
            </div>
            <div class="stat-card">
                <h3>🎬 Modulok</h3>
                <div class="number"><?php echo count($modules); ?></div>
            </div>
        </div>
        </div>
        
        <!-- Tab Navigation -->
        <div class="tabs">
            <button class="tab-button <?php echo $tab == 'kiosks' ? 'active' : ''; ?>" onclick="switchTab('kiosks')">
                🖥️ Kijelzők
            </button>
            <button class="tab-button <?php echo $tab == 'companies' ? 'active' : ''; ?>" onclick="switchTab('companies')">
                🏢 Cégek
            </button>
            <button class="tab-button <?php echo $tab == 'users' ? 'active' : ''; ?>" onclick="switchTab('users')">
                👥 Felhasználók
            </button>
            <button class="tab-button <?php echo $tab == 'modules' ? 'active' : ''; ?>" onclick="switchTab('modules')">
                🎬 Modulok
            </button>
        </div>
        
        <!-- KIOSKS TAB -->
        <div class="tab-content <?php echo $tab == 'kiosks' ? 'active' : ''; ?>" id="kiosks-tab">
            <h2>Kijelzők Kezelése</h2>
            <p style="color: #666; margin-bottom: 15px;">Összes kijelző listája és kezelésük</p>
            
            <input type="text" class="search-box" id="kioskSearch" placeholder="🔍 Keresés: hostname, MAC, cég, hely...">
            
            <div style="overflow-x: auto;">
                <table id="kioskTable" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Hostname</th>
                            <th>Státusz</th>
                            <th>Cég</th>
                            <th>Hely</th>
                            <th>Utolsó szinkronizálás</th>
                            <th>MAC Cím</th>
                            <th>Művelet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($kiosks)): ?>
                            <tr>
                                <td colspan="8" style="text-align: center; color: #999; padding: 20px;">Nincs regisztrált kijelző</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($kiosks as $kiosk): 
                                $last_seen_timestamp = $kiosk['last_seen'] ? strtotime($kiosk['last_seen']) : 0;
                                $time_diff = $last_seen_timestamp ? time() - $last_seen_timestamp : 0;
                                $is_offline_long = $time_diff > 600;
                            ?>
                                <tr 
                                    data-id="<?php echo $kiosk['id']; ?>"
                                    data-hostname="<?php echo htmlspecialchars($kiosk['hostname'] ?? ''); ?>"
                                    data-company="<?php echo htmlspecialchars($kiosk['company_name'] ?? 'Hozzárendeletlen'); ?>"
                                    data-status="<?php echo $kiosk['status']; ?>"
                                    data-mac="<?php echo htmlspecialchars($kiosk['mac'] ?? ''); ?>"
                                    data-location="<?php echo htmlspecialchars($kiosk['location'] ?? ''); ?>"
                                    style="<?php echo $is_offline_long ? 'background: #fff5f5;' : ''; ?>"
                                >
                                    <td><small><?php echo $kiosk['id']; ?></small></td>
                                    <td><strong><?php echo htmlspecialchars($kiosk['hostname'] ?? 'N/A'); ?></strong></td>
                                    <td>
                                        <span class="status-badge status-<?php echo $kiosk['status']; ?>">
                                            <?php echo $kiosk['status'] == 'online' ? '🟢 Online' : '🔴 Offline'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($kiosk['company_name'] ?? '⚠️ Hozzárendeletlen'); ?></td>
                                    <td><?php echo htmlspecialchars($kiosk['location'] ?? '-'); ?></td>
                                    <td><small><?php echo $kiosk['last_seen'] ? date('Y-m-d H:i', strtotime($kiosk['last_seen'])) : 'Soha'; ?></small></td>
                                    <td><code style="font-size: 11px;"><?php echo htmlspecialchars(substr($kiosk['mac'], 0, 17)); ?></code></td>
                                    <td>
                                        <a href="kiosk_details.php?id=<?php echo $kiosk['id']; ?>" class="action-btn action-btn-small">👁️ Részletek</a>
                                        <a href="?tab=kiosks&screenshot=<?php echo $kiosk['id']; ?>" class="action-btn action-btn-small" onclick="return confirm('Képernyőfelvétel?')">📸</a>
                                        <a href="?tab=kiosks&toggle_ping=<?php echo $kiosk['id']; ?>" class="action-btn action-btn-small"><?php echo ($kiosk['sync_interval'] == 20) ? '🐌' : '⚡'; ?></a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- COMPANIES TAB -->
        <div class="tab-content <?php echo $tab == 'companies' ? 'active' : ''; ?>" id="companies-tab">
            <h2>Cégek Kezelése</h2>
            <p style="color: #666; margin-bottom: 15px;">
                <a href="companies.php" class="btn" style="display: inline-block;">➕ Új cég hozzáadása</a>
            </p>
            
            <div style="overflow-x: auto;">
                <table style="width: 100%;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Cégnév</th>
                            <th>Kijelzők</th>
                            <th>Felhasználók</th>
                            <th>Művelet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($companies)): ?>
                            <tr>
                                <td colspan="5" style="text-align: center; color: #999; padding: 20px;">Nincs regisztrált cég</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($companies as $company): 
                                $company_kiosks = array_filter($kiosks, fn($k) => $k['company_id'] == $company['id']);
                                $company_users = array_filter($users, fn($u) => $u['company_id'] == $company['id']);
                            ?>
                                <tr>
                                    <td><small><?php echo $company['id']; ?></small></td>
                                    <td><strong><?php echo htmlspecialchars($company['name']); ?></strong></td>
                                    <td><?php echo count($company_kiosks); ?></td>
                                    <td><?php echo count($company_users); ?></td>
                                    <td>
                                        <a href="companies.php?id=<?php echo $company['id']; ?>" class="action-btn action-btn-small">✏️ Szerkesztés</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- USERS TAB -->
        <div class="tab-content <?php echo $tab == 'users' ? 'active' : ''; ?>" id="users-tab">
            <h2>Felhasználók Kezelése</h2>
            <p style="color: #666; margin-bottom: 15px;">
                <a href="users.php" class="btn" style="display: inline-block;">➕ Új felhasználó</a>
            </p>
            
            <input type="text" class="search-box" id="userSearch" placeholder="🔍 Keresés: felhasználónév, cég...">
            
            <div style="overflow-x: auto;">
                <table id="userTable" style="width: 100%;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Felhasználónév</th>
                            <th>Cég</th>
                            <th>Típus</th>
                            <th>Utolsó bejelentkezés</th>
                            <th>Művelet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($users)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #999; padding: 20px;">Nincs regisztrált felhasználó</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($users as $user): ?>
                                <tr data-username="<?php echo htmlspecialchars($user['username']); ?>" data-company="<?php echo htmlspecialchars($user['company_name'] ?? 'N/A'); ?>">
                                    <td><small><?php echo $user['id']; ?></small></td>
                                    <td><strong><?php echo htmlspecialchars($user['username']); ?></strong></td>
                                    <td><?php echo htmlspecialchars($user['company_name'] ?? '(nincs)'); ?></td>
                                    <td>
                                        <span class="status-badge" style="background: <?php echo $user['isadmin'] ? '#e3f2fd' : '#f3e5f5'; ?>; color: <?php echo $user['isadmin'] ? '#1976d2' : '#7b1fa2'; ?>;">
                                            <?php echo $user['isadmin'] ? '🔐 Admin' : '👤 Felhasználó'; ?>
                                        </span>
                                    </td>
                                    <td><small><?php echo $user['last_login'] ? date('Y-m-d H:i', strtotime($user['last_login'])) : 'Soha'; ?></small></td>
                                    <td>
                                        <a href="users.php?id=<?php echo $user['id']; ?>" class="action-btn action-btn-small">✏️ Szerkesztés</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- MODULES TAB -->
        <div class="tab-content <?php echo $tab == 'modules' ? 'active' : ''; ?>" id="modules-tab">
            <h2>Modulok Kezelése</h2>
            <p style="color: #666; margin-bottom: 15px;">
                <a href="module_licenses.php" class="btn" style="display: inline-block;">🔑 Licencek Kezelése</a>
            </p>
            
            <div style="overflow-x: auto;">
                <table style="width: 100%;">
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Modulnév</th>
                            <th>Modul Kulcs</th>
                            <th>Leírás</th>
                            <th>Státusz</th>
                            <th>Művelet</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($modules)): ?>
                            <tr>
                                <td colspan="6" style="text-align: center; color: #999; padding: 20px;">Nincs elérhető modul</td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($modules as $module): ?>
                                <tr>
                                    <td><small><?php echo $module['id']; ?></small></td>
                                    <td><strong><?php echo htmlspecialchars($module['name']); ?></strong></td>
                                    <td><code style="font-size: 11px;"><?php echo htmlspecialchars($module['module_key']); ?></code></td>
                                    <td><?php echo htmlspecialchars(substr($module['description'] ?? '', 0, 50)); ?></td>
                                    <td>
                                        <span class="status-badge" style="background: <?php echo $module['is_active'] ? '#e8f5e9' : '#ffebee'; ?>; color: <?php echo $module['is_active'] ? '#2e7d32' : '#c62828'; ?>;">
                                            <?php echo $module['is_active'] ? '✓ Aktív' : '✗ Inaktív'; ?>
                                        </span>
                                    </td>
                                    <td>
                                        <a href="module_licenses.php?module_id=<?php echo $module['id']; ?>" class="action-btn action-btn-small">🔑 Licencek</a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function switchTab(tabName) {
            // Hide all tabs
            document.querySelectorAll('.tab-content').forEach(tab => {
                tab.classList.remove('active');
            });
            
            // Remove active from all buttons
            document.querySelectorAll('.tab-button').forEach(btn => {
                btn.classList.remove('active');
            });
            
            // Show selected tab
            document.getElementById(tabName + '-tab').classList.add('active');
            
            // Add active to clicked button
            event.target.classList.add('active');
            
            // Update URL
            window.history.pushState(null, '', '?tab=' + tabName);
        }
        
        // Search functionality for kiosks
        document.addEventListener('DOMContentLoaded', function() {
            const kioskSearch = document.getElementById('kioskSearch');
            const kioskTable = document.getElementById('kioskTable');
            
            if (kioskSearch) {
                kioskSearch.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = kioskTable.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                });
            }
            
            // Search functionality for users
            const userSearch = document.getElementById('userSearch');
            const userTable = document.getElementById('userTable');
            
            if (userSearch) {
                userSearch.addEventListener('keyup', function() {
                    const searchTerm = this.value.toLowerCase();
                    const rows = userTable.querySelectorAll('tbody tr');
                    
                    rows.forEach(row => {
                        const text = row.textContent.toLowerCase();
                        row.style.display = text.includes(searchTerm) ? '' : 'none';
                    });
                });
            }
        });
    </script>
    
    <style>
        .tabs {
            display: flex;
            gap: 0;
            border-bottom: 2px solid #eee;
            margin-bottom: 20px;
        }
        
        .tab-button {
            padding: 12px 20px;
            background: none;
            border: none;
            cursor: pointer;
            font-size: 14px;
            font-weight: 500;
            color: #666;
            border-bottom: 3px solid transparent;
            transition: all 0.3s;
        }
        
        .tab-button:hover {
            color: #333;
        }
        
        .tab-button.active {
            color: #1e40af;
            border-bottom-color: #1e40af;
        }
        
        .tab-content {
            display: none;
        }
        
        .tab-content.active {
            display: block;
        }
        
        .search-box {
            width: 100%;
            max-width: 400px;
            padding: 10px;
            margin-bottom: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            font-size: 13px;
        }
        
        .action-btn {
            padding: 6px 12px;
            font-size: 12px;
            margin-right: 5px;
            border: 1px solid #ddd;
            border-radius: 3px;
            background: white;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .action-btn:hover {
            background: #f5f5f5;
        }
        
        .action-btn-small {
            padding: 4px 8px;
            font-size: 11px;
        }
    </style>
<?php include 'footer.php'; ?>

