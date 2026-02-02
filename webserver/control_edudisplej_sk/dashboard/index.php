<?php
/**
 * Company Dashboard - Saját Kijelzők
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';

$error = '';
$success = '';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

// Get user info
$user_id = $_SESSION['user_id'];
$company_id = null;
$company_name = '';
$is_admin = isset($_SESSION['isadmin']) && $_SESSION['isadmin'];
$kiosks = [];

try {
    $conn = getDbConnection();
    
    // Get user and company info
    $stmt = $conn->prepare("SELECT u.*, c.name as company_name FROM users u LEFT JOIN companies c ON u.company_id = c.id WHERE u.id = ?");
    $stmt->bind_param("i", $user_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $user = $result->fetch_assoc();
    $stmt->close();
    
    if (!$user) {
        $conn->close();
        header('Location: ../login.php');
        exit();
    }
    
    $company_id = $user['company_id'];
    $company_name = $user['company_name'] ?? 'No Company';
    
    // Non-admin users must have a company assigned
    if (!$is_admin && !$company_id) {
        $error = 'You are not assigned to any company. Please contact an administrator.';
    } else if ($company_id) {
        // Get company kiosks with group information
        $query = "SELECT k.*, 
                  GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') as group_names,
                  GROUP_CONCAT(DISTINCT g.id SEPARATOR ',') as group_ids
                  FROM kiosks k 
                  LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
                  LEFT JOIN kiosk_groups g ON kga.group_id = g.id
                  WHERE k.company_id = ? 
                  GROUP BY k.id
                  ORDER BY k.hostname";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $kiosks[] = $row;
        }
        $stmt->close();
        
        // Get all groups for filtering
        $groups_query = "SELECT DISTINCT g.id, g.name FROM kiosk_groups g 
                        INNER JOIN kiosk_group_assignments kga ON g.id = kga.group_id
                        INNER JOIN kiosks k ON kga.kiosk_id = k.id
                        WHERE k.company_id = ?
                        ORDER BY g.name";
        $groups_stmt = $conn->prepare($groups_query);
        $groups_stmt->bind_param("i", $company_id);
        $groups_stmt->execute();
        $groups_result = $groups_stmt->get_result();
        
        $groups = [];
        while ($group_row = $groups_result->fetch_assoc()) {
            $groups[] = $group_row;
        }
        $groups_stmt->close();
    }
    
    $conn->close();
    
} catch (Exception $e) {
    $error = 'Database error: ' . $e->getMessage();
    error_log($e->getMessage());
}

$logout_url = '../login.php?logout=1';
?>
<?php include '../admin/header.php'; ?>
        
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($company_id): ?>
            <div class="stats">
                <div class="stat-card">
                    <h3>🖥️ Össz Kijelzők</h3>
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
            </div>
            
            <h2>Saját Kijelzők</h2>
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px; gap: 15px; flex-wrap: wrap;">
                <p style="color: #666; margin: 0;">A cégjéhez rendelt összes kijelző</p>
                <div style="display: flex; gap: 10px; align-items: center;">
                    <!-- Group Filter -->
                    <?php if (!empty($groups)): ?>
                    <select id="groupFilter" onchange="filterByGroup()" style="
                        padding: 10px;
                        border: 1px solid #ddd;
                        border-radius: 5px;
                        font-size: 14px;
                        cursor: pointer;
                    ">
                        <option value="">Minden csoport</option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                    
                    <button id="toggleViewBtn" onclick="toggleView()" style="
                        background: #1a3a52;
                        color: white;
                        border: none;
                        padding: 10px 20px;
                        border-radius: 5px;
                        cursor: pointer;
                        font-size: 14px;
                        transition: background 0.2s;
                    " onmouseover="this.style.background='#0f2537'" onmouseout="this.style.background='#1a3a52'">
                        📸 Realtime Nézet
                    </button>
                </div>
            </div>
            
            <?php if (empty($kiosks)): ?>
                <div style="text-align: center; padding: 40px; color: #999; background: white; border-radius: 10px;">
                    Nincs kijelző hozzárendelve a cégjéhez
                </div>
            <?php else: ?>
                <!-- List View -->
                <div id="listView" style="overflow-x: auto;">
                    <table style="width: 100%;">
                        <thead>
                            <tr>
                                <th>ID</th>
                                <th>Hostname</th>
                                <th>Státusz</th>
                                <th>Hely</th>
                                <th>Utolsó szinkronizálás</th>
                                <th>Loop</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kiosks as $kiosk): ?>
                                <tr>
                                    <td><small><?php echo $kiosk['id']; ?></small></td>
                                    <td>
                                        <strong style="cursor: pointer; color: #1a3a52;" onclick="openKioskDetail(<?php echo $kiosk['id']; ?>, '<?php echo htmlspecialchars($kiosk['hostname'] ?? 'N/A'); ?>')">
                                            <?php echo htmlspecialchars($kiosk['hostname'] ?? 'N/A'); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $kiosk['status']; ?>">
                                            <?php echo $kiosk['status'] == 'online' ? '🟢 Online' : '🔴 Offline'; ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars($kiosk['location'] ?? '-'); ?></td>
                                    <td><small id="sync-time-<?php echo $kiosk['id']; ?>" data-last-seen="<?php echo htmlspecialchars($kiosk['last_seen']); ?>"></small></td>
                                    <td>
                                        <button onclick="viewKioskLoop(<?php echo $kiosk['id']; ?>, '<?php echo htmlspecialchars($kiosk['hostname'] ?? 'N/A', ENT_QUOTES); ?>')" 
                                                style="background: #1a3a52; color: white; border: none; padding: 6px 12px; border-radius: 5px; cursor: pointer; font-size: 12px;">
                                            🔄 Loop
                                        </button>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <!-- Realtime Screenshot View -->
                <div id="realtimeView" style="display: none;">
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(350px, 1fr)); gap: 20px;">
                        <?php foreach ($kiosks as $kiosk): ?>
                            <div class="screenshot-card" 
                                 data-kiosk-id="<?php echo $kiosk['id']; ?>"
                                 data-group-ids="<?php echo htmlspecialchars($kiosk['group_ids'] ?? ''); ?>"
                                 style="
                                background: white;
                                border-radius: 10px;
                                padding: 15px;
                                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                                transition: transform 0.2s;
                            " onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                                <div style="margin-bottom: 10px;">
                                    <h3 style="margin: 0 0 5px 0; font-size: 16px; color: #1a3a52;">
                                        <?php echo htmlspecialchars($kiosk['hostname'] ?? 'N/A'); ?>
                                    </h3>
                                    <div style="display: flex; align-items: center; gap: 8px; margin-bottom: 5px;">
                                        <span class="status-badge status-<?php echo $kiosk['status']; ?>">
                                            <?php echo $kiosk['status'] == 'online' ? '🟢' : '🔴'; ?>
                                        </span>
                                        <?php if (!empty($kiosk['group_names'])): ?>
                                            <span style="background: #e3f2fd; color: #1976d2; padding: 2px 8px; border-radius: 3px; font-size: 11px; font-weight: 600;">
                                                📁 <?php echo htmlspecialchars($kiosk['group_names']); ?>
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    <small style="color: #999; display: block;">
                                        <?php echo htmlspecialchars($kiosk['location'] ?? '-'); ?>
                                    </small>
                                    <!-- Tech Info -->
                                    <div style="margin-top: 8px; padding: 8px; background: #f8f9fa; border-radius: 5px; font-size: 11px; color: #666;">
                                        <div style="display: grid; grid-template-columns: auto 1fr; gap: 5px; margin-bottom: 8px;">
                                            <?php if (!empty($kiosk['version'])): ?>
                                                <span style="font-weight: 600;">📦 Verzió:</span>
                                                <span id="version-<?php echo $kiosk['id']; ?>"><?php echo htmlspecialchars($kiosk['version']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($kiosk['screen_resolution'])): ?>
                                                <span style="font-weight: 600;">🖥️ Felbontás:</span>
                                                <span id="resolution-<?php echo $kiosk['id']; ?>"><?php echo htmlspecialchars($kiosk['screen_resolution']); ?></span>
                                            <?php endif; ?>
                                            <?php if (!empty($kiosk['screen_status'])): ?>
                                                <span style="font-weight: 600;">💡 Képernyő:</span>
                                                <span id="screen-status-<?php echo $kiosk['id']; ?>">
                                                    <?php 
                                                        $status = $kiosk['screen_status'];
                                                        if ($status == 'on') echo '✅ Bekapcsolva';
                                                        elseif ($status == 'off') echo '❌ Kikapcsolva';
                                                        else echo '❓ ' . htmlspecialchars($status);
                                                    ?>
                                                </span>
                                            <?php endif; ?>
                                        </div>
                                        <!-- Sync timestamps -->
                                        <div style="border-top: 1px solid #ddd; padding-top: 5px; display: grid; grid-template-columns: auto 1fr; gap: 5px;">
                                            <span style="font-weight: 600;">⏱️ Szinkronizálás:</span>
                                            <span id="last-sync-<?php echo $kiosk['id']; ?>" style="color: #666;">
                                                <?php echo (!empty($kiosk['last_sync']) && $kiosk['last_sync'] !== 'NULL') ? date('H:i', strtotime($kiosk['last_sync'])) : '-'; ?>
                                            </span>
                                            <span style="font-weight: 600;">🔄 Loop verzió:</span>
                                            <span id="loop-version-<?php echo $kiosk['id']; ?>" style="color: #666;">
                                                <?php echo (!empty($kiosk['loop_last_update']) && $kiosk['loop_last_update'] !== 'NULL') ? date('H:i', strtotime($kiosk['loop_last_update'])) : '-'; ?>
                                            </span>
                                        </div>
                                    </div>
                                </div>
                                <div class="screenshot-container" style="
                                    position: relative;
                                    width: 100%;
                                    padding-top: 56.25%; /* 16:9 aspect ratio */
                                    background: #f5f5f5;
                                    border-radius: 5px;
                                    overflow: hidden;
                                ">
                                    <img class="screenshot-img" 
                                         src="<?php echo !empty($kiosk['screenshot_url']) ? '../' . $kiosk['screenshot_url'] : 'data:image/svg+xml,%3Csvg xmlns=\'http://www.w3.org/2000/svg\' width=\'400\' height=\'300\'%3E%3Crect fill=\'%23f5f5f5\' width=\'400\' height=\'300\'/%3E%3Ctext x=\'50%25\' y=\'50%25\' text-anchor=\'middle\' fill=\'%23999\' font-size=\'18\' dy=\'.3em\'%3ENo screenshot%3C/text%3E%3C/svg%3E'; ?>" 
                                         alt="Screenshot"
                                         style="
                                            position: absolute;
                                            top: 0;
                                            left: 0;
                                            width: 100%;
                                            height: 100%;
                                            object-fit: contain;
                                         "
                                    />
                                    <div class="screenshot-timestamp" style="
                                        position: absolute;
                                        bottom: 5px;
                                        right: 5px;
                                        background: rgba(0,0,0,0.7);
                                        color: white;
                                        padding: 3px 8px;
                                        border-radius: 3px;
                                        font-size: 11px;
                                    ">
                                        <span id="screenshot-time-<?php echo $kiosk['id']; ?>">-</span>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <div style="text-align: center; margin-top: 20px; color: #999; font-size: 12px;">
                        <span id="autoRefreshIndicator">Auto-refresh: <span id="refreshCountdown">15</span>s</span>
                    </div>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #999;">
                <p>Nem rendelt cég vagy nincs hozzáférése az adatokhoz.</p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Store the initial load time
        let pageLoadTime = new Date();
        
        // Update sync times on page load
        function updateSyncTimes() {
            document.querySelectorAll('[id^="sync-time-"]').forEach(el => {
                const lastSeen = el.getAttribute('data-last-seen');
                if (!lastSeen || lastSeen === 'NULL' || lastSeen === '') {
                    el.innerHTML = '<span style="color: #999;">Soha</span>';
                    return;
                }
                
                const lastDate = new Date(lastSeen);
                const now = new Date();
                const diffMs = now - lastDate;
                const diffMins = Math.floor(diffMs / 60000);
                const diffSecs = Math.floor((diffMs % 60000) / 1000);
                
                let timeStr = '';
                if (diffMins > 0) {
                    timeStr = `${diffMins} perc${diffSecs > 0 ? ` ${diffSecs}s` : ''} előtt`;
                } else {
                    timeStr = `${diffSecs}s előtt`;
                }
                
                // If more than 120 minutes, show as red OFFLINE
                if (diffMins > 120) {
                    el.innerHTML = `<span style="color: #d32f2f; font-weight: bold;">OFFLINE (${timeStr})</span>`;
                } else {
                    el.innerHTML = timeStr;
                }
            });
        }
        
        // Refresh kiosk data from server to get updated last_seen values
        function refreshKioskData() {
            <?php if (!empty($kiosks)): ?>
            const kioskIds = [<?php echo implode(',', array_column($kiosks, 'id')); ?>];
            
            fetch('../api/kiosk_details.php?refresh_list=' + kioskIds.join(','))
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.kiosks) {
                        data.kiosks.forEach(kiosk => {
                            const el = document.getElementById('sync-time-' + kiosk.id);
                            if (el && kiosk.last_seen) {
                                // Update the data attribute with fresh value from server
                                el.setAttribute('data-last-seen', kiosk.last_seen);
                            }
                        });
                        // Update the display after refreshing data
                        updateSyncTimes();
                    }
                })
                .catch(err => {
                    console.error('Error refreshing kiosk data:', err);
                });
            <?php endif; ?>
        }
        
        // Initial display
        updateSyncTimes();
        
        // Refresh data from server every 30 seconds
        setInterval(refreshKioskData, 30000);
        
        // Update display every 5 seconds (shows time elapsed)
        setInterval(updateSyncTimes, 5000);
        
        function updateSyncInterval(kioskId, newInterval) {
            if (!confirm(`Biztosan beállítja a szinkronizálási időközt ${newInterval} másodpercre?`)) {
                location.reload();
                return;
            }
            
            fetch('../api/update_sync_interval.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ kiosk_id: kioskId, sync_interval: parseInt(newInterval) })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    alert('✅ Szinkronizálási időköz frissítve');
                } else {
                    alert('⚠️ Hiba: ' + data.message);
                    location.reload();
                }
            })
            .catch(err => {
                alert('⚠️ Hiba történt: ' + err);
                location.reload();
            });
        }
        
        function toggleScreenshot(kioskId, enabled) {
            const targetInterval = enabled ? 15 : 120;
            
            fetch('../api/toggle_screenshot.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ 
                    kiosk_id: kioskId, 
                    screenshot_enabled: enabled ? 1 : 0 
                })
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Update the toggle label
                    const checkbox = document.getElementById('screenshot-toggle-' + kioskId);
                    const label = checkbox.parentElement.querySelector('span');
                    label.textContent = enabled ? '✅ Bekapcsolva' : '⭕ Kikapcsolva';
                    label.style.color = enabled ? '#2e7d32' : '#999';
                    
                    // Update screenshot container
                    const container = document.getElementById('screenshot-container-' + kioskId);
                    if (enabled) {
                        container.innerHTML = '<p style="text-align: center; color: #999; padding: 20px;">Még nincs képernyőkép feltöltve. Várjon a következő szinkronizálásig.</p>';
                    } else {
                        container.innerHTML = '<p style="text-align: center; color: #999; padding: 20px;">A képernyőkép funkció ki van kapcsolva. Kapcsolja be a képernyőképek fogadásához.</p>';
                    }
                    
                    // Update sync interval dropdown
                    const selector = document.getElementById('sync-interval-selector-' + kioskId);
                    if (selector) {
                        selector.value = targetInterval;
                    }
                    
                    alert(`✅ Képernyőkép funkció ${enabled ? 'bekapcsolva' : 'kikapcsolva'}\nSzinkronizálási időköz: ${targetInterval} másodperc`);
                } else {
                    alert('⚠️ Hiba: ' + data.message);
                    location.reload();
                }
            })
            .catch(err => {
                alert('⚠️ Hiba történt: ' + err);
                location.reload();
            });
        }
        
        function refreshScreenshot(kioskId) {
            // Request a new screenshot from the kiosk
            fetch('../api/kiosk_details.php?id=' + kioskId)
                .then(response => response.json())
                .then(data => {
                    if (data.success && data.screenshot_url) {
                        const container = document.getElementById('screenshot-container-' + kioskId);
                        container.innerHTML = '<img src="../' + data.screenshot_url + '?t=' + Date.now() + '" alt="Screenshot" style="width: 100%; max-width: 600px; border: 2px solid #ddd; border-radius: 5px; display: block; margin: 0 auto;" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';"><p style="display: none; text-align: center; color: #999; padding: 20px;">Screenshot nem elérhető</p>';
                    } else {
                        alert('⚠️ Nincs elérhető képernyőkép');
                    }
                })
                .catch(err => {
                    alert('⚠️ Hiba történt: ' + err);
                });
        }
        
        function openKioskDetail(kioskId, hostname) {
            // Create modal
            const modal = document.createElement('div');
            modal.id = 'kiosk-detail-modal';
            modal.style.cssText = `
                display: flex;
                position: fixed;
                z-index: 1000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                align-items: center;
                justify-content: center;
            `;
            
            // Close modal when clicking outside
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
            
            modal.innerHTML = `
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 8px;
                    max-width: 600px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin: 0;">Kijelző Részletek: ${hostname}</h2>
                        <button onclick="document.getElementById('kiosk-detail-modal').remove()" style="
                            background: #1a3a52;
                            color: white;
                            border: none;
                            font-size: 16px;
                            cursor: pointer;
                            width: 36px;
                            height: 36px;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            transition: background 0.2s;
                        " onmouseover="this.style.background='#0f2537'" onmouseout="this.style.background='#1a3a52'">✕</button>
                    </div>
                    
                    <div id="kiosk-detail-content" style="color: #666;">
                        <p style="text-align: center; padding: 40px;">Betöltés...</p>
                    </div>
                </div>
            `;
            
            document.body.appendChild(modal);
            
            // Load kiosk details via AJAX
            fetch(`../api/kiosk_details.php?id=${kioskId}`)
                .then(response => response.json())
                .then(data => {
                    const content = document.getElementById('kiosk-detail-content');
                    if (data.success) {
                        content.innerHTML = `
                            <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                                <h3 style="margin-top: 0;">ℹ️ Alapadatok</h3>
                                <table style="width: 100%; font-size: 13px;">
                                    <tr><td style="font-weight: bold; width: 30%;">MAC Cím:</td><td><code>${data.mac}</code></td></tr>
                                    <tr><td style="font-weight: bold;">Státusz:</td><td>${data.status === 'online' ? '🟢 Online' : '🔴 Offline'}</td></tr>
                                    <tr><td style="font-weight: bold;">Hely:</td><td>${data.location || '-'}</td></tr>
                                    <tr><td style="font-weight: bold;">Utolsó szinkronizálás:</td><td>${data.last_seen || 'Soha'}</td></tr>
                                    <tr>
                                        <td style="font-weight: bold;">Szinkronizálás gyakorisága:</td>
                                        <td>
                                            <select id="sync-interval-selector-${kioskId}" onchange="updateSyncInterval(${kioskId}, this.value)" style="padding: 5px; border-radius: 3px; border: 1px solid #ccc;">
                                                <option value="10" ${data.sync_interval === 10 ? 'selected' : ''}>10 másodperc</option>
                                                <option value="15" ${data.sync_interval === 15 ? 'selected' : ''}>15 másodperc</option>
                                                <option value="30" ${data.sync_interval === 30 ? 'selected' : ''}>30 másodperc</option>
                                                <option value="60" ${data.sync_interval === 60 ? 'selected' : ''}>1 perc</option>
                                                <option value="120" ${data.sync_interval === 120 ? 'selected' : ''}>2 perc (120s)</option>
                                                <option value="300" ${data.sync_interval === 300 ? 'selected' : ''}>5 perc (300s)</option>
                                                <option value="600" ${data.sync_interval === 600 ? 'selected' : ''}>10 perc (600s)</option>
                                            </select>
                                        </td>
                                    </tr>
                                </table>
                            </div>
                            
                            <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                                    <h3 style="margin: 0;">📸 Képernyőkép</h3>
                                    <div style="display: flex; align-items: center; gap: 10px;">
                                        <label style="display: flex; align-items: center; cursor: pointer; user-select: none;">
                                            <input type="checkbox" id="screenshot-toggle-${kioskId}" ${data.screenshot_enabled ? 'checked' : ''} 
                                                onchange="toggleScreenshot(${kioskId}, this.checked)" 
                                                style="margin-right: 5px; cursor: pointer;">
                                            <span style="font-weight: bold; color: ${data.screenshot_enabled ? '#2e7d32' : '#999'};">
                                                ${data.screenshot_enabled ? '✅ Bekapcsolva' : '⭕ Kikapcsolva'}
                                            </span>
                                        </label>
                                        ${data.screenshot_enabled && data.screenshot_url ? 
                                            '<button onclick="refreshScreenshot(' + kioskId + ')" style="background: #1a3a52; color: white; border: none; padding: 6px 12px; border-radius: 5px; cursor: pointer; font-size: 12px;">🔄 Frissítés</button>' 
                                            : ''}
                                    </div>
                                </div>
                                <div id="screenshot-container-${kioskId}" style="margin-top: 10px;">
                                    ${data.screenshot_enabled ? 
                                        (data.screenshot_url ? 
                                            '<img src="../' + data.screenshot_url + '?t=' + Date.now() + '" alt="Screenshot" style="width: 100%; max-width: 600px; border: 2px solid #ddd; border-radius: 5px; display: block; margin: 0 auto;" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';"><p style="display: none; text-align: center; color: #999; padding: 20px;">Screenshot nem elérhető</p>' 
                                            : '<p style="text-align: center; color: #999; padding: 20px;">Még nincs képernyőkép feltöltve. Várjon a következő szinkronizálásig.</p>'
                                        ) 
                                        : '<p style="text-align: center; color: #999; padding: 20px;">A képernyőkép funkció ki van kapcsolva. Kapcsolja be a képernyőképek fogadásához.</p>'
                                    }
                                </div>
                            </div>
                            
                            ${data.hw_info ? `
                            <div style="background: #f9f9f9; padding: 15px; border-radius: 5px; margin-bottom: 15px;">
                                <h3 style="margin-top: 0;">🖥️ Hardware Adatok</h3>
                                <table style="width: 100%; font-size: 13px;">
                                    ${data.hw_info.cpu ? `<tr><td style="font-weight: bold;">CPU:</td><td>${data.hw_info.cpu}</td></tr>` : ''}
                                    ${data.hw_info.ram ? `<tr><td style="font-weight: bold;">RAM:</td><td>${data.hw_info.ram}</td></tr>` : ''}
                                    ${data.hw_info.disk ? `<tr><td style="font-weight: bold;">Tárhely:</td><td>${data.hw_info.disk}</td></tr>` : ''}
                                    ${data.hw_info.uptime ? `<tr><td style="font-weight: bold;">Üzemidő:</td><td>${data.hw_info.uptime}</td></tr>` : ''}
                                </table>
                            </div>
                            ` : ''}
                            
                            ${data.modules && data.modules.length > 0 ? `
                            <div style="background: #f9f9f9; padding: 15px; border-radius: 5px;">
                                <h3 style="margin-top: 0;">🎬 Modulok Loop Sorrendje</h3>
                                <div id="modules-loop" style="display: grid; grid-template-columns: repeat(auto-fill, minmax(100px, 1fr)); gap: 10px;">
                                    ${data.modules.map(m => `<div style="background: white; padding: 10px; border-radius: 3px; border-left: 4px solid #1a3a52; text-align: center; font-size: 12px;">${m}</div>`).join('')}
                                </div>
                            </div>
                            ` : ''}
                        `;
                    } else {
                        content.innerHTML = `<p style="color: #d32f2f;">Hiba: ${data.message}</p>`;
                    }
                })
                .catch(err => {
                    if (document.getElementById('kiosk-detail-content')) {
                        document.getElementById('kiosk-detail-content').innerHTML = `<p style="color: #d32f2f;">Hiba: ${err}</p>`;
                    }
                });
        }
        
        function viewKioskLoop(kioskId, hostname) {
            // Get kiosk's group(s) first
            fetch(`../api/get_kiosk_loop.php?kiosk_id=${kioskId}`)
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        const loops = data.loops;
                        let html = '';
                        
                        if (loops.length === 0) {
                            html += '<p style="text-align: center; color: #999; padding: 20px;">Nincs beállított loop ehhez a kijelzőhöz</p>';
                        } else {
                            // Preview section with speed controls
                            html += `
                            <div style="margin-bottom: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px;">
                                <h3 style="margin-top: 0; margin-bottom: 10px;">🎬 Előnézet Lejátszó</h3>
                                <div style="display: flex; align-items: center; gap: 10px; margin-bottom: 10px;">
                                    <button onclick="playLoopPreview(${kioskId}, 1)" style="
                                        background: #1a3a52;
                                        color: white;
                                        border: none;
                                        padding: 8px 16px;
                                        border-radius: 5px;
                                        cursor: pointer;
                                        font-size: 13px;
                                    ">▶️ Lejátszás (1x)</button>
                                    <button onclick="playLoopPreview(${kioskId}, 2)" style="
                                        background: #1a3a52;
                                        color: white;
                                        border: none;
                                        padding: 8px 16px;
                                        border-radius: 5px;
                                        cursor: pointer;
                                        font-size: 13px;
                                    ">⏩ Gyors (2x)</button>
                                    <button onclick="playLoopPreview(${kioskId}, 4)" style="
                                        background: #1a3a52;
                                        color: white;
                                        border: none;
                                        padding: 8px 16px;
                                        border-radius: 5px;
                                        cursor: pointer;
                                        font-size: 13px;
                                    ">⏩⏩ Nagyon Gyors (4x)</button>
                                    <button onclick="stopLoopPreview()" style="
                                        background: #d32f2f;
                                        color: white;
                                        border: none;
                                        padding: 8px 16px;
                                        border-radius: 5px;
                                        cursor: pointer;
                                        font-size: 13px;
                                    ">⏹️ Stop</button>
                                </div>
                                <div id="preview-display" style="
                                    background: #1a1a1a;
                                    color: white;
                                    padding: 40px;
                                    border-radius: 8px;
                                    text-align: center;
                                    font-size: 24px;
                                    font-weight: bold;
                                    min-height: 150px;
                                    display: flex;
                                    align-items: center;
                                    justify-content: center;
                                    flex-direction: column;
                                    gap: 10px;
                                ">
                                    <div>Nyomja meg a lejátszás gombot</div>
                                    <div style="font-size: 14px; opacity: 0.7; font-weight: normal;">Az előnézet a modulokat fogja megjeleníteni az időzítések szerint</div>
                                </div>
                                <div id="preview-progress" style="
                                    margin-top: 10px;
                                    font-size: 12px;
                                    color: #666;
                                    text-align: center;
                                "></div>
                            </div>`;
                            
                            // Loop configuration list
                            html += '<div style="max-height: 400px; overflow-y: auto;">';
                            html += '<h3 style="margin-top: 0;">📋 Modul Sorrend</h3>';
                            html += '<div style="display: flex; flex-direction: column; gap: 10px;">';
                            loops.forEach((loop, index) => {
                                html += `<div class="loop-item-${index}" style="
                                    background: linear-gradient(135deg, #0f2537 0%, #1a4d2e 100%);
                                    color: white;
                                    padding: 15px;
                                    border-radius: 8px;
                                    display: flex;
                                    align-items: center;
                                    gap: 15px;
                                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
                                    transition: transform 0.2s;
                                ">
                                    <div style="
                                        background: rgba(255,255,255,0.2);
                                        padding: 8px 12px;
                                        border-radius: 5px;
                                        font-weight: bold;
                                        font-size: 14px;
                                    ">${index + 1}</div>
                                    <div style="flex: 1;">
                                        <div style="font-weight: bold; font-size: 14px;">${loop.module_name}</div>
                                        <div style="font-size: 12px; opacity: 0.9;">${loop.description || ''}</div>
                                    </div>
                                    <div style="
                                        background: rgba(255,255,255,0.2);
                                        padding: 8px 12px;
                                        border-radius: 5px;
                                        text-align: center;
                                    ">
                                        <div style="font-size: 18px; font-weight: bold;">${loop.duration_seconds}</div>
                                        <div style="font-size: 11px; opacity: 0.9;">sec</div>
                                    </div>
                                </div>`;
                            });
                            html += '</div>';
                            
                            // Add total duration
                            const totalDuration = loops.reduce((sum, loop) => sum + parseInt(loop.duration_seconds), 0);
                            html += `<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; text-align: center;">
                                <strong>Teljes loop időtartam:</strong> ${totalDuration} másodperc (${Math.floor(totalDuration / 60)} perc ${totalDuration % 60} mp)
                            </div>`;
                            html += '</div>';
                        }
                        
                        showModal('🔄 Loop Konfiguráció - ' + hostname, html);
                        
                        // Store loop data for preview
                        window.currentLoopData = loops;
                    } else {
                        alert('⚠️ ' + data.message);
                    }
                })
                .catch(error => {
                    alert('⚠️ Hiba történt: ' + error);
                });
        }
        
        function showModal(title, content) {
            const modal = document.createElement('div');
            modal.style.cssText = `
                display: flex;
                position: fixed;
                z-index: 1000;
                left: 0;
                top: 0;
                width: 100%;
                height: 100%;
                background-color: rgba(0, 0, 0, 0.5);
                align-items: center;
                justify-content: center;
            `;
            
            modal.addEventListener('click', function(e) {
                if (e.target === modal) {
                    modal.remove();
                }
            });
            
            modal.innerHTML = `
                <div style="
                    background: white;
                    padding: 30px;
                    border-radius: 12px;
                    max-width: 700px;
                    width: 90%;
                    max-height: 80vh;
                    overflow-y: auto;
                    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
                ">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                        <h2 style="margin: 0;">${title}</h2>
                        <button onclick="this.closest('div').parentElement.parentElement.remove()" style="
                            background: #1a3a52;
                            color: white;
                            border: none;
                            font-size: 16px;
                            cursor: pointer;
                            width: 36px;
                            height: 36px;
                            border-radius: 50%;
                            display: flex;
                            align-items: center;
                            justify-content: center;
                            transition: background 0.2s;
                        " onmouseover="this.style.background='#0f2537'" onmouseout="this.style.background='#1a3a52'">✕</button>
                    </div>
                    <div>${content}</div>
                </div>
            `;
            
            document.body.appendChild(modal);
        }
        
        // Toggle between list and realtime view
        let currentView = 'list';
        let realtimeRefreshInterval = null;
        let countdownInterval = null;
        
        function toggleView() {
            const listView = document.getElementById('listView');
            const realtimeView = document.getElementById('realtimeView');
            const toggleBtn = document.getElementById('toggleViewBtn');
            
            if (currentView === 'list') {
                listView.style.display = 'none';
                realtimeView.style.display = 'block';
                toggleBtn.textContent = '📋 Lista Nézet';
                currentView = 'realtime';
                startRealtimeRefresh();
            } else {
                listView.style.display = 'block';
                realtimeView.style.display = 'none';
                toggleBtn.textContent = '📸 Realtime Nézet';
                currentView = 'list';
                stopRealtimeRefresh();
            }
        }
        
        function startRealtimeRefresh() {
            // Initial load
            refreshScreenshots();
            
            // Set up auto-refresh every 15 seconds
            let countdown = 15;
            realtimeRefreshInterval = setInterval(() => {
                refreshScreenshots();
                countdown = 15;
            }, 15000);
            
            // Countdown timer
            countdownInterval = setInterval(() => {
                countdown--;
                const countdownEl = document.getElementById('refreshCountdown');
                if (countdownEl) {
                    countdownEl.textContent = countdown;
                }
                if (countdown <= 0) {
                    countdown = 15;
                }
            }, 1000);
        }
        
        function stopRealtimeRefresh() {
            if (realtimeRefreshInterval) {
                clearInterval(realtimeRefreshInterval);
                realtimeRefreshInterval = null;
            }
            if (countdownInterval) {
                clearInterval(countdownInterval);
                countdownInterval = null;
            }
        }
        
        // Filter by group function
        function filterByGroup() {
            const groupFilter = document.getElementById('groupFilter');
            const selectedGroupId = groupFilter.value;
            const cards = document.querySelectorAll('.screenshot-card');
            
            cards.forEach(card => {
                const groupIds = (card.getAttribute('data-group-ids') || '').split(',').filter(id => id);
                
                // Show card if no group selected or card belongs to selected group
                if (!selectedGroupId) {
                    card.style.display = '';
                } else if (groupIds.includes(selectedGroupId)) {
                    card.style.display = '';
                } else {
                    card.style.display = 'none';
                }
            });
        }
        
        function refreshScreenshots() {
            // Get all screenshot cards
            const cards = document.querySelectorAll('.screenshot-card');
            
            cards.forEach(card => {
                const kioskId = card.getAttribute('data-kiosk-id');
                
                // Fetch latest screenshot URL for this kiosk
                fetch(`../api/kiosk_details.php?id=${kioskId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            const img = card.querySelector('.screenshot-img');
                            const timestampEl = document.getElementById(`screenshot-time-${kioskId}`);
                            
                            // Update screenshot if URL changed
                            if (data.screenshot_url) {
                                const newSrc = '../' + data.screenshot_url + '?t=' + new Date().getTime();
                                if (img.src !== newSrc) {
                                    img.src = newSrc;
                                }
                                
                                // Update timestamp
                                if (timestampEl) {
                                    const now = new Date();
                                    timestampEl.textContent = now.toLocaleTimeString('hu-HU');
                                }
                            }
                            
                            // Update tech info
                            if (data.version) {
                                const versionEl = document.getElementById(`version-${kioskId}`);
                                if (versionEl) versionEl.textContent = data.version;
                            }
                            
                            if (data.screen_resolution) {
                                const resEl = document.getElementById(`resolution-${kioskId}`);
                                if (resEl) resEl.textContent = data.screen_resolution;
                            }
                            
                            if (data.screen_status) {
                                const statusEl = document.getElementById(`screen-status-${kioskId}`);
                                if (statusEl) {
                                    let statusText = '';
                                    if (data.screen_status == 'on') statusText = '✅ Bekapcsolva';
                                    else if (data.screen_status == 'off') statusText = '❌ Kikapcsolva';
                                    else statusText = '❓ ' + data.screen_status;
                                    statusEl.textContent = statusText;
                                }
                            }
                            
                            // Update sync timestamps
                            if (data.last_sync) {
                                const syncEl = document.getElementById(`last-sync-${kioskId}`);
                                if (syncEl) {
                                    const syncTime = new Date(data.last_sync);
                                    syncEl.textContent = syncTime.toLocaleTimeString('hu-HU', { hour: '2-digit', minute: '2-digit' });
                                }
                            }
                            
                            if (data.loop_last_update) {
                                const loopEl = document.getElementById(`loop-version-${kioskId}`);
                                if (loopEl) {
                                    const loopTime = new Date(data.loop_last_update);
                                    loopEl.textContent = loopTime.toLocaleTimeString('hu-HU', { hour: '2-digit', minute: '2-digit' });
                                }
                            }
                        }
                    })
                    .catch(err => console.error('Failed to refresh screenshot for kiosk ' + kioskId, err));
            });
        }
        
        // Loop preview functions
        let previewInterval = null;
        let previewTimeout = null;
        
        function playLoopPreview(kioskId, speed) {
            stopLoopPreview(); // Stop any existing preview
            
            if (!window.currentLoopData || window.currentLoopData.length === 0) {
                alert('Nincs elérhető loop adat');
                return;
            }
            
            const loops = window.currentLoopData;
            const previewDisplay = document.getElementById('preview-display');
            const previewProgress = document.getElementById('preview-progress');
            
            if (!previewDisplay) return;
            
            let currentIndex = 0;
            let cycleCount = 0;
            const maxCycles = 2; // Show 2 full cycles
            
            function showModule(index) {
                if (!loops[index]) return;
                
                const loop = loops[index];
                const actualDuration = parseInt(loop.duration_seconds) * 1000; // ms
                const displayDuration = actualDuration / speed; // Adjust for speed
                
                // Highlight current module in list
                document.querySelectorAll('[class^="loop-item-"]').forEach((el, i) => {
                    if (i === index) {
                        el.style.transform = 'scale(1.05)';
                        el.style.border = '3px solid #ffd700';
                    } else {
                        el.style.transform = 'scale(1)';
                        el.style.border = 'none';
                    }
                });
                
                // Update preview display
                previewDisplay.innerHTML = `
                    <div style="font-size: 32px; font-weight: bold;">${loop.module_name}</div>
                    <div style="font-size: 16px; opacity: 0.8;">${loop.description || ''}</div>
                    <div style="margin-top: 20px; font-size: 14px; opacity: 0.6;">
                        Modul ${index + 1} / ${loops.length} | 
                        ${speed}x sebesség | 
                        Eredeti időtartam: ${loop.duration_seconds}s
                    </div>
                    <div style="margin-top: 10px; width: 100%; background: rgba(255,255,255,0.2); height: 4px; border-radius: 2px; overflow: hidden;">
                        <div id="progress-bar" style="width: 0%; height: 100%; background: #4caf50; transition: width ${displayDuration}ms linear;"></div>
                    </div>
                `;
                
                // Animate progress bar
                setTimeout(() => {
                    const progressBar = document.getElementById('progress-bar');
                    if (progressBar) {
                        progressBar.style.width = '100%';
                    }
                }, 50);
                
                // Update progress text
                if (previewProgress) {
                    previewProgress.textContent = `Ciklus ${cycleCount + 1}/${maxCycles} - Modul ${index + 1}/${loops.length}`;
                }
                
                // Schedule next module
                previewTimeout = setTimeout(() => {
                    currentIndex++;
                    if (currentIndex >= loops.length) {
                        currentIndex = 0;
                        cycleCount++;
                        
                        if (cycleCount >= maxCycles) {
                            stopLoopPreview();
                            previewDisplay.innerHTML = `
                                <div style="font-size: 24px;">✅ Előnézet befejezve</div>
                                <div style="font-size: 14px; opacity: 0.7; margin-top: 10px;">
                                    ${maxCycles} teljes ciklus lejátszva ${speed}x sebességgel
                                </div>
                            `;
                            if (previewProgress) {
                                previewProgress.textContent = '';
                            }
                            // Reset highlighting
                            document.querySelectorAll('[class^="loop-item-"]').forEach(el => {
                                el.style.transform = 'scale(1)';
                                el.style.border = 'none';
                            });
                            return;
                        }
                    }
                    showModule(currentIndex);
                }, displayDuration);
            }
            
            // Start preview
            showModule(0);
        }
        
        function stopLoopPreview() {
            if (previewTimeout) {
                clearTimeout(previewTimeout);
                previewTimeout = null;
            }
            if (previewInterval) {
                clearInterval(previewInterval);
                previewInterval = null;
            }
            
            // Reset highlighting
            document.querySelectorAll('[class^="loop-item-"]').forEach(el => {
                el.style.transform = 'scale(1)';
                el.style.border = 'none';
            });
            
            const previewDisplay = document.getElementById('preview-display');
            const previewProgress = document.getElementById('preview-progress');
            
            if (previewDisplay) {
                previewDisplay.innerHTML = `
                    <div>Előnézet leállítva</div>
                    <div style="font-size: 14px; opacity: 0.7; font-weight: normal;">Nyomja meg a lejátszás gombot újra</div>
                `;
            }
            if (previewProgress) {
                previewProgress.textContent = '';
            }
        }
    </script>

<?php include '../admin/footer.php'; ?>

