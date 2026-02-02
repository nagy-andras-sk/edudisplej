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
            <p style="color: #666; margin-bottom: 15px;">A cégjéhez rendelt összes kijelző</p>
            
            <?php if (empty($kiosks)): ?>
                <div style="text-align: center; padding: 40px; color: #999; background: white; border-radius: 10px;">
                    Nincs kijelző hozzárendelve a cégjéhez
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
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
    </script>

<?php include '../admin/footer.php'; ?>

