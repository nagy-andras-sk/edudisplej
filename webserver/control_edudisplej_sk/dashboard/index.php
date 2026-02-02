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
        // Get company kiosks
        $query = "SELECT k.* FROM kiosks k WHERE k.company_id = ? ORDER BY k.hostname";
        $stmt = $conn->prepare($query);
        $stmt->bind_param("i", $company_id);
        $stmt->execute();
        $result = $stmt->get_result();
        
        while ($row = $result->fetch_assoc()) {
            $kiosks[] = $row;
        }
        $stmt->close();
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
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 15px;">
                <p style="color: #666; margin: 0;">A cégjéhez rendelt összes kijelző</p>
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
                    <div style="display: grid; grid-template-columns: repeat(auto-fill, minmax(300px, 1fr)); gap: 20px;">
                        <?php foreach ($kiosks as $kiosk): ?>
                            <div class="screenshot-card" data-kiosk-id="<?php echo $kiosk['id']; ?>" style="
                                background: white;
                                border-radius: 10px;
                                padding: 15px;
                                box-shadow: 0 2px 8px rgba(0,0,0,0.1);
                                transition: transform 0.2s;
                            " onmouseover="this.style.transform='translateY(-5px)'" onmouseout="this.style.transform='translateY(0)'">
                                <div style="margin-bottom: 10px;">
                                    <h3 style="margin: 0; font-size: 16px; color: #1a3a52;">
                                        <?php echo htmlspecialchars($kiosk['hostname'] ?? 'N/A'); ?>
                                    </h3>
                                    <small style="color: #999;">
                                        <span class="status-badge status-<?php echo $kiosk['status']; ?>">
                                            <?php echo $kiosk['status'] == 'online' ? '🟢' : '🔴'; ?>
                                        </span>
                                        <?php echo htmlspecialchars($kiosk['location'] ?? '-'); ?>
                                    </small>
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
                    timeStr = `${diffMins} perc${diffSecs > 0 ? ` ${diffSecs}s` : ''} elôtte`;
                } else {
                    timeStr = `${diffSecs}s elôtte`;
                }
                
                // If more than 120 minutes, show as red OFFLINE
                if (diffMins > 120) {
                    el.innerHTML = `<span style="color: #d32f2f; font-weight: bold;">OFFLINE (${timeStr})</span>`;
                } else {
                    el.innerHTML = timeStr;
                }
            });
        }
        
        updateSyncTimes();
        setInterval(updateSyncTimes, 5000); // Update every 5 seconds (real-time)
        
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
                                                <option value="120" ${data.sync_interval === 120 ? 'selected' : ''}>2 perc (120s)</option>
                                                <option value="300" ${data.sync_interval === 300 ? 'selected' : ''}>5 perc (300s)</option>
                                                <option value="600" ${data.sync_interval === 600 ? 'selected' : ''}>10 perc (600s)</option>
                                            </select>
                                        </td>
                                    </tr>
                                </table>
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
                        let html = '<div style="max-height: 500px; overflow-y: auto;">';
                        
                        if (loops.length === 0) {
                            html += '<p style="text-align: center; color: #999; padding: 20px;">Nincs beállított loop ehhez a kijelzőhöz</p>';
                        } else {
                            html += '<div style="display: flex; flex-direction: column; gap: 10px;">';
                            loops.forEach((loop, index) => {
                                html += `<div style="
                                    background: linear-gradient(135deg, #0f2537 0%, #1a4d2e 100%);
                                    color: white;
                                    padding: 15px;
                                    border-radius: 8px;
                                    display: flex;
                                    align-items: center;
                                    gap: 15px;
                                    box-shadow: 0 2px 8px rgba(0,0,0,0.2);
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
                        }
                        html += '</div>';
                        
                        showModal('🔄 Loop Konfiguráció - ' + hostname, html);
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
                        }
                    })
                    .catch(err => console.error('Failed to refresh screenshot for kiosk ' + kioskId, err));
            });
        }
    </script>

<?php include '../admin/footer.php'; ?>

