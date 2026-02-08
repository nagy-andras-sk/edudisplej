<?php
/**
 * Company Dashboard - Saját Kijelzők
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';

$current_lang = edudisplej_apply_language_preferences();

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

    $priority_check = $conn->query("SHOW COLUMNS FROM kiosk_groups LIKE 'priority'");
    if ($priority_check && $priority_check->num_rows === 0) {
        $conn->query("ALTER TABLE kiosk_groups ADD COLUMN priority INT(11) NOT NULL DEFAULT 0");
    }
    
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
        $error = t('dashboard.error.no_company_assigned');
    } else if ($company_id) {
        // Get company kiosks with group information
        // Calculate status dynamically: offline if last_seen is more than 30 minutes ago
        $query = "SELECT k.*, 
                  CASE 
                      WHEN k.last_seen IS NULL THEN 'offline'
                      WHEN TIMESTAMPDIFF(MINUTE, k.last_seen, NOW()) > 30 THEN 'offline'
                      ELSE 'online'
                  END as status,
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
        $groups_query = "SELECT DISTINCT g.id, g.name, g.priority FROM kiosk_groups g 
                        INNER JOIN kiosk_group_assignments kga ON g.id = kga.group_id
                        INNER JOIN kiosks k ON kga.kiosk_id = k.id
                        WHERE k.company_id = ?
                ORDER BY g.priority DESC, g.name";
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
$no_image_text = rawurlencode(t('dashboard.screenshot.none'));
$no_image_svg = "data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='140' height='78'%3E%3Crect fill='%23f5f5f5' width='140' height='78'/%3E%3Ctext x='50%25' y='50%25' text-anchor='middle' fill='%23999' font-size='12' dy='.3em'%3E{$no_image_text}%3C/text%3E%3C/svg%3E";
?>
<?php include '../admin/header.php'; ?>
    <style>
        .minimal-summary {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            align-items: center;
            color: #333;
            font-size: 13px;
            margin-bottom: 15px;
        }

        .summary-item {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            background: #f7f9fb;
            border: 1px solid #e1e5ea;
            border-radius: 14px;
            padding: 4px 10px;
            cursor: pointer;
        }

        .summary-dot {
            width: 8px;
            height: 8px;
            border-radius: 50%;
            display: inline-block;
        }

        .dot-total { background: #607d8b; }
        .dot-online { background: #2e7d32; }
        .dot-offline { background: #c62828; }
        .dot-groups { background: #1565c0; }

        .minimal-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        .minimal-table th,
        .minimal-table td {
            border-bottom: 1px solid #e6e9ed;
            padding: 10px 8px;
            text-align: left;
            vertical-align: middle;
        }

        .minimal-table th {
            background: #fafbfc;
            font-weight: 600;
            color: #444;
        }

        .compact-btn {
            background: #1a3a52;
            color: white;
            border: none;
            padding: 6px 12px;
            border-radius: 4px;
            cursor: pointer;
            font-size: 12px;
        }

        .compact-select {
            padding: 6px 8px;
            border: 1px solid #d9dde2;
            border-radius: 4px;
            font-size: 12px;
            min-width: 160px;
        }

        .preview-card {
            position: relative;
            width: 140px;
            height: 78px;
            border: 1px solid #e1e5ea;
            border-radius: 4px;
            background: #f5f5f5;
            overflow: hidden;
        }

        .preview-card .screenshot-img {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            object-fit: contain;
        }

        .preview-card .screenshot-loader {
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            display: none;
            align-items: center;
            justify-content: center;
            background: repeating-linear-gradient(
                45deg,
                rgba(0,0,0,0.03),
                rgba(0,0,0,0.03) 10px,
                rgba(0,0,0,0.06) 10px,
                rgba(0,0,0,0.06) 20px
            );
            color: #666;
            font-size: 11px;
            text-align: center;
            padding: 6px;
        }

        .preview-card .screenshot-timestamp {
            position: absolute;
            bottom: 3px;
            right: 4px;
            background: rgba(0,0,0,0.7);
            color: white;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 10px;
        }

        .loop-mini {
            margin-top: 6px;
            background: #f7f9fb;
            border: 1px solid #e1e5ea;
            border-radius: 4px;
            font-size: 11px;
            color: #444;
            padding: 4px 6px;
            min-height: 26px;
            display: flex;
            align-items: center;
        }

        .loop-info {
            margin-top: 4px;
            font-size: 11px;
            color: #666;
        }
    </style>
        
        <?php if ($error): ?>
            <div class="error">⚠️ <?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        
        <?php if ($company_id): ?>
            <div class="minimal-summary">
                <span class="summary-item" data-filter="all" onclick="applyStatusFilter('all')"><span class="summary-dot dot-total"></span><?php echo htmlspecialchars(t('dashboard.total')); ?></span>
                <span class="summary-item" data-filter="online" onclick="applyStatusFilter('online')"><span class="summary-dot dot-online"></span><?php echo htmlspecialchars(t('dashboard.online')); ?>: <?php echo count(array_filter($kiosks, fn($k) => $k['status'] == 'online')); ?></span>
                <span class="summary-item" data-filter="offline" onclick="applyStatusFilter('offline')"><span class="summary-dot dot-offline"></span><?php echo htmlspecialchars(t('dashboard.offline')); ?>: <?php echo count(array_filter($kiosks, fn($k) => $k['status'] == 'offline')); ?></span>
            </div>
            
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 12px; gap: 12px; flex-wrap: wrap;">
                <div style="color: #666; font-size: 13px;"><?php echo htmlspecialchars(t('dashboard.company_displays')); ?></div>
                <div style="display: flex; gap: 8px; align-items: center;">
                    <?php if (!empty($groups)): ?>
                    <select id="groupFilter" onchange="filterByGroup()" class="compact-select">
                        <option value=""><?php echo htmlspecialchars(t('dashboard.all_groups')); ?></option>
                        <?php foreach ($groups as $group): ?>
                            <option value="<?php echo $group['id']; ?>"><?php echo htmlspecialchars($group['name']); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <?php endif; ?>
                </div>
            </div>
            
            <?php if (empty($kiosks)): ?>
                <div style="text-align: center; padding: 40px; color: #999; background: white; border-radius: 10px;">
                    <?php echo htmlspecialchars(t('dashboard.none_assigned')); ?>
                </div>
            <?php else: ?>
                <!-- List View -->
                <div id="listView" style="overflow-x: auto;">
                    <table class="minimal-table">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(t('dashboard.header.id')); ?></th>
                                <th><?php echo htmlspecialchars(t('dashboard.header.hostname')); ?></th>
                                <th><?php echo htmlspecialchars(t('dashboard.header.status')); ?></th>
                                <th><?php echo htmlspecialchars(t('dashboard.header.group')); ?></th>
                                <th><?php echo htmlspecialchars(t('dashboard.header.preview')); ?></th>
                                <th><?php echo htmlspecialchars(t('dashboard.header.location')); ?></th>
                                <th><?php echo htmlspecialchars(t('dashboard.header.last_sync')); ?></th>
                                <th><?php echo htmlspecialchars(t('dashboard.header.loop')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($kiosks as $kiosk): ?>
                                <?php
                                    $group_ids = array_filter(explode(',', $kiosk['group_ids'] ?? ''));
                                    $selected_group_id = $group_ids[0] ?? '';
                                ?>
                                <tr data-status="<?php echo htmlspecialchars($kiosk['status']); ?>" data-group-ids="<?php echo htmlspecialchars($kiosk['group_ids'] ?? ''); ?>">
                                    <td><small><?php echo $kiosk['id']; ?></small></td>
                                    <td>
                                        <strong style="cursor: pointer; color: #1a3a52;" onclick="openKioskDetail(<?php echo $kiosk['id']; ?>, '<?php echo htmlspecialchars($kiosk['hostname'] ?? 'N/A'); ?>')">
                                            <?php echo htmlspecialchars($kiosk['hostname'] ?? 'N/A'); ?>
                                        </strong>
                                    </td>
                                    <td>
                                        <span class="status-badge status-<?php echo $kiosk['status']; ?>">
                                            <?php echo $kiosk['status'] == 'online' ? '🟢 ' . htmlspecialchars(t('dashboard.status.online')) : '🔴 ' . htmlspecialchars(t('dashboard.status.offline')); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <select class="compact-select" onchange="assignGroup(<?php echo $kiosk['id']; ?>, this.value)">
                                            <option value="">-</option>
                                            <?php foreach ($groups as $group): ?>
                                                <option value="<?php echo $group['id']; ?>" <?php echo ((string)$group['id'] === (string)$selected_group_id) ? 'selected' : ''; ?>>
                                                    <?php echo htmlspecialchars($group['name']); ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <div class="preview-card" data-kiosk-id="<?php echo $kiosk['id']; ?>" data-screenshot-url="<?php echo htmlspecialchars($kiosk['screenshot_url'] ?? ''); ?>" data-screenshot-timestamp="<?php echo htmlspecialchars($kiosk['screenshot_timestamp'] ?? ''); ?>">
                                            <img class="screenshot-img" src="<?php echo !empty($kiosk['screenshot_url']) ? '../' . $kiosk['screenshot_url'] : $no_image_svg; ?>" alt="Preview" />
                                            <div class="screenshot-loader"><?php echo htmlspecialchars(t('dashboard.screenshot.no_fresh')); ?></div>
                                            <div class="screenshot-timestamp"><span id="screenshot-time-<?php echo $kiosk['id']; ?>">-</span></div>
                                        </div>
                                    </td>
                                    <td><?php echo htmlspecialchars($kiosk['location'] ?? '-'); ?></td>
                                    <td><small id="sync-time-<?php echo $kiosk['id']; ?>" data-last-seen="<?php echo htmlspecialchars($kiosk['last_seen']); ?>"></small></td>
                                    <td>
                                        <button class="compact-btn" onclick="viewKioskLoop(<?php echo $kiosk['id']; ?>, '<?php echo htmlspecialchars($kiosk['hostname'] ?? 'N/A', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($kiosk['screen_resolution'] ?? '', ENT_QUOTES); ?>', '<?php echo htmlspecialchars($selected_group_id, ENT_QUOTES); ?>')">
                                            <?php echo htmlspecialchars(t('dashboard.loop.preview')); ?>
                                        </button>
                                        <div class="loop-mini" id="loop-preview-<?php echo $kiosk['id']; ?>"><?php echo htmlspecialchars(t('dashboard.loop.loading')); ?></div>
                                        <div class="loop-info" id="loop-info-<?php echo $kiosk['id']; ?>" data-loop-last-update="<?php echo htmlspecialchars($kiosk['loop_last_update'] ?? ''); ?>"></div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div style="text-align: center; padding: 40px; color: #999;">
                <p><?php echo htmlspecialchars(t('dashboard.no_company')); ?></p>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        const I18N = <?php echo json_encode(edudisplej_i18n_catalog($current_lang), JSON_UNESCAPED_UNICODE); ?>;
        const CURRENT_LANG = '<?php echo htmlspecialchars($current_lang, ENT_QUOTES); ?>';
        const LOCALE_MAP = { hu: 'hu-HU', en: 'en-US', sk: 'sk-SK' };
        const CURRENT_LOCALE = LOCALE_MAP[CURRENT_LANG] || 'en-US';
        const RELATIVE_TIME = new Intl.RelativeTimeFormat(CURRENT_LOCALE, { numeric: 'auto' });

        function tjs(key, vars = {}) {
            let value = I18N[key] || key;
            Object.keys(vars).forEach(name => {
                value = value.replace(`{${name}}`, String(vars[name]));
            });
            return value;
        }

        // Store the initial load time
        let pageLoadTime = new Date();
        
        // Update sync times on page load
        function updateSyncTimes() {
            document.querySelectorAll('[id^="sync-time-"]').forEach(el => {
                const lastSeen = el.getAttribute('data-last-seen');
                if (!lastSeen || lastSeen === 'NULL' || lastSeen === '') {
                    el.innerHTML = `<span style="color: #999;">${tjs('dashboard.sync.never')}</span>`;
                    return;
                }
                
                const lastDate = new Date(lastSeen);
                const now = new Date();
                const diffMs = now - lastDate;
                const diffMins = Math.floor(diffMs / 60000);
                const diffSecs = Math.floor((diffMs % 60000) / 1000);
                
                let timeStr = '';
                
                // If more than 120 minutes (2 hours), show full timestamp (date, time)
                if (diffMins > 120) {
                    const year = lastDate.getFullYear();
                    const month = String(lastDate.getMonth() + 1).padStart(2, '0');
                    const day = String(lastDate.getDate()).padStart(2, '0');
                    const hours = String(lastDate.getHours()).padStart(2, '0');
                    const minutes = String(lastDate.getMinutes()).padStart(2, '0');
                    timeStr = `${year}-${month}-${day} ${hours}:${minutes}`;
                    el.innerHTML = `<span style="color: #d32f2f; font-weight: bold;">${timeStr}</span>`;
                } else {
                    if (diffMins > 0) {
                        el.innerHTML = RELATIVE_TIME.format(-diffMins, 'minute');
                    } else {
                        el.innerHTML = RELATIVE_TIME.format(-diffSecs, 'second');
                    }
                }
            });
        }

        function updateLoopInfo() {
            document.querySelectorAll('[id^="loop-info-"]').forEach(el => {
                const loopLastUpdate = el.getAttribute('data-loop-last-update');
                if (!loopLastUpdate || loopLastUpdate === 'NULL' || loopLastUpdate === '') {
                    el.textContent = `${tjs('dashboard.loop.info_time')}: -`;
                    return;
                }
                el.textContent = `${tjs('dashboard.loop.info_time')}: ${formatTimestamp(loopLastUpdate)}`;
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
                            const loopInfoEl = document.getElementById('loop-info-' + kiosk.id);
                            if (loopInfoEl && kiosk.loop_last_update) {
                                loopInfoEl.setAttribute('data-loop-last-update', kiosk.loop_last_update);
                            }
                            const previewCard = document.querySelector(`.preview-card[data-kiosk-id="${kiosk.id}"]`);
                            if (previewCard) {
                                if (kiosk.screenshot_url) {
                                    previewCard.setAttribute('data-screenshot-url', kiosk.screenshot_url);
                                }
                                if (kiosk.screenshot_timestamp) {
                                    previewCard.setAttribute('data-screenshot-timestamp', kiosk.screenshot_timestamp);
                                }
                                renderScreenshotState(previewCard, kiosk.screenshot_url, kiosk.screenshot_timestamp);
                            }
                        });
                        // Update the display after refreshing data
                        updateSyncTimes();
                        updateLoopInfo();
                    }
                })
                .catch(err => {
                    console.error('Error refreshing kiosk data:', err);
                });
            <?php endif; ?>
        }

        function assignGroup(kioskId, groupId) {
            if (!groupId) {
                alert(`⚠️ ${tjs('dashboard.assign_group_missing')}`);
                return;
            }

            fetch(`../api/assign_kiosk_group.php?kiosk_id=${kioskId}&group_id=${groupId}`)
                .then(response => response.json())
                .then(data => {
                    if (!data.success) {
                        alert('⚠️ ' + data.message);
                    }
                })
                .catch(err => {
                    alert(`⚠️ ${tjs('dashboard.error')}: ${err}`);
                });
        }
        
        // Initial display
        updateSyncTimes();
        updateLoopInfo();
        
        // Refresh data from server every 30 seconds
        setInterval(refreshKioskData, 30000);
        
        // Update display every 5 seconds (shows time elapsed)
        setInterval(updateSyncTimes, 5000);
        setInterval(updateLoopInfo, 15000);
        
        function updateSyncInterval(kioskId, newInterval) {
            if (!confirm(tjs('dashboard.sync_interval_confirm', { seconds: newInterval }))) {
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
                    alert(`✅ ${tjs('dashboard.sync_interval_updated')}`);
                } else {
                    alert(`⚠️ ${tjs('dashboard.error')}: ${data.message}`);
                    location.reload();
                }
            })
            .catch(err => {
                alert(`⚠️ ${tjs('dashboard.error')}: ${err}`);
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
                    label.textContent = enabled ? `✅ ${tjs('dashboard.screenshot.enabled')}` : `⭕ ${tjs('dashboard.screenshot.disabled')}`;
                    label.style.color = enabled ? '#2e7d32' : '#999';
                    
                    // Update screenshot container
                    const container = document.getElementById('screenshot-container-' + kioskId);
                    if (enabled) {
                        container.innerHTML = `<p style="text-align: center; color: #999; padding: 20px;">${tjs('dashboard.screenshot.waiting')}</p>`;
                    } else {
                        container.innerHTML = `<p style="text-align: center; color: #999; padding: 20px;">${tjs('dashboard.screenshot.off')}</p>`;
                    }
                    
                    // Update sync interval dropdown
                    const selector = document.getElementById('sync-interval-selector-' + kioskId);
                    if (selector) {
                        selector.value = targetInterval;
                    }
                    
                    alert(`✅ ${tjs('dashboard.screenshot.toggled', { state: enabled ? tjs('dashboard.screenshot.enabled') : tjs('dashboard.screenshot.disabled') })}\n${tjs('dashboard.sync_interval_label', { seconds: targetInterval })}`);
                } else {
                    alert(`⚠️ ${tjs('dashboard.error')}: ${data.message}`);
                    location.reload();
                }
            })
            .catch(err => {
                alert(`⚠️ ${tjs('dashboard.error')}: ${err}`);
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
                        container.innerHTML = '<img src="../' + data.screenshot_url + '?t=' + Date.now() + '" alt="Screenshot" style="width: 100%; max-width: 600px; border: 2px solid #ddd; border-radius: 5px; display: block; margin: 0 auto;" onerror="this.style.display=\'none\'; this.nextElementSibling.style.display=\'block\';"><p style="display: none; text-align: center; color: #999; padding: 20px;">' + tjs('dashboard.screenshot.unavailable') + '</p>';
                    } else {
                        alert(`⚠️ ${tjs('dashboard.screenshot.unavailable')}`);
                    }
                })
                .catch(err => {
                    alert(`⚠️ ${tjs('dashboard.error')}: ${err}`);
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
                        let currentStatusFilter = 'all';

                        function filterByGroup() {
                            applyFilters();
                        }

                        function applyStatusFilter(status) {
                            if (currentStatusFilter === status) {
                                currentStatusFilter = 'all';
                            } else {
                                currentStatusFilter = status;
                            }
                            applyFilters();
                        }

                        function applyFilters() {
                            const selectedGroupId = (document.getElementById('groupFilter') || {}).value || '';
                            const rows = document.querySelectorAll('#listView tbody tr');

                            rows.forEach(row => {
                                const rowStatus = row.getAttribute('data-status');
                                const groupIds = (row.getAttribute('data-group-ids') || '').split(',').filter(id => id);

                                const statusMatch = currentStatusFilter === 'all' || rowStatus === currentStatusFilter;
                                const groupMatch = !selectedGroupId || groupIds.includes(selectedGroupId);

                                row.style.display = (statusMatch && groupMatch) ? '' : 'none';
                            });
                        }
                                    margin-bottom: 12px;
                                    padding: 8px;
                                    background: white;
                                    border-radius: 8px;
                                    font-size: 13px;
                                    color: #666;
                                    text-align: center;
                                    font-weight: 500;
                                ">${tjs('dashboard.loop.loading')}</div>

                                <div style="display: flex; justify-content: center; align-items: center; gap: 8px; flex-wrap: wrap;">
                                    <button onclick="playLoopPreview(${kioskId}, 1)" style="
                                        background: #4caf50;
                                        color: white;
                                        border: none;
                                        padding: 8px 16px;
                                        border-radius: 5px;
                                        cursor: pointer;
                                        font-size: 12px;
                                        font-weight: 600;
                                        transition: background 0.2s;
                                    " onmouseover="this.style.background='#45a049'" onmouseout="this.style.background='#4caf50'">
                                        ▶️ 1x
                                    </button>
                                    <button onclick="playLoopPreview(${kioskId}, 2)" style="
                                        background: #2196f3;
                                        color: white;
                                        border: none;
                                        padding: 8px 16px;
                                        border-radius: 5px;
                                        cursor: pointer;
                                        font-size: 12px;
                                        font-weight: 600;
                                        transition: background 0.2s;
                                    " onmouseover="this.style.background='#0b7dda'" onmouseout="this.style.background='#2196f3'">
                                        ⏩ 2x
                                    </button>
                                    <button onclick="playLoopPreview(${kioskId}, 4)" style="
                                        background: #ff9800;
                                        color: white;
                                        border: none;
                                        padding: 8px 16px;
                                        border-radius: 5px;
                                        cursor: pointer;
                                        font-size: 12px;
                                        font-weight: 600;
                                        transition: background 0.2s;
                                    " onmouseover="this.style.background='#e68900'" onmouseout="this.style.background='#ff9800'">
                                        ⏩⏩ 4x
                                    </button>
                                    <button onclick="stopLoopPreview()" style="
                                        background: #d32f2f;
                                        color: white;
                                        border: none;
                                        padding: 8px 16px;
                                        border-radius: 5px;
                                        cursor: pointer;
                                        font-size: 12px;
                                        font-weight: 600;
                                        transition: background 0.2s;
                                    " onmouseover="this.style.background='#b71c1c'" onmouseout="this.style.background='#d32f2f'">
                                        ⏹️ Stop
                                    </button>
                                </div>
                            </div>`;
                            
                            // Loop configuration list
                            html += '<div style="max-height: 400px; overflow-y: auto;">';
                            html += `<h3 style="margin-top: 0;">📋 ${tjs('dashboard.loop.module_order')}</h3>`;
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
                                        <div style="font-size: 11px; opacity: 0.9;">${tjs('dashboard.loop.seconds_short')}</div>
                                    </div>
                                </div>`;
                            });
                            html += '</div>';
                            
                            // Add total duration
                            const totalDuration = loops.reduce((sum, loop) => sum + parseInt(loop.duration_seconds), 0);
                            html += `<div style="margin-top: 20px; padding: 15px; background: #f8f9fa; border-radius: 8px; text-align: center;">
                                <strong>${tjs('dashboard.loop.total_duration')}:</strong> ${totalDuration} ${tjs('dashboard.loop.seconds')} (${Math.floor(totalDuration / 60)} ${tjs('dashboard.loop.minutes_short')} ${totalDuration % 60} ${tjs('dashboard.loop.seconds_short')})
                            </div>`;
                            html += '</div>';
                        }
                        
                        showModal(`🔄 ${tjs('dashboard.loop.modal_title')} - ${hostname}`, html);
                        
                        // Store loop data for preview
                        window.currentLoopData = loops;
                        
                        // Auto-start the preview at 2x speed after a short delay
                        setTimeout(() => {
                            if (loops.length > 0) {
                                playLoopPreview(kioskId, 2);
                            }
                        }, 500);
                    } else {
                        alert('⚠️ ' + data.message);
                    }
                })
                .catch(error => {
                    alert(`⚠️ ${tjs('dashboard.error')}: ${error}`);
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

        function getAspectRatio(resolution) {
            if (!resolution) return '16 / 9';
            const parts = resolution.toLowerCase().split('x');
            if (parts.length !== 2) return '16 / 9';
            const w = parseFloat(parts[0]);
            const h = parseFloat(parts[1]);
            if (!w || !h) return '16 / 9';
            return `${w} / ${h}`;
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
                toggleBtn.textContent = `📋 ${tjs('dashboard.toggle.list_view')}`;
                currentView = 'realtime';
                startRealtimeRefresh();
            } else {
                listView.style.display = 'block';
                realtimeView.style.display = 'none';
                toggleBtn.textContent = `📸 ${tjs('dashboard.toggle.realtime_view')}`;
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
        
        const FRESHNESS_WINDOW_MS = 3 * 60 * 1000;
        
        function parseTimestamp(ts) {
            if (!ts || ts === 'NULL') return null;
            const parsed = new Date(ts);
            if (Number.isNaN(parsed.getTime())) return null;
            return parsed;
        }
        
        function formatTimestamp(ts) {
            const parsed = parseTimestamp(ts);
            if (!parsed) return '-';
            return parsed.toLocaleString(CURRENT_LOCALE, {
                year: 'numeric',
                month: '2-digit',
                day: '2-digit',
                hour: '2-digit',
                minute: '2-digit'
            });
        }
        
        function isFreshScreenshot(ts) {
            const parsed = parseTimestamp(ts);
            if (!parsed) return false;
            return (Date.now() - parsed.getTime()) <= FRESHNESS_WINDOW_MS;
        }
        
        function renderScreenshotState(card, screenshotUrl, screenshotTimestamp) {
            const img = card.querySelector('.screenshot-img');
            const loader = card.querySelector('.screenshot-loader');
            const timestampEl = card.querySelector('.screenshot-timestamp span');
            const hasTimestamp = !!parseTimestamp(screenshotTimestamp);
            
            if (timestampEl) {
                if (hasTimestamp) {
                    timestampEl.textContent = `${tjs('dashboard.screenshot.time')}: ${formatTimestamp(screenshotTimestamp)}`;
                } else if (screenshotUrl) {
                    timestampEl.textContent = tjs('dashboard.screenshot.time_unknown');
                } else {
                    timestampEl.textContent = `${tjs('dashboard.screenshot.time')}: -`;
                }
            }
            
            const isFresh = isFreshScreenshot(screenshotTimestamp);
            const hasImage = !!screenshotUrl;
            
            if ((isFresh && hasImage) || (hasImage && !hasTimestamp)) {
                const newSrc = '../' + screenshotUrl + '?t=' + new Date().getTime();
                if (img && img.src !== newSrc) {
                    img.src = newSrc;
                }
                if (img) img.style.display = 'block';
                if (loader) loader.style.display = 'none';
            } else {
                if (img) img.style.display = 'none';
                if (loader) loader.style.display = 'flex';
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
                            const screenshotTimestamp = data.screenshot_timestamp || card.getAttribute('data-screenshot-timestamp');
                            renderScreenshotState(card, data.screenshot_url, screenshotTimestamp);
                            if (screenshotTimestamp) {
                                card.setAttribute('data-screenshot-timestamp', screenshotTimestamp);
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
                                    if (data.screen_status == 'on') statusText = `✅ ${tjs('dashboard.screen.on')}`;
                                    else if (data.screen_status == 'off') statusText = `❌ ${tjs('dashboard.screen.off')}`;
                                    else statusText = `❓ ${data.screen_status}`;
                                    statusEl.textContent = statusText;
                                }
                            }
                            
                            // Update sync timestamps
                            if (data.last_sync) {
                                const syncEl = document.getElementById(`last-sync-${kioskId}`);
                                if (syncEl) {
                                    const syncTime = new Date(data.last_sync);
                                    syncEl.textContent = syncTime.toLocaleTimeString(CURRENT_LOCALE, { hour: '2-digit', minute: '2-digit' });
                                }
                            }
                            
                            if (data.loop_last_update) {
                                const loopEl = document.getElementById(`loop-version-${kioskId}`);
                                if (loopEl) {
                                    const loopTime = new Date(data.loop_last_update);
                                    loopEl.textContent = loopTime.toLocaleTimeString(CURRENT_LOCALE, { hour: '2-digit', minute: '2-digit' });
                                }
                                const loopInfoEl = document.getElementById(`loop-info-${kioskId}`);
                                if (loopInfoEl) {
                                    loopInfoEl.setAttribute('data-loop-last-update', data.loop_last_update);
                                    updateLoopInfo();
                                }
                            }
                        }
                    })
                    .catch(err => console.error('Failed to refresh screenshot for kiosk ' + kioskId, err));
            });
        }
        
        function initListPreviews() {
            document.querySelectorAll('.preview-card').forEach(card => {
                const screenshotUrl = card.getAttribute('data-screenshot-url');
                const screenshotTimestamp = card.getAttribute('data-screenshot-timestamp');
                renderScreenshotState(card, screenshotUrl, screenshotTimestamp);
            });
        }

        function initLoopPreviews() {
            const kiosks = [<?php echo implode(',', array_column($kiosks, 'id')); ?>];
            kiosks.forEach(kioskId => {
                const target = document.getElementById('loop-preview-' + kioskId);
                if (!target) return;

                fetch(`../api/get_kiosk_loop.php?kiosk_id=${kioskId}`)
                    .then(response => response.json())
                    .then(data => {
                        if (!data.success || !Array.isArray(data.loops) || data.loops.length === 0) {
                            target.textContent = tjs('dashboard.loop.none');
                            return;
                        }

                        let index = 0;
                        const loops = data.loops;

                        const showNext = () => {
                            const loop = loops[index];
                            target.textContent = loop.module_name || tjs('dashboard.loop.module');
                            index = (index + 1) % loops.length;
                            const duration = Math.min(Math.max(parseInt(loop.duration_seconds || '3', 10), 2), 10) * 1000;
                            setTimeout(showNext, duration);
                        };

                        showNext();
                    })
                    .catch(() => {
                        target.textContent = tjs('dashboard.loop.error');
                    });
            });
        }

        // Loop preview functions
        let previewInterval = null;
        let previewTimeout = null;
        
        function playLoopPreview(kioskId, speed) {
            stopLoopPreview(); // Stop any existing preview
            
            if (!window.currentLoopData || window.currentLoopData.length === 0) {
                alert(tjs('dashboard.loop.no_data'));
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

        initListPreviews();
        initLoopPreviews();
        applyFilters();
    </script>

<?php include '../admin/footer.php'; ?>

