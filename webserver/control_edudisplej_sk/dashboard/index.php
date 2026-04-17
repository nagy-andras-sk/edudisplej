<?php
/**
 * Dashboard – Company Kiosk List
 * EduDisplej Control Panel
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';
require_once '../kiosk_status.php';
require_once '../auth_roles.php';
require_once __DIR__ . '/dashboard_helpers.php';

$current_lang = edudisplej_apply_language_preferences();

if (!isset($_SESSION['user_id'])) {
    header('Location: ../login.php');
    exit();
}

$user_id    = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;
$company_name = $_SESSION['company_name'] ?? '';
$session_role = edudisplej_get_session_role();
$can_edit_kiosk_details = true;

if ($session_role === 'easy_user') {
    header('Location: easy_user/');
    exit();
}

if (!$company_id) {
    header('Location: ../login.php');
    exit();
}

$kiosks = [];
$groups = [];
$error  = '';
$group_plans_by_id = [];

function edudisplej_append_screenshot_cache_buster($url, $timestamp = null) {
    $url = (string)($url ?? '');
    if ($url === '') {
        return '';
    }
    
    // Use timestamp if provided, otherwise use current time
    $buster = $timestamp ? strtotime($timestamp) : time();
    if ($buster === false) {
        $buster = time();
    }
    
    $separator = strpos($url, '?') === false ? '?' : '&';
    return $url . $separator . 't=' . $buster;
}

function edudisplej_parse_loop_version_timestamp($value) {
    if ($value === null || $value === '') {
        return null;
    }

    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    if (preg_match('/^\d{14}$/', $text)) {
        $dt = DateTimeImmutable::createFromFormat('YmdHis', $text);
        if ($dt instanceof DateTimeImmutable) {
            return $dt->getTimestamp();
        }
    }

    $ts = strtotime($text);
    return $ts === false ? null : $ts;
}

try {
    $conn = getDbConnection();

    // Load groups for this company
    $stmt = $conn->prepare(
        "SELECT id, name FROM kiosk_groups WHERE company_id = ? ORDER BY priority DESC, name"
    );
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        $groups[] = $row;
    }
    $stmt->close();

    // Load kiosks with group assignments
    $stmt = $conn->prepare("
          SELECT k.id, k.hostname, k.friendly_name, k.status, k.location, k.last_seen, k.last_sync,
                    k.screenshot_url, k.screenshot_timestamp, k.loop_last_update,
                    (SELECT DATE_FORMAT(MAX(COALESCE(kgm.updated_at, kgm.created_at)), '%Y%m%d%H%i%s')
                        FROM kiosk_group_assignments kga2
                        JOIN kiosk_group_modules kgm ON kgm.group_id = kga2.group_id
                      WHERE kga2.kiosk_id = k.id AND kgm.is_active = 1) AS group_server_loop_version,
                    (SELECT DATE_FORMAT(MAX(km.created_at), '%Y%m%d%H%i%s')
                        FROM kiosk_modules km
                      WHERE km.kiosk_id = k.id AND km.is_active = 1) AS kiosk_server_loop_version,
               GROUP_CONCAT(DISTINCT g.id   ORDER BY COALESCE(g.priority, 0) DESC, g.id SEPARATOR ',') AS group_ids,
               GROUP_CONCAT(DISTINCT g.name ORDER BY COALESCE(g.priority, 0) DESC, g.id SEPARATOR ', ') AS group_names
        FROM kiosks k
        LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
        LEFT JOIN kiosk_groups g ON kga.group_id = g.id
        WHERE k.company_id = ?
        GROUP BY k.id
        ORDER BY COALESCE(NULLIF(k.friendly_name, ''), k.hostname)
    ");
    $stmt->bind_param('i', $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    while ($row = $result->fetch_assoc()) {
        kiosk_apply_effective_status($row);

        $kiosk_loop_version = null;
        if (!empty($row['loop_last_update'])) {
            $ts = strtotime((string)$row['loop_last_update']);
            if ($ts !== false) {
                $kiosk_loop_version = date('YmdHis', $ts);
            }
        }
        $server_loop_version = $row['group_server_loop_version'] ?: $row['kiosk_server_loop_version'];
        $kiosk_loop_ts = edudisplej_parse_loop_version_timestamp($kiosk_loop_version);
        $server_loop_ts = edudisplej_parse_loop_version_timestamp($server_loop_version);
        $server_loop_is_newer = false;
        if ($kiosk_loop_version !== null && !empty($server_loop_version) && $kiosk_loop_version !== $server_loop_version) {
            if ($kiosk_loop_ts !== null && $server_loop_ts !== null) {
                $server_loop_is_newer = $server_loop_ts > $kiosk_loop_ts;
            } else {
                $server_loop_is_newer = strcmp((string)$server_loop_version, (string)$kiosk_loop_version) > 0;
            }
        }

        $loop_version_mismatch_raw = (
            $row['status'] !== 'offline'
            && $kiosk_loop_version !== null
            && !empty($server_loop_version)
            && $server_loop_is_newer
        );

        $loop_update_grace_active = (
            $loop_version_mismatch_raw
            && $server_loop_ts !== null
            && (time() - $server_loop_ts) <= 900
        );

        $loop_version_mismatch = $loop_version_mismatch_raw && !$loop_update_grace_active;

        if ($loop_version_mismatch) {
            $row['status'] = 'online_error';
        } elseif ($loop_update_grace_active && $row['status'] !== 'offline') {
            $row['status'] = 'online_pending';
        }

        $row['kiosk_loop_version'] = $kiosk_loop_version;
        $row['server_loop_version'] = $server_loop_version;
        $row['loop_version_mismatch'] = $loop_version_mismatch;
        $row['loop_update_grace_active'] = $loop_update_grace_active;

        $row['screenshot_url'] = !empty($row['screenshot_url'])
            ? ('../api/screenshot_file.php?kiosk_id=' . (int)$row['id'])
            : null;
        $kiosks[] = $row;
    }
    $stmt->close();

    $group_ids_for_plans = [];
    foreach ($kiosks as $kiosk_row) {
        if (empty($kiosk_row['group_ids'])) {
            continue;
        }
        foreach (explode(',', (string)$kiosk_row['group_ids']) as $gid_part) {
            $gid = (int)$gid_part;
            if ($gid > 0) {
                $group_ids_for_plans[$gid] = true;
            }
        }
    }

    if (!empty($group_ids_for_plans)) {
        $gid_list = array_keys($group_ids_for_plans);
        $placeholders = implode(',', array_fill(0, count($gid_list), '?'));
        $types = str_repeat('i', count($gid_list));
        $plan_stmt = $conn->prepare("SELECT group_id, plan_json FROM kiosk_group_loop_plans WHERE group_id IN ($placeholders)");
        if ($plan_stmt) {
            $plan_stmt->bind_param($types, ...$gid_list);
            $plan_stmt->execute();
            $plan_result = $plan_stmt->get_result();
            while ($plan_row = $plan_result->fetch_assoc()) {
                $gid = (int)$plan_row['group_id'];
                $decoded = json_decode((string)$plan_row['plan_json'], true);
                if (is_array($decoded)) {
                    $group_plans_by_id[$gid] = $decoded;
                }
            }
            $plan_stmt->close();
        }
    }

    closeDbConnection($conn);

} catch (Exception $e) {
    $error = t_def('dashboard.error.db', 'Adatbázis hiba');
    error_log($e->getMessage());
}

$total   = count($kiosks);
$online  = count(array_filter($kiosks, fn($k) => in_array($k['status'], ['online', 'online_error', 'online_pending'], true)));
$offline = $total - $online;
$dashboard_screenshot_session_start_epoch = time();

include '../admin/header.php';
?>

<style>
    .kiosk-name-offline {
        color: var(--danger) !important;
        font-weight: 700;
    }

    .status-badge.status-online_error {
        background: #fff8e1;
        color: #8a5400;
        border: 1px solid #e0b24d;
    }

    .status-badge.status-online_pending {
        background: #e8f4ff;
        color: #0a4f82;
        border: 1px solid #6fb0e0;
    }

    .kiosk-screenshot-cell .preview-card.placeholder .screenshot-loader {
        font-weight: 700;
    }

    .dashboard-refresh-indicator {
        display: none;
        margin: 8px 0 10px;
        font-size: 12px;
        color: #425466;
        font-weight: 600;
    }

    .dashboard-refresh-indicator.active {
        display: block;
    }

</style>

<?php if ($error): ?>
    <div style="color:var(--danger);padding:12px 0;"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<!-- Summary / group filter bar -->
<div class="minimal-summary">
    <span class="summary-item active" data-group-filter="all" onclick="filterByGroup('all')">
        <span class="summary-dot dot-total"></span>
        <?php echo htmlspecialchars(t('dashboard.total')); ?>: <strong><?php echo $total; ?></strong>
    </span>
    <span class="summary-item" data-group-filter="online" onclick="filterByGroup('online')">
        <span class="summary-dot dot-online"></span>
        <?php echo htmlspecialchars(t('dashboard.online')); ?>: <strong><?php echo $online; ?></strong>
    </span>
    <span class="summary-item" data-group-filter="offline" onclick="filterByGroup('offline')">
        <span class="summary-dot dot-offline"></span>
        <?php echo htmlspecialchars(t('dashboard.offline')); ?>: <strong><?php echo $offline; ?></strong>
    </span>
    <?php foreach ($groups as $g): ?>
        <span class="summary-item" data-group-filter="g<?php echo (int)$g['id']; ?>" onclick="filterByGroup('g<?php echo (int)$g['id']; ?>')">
            <span class="summary-dot dot-groups"></span>
            <?php echo htmlspecialchars($g['name']); ?>
        </span>
    <?php endforeach; ?>
</div>

<div id="active-location-filter" class="panel" style="display:none; margin-top:10px; padding:10px 14px;">
    <strong><?php echo htmlspecialchars(t_def('dashboard.filter.active', 'Aktívny filter:')); ?></strong> <?php echo htmlspecialchars(t_def('dashboard.filter.location_based', 'podľa miesta')); ?> — <span id="active-location-filter-name"></span>
    <button type="button" class="btn" style="margin-left:10px;" onclick="clearLocationFilter()"><?php echo htmlspecialchars(t_def('dashboard.filter.clear', 'Zrušiť filter')); ?></button>
</div>

<!-- Kiosk table -->
<div class="table-wrap">
    <table class="minimal-table" id="kiosk-table">
        <thead>
            <tr>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.name', 'Názov')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.status', 'Stav')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.location', 'Miesto')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.group', 'Skupina')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.current_content', 'Aktuálny obsah')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.last_sync', 'Posledná synchronizácia')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.screen', 'Obrazovka')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($kiosks)): ?>
                <tr><td colspan="7" style="text-align:center;color:#999;padding:20px;"><?php echo htmlspecialchars(t_def('dashboard.no_registered_kiosk', 'Nie je registrovaný žiadny displej.')); ?></td></tr>
            <?php else: ?>
                <?php
                $dashboard_now = new DateTimeImmutable('now');
                foreach ($kiosks as $k):
                    $gids = $k['group_ids'] ? array_map('intval', explode(',', $k['group_ids'])) : [];
                    $data_groups = implode(' ', array_map(fn($id) => 'g' . $id, $gids));
                    $location_value = trim((string)($k['location'] ?? ''));
                    $location_value_lc = strtolower($location_value);
                    $last_activity_raw = kiosk_status_reference_time($k);
                    $last_seen_str = $last_activity_raw ? date('Y-m-d H:i', strtotime($last_activity_raw)) : 'Nikdy';
                    $display_name = trim((string)($k['friendly_name'] ?? ''));
                    if ($display_name === '') {
                        $display_name = $k['hostname'] ?? 'N/A';
                    }
                    $display_name_json = json_encode($display_name, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                    if ($display_name_json === false) {
                        $display_name_json = '"N/A"';
                    }
                    $screenshot_url_json = json_encode($k['screenshot_url'] ?? '', JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                    if ($screenshot_url_json === false) {
                        $screenshot_url_json = '""';
                    }
                    $group_names_arr = [];
                    if (!empty($k['group_names'])) {
                        $group_names_arr = array_map('trim', explode(',', (string)$k['group_names']));
                    }
                    $screenshot_ts = $k['screenshot_timestamp'] ? date('Y-m-d H:i:s', strtotime($k['screenshot_timestamp'])) : null;
                    $screenshot_ts_epoch = $screenshot_ts ? strtotime($screenshot_ts) : false;
                    $has_fresh_screenshot = ($screenshot_ts_epoch !== false) && ((time() - $screenshot_ts_epoch) <= 300);
                    $screenshot_ts_json = json_encode($screenshot_ts ?? t_def('dashboard.screenshot.no_timestamp', 'Bez časovej pečiatky'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                    if ($screenshot_ts_json === false) {
                        $screenshot_ts_json = json_encode(t_def('dashboard.screenshot.no_timestamp', 'Bez časovej pečiatky'));
                    }
                    $primary_group_id = !empty($gids) ? (int)$gids[0] : 0;
                    $current_content = ['loop_name' => '—', 'schedule_text' => '—'];
                    if ($primary_group_id > 0) {
                        $current_content = edudisplej_resolve_group_current_content($group_plans_by_id[$primary_group_id] ?? null, $dashboard_now);
                    }
                ?>
                    <tr class="kiosk-row"
                        data-kiosk-id="<?php echo (int)$k['id']; ?>"
                        data-status="<?php echo htmlspecialchars($k['status']); ?>"
                        data-groups="<?php echo htmlspecialchars($data_groups); ?>"
                        data-location="<?php echo htmlspecialchars($location_value_lc); ?>">
                        <td>
                            <a href="#" class="kiosk-hostname-link kiosk-name-link<?php echo $k['status'] === 'offline' ? ' kiosk-name-offline' : ''; ?>"
                               onclick="openKioskDetail(<?php echo (int)$k['id']; ?>, <?php echo htmlspecialchars($display_name_json, ENT_QUOTES, 'UTF-8'); ?>); return false;">
                                <?php echo htmlspecialchars($display_name); ?>
                            </a>
                        </td>
                        <td>
                            <span class="status-badge kiosk-status-badge status-<?php echo htmlspecialchars($k['status']); ?>" style="cursor:pointer;" onclick="filterByStatusValue('<?php echo htmlspecialchars($k['status'], ENT_QUOTES, 'UTF-8'); ?>')" title="<?php echo htmlspecialchars(t_def('dashboard.filter.status_title', 'Filtrovať podľa tohto stavu')); ?>">
                                <?php if ($k['status'] === 'online_error'): ?>
                                    ⚠️ <?php echo htmlspecialchars(t_def('dashboard.status.online_error', 'Online-Hiba')); ?>
                                <?php elseif ($k['status'] === 'online_pending'): ?>
                                    ⏳ <?php echo htmlspecialchars(t_def('dashboard.status.online_pending', 'Frissítésre vár')); ?>
                                <?php elseif ($k['status'] === 'online'): ?>
                                    🟢 <?php echo htmlspecialchars(t('dashboard.status.online')); ?>
                                <?php else: ?>
                                    🔴 <?php echo htmlspecialchars(t('dashboard.status.offline')); ?>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($location_value !== ''): ?>
                                <a href="#" onclick="filterByLocationValue(<?php echo htmlspecialchars((json_encode($location_value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '""'), ENT_QUOTES, 'UTF-8'); ?>); return false;" title="<?php echo htmlspecialchars(t_def('dashboard.filter.location_title', 'Filtrovať podľa tohto miesta')); ?>">
                                    <?php echo htmlspecialchars($location_value); ?>
                                </a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="muted">
                            <?php if (!empty($gids) && !empty($group_names_arr)): ?>
                                <?php foreach ($gids as $idx => $gid):
                                    $gname = $group_names_arr[$idx] ?? (t_def('dashboard.group_prefix', 'Skupina #') . $gid);
                                ?>
                                    <a href="group_loop/index.php?id=<?php echo (int)$gid; ?>" title="<?php echo htmlspecialchars(t_def('dashboard.group.edit', 'Upraviť skupinu')); ?>"><?php echo htmlspecialchars($gname); ?></a><?php echo $idx < (count($gids) - 1) ? ', ' : ''; ?>
                                <?php endforeach; ?>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="muted">
                            <?php if ($primary_group_id > 0): ?>
                                <a href="group_loop/index.php?id=<?php echo (int)$primary_group_id; ?>" title="<?php echo htmlspecialchars(t_def('dashboard.loop.open_editor', 'Megnyitás a loop szerkesztőben')); ?>">
                                    <strong><?php echo htmlspecialchars((string)$current_content['loop_name']); ?></strong><br>
                                    <span style="font-size:11px;"><?php echo htmlspecialchars((string)$current_content['schedule_text']); ?></span>
                                </a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="muted nowrap kiosk-last-seen"><?php echo htmlspecialchars($last_seen_str); ?></td>
                        <td class="kiosk-screenshot-cell">
                                                        <?php if (in_array($k['status'], ['online', 'online_error', 'online_pending'], true) && $k['screenshot_url'] && $has_fresh_screenshot): ?>
                                  <div class="preview-card js-open-screenshot-viewer" style="cursor:pointer;" data-screenshot-kiosk-id="<?php echo (int)$k['id']; ?>" data-screenshot-url="<?php echo htmlspecialchars((string)($k['screenshot_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-screenshot-ts="<?php echo htmlspecialchars((string)($screenshot_ts ?? t_def('dashboard.screenshot.no_timestamp', 'Bez časovej pečiatky')), ENT_QUOTES, 'UTF-8'); ?>" title="<?php echo htmlspecialchars(t_def('dashboard.screenshot.zoom_live', 'Nagyítás és élő frissítés')); ?>">
                                    <img class="screenshot-img"
                                         src="<?php echo htmlspecialchars(edudisplej_append_screenshot_cache_buster($k['screenshot_url'], $k['screenshot_timestamp'])); ?>"
                                         alt="Screenshot"
                                         loading="lazy">
                                    <span class="screenshot-timestamp"><?php echo htmlspecialchars($screenshot_ts ?? t_def('dashboard.screenshot.no_timestamp', 'Bez časovej pečiatky')); ?></span>
                                </div>
                            <?php elseif ($k['status'] === 'offline'): ?>
                                <div class="preview-card placeholder js-open-screenshot-viewer" style="cursor:pointer;" data-screenshot-kiosk-id="<?php echo (int)$k['id']; ?>" data-screenshot-url="<?php echo htmlspecialchars((string)($k['screenshot_url'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" data-screenshot-ts="OFFLINE" title="<?php echo htmlspecialchars(t_def('dashboard.screenshot.open_history', 'Előzmények megnyitása')); ?>">
                                    <div class="screenshot-loader">OFFLINE</div>
                                    <span class="screenshot-timestamp">OFFLINE</span>
                                </div>
                            <?php else: ?>
                                <div class="preview-card placeholder">
                                    <div class="screenshot-loader">⏳ <?php echo htmlspecialchars(t('dashboard.screenshot.loading')); ?></div>
                                    <span class="screenshot-timestamp"><?php echo htmlspecialchars(t('dashboard.screenshot.none')); ?></span>
                                </div>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>
<div id="dashboard-refresh-indicator" class="dashboard-refresh-indicator">⏳ <?php echo htmlspecialchars(t_def('dashboard.screenshot.refreshing', 'Screenshot frissítés...')); ?></div>

<!-- Kiosk detail modal -->
<div id="kiosk-modal" class="kiosk-modal" onclick="handleModalBackdropClick(event)">
    <div class="kiosk-modal-box">
        <div class="kiosk-modal-header">
            <span id="kiosk-modal-title" class="kiosk-modal-title"></span>
            <button class="kiosk-modal-close" onclick="closeKioskModal()" aria-label="<?php echo htmlspecialchars(t('dashboard.modal.close')); ?>">&times;</button>
        </div>
        <div class="kiosk-modal-body" id="kiosk-modal-body">
            <p class="muted"><?php echo htmlspecialchars(t_def('common.loading', 'Betöltés…')); ?></p>
        </div>
    </div>
</div>

<!-- Screenshot viewer modal -->
<div id="screenshot-viewer-modal" class="kiosk-modal" onclick="handleScreenshotBackdropClick(event)">
    <div class="kiosk-modal-box" style="max-width: 980px;">
        <div class="kiosk-modal-header">
            <span class="kiosk-modal-title"><?php echo htmlspecialchars(t_def('dashboard.screenshot.large_view', 'Snímka obrazovky - veľké zobrazenie')); ?></span>
            <button class="kiosk-modal-close" onclick="closeScreenshotViewer()" aria-label="<?php echo htmlspecialchars(t('dashboard.modal.close')); ?>">&times;</button>
        </div>
        <div class="kiosk-modal-body" style="text-align:center;">
            <div id="screenshot-viewer-stage" style="position:relative;display:inline-block;max-width:100%;">
                <img id="screenshot-viewer-img" src="" alt="Snímka obrazovky" style="display:block;width:min(860px, calc(100vw - 80px));height:min(70vh, calc((100vw - 80px) * 0.5625));max-width:100%;object-fit:contain;border:1px solid #d0d6dc;border-radius:6px;background:#f8f9fb;">
                <div id="screenshot-viewer-placeholder" style="display:none;width:min(860px, calc(100vw - 80px));height:min(70vh, calc((100vw - 80px) * 0.5625));max-width:100%;border:1px solid #d0d6dc;border-radius:6px;background:repeating-linear-gradient(135deg, #d7dbe1 0 14px, #c8ced6 14px 28px);"></div>
                <div id="screenshot-viewer-timestamp" style="position:absolute;right:8px;bottom:8px;background:rgba(0,0,0,0.78);color:#fff;padding:4px 8px;border-radius:4px;font-size:12px;line-height:1.2;">—</div>
            </div>

            <div style="margin-top:10px;max-width:860px;margin-left:auto;margin-right:auto;text-align:left;">
                <div style="display:flex;justify-content:space-between;font-size:11px;color:#666;margin-bottom:4px;">
                    <span id="history-timeline-oldest"><?php echo htmlspecialchars(t_def('dashboard.history.oldest', 'Legrégebbi')); ?>: —</span>
                    <span id="history-timeline-latest"><?php echo htmlspecialchars(t_def('dashboard.history.latest', 'Legfrissebb')); ?>: —</span>
                </div>
                <input type="range" id="history-timeline" min="0" max="0" value="0" step="0.001" oninput="onTimelineInput(this.value)" style="width:100%;direction:rtl;">
                <div id="history-timeline-current" style="margin-top:4px;font-size:12px;color:#444;text-align:right;"><?php echo htmlspecialchars(t_def('dashboard.history.current', 'Aktuálne')); ?>: —</div>
                <div id="history-timeline-labels" style="margin-top:6px;display:flex;justify-content:space-between;align-items:flex-start;gap:2px;min-height:92px;"></div>
            </div>

            <div style="margin-top:10px;display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn" id="history-player-play" onclick="startHistoryPlayer()">▶ <?php echo htmlspecialchars(t_def('dashboard.history.play', 'Lejátszás')); ?></button>
                <button type="button" class="btn" id="history-player-stop" onclick="stopHistoryPlayer()">⏸ <?php echo htmlspecialchars(t_def('dashboard.history.pause', 'Szünet')); ?></button>
                <button type="button" class="btn" onclick="previousHistoryFrame()"><?php echo htmlspecialchars(t_def('dashboard.history.prev', '◀ Előző')); ?></button>
                <button type="button" class="btn" onclick="nextHistoryFrame()"><?php echo htmlspecialchars(t_def('dashboard.history.next', 'Következő ▶')); ?></button>
                <span id="history-player-status" class="muted"><?php echo htmlspecialchars(t('dashboard.screenshot.none')); ?></span>
            </div>

            <div style="margin-top:10px;display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn" id="screenshot-watch-toggle" onclick="toggleScreenshotWatch()">⏱ <?php echo htmlspecialchars(t_def('dashboard.toggle.realtime_view', 'Realtime zobrazenie')); ?></button>
                <span id="screenshot-watch-status" class="muted"><?php echo htmlspecialchars(t_def('dashboard.screenshot.none', 'Žiadna snímka')); ?></span>
            </div>

            <div style="margin-top:18px;text-align:left;">
                <div style="display:flex;gap:8px;align-items:end;flex-wrap:wrap;margin-bottom:8px;">
                    <div>
                        <label for="history-date-from" style="display:block;font-size:12px;color:#555;"><?php echo htmlspecialchars(t_def('dashboard.history.date_from', 'Dátumtól')); ?></label>
                        <input type="date" id="history-date-from">
                    </div>
                    <div>
                        <label for="history-date-to" style="display:block;font-size:12px;color:#555;"><?php echo htmlspecialchars(t_def('dashboard.history.date_to', 'Dátumig')); ?></label>
                        <input type="date" id="history-date-to">
                    </div>
                    <button type="button" class="btn btn-primary" onclick="applyHistoryFilter()"><?php echo htmlspecialchars(t_def('dashboard.history.filter', 'Filtrovať')); ?></button>
                    <button type="button" class="btn" onclick="clearHistoryFilter()"><?php echo htmlspecialchars(t_def('dashboard.history.filter_clear', 'Szűrő törlése')); ?></button>
                </div>

                <table class="minimal-table" id="history-table" style="width:100%;">
                    <thead>
                        <tr>
                            <th style="width:180px;"><?php echo htmlspecialchars(t_def('dashboard.history.time', 'Időpont')); ?></th>
                            <th><?php echo htmlspecialchars(t_def('dashboard.history.image', 'Kép')); ?></th>
                        </tr>
                    </thead>
                    <tbody id="history-table-body">
                        <tr><td colspan="2" class="muted" style="text-align:center;"><?php echo htmlspecialchars(t_def('common.loading', 'Betöltés…')); ?></td></tr>
                    </tbody>
                </table>

                <div id="history-gallery" style="margin-top:10px;display:grid;grid-template-columns:repeat(auto-fill,minmax(120px,1fr));gap:8px;"></div>

                <div style="margin-top:8px;display:flex;align-items:center;justify-content:space-between;">
                    <button type="button" class="btn" id="history-prev-btn" onclick="changeHistoryPage(-1)"><?php echo htmlspecialchars(t_def('dashboard.history.prev', '◀ Előző')); ?></button>
                    <span class="muted" id="history-page-info"><?php echo htmlspecialchars(t_def('dashboard.history.page', 'Oldal')); ?> 1/1</span>
                    <button type="button" class="btn" id="history-next-btn" onclick="changeHistoryPage(1)"><?php echo htmlspecialchars(t_def('dashboard.history.next', 'Következő ▶')); ?></button>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
var _screenshotKeepaliveTimer = null;
var _currentModalKioskId = null;
var _viewerKioskId = null;
var _viewerImageBase = '';
var _historyPage = 1;
var _historyTotalPages = 1;
var _historyItems = [];
var _historyTableItems = [];
var _historyCurrentIndex = 0;
var _historyPlayerTimer = null;
var _historyPlaybackElapsedMs = 0;
var _dashboardAutoRefreshTimer = null;
var _dashboardRefreshInFlight = false;
var _viewerWatchTimer = null;
var _viewerWatchActive = false;
var _autoOpenedRecentScreenshot = false;
var _summaryFilter = 'all';
var _quickFilter = { type: null, value: null, label: null };
var HISTORY_VIEWER_LIMIT = 10;
var SCREENSHOT_SESSION_START_EPOCH = <?php echo (int)$dashboard_screenshot_session_start_epoch; ?>;
var SCREENSHOT_LOADING_TEXT = <?php
    $screenshot_loading_text_json = json_encode(t('dashboard.screenshot.loading'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $screenshot_loading_text_json !== false ? $screenshot_loading_text_json : '"Loading"';
?>;
var SCREENSHOT_UNAVAILABLE_TEXT = <?php
    $screenshot_unavailable_text_json = json_encode(t('dashboard.screenshot.unavailable'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $screenshot_unavailable_text_json !== false ? $screenshot_unavailable_text_json : '"Screenshot unavailable"';
?>;
var SCREENSHOT_NONE_TEXT = <?php
    $screenshot_none_text_json = json_encode(t('dashboard.screenshot.none'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $screenshot_none_text_json !== false ? $screenshot_none_text_json : '"No image"';
?>;
var NO_TIMESTAMP_TEXT = <?php
    $no_timestamp_text_json = json_encode(t_def('dashboard.screenshot.no_timestamp', 'Bez časovej pečiatky'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $no_timestamp_text_json !== false ? $no_timestamp_text_json : '"No timestamp"';
?>;
var SCREENSHOT_WATCH_ON_TEXT = <?php
    $screenshot_watch_on_text_json = json_encode(t_def('dashboard.toggle.realtime_view', 'Realtime zobrazenie'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $screenshot_watch_on_text_json !== false ? $screenshot_watch_on_text_json : '"Realtime view"';
?>;
var SCREENSHOT_WATCH_OFF_TEXT = <?php
    $screenshot_watch_off_text_json = json_encode(t_def('dashboard.screenshot.none', 'Žiadna snímka'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $screenshot_watch_off_text_json !== false ? $screenshot_watch_off_text_json : '"No image"';
?>;
var SCREENSHOT_OFFLINE_TEXT = 'OFFLINE';
var COMMON_LOADING_TEXT = <?php
    $common_loading_text_json = json_encode(t_def('common.loading', 'Načítavam…'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_loading_text_json !== false ? $common_loading_text_json : '"Loading..."';
?>;
var COMMON_LOAD_ERROR_TEXT = <?php
    $common_load_error_text_json = json_encode(t_def('common.load_error', 'Chyba načítania.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_load_error_text_json !== false ? $common_load_error_text_json : '"Load error."';
?>;
var COMMON_ERROR_PREFIX_TEXT = <?php
    $common_error_prefix_text_json = json_encode(t_def('common.error_prefix', 'Chyba:'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_error_prefix_text_json !== false ? $common_error_prefix_text_json : '"Error:"';
?>;
var COMMON_UNKNOWN_TEXT = <?php
    $common_unknown_text_json = json_encode(t_def('common.unknown', 'neznáme'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_unknown_text_json !== false ? $common_unknown_text_json : '"unknown"';
?>;
var HISTORY_OFFLINE_SINCE_TEXT = <?php
    $history_offline_since_text_json = json_encode(t_def('dashboard.history.offline_since', 'OFFLINE SINCE:'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $history_offline_since_text_json !== false ? $history_offline_since_text_json : '"OFFLINE SINCE:"';
?>;
var HISTORY_PAGE_TEXT = <?php
    $history_page_text_json = json_encode(t_def('dashboard.history.page', 'Strana'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $history_page_text_json !== false ? $history_page_text_json : '"Page"';
?>;
var HISTORY_NO_RESULTS_TEXT = <?php
    $history_no_results_text_json = json_encode(t_def('dashboard.history.no_results', 'Žiadne výsledky.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $history_no_results_text_json !== false ? $history_no_results_text_json : '"No results."';
?>;
var HISTORY_TIME_TEXT = <?php
    $history_time_text_json = json_encode(t_def('dashboard.history.time', 'Čas'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $history_time_text_json !== false ? $history_time_text_json : '"Time"';
?>;
var HISTORY_OLDEST_TEXT = <?php
    $history_oldest_text_json = json_encode(t_def('dashboard.history.oldest', 'Najstaršie'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $history_oldest_text_json !== false ? $history_oldest_text_json : '"Oldest"';
?>;
var HISTORY_LATEST_TEXT = <?php
    $history_latest_text_json = json_encode(t_def('dashboard.history.latest', 'Najnovšie'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $history_latest_text_json !== false ? $history_latest_text_json : '"Latest"';
?>;
var HISTORY_CURRENT_TEXT = <?php
    $history_current_text_json = json_encode(t_def('dashboard.history.current', 'Aktuálne'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $history_current_text_json !== false ? $history_current_text_json : '"Current"';
?>;
var STATUS_ONLINE_TEXT = <?php
    $status_online_text_json = json_encode(t('dashboard.status.online'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $status_online_text_json !== false ? $status_online_text_json : '"Online"';
?>;
var STATUS_OFFLINE_TEXT = <?php
    $status_offline_text_json = json_encode(t('dashboard.status.offline'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $status_offline_text_json !== false ? $status_offline_text_json : '"Offline"';
?>;
var STATUS_ONLINE_ERROR_TEXT = <?php
    $status_online_error_text_json = json_encode(t_def('dashboard.status.online_error', 'Online - chyba'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $status_online_error_text_json !== false ? $status_online_error_text_json : '"Online error"';
?>;
var STATUS_ONLINE_PENDING_TEXT = <?php
    $status_online_pending_text_json = json_encode(t_def('dashboard.status.online_pending', 'Čaká na obnovenie'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $status_online_pending_text_json !== false ? $status_online_pending_text_json : '"Waiting for refresh"';
?>;
var DASHBOARD_COL_NAME_TEXT = <?php
    $dashboard_col_name_text_json = json_encode(t_def('dashboard.col.name', 'Názov'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_name_text_json !== false ? $dashboard_col_name_text_json : '"Name"';
?>;
var DASHBOARD_COL_LOCATION_TEXT = <?php
    $dashboard_col_location_text_json = json_encode(t_def('dashboard.col.location', 'Miesto'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_location_text_json !== false ? $dashboard_col_location_text_json : '"Location"';
?>;
var DASHBOARD_COL_GROUP_TEXT = <?php
    $dashboard_col_group_text_json = json_encode(t_def('dashboard.col.group', 'Skupina'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_group_text_json !== false ? $dashboard_col_group_text_json : '"Group"';
?>;
var DASHBOARD_COL_STATUS_TEXT = <?php
    $dashboard_col_status_text_json = json_encode(t_def('dashboard.col.status', 'Stav'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_status_text_json !== false ? $dashboard_col_status_text_json : '"Status"';
?>;
var DASHBOARD_COL_LAST_SYNC_TEXT = <?php
    $dashboard_col_last_sync_text_json = json_encode(t_def('dashboard.col.last_sync', 'Posledná synchronizácia'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_last_sync_text_json !== false ? $dashboard_col_last_sync_text_json : '"Last sync"';
?>;
var DASHBOARD_COL_VERSION_TEXT = <?php
    $dashboard_col_version_text_json = json_encode(t_def('dashboard.col.version', 'Verzia'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_version_text_json !== false ? $dashboard_col_version_text_json : '"Version"';
?>;
var DASHBOARD_ACTION_SAVE_TEXT = <?php
    $dashboard_action_save_text_json = json_encode(t_def('common.save', 'Uložiť'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_action_save_text_json !== false ? $dashboard_action_save_text_json : '"Save"';
?>;
var DASHBOARD_LOOP_KIOSK_VERSION_TEXT = <?php
    $dashboard_loop_kiosk_version_text_json = json_encode(t_def('dashboard.loop.kiosk_version', 'Kioskon lévő loop verzió'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_loop_kiosk_version_text_json !== false ? $dashboard_loop_kiosk_version_text_json : '"Kiosk loop version"';
?>;
var DASHBOARD_LOOP_SERVER_VERSION_TEXT = <?php
    $dashboard_loop_server_version_text_json = json_encode(t_def('dashboard.loop.server_version', 'Szerveren lévő loop verzió'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_loop_server_version_text_json !== false ? $dashboard_loop_server_version_text_json : '"Server loop version"';
?>;
var DASHBOARD_LOOP_STATUS_TEXT = <?php
    $dashboard_loop_status_text_json = json_encode(t_def('dashboard.loop.status', 'Loop állapot'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_loop_status_text_json !== false ? $dashboard_loop_status_text_json : '"Loop status"';
?>;
var DASHBOARD_LOOP_STATUS_OFFLINE_TEXT = <?php
    $dashboard_loop_status_offline_text_json = json_encode(t_def('dashboard.loop.status_offline_not_evaluated', '— Offline (nem értékelt)'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_loop_status_offline_text_json !== false ? $dashboard_loop_status_offline_text_json : '"— Offline (not evaluated)"';
?>;
var DASHBOARD_LOOP_STATUS_MISMATCH_TEXT = <?php
    $dashboard_loop_status_mismatch_text_json = json_encode(t_def('dashboard.loop.status_mismatch', '⚠️ Hiba (nem egyezik)'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_loop_status_mismatch_text_json !== false ? $dashboard_loop_status_mismatch_text_json : '"⚠️ Mismatch"';
?>;
var DASHBOARD_LOOP_STATUS_PENDING_TEXT = <?php
    $dashboard_loop_status_pending_text_json = json_encode(t_def('dashboard.loop.status_pending', '⏳ Várakozás frissítésre (max. 15 perc)'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_loop_status_pending_text_json !== false ? $dashboard_loop_status_pending_text_json : '"⏳ Waiting for refresh"';
?>;
var DASHBOARD_LOOP_STATUS_MATCH_TEXT = <?php
    $dashboard_loop_status_match_text_json = json_encode(t_def('dashboard.loop.status_match', '✓ Egyezik'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_loop_status_match_text_json !== false ? $dashboard_loop_status_match_text_json : '"✓ Match"';
?>;
var DASHBOARD_COL_MODULES_TEXT = <?php
    $dashboard_col_modules_text_json = json_encode(t_def('dashboard.col.modules', 'Modulok'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_modules_text_json !== false ? $dashboard_col_modules_text_json : '"Modules"';
?>;
var DASHBOARD_COL_SCREENSHOT_TEXT = <?php
    $dashboard_col_screenshot_text_json = json_encode(t_def('dashboard.col.screenshot', 'Screenshot'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_screenshot_text_json !== false ? $dashboard_col_screenshot_text_json : '"Screenshot"';
?>;
var COMMON_ENABLED_TEXT = <?php
    $common_enabled_text_json = json_encode(t_def('common.enabled', 'Engedélyezve'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_enabled_text_json !== false ? $common_enabled_text_json : '"Enabled"';
?>;
var COMMON_HOSTNAME_TEXT = <?php
    $common_hostname_text_json = json_encode(t_def('common.hostname', 'Hostname'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_hostname_text_json !== false ? $common_hostname_text_json : '"Hostname"';
?>;
var DASHBOARD_SAVE_ERROR_TEXT = <?php
    $dashboard_save_error_text_json = json_encode(t_def('dashboard.error.save', 'Mentési hiba'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_save_error_text_json !== false ? $dashboard_save_error_text_json : '"Save error"';
?>;
var DASHBOARD_OPEN_HISTORY_TITLE_TEXT = <?php
    $dashboard_open_history_title_text_json = json_encode(t_def('dashboard.screenshot.open_history', 'Előzmények megnyitása'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_open_history_title_text_json !== false ? $dashboard_open_history_title_text_json : '"Open history"';
?>;
var DASHBOARD_ZOOM_LIVE_TITLE_TEXT = <?php
    $dashboard_zoom_live_title_text_json = json_encode(t_def('dashboard.screenshot.zoom_live', 'Nagyítás és élő frissítés'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_zoom_live_title_text_json !== false ? $dashboard_zoom_live_title_text_json : '"Zoom and live refresh"';
?>;
var DASHBOARD_KIOSK_PREFIX_TEXT = <?php
    $dashboard_kiosk_prefix_text_json = json_encode(t_def('dashboard.kiosk.prefix', 'Kioszk #'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_kiosk_prefix_text_json !== false ? $dashboard_kiosk_prefix_text_json : '"Kiosk #"';
?>;
var COMMON_UNKNOWN_ERROR_TEXT = <?php
    $common_unknown_error_text_json = json_encode(t_def('common.unknown_error', 'Ismeretlen hiba'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_unknown_error_text_json !== false ? $common_unknown_error_text_json : '"Unknown error"';
?>;
var DASHBOARD_SCREENSHOT_REFRESHING_TEXT = <?php
    $dashboard_screenshot_refreshing_text_json = json_encode(t_def('dashboard.screenshot.refreshing', 'Screenshot frissítés...'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_screenshot_refreshing_text_json !== false ? $dashboard_screenshot_refreshing_text_json : '"Screenshot refreshing..."';
?>;
var CAN_EDIT_KIOSK_DETAILS = <?php echo $can_edit_kiosk_details ? 'true' : 'false'; ?>;
var DASHBOARD_GROUPS = <?php
    $dashboard_groups_json = json_encode(
        array_map(function ($group) {
            return [
                'id' => (int)$group['id'],
                'name' => (string)$group['name']
            ];
        }, $groups),
        JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT
    );
    echo $dashboard_groups_json !== false ? $dashboard_groups_json : '[]';
?>;

function filterByGroup(filter) {
    _summaryFilter = filter;
    _quickFilter = { type: null, value: null, label: null };
    applyCombinedFilters();
}

function updateActiveFilterIndicator() {
    var wrap = document.getElementById('active-location-filter');
    var name = document.getElementById('active-location-filter-name');
    if (!wrap || !name) {
        return;
    }

    if (_quickFilter.type === 'location' && _quickFilter.label) {
        name.textContent = _quickFilter.label;
        wrap.style.display = '';
    } else {
        name.textContent = '';
        wrap.style.display = 'none';
    }
}

function applyCombinedFilters() {
    document.querySelectorAll('.summary-item').forEach(function (el) {
        el.classList.toggle('active', el.dataset.groupFilter === _summaryFilter);
    });

    document.querySelectorAll('.kiosk-row').forEach(function (row) {
        var status = row.dataset.status;
        var groups = row.dataset.groups ? row.dataset.groups.split(' ') : [];
        var location = String(row.dataset.location || '').toLowerCase();

        var showBySummary;
        if (_summaryFilter === 'all') {
            showBySummary = true;
        } else if (_summaryFilter === 'online') {
            showBySummary = (status === 'online' || status === 'online_error' || status === 'online_pending');
        } else if (_summaryFilter === 'offline') {
            showBySummary = status === 'offline';
        } else {
            showBySummary = groups.indexOf(_summaryFilter) !== -1;
        }

        var showByQuick = true;
        if (_quickFilter.type === 'status') {
            showByQuick = status === _quickFilter.value;
        } else if (_quickFilter.type === 'location') {
            showByQuick = location === _quickFilter.value;
        }

        row.style.display = (showBySummary && showByQuick) ? '' : 'none';
    });

    updateActiveFilterIndicator();
}

function filterByStatusValue(status) {
    var normalized = String(status || '').toLowerCase();
    filterByGroup((normalized === 'online' || normalized === 'online_error' || normalized === 'online_pending') ? 'online' : 'offline');
}

function filterByLocationValue(location) {
    var label = String(location || '').trim();
    var normalized = String(location || '').trim().toLowerCase();
    if (normalized === '') {
        return;
    }
    if (_quickFilter.type === 'location' && _quickFilter.value === normalized) {
        _quickFilter = { type: null, value: null, label: null };
    } else {
        _quickFilter = { type: 'location', value: normalized, label: label };
    }
    applyCombinedFilters();
}

function clearLocationFilter() {
    _quickFilter = { type: null, value: null, label: null };
    applyCombinedFilters();
}

function toLocalDateTimeInputValue(date) {
    var d = new Date(date.getTime() - (date.getTimezoneOffset() * 60000));
    return d.toISOString().slice(0, 16);
}

function parseLocalDateTimeInput(value) {
    var text = String(value || '').trim();
    var match = text.match(/^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2})$/);
    if (!match) {
        return null;
    }
    var year = parseInt(match[1], 10);
    var month = parseInt(match[2], 10) - 1;
    var day = parseInt(match[3], 10);
    var hour = parseInt(match[4], 10);
    var minute = parseInt(match[5], 10);
    return new Date(year, month, day, hour, minute, 0, 0);
}

function toPlannerDateTimeValue(date) {
    var pad = function (n) { return String(n).padStart(2, '0'); };
    return [
        date.getFullYear(), '-', pad(date.getMonth() + 1), '-', pad(date.getDate()),
        ' ', pad(date.getHours()), ':', pad(date.getMinutes()), ':00'
    ].join('');
}

function setScreenshotViewerTimestamp(timestampText) {
    var label = document.getElementById('screenshot-viewer-timestamp');
    if (!label) {
        return;
    }
    var value = String(timestampText || '').trim();
    label.textContent = value !== '' ? value : NO_TIMESTAMP_TEXT;
}

function setScreenshotViewerMedia(imageSrc, forceOfflinePlaceholder) {
    var img = document.getElementById('screenshot-viewer-img');
    var placeholder = document.getElementById('screenshot-viewer-placeholder');
    if (!img || !placeholder) {
        return;
    }

    var url = String(imageSrc || '');
    var showImage = !forceOfflinePlaceholder && url !== '';

    if (showImage) {
        img.src = appendCacheBuster(url);
        img.style.display = 'block';
        placeholder.style.display = 'none';
    } else {
        img.src = '';
        img.style.display = 'none';
        placeholder.style.display = 'block';
    }
}

function openScreenshotViewer(kioskId, imageSrc, initialTimestamp) {
    _viewerKioskId = parseInt(kioskId, 10);
    _viewerImageBase = String(imageSrc || '');
    _historyPage = 1;
    _historyItems = [];
    _historyTableItems = [];
    _historyCurrentIndex = 0;
    stopHistoryPlayer();

    var modal = document.getElementById('screenshot-viewer-modal');
    var fromInput = document.getElementById('history-date-from');
    var toInput = document.getElementById('history-date-to');
    var isOfflineView = String(initialTimestamp || '').toUpperCase() === SCREENSHOT_OFFLINE_TEXT;

    var today = new Date();
    var threeDaysAgo = new Date();
    threeDaysAgo.setDate(today.getDate() - 3);

    function fmtDate(dateObj) {
        var y = dateObj.getFullYear();
        var m = String(dateObj.getMonth() + 1).padStart(2, '0');
        var d = String(dateObj.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    }

    if (fromInput && !fromInput.value) {
        fromInput.value = fmtDate(threeDaysAgo);
    }
    if (toInput && !toInput.value) {
        toInput.value = fmtDate(today);
    }

    setScreenshotViewerMedia(_viewerImageBase, isOfflineView);
    setScreenshotViewerTimestamp(initialTimestamp || NO_TIMESTAMP_TEXT);
    modal.style.display = 'flex';

    setScreenshotWatchActive(false, null);

    loadScreenshotHistory(1);
}

function loadScreenshotHistory(page) {
    if (!_viewerKioskId) {
        return;
    }

    var fromInput = document.getElementById('history-date-from');
    var toInput = document.getElementById('history-date-to');
    var dateFrom = fromInput ? fromInput.value : '';
    var dateTo = toInput ? toInput.value : '';
    var tbody = document.getElementById('history-table-body');

    _historyPage = Math.max(1, parseInt(page, 10) || 1);
    if (tbody) {
        tbody.innerHTML = '<tr><td colspan="2" class="muted" style="text-align:center;">' + escapeHtml(COMMON_LOADING_TEXT) + '</td></tr>';
    }

    var url = '../api/screenshot_history.php?kiosk_id=' + encodeURIComponent(_viewerKioskId)
        + '&page=' + encodeURIComponent(_historyPage)
        + '&per_page=50';
    if (dateFrom) {
        url += '&date_from=' + encodeURIComponent(dateFrom);
    }
    if (dateTo) {
        url += '&date_to=' + encodeURIComponent(dateTo);
    }

    fetch(url)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data.success) {
                if (tbody) {
                    tbody.innerHTML = '<tr><td colspan="2" class="muted" style="text-align:center;">' + escapeHtml(COMMON_ERROR_PREFIX_TEXT) + ' ' + escapeHtml(data.message || COMMON_LOAD_ERROR_TEXT) + '</td></tr>';
                }
                return;
            }

            var items = Array.isArray(data.items) ? data.items : [];
            var pagination = data.pagination || {};
            _historyTotalPages = Math.max(1, parseInt(pagination.total_pages, 10) || 1);
            _historyPage = Math.max(1, parseInt(pagination.page, 10) || 1);

            _historyTableItems = items;
            _historyItems = items.slice(0, HISTORY_VIEWER_LIMIT);
            _historyCurrentIndex = 0;

            renderScreenshotHistoryRows(_historyTableItems);
            renderScreenshotGallery(_historyItems);
            updateHistoryPager();

            if (_historyItems.length > 0) {
                showHistoryItemByIndex(0);
                startHistoryPlayer();
            } else {
                updateHistoryPlayerStatus();
                updateHistoryTimeline();
                stopHistoryPlayer();
            }
        })
        .catch(function () {
            if (tbody) {
                tbody.innerHTML = '<tr><td colspan="2" class="muted" style="text-align:center;">' + escapeHtml(COMMON_LOAD_ERROR_TEXT) + '</td></tr>';
            }
        });
}


    function updateScreenshotWatchUi(active) {
        var button = document.getElementById('screenshot-watch-toggle');
        var status = document.getElementById('screenshot-watch-status');

        if (button) {
            button.classList.toggle('btn-primary', !!active);
            button.textContent = active
                ? '⏱ Realtime zobrazenie: ZAPNUTÉ'
                : '⏱ Realtime zobrazenie: VYPNUTÉ';
        }

        if (status) {
            status.textContent = active ? '15 s / 5 min' : SCREENSHOT_WATCH_OFF_TEXT;
        }
    }

    function refreshScreenshotViewerLiveImage() {
        var img = document.getElementById('screenshot-viewer-img');
        if (!img) {
            return;
        }

        var base = img.getAttribute('data-base-src') || _viewerImageBase || '';
        if (base === '') {
            return;
        }

        img.setAttribute('data-base-src', base);
        img.src = appendCacheBuster(base);
    }

    function stopScreenshotWatchTimer() {
        if (_viewerWatchTimer) {
            clearInterval(_viewerWatchTimer);
            _viewerWatchTimer = null;
        }
    }

    function startScreenshotWatchTimer() {
        stopScreenshotWatchTimer();
        if (!_viewerKioskId) {
            return;
        }

        _viewerWatchTimer = setInterval(function () {
            if (!_viewerWatchActive || !_viewerKioskId) {
                return;
            }
            requestScreenshotTTL(_viewerKioskId, 300).then(function (data) {
                if (data && data.success === false) {
                    _viewerWatchActive = false;
                    updateScreenshotWatchUi(false);
                    stopScreenshotWatchTimer();
                    if (data.message) {
                        alert('⚠️ ' + data.message);
                    }
                    return;
                }
                refreshScreenshotViewerLiveImage();
            });
        }, 15000);
    }

    function setScreenshotWatchActive(active, kioskId) {
        _viewerWatchActive = !!active;
        updateScreenshotWatchUi(_viewerWatchActive);

        if (_viewerWatchActive) {
            if (kioskId) {
                requestScreenshotTTL(kioskId, 300).then(function (data) {
                    if (data && data.success === false) {
                        _viewerWatchActive = false;
                        updateScreenshotWatchUi(false);
                        stopScreenshotWatchTimer();
                        if (data.message) {
                            alert('⚠️ ' + data.message);
                        }
                        return;
                    }
                    refreshScreenshotViewerLiveImage();
                    startScreenshotWatchTimer();
                });
                return;
            }
            refreshScreenshotViewerLiveImage();
            startScreenshotWatchTimer();
            return;
        }

        stopScreenshotWatchTimer();
        if (kioskId) {
            stopScreenshotTTL(kioskId);
        }
    }

    function toggleScreenshotWatch() {
        if (!_viewerKioskId) {
            return;
        }

        setScreenshotWatchActive(!_viewerWatchActive, _viewerKioskId);
    }
function renderScreenshotHistoryRows(items) {
    var tbody = document.getElementById('history-table-body');
    if (!tbody) {
        return;
    }

    if (!items.length) {
        tbody.innerHTML = '<tr><td colspan="2" class="muted" style="text-align:center;">' + escapeHtml(HISTORY_NO_RESULTS_TEXT) + '</td></tr>';
        return;
    }

    var html = '';
    items.forEach(function (item, tableIndex) {
        var timestamp = item.timestamp || '—';
        var screenshotUrl = item.screenshot_url || '';
        var isOfflineMarker = !!item.is_offline_marker;
        var thumbHtml = '';
        if (isOfflineMarker) {
            thumbHtml = '<div class="history-offline-marker" data-table-index="' + tableIndex + '" style="display:inline-block;padding:10px 12px;border:1px solid #e5b4b4;border-radius:4px;background:#fdecec;color:#9f1d1d;font-weight:700;cursor:pointer;">'
                + escapeHtml(item.label || (HISTORY_OFFLINE_SINCE_TEXT + ' ' + (item.offline_since || COMMON_UNKNOWN_TEXT)))
                + '</div>';
        } else {
            thumbHtml = screenshotUrl
                ? '<img src="' + escapeHtml(appendCacheBuster(screenshotUrl)) + '" data-table-index="' + tableIndex + '" class="history-thumb-img" alt="Screenshot" style="width:120px;height:68px;object-fit:cover;border:1px solid #d0d6dc;border-radius:4px;cursor:pointer;">'
                : '<span class="muted">' + escapeHtml(SCREENSHOT_NONE_TEXT) + '</span>';
        }

        html += '<tr>'
            + '<td class="nowrap">' + escapeHtml(timestamp) + '</td>'
            + '<td>' + thumbHtml + '</td>'
            + '</tr>';
    });

    tbody.innerHTML = html;

    tbody.querySelectorAll('.history-thumb-img').forEach(function (imgEl) {
        imgEl.addEventListener('click', function () {
            var idx = parseInt(imgEl.getAttribute('data-table-index'), 10);
            if (isNaN(idx)) {
                return;
            }
            stopHistoryPlayer();
            showHistoryTableItemByIndex(idx);
        });
    });

    tbody.querySelectorAll('.history-offline-marker').forEach(function (markerEl) {
        markerEl.addEventListener('click', function () {
            var idx = parseInt(markerEl.getAttribute('data-table-index'), 10);
            if (isNaN(idx)) {
                return;
            }
            stopHistoryPlayer();
            showHistoryTableItemByIndex(idx);
        });
    });
}

function showHistoryTableItemByIndex(index) {
    if (index < 0 || index >= _historyTableItems.length) {
        return;
    }

    var selected = _historyTableItems[index] || null;
    if (!selected) {
        return;
    }

    var focusIndex = _historyItems.indexOf(selected);
    if (focusIndex >= 0) {
        showHistoryItemByIndex(focusIndex);
        return;
    }

    var url = String(selected.screenshot_url || '');
    _viewerImageBase = url;
    setScreenshotViewerMedia(_viewerImageBase, !!selected.is_offline_marker);

    var currentTs = selected.timestamp ? String(selected.timestamp) : NO_TIMESTAMP_TEXT;
    if (selected.is_offline_marker) {
        currentTs = selected.label || (HISTORY_OFFLINE_SINCE_TEXT + ' ' + (selected.offline_since || COMMON_UNKNOWN_TEXT));
    }
    setScreenshotViewerTimestamp(currentTs);

    var status = document.getElementById('history-player-status');
    if (status) {
        status.textContent = (index + 1) + ' / ' + _historyTableItems.length + ' • ' + currentTs;
    }
}

function renderScreenshotGallery(items) {
    var gallery = document.getElementById('history-gallery');
    if (!gallery) {
        return;
    }

    if (!items.length) {
        gallery.innerHTML = '';
        return;
    }

    var html = '';
    items.forEach(function (item, index) {
        var isOfflineMarker = !!item.is_offline_marker;
        var timestamp = item.timestamp || '—';
        if (isOfflineMarker) {
            html += '<button type="button" data-index="' + index + '" class="history-gallery-offline" style="border:1px solid #e5b4b4;border-radius:6px;background:#fdecec;color:#9f1d1d;padding:8px;cursor:pointer;text-align:left;">'
                + '<div style="font-size:11px;font-weight:700;">' + escapeHtml(HISTORY_OFFLINE_SINCE_TEXT) + '</div>'
                + '<div style="font-size:11px;">' + escapeHtml(item.offline_since || COMMON_UNKNOWN_TEXT) + '</div>'
                + '</button>';
            return;
        }

        var screenshotUrl = String(item.screenshot_url || '');
        html += '<button type="button" data-index="' + index + '" class="history-gallery-item" style="border:1px solid #d0d6dc;border-radius:6px;background:#fff;padding:4px;cursor:pointer;text-align:left;">'
            + '<img src="' + escapeHtml(appendCacheBuster(screenshotUrl)) + '" alt="Screenshot" style="display:block;width:100%;height:70px;object-fit:cover;border-radius:4px;">'
            + '<div style="margin-top:4px;font-size:11px;color:#555;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;">' + escapeHtml(timestamp) + '</div>'
            + '</button>';
    });

    gallery.innerHTML = html;
    gallery.querySelectorAll('[data-index]').forEach(function (buttonEl) {
        buttonEl.addEventListener('click', function () {
            var idx = parseInt(buttonEl.getAttribute('data-index'), 10);
            if (isNaN(idx)) {
                return;
            }
            stopHistoryPlayer();
            showHistoryItemByIndex(idx);
        });
    });
}

function showHistoryItemByIndex(index) {
    if (index < 0 || index >= _historyItems.length) {
        return;
    }

    _historyCurrentIndex = index;
    var currentItem = _historyItems[_historyCurrentIndex] || null;
    var url = currentItem ? String(currentItem.screenshot_url || '') : '';
    _viewerImageBase = url;

    setScreenshotViewerMedia(_viewerImageBase, !!(currentItem && currentItem.is_offline_marker));

    var currentTs = currentItem && currentItem.timestamp ? String(currentItem.timestamp) : NO_TIMESTAMP_TEXT;
    if (currentItem && currentItem.is_offline_marker) {
        currentTs = currentItem.label || (HISTORY_OFFLINE_SINCE_TEXT + ' ' + (currentItem.offline_since || COMMON_UNKNOWN_TEXT));
    }
    setScreenshotViewerTimestamp(currentTs);

    updateHistoryPlayerStatus();
    updateHistoryTimeline();
}

function updateHistoryTimeline() {
    var timeline = document.getElementById('history-timeline');
    var oldestLabel = document.getElementById('history-timeline-oldest');
    var latestLabel = document.getElementById('history-timeline-latest');
    var currentLabel = document.getElementById('history-timeline-current');
    var labelsWrap = document.getElementById('history-timeline-labels');

    if (!timeline || !oldestLabel || !latestLabel || !currentLabel || !labelsWrap) {
        return;
    }

    if (_historyItems.length === 0) {
        timeline.min = '0';
        timeline.max = '0';
        timeline.value = '0';
        oldestLabel.textContent = HISTORY_OLDEST_TEXT + ': —';
        latestLabel.textContent = HISTORY_LATEST_TEXT + ': —';
        currentLabel.textContent = HISTORY_CURRENT_TEXT + ': —';
        labelsWrap.innerHTML = '';
        return;
    }

    timeline.min = '0';
    timeline.max = String(_historyItems.length - 1);
    timeline.value = String(_historyCurrentIndex);

    var oldestItem = _historyItems[_historyItems.length - 1] || null;
    var latestItem = _historyItems[0] || null;
    var currentItem = _historyItems[_historyCurrentIndex] || null;

    oldestLabel.textContent = HISTORY_OLDEST_TEXT + ': ' + (oldestItem && oldestItem.timestamp ? oldestItem.timestamp : '—');
    latestLabel.textContent = HISTORY_LATEST_TEXT + ': ' + (latestItem && latestItem.timestamp ? latestItem.timestamp : '—');
    currentLabel.textContent = HISTORY_CURRENT_TEXT + ': ' + (currentItem && currentItem.timestamp ? currentItem.timestamp : '—');

    var labelsHtml = '';
    _historyItems.forEach(function (item) {
        var ts = item && item.timestamp ? String(item.timestamp) : '—';
        labelsHtml += '<div style="flex:1;min-width:0;text-align:center;">'
            + '<span style="display:inline-block;writing-mode:vertical-rl;text-orientation:mixed;font-size:10px;color:#666;line-height:1;max-height:90px;overflow:hidden;">'
            + escapeHtml(ts)
            + '</span></div>';
    });
    labelsWrap.innerHTML = labelsHtml;
}

function onTimelineInput(value) {
    if (_historyItems.length === 0) {
        return;
    }
    stopHistoryPlayer();
    var idx = parseInt(value, 10);
    if (isNaN(idx)) {
        return;
    }
    idx = Math.max(0, Math.min(_historyItems.length - 1, idx));
    showHistoryItemByIndex(idx);
}

function updateHistoryPlayerStatus() {
    var status = document.getElementById('history-player-status');
    if (!status) {
        return;
    }
    if (_historyItems.length === 0) {
        status.textContent = SCREENSHOT_NONE_TEXT;
        return;
    }
    var currentItem = _historyItems[_historyCurrentIndex] || null;
    var ts = currentItem && currentItem.timestamp ? String(currentItem.timestamp) : (COMMON_UNKNOWN_TEXT + ' ' + HISTORY_TIME_TEXT.toLowerCase());
    if (currentItem && currentItem.is_offline_marker) {
        ts = currentItem.label || (HISTORY_OFFLINE_SINCE_TEXT + ' ' + (currentItem.offline_since || COMMON_UNKNOWN_TEXT));
    }
    status.textContent = (_historyCurrentIndex + 1) + ' / ' + _historyItems.length + ' • ' + ts + ' • 3 mp';
}

function startHistoryPlayer() {
    if (_historyItems.length <= 1) {
        updateHistoryPlayerStatus();
        return;
    }
    stopHistoryPlayer();
    _historyPlaybackElapsedMs = 0;
    _historyPlayerTimer = setInterval(function () {
        _historyPlaybackElapsedMs += 100;

        if (_historyPlaybackElapsedMs < 3000) {
            return;
        }

        _historyPlaybackElapsedMs = 0;
        if (_historyCurrentIndex >= _historyItems.length - 1) {
            stopHistoryPlayer();
            updateHistoryTimeline();
            return;
        }
        _historyCurrentIndex += 1;
        showHistoryItemByIndex(_historyCurrentIndex);
    }, 100);
    updateHistoryPlayerStatus();
    updateHistoryTimeline();
}

function stopHistoryPlayer() {
    if (_historyPlayerTimer) {
        clearInterval(_historyPlayerTimer);
        _historyPlayerTimer = null;
    }
    _historyPlaybackElapsedMs = 0;
}

function nextHistoryFrame() {
    if (_historyItems.length === 0) {
        return;
    }
    stopHistoryPlayer();
    _historyCurrentIndex = Math.min(_historyItems.length - 1, _historyCurrentIndex + 1);
    showHistoryItemByIndex(_historyCurrentIndex);
}

function previousHistoryFrame() {
    if (_historyItems.length === 0) {
        return;
    }
    stopHistoryPlayer();
    _historyCurrentIndex = Math.max(0, _historyCurrentIndex - 1);
    showHistoryItemByIndex(_historyCurrentIndex);
}

function appendCacheBuster(url) {
    var base = String(url || '');
    if (base === '') {
        return '';
    }
    return base + (base.indexOf('?') === -1 ? '?' : '&') + 't=' + Date.now();
}

function updateHistoryPager() {
    var info = document.getElementById('history-page-info');
    var prev = document.getElementById('history-prev-btn');
    var next = document.getElementById('history-next-btn');

    if (info) {
        info.textContent = HISTORY_PAGE_TEXT + ' ' + _historyPage + '/' + _historyTotalPages;
    }
    if (prev) {
        prev.disabled = _historyPage <= 1;
    }
    if (next) {
        next.disabled = _historyPage >= _historyTotalPages;
    }
}

function changeHistoryPage(delta) {
    var nextPage = _historyPage + parseInt(delta, 10);
    if (nextPage < 1 || nextPage > _historyTotalPages) {
        return;
    }
    loadScreenshotHistory(nextPage);
}

function applyHistoryFilter() {
    loadScreenshotHistory(1);
}

function clearHistoryFilter() {
    var fromInput = document.getElementById('history-date-from');
    var toInput = document.getElementById('history-date-to');
    var today = new Date();
    var threeDaysAgo = new Date();
    threeDaysAgo.setDate(today.getDate() - 3);
    var fmt = function (dateObj) {
        var y = dateObj.getFullYear();
        var m = String(dateObj.getMonth() + 1).padStart(2, '0');
        var d = String(dateObj.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    };
    if (fromInput) {
        fromInput.value = fmt(threeDaysAgo);
    }
    if (toInput) {
        toInput.value = fmt(today);
    }
    loadScreenshotHistory(1);
}

function closeScreenshotViewer() {
    var modal = document.getElementById('screenshot-viewer-modal');
    modal.style.display = 'none';
    stopHistoryPlayer();
    stopScreenshotWatchTimer();
    if (_viewerKioskId) {
        stopScreenshotTTL(_viewerKioskId);
    }
    _viewerKioskId = null;
    _viewerImageBase = '';
    _viewerWatchActive = false;
    _historyPage = 1;
    _historyTotalPages = 1;
    _historyItems = [];
    _historyTableItems = [];
    _historyCurrentIndex = 0;
    updateScreenshotWatchUi(false);
    setScreenshotViewerMedia('', false);
    setScreenshotViewerTimestamp('—');
    updateHistoryTimeline();
}

function handleScreenshotBackdropClick(event) {
    if (event.target === document.getElementById('screenshot-viewer-modal')) {
        closeScreenshotViewer();
    }
}

function handleScreenshotCardClick(event) {
    var target = event.target;
    while (target && target !== document.body) {
        if (target.classList && target.classList.contains('js-open-screenshot-viewer')) {
            var kioskId = parseInt(target.getAttribute('data-screenshot-kiosk-id') || '0', 10);
            var imageSrc = target.getAttribute('data-screenshot-url') || '';
            var timestamp = target.getAttribute('data-screenshot-ts') || '';
            if (kioskId > 0) {
                openScreenshotViewer(kioskId, imageSrc, timestamp);
            }
            return;
        }
        target = target.parentNode;
    }
}

function requestScreenshotTTL(kioskId, ttlSeconds) {
    return fetch('../api/screenshot_request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ kiosk_id: kioskId, ttl_seconds: ttlSeconds || 300 })
    })
    .then(function (response) { return response.json(); })
    .catch(function () {});
}

function stopScreenshotTTL(kioskId) {
    if (_screenshotKeepaliveTimer) {
        clearInterval(_screenshotKeepaliveTimer);
        _screenshotKeepaliveTimer = null;
    }
    fetch('../api/screenshot_request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ kiosk_id: kioskId, action: 'stop' })
    }).catch(function () {});
}

function closeKioskModal() {
    var modal = document.getElementById('kiosk-modal');
    modal.style.display = 'none';
    if (_currentModalKioskId) {
        stopScreenshotTTL(_currentModalKioskId);
    }
    _currentModalKioskId = null;
    document.getElementById('kiosk-modal-body').innerHTML = '<p class="muted">' + escapeHtml(COMMON_LOADING_TEXT) + '</p>';
}

function handleModalBackdropClick(event) {
    if (event.target === document.getElementById('kiosk-modal')) {
        closeKioskModal();
    }
}

function escapeHtml(str) {
    return String(str)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;');
}

function buildKioskModalHTML(data) {
    var screenshotSrc = data.screenshot_url || '';
    var screenshotTs  = data.screenshot_timestamp || '';
    var screenshotBlock = screenshotSrc
        ? '<div class="preview-card kiosk-modal-screenshot">'
            + '<img class="screenshot-img" id="modal-screenshot-img" src="' + escapeHtml(screenshotSrc) + '" alt="Screenshot">'
            + '<span class="screenshot-timestamp">' + escapeHtml(screenshotTs) + '</span>'
            + '</div>'
        : '<div class="preview-card kiosk-modal-screenshot placeholder">'
            + '<div class="screenshot-loader">⏳ ' + escapeHtml(SCREENSHOT_LOADING_TEXT) + '</div>'
            + '<span class="screenshot-timestamp">' + escapeHtml(SCREENSHOT_UNAVAILABLE_TEXT) + '</span>'
            + '</div>';
    var modulesText = (data.modules && data.modules.length) ? data.modules.join(', ') : '—';
    var selectedGroupId = '';
    if (data.group_ids) {
        var firstGroup = String(data.group_ids).split(',')[0];
        selectedGroupId = String(parseInt(firstGroup, 10) || '');
    }
    var groupOptions = '<option value="">—</option>';
    DASHBOARD_GROUPS.forEach(function (group) {
        var selected = String(group.id) === selectedGroupId ? ' selected' : '';
        groupOptions += '<option value="' + escapeHtml(group.id) + '"' + selected + '>' + escapeHtml(group.name) + '</option>';
    });

    var editableSection = '';
    if (CAN_EDIT_KIOSK_DETAILS) {
        editableSection = '<table class="minimal-table"><tbody>'
            + '<tr><th>' + escapeHtml(DASHBOARD_COL_NAME_TEXT) + '</th><td><input type="text" id="kiosk-edit-friendly-name" value="' + escapeHtml(data.friendly_name || data.hostname || '') + '" style="width:100%;"></td></tr>'
            + '<tr><th>' + escapeHtml(DASHBOARD_COL_LOCATION_TEXT) + '</th><td><input type="text" id="kiosk-edit-location" value="' + escapeHtml(data.location || '') + '" style="width:100%;"></td></tr>'
            + '<tr><th>' + escapeHtml(DASHBOARD_COL_GROUP_TEXT) + '</th><td><select id="kiosk-edit-group" style="width:100%;">' + groupOptions + '</select></td></tr>'
            + '<tr><th>' + escapeHtml(DASHBOARD_COL_SCREENSHOT_TEXT) + '</th><td><label><input type="checkbox" id="kiosk-edit-screenshot-enabled" ' + (data.screenshot_enabled ? 'checked' : '') + '> ' + escapeHtml(COMMON_ENABLED_TEXT) + '</label></td></tr>'
            + '<tr><td colspan="2" style="text-align:right;"><button class="btn btn-primary" onclick="saveKioskDetails(' + escapeHtml(data.id || 0) + ')">' + escapeHtml(DASHBOARD_ACTION_SAVE_TEXT) + '</button></td></tr>'
            + '</tbody></table>';
    } else {
        editableSection = '<table class="minimal-table"><tbody>'
            + '<tr><th>' + escapeHtml(DASHBOARD_COL_NAME_TEXT) + '</th><td>' + escapeHtml(data.friendly_name || data.hostname || '') + '</td></tr>'
            + '<tr><th>' + escapeHtml(DASHBOARD_COL_LOCATION_TEXT) + '</th><td>' + escapeHtml(data.location || '—') + '</td></tr>'
            + '<tr><th>' + escapeHtml(DASHBOARD_COL_GROUP_TEXT) + '</th><td>' + escapeHtml(data.group_names || '—') + '</td></tr>'
            + '</tbody></table>';
    }

    return '<div class="kiosk-detail-grid">'
        + '<div class="kiosk-detail-info">'
        + editableSection
        + '<div style="height:12px;"></div>'
        + '<table class="minimal-table"><tbody>'
        + '<tr><th>ID</th><td>' + escapeHtml(data.id || '—') + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_COL_STATUS_TEXT) + '</th><td>'
        + (data.status === 'online_error'
            ? ('⚠️ ' + escapeHtml(STATUS_ONLINE_ERROR_TEXT))
            : (data.status === 'online_pending'
                ? ('⏳ ' + escapeHtml(STATUS_ONLINE_PENDING_TEXT))
                : (data.status === 'online'
                    ? ('🟢 ' + escapeHtml(STATUS_ONLINE_TEXT))
                    : ('🔴 ' + escapeHtml(STATUS_OFFLINE_TEXT))
                )
            )
        )
        + '</td></tr>'
        + '<tr><th>' + escapeHtml(COMMON_HOSTNAME_TEXT) + '</th><td class="mono">' + escapeHtml(data.hostname || '—') + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_COL_GROUP_TEXT) + '</th><td>' + escapeHtml(data.group_names || '—') + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_COL_LAST_SYNC_TEXT) + '</th><td>' + escapeHtml(data.last_sync || data.last_seen || '—')
        + (data.next_sync_eta ? ('<div class="muted" style="font-size:11px;">' + escapeHtml(data.next_sync_eta) + '</div>') : '')
        + '</td></tr>'
        + '<tr><th>MAC</th><td class="mono">' + escapeHtml(data.mac || '—') + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_COL_VERSION_TEXT) + '</th><td>' + escapeHtml(data.version || COMMON_UNKNOWN_TEXT) + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_LOOP_KIOSK_VERSION_TEXT) + '</th><td class="mono">' + escapeHtml(data.kiosk_loop_version || 'n/a') + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_LOOP_SERVER_VERSION_TEXT) + '</th><td class="mono">' + escapeHtml(data.server_loop_version || 'n/a') + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_LOOP_STATUS_TEXT) + '</th><td>'
        + (data.status === 'offline'
            ? escapeHtml(DASHBOARD_LOOP_STATUS_OFFLINE_TEXT)
            : ((data.loop_update_grace_active === true || data.status === 'online_pending')
                ? escapeHtml(DASHBOARD_LOOP_STATUS_PENDING_TEXT)
                : (data.loop_version_mismatch
                    ? escapeHtml(DASHBOARD_LOOP_STATUS_MISMATCH_TEXT)
                    : escapeHtml(DASHBOARD_LOOP_STATUS_MATCH_TEXT)
                )
            )
        )
        + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_COL_MODULES_TEXT) + '</th><td>' + escapeHtml(modulesText) + '</td></tr>'
        + '</tbody></table>'
        + '</div>'
        + '<div class="kiosk-detail-screenshot">' + screenshotBlock + '</div>'
        + '</div>';
}

function saveKioskDetails(kioskId) {
    var friendlyName = document.getElementById('kiosk-edit-friendly-name');
    var location = document.getElementById('kiosk-edit-location');
    var group = document.getElementById('kiosk-edit-group');
    var screenshotEnabled = document.getElementById('kiosk-edit-screenshot-enabled');
    if (!friendlyName || !location || !group || !screenshotEnabled) {
        return;
    }

    fetch('../api/kiosk_details.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: parseInt(kioskId, 10),
            friendly_name: friendlyName.value,
            location: location.value,
            group_id: group.value ? parseInt(group.value, 10) : null,
            screenshot_enabled: screenshotEnabled.checked ? 1 : 0
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data.success) {
            alert('⚠️ ' + (data.message || DASHBOARD_SAVE_ERROR_TEXT));
            return;
        }
        window.location.reload();
    })
    .catch(function () {
        alert('⚠️ ' + DASHBOARD_SAVE_ERROR_TEXT + '.');
    });
}

function renderKioskScreenshotCell(kiosk) {
    var status = String(kiosk.status || '').toLowerCase();
    var kioskId = parseInt(kiosk.id, 10) || 0;
    var screenshotUrl = String(kiosk.screenshot_url || '');
    if (status === 'offline') {
        return '<div class="preview-card placeholder js-open-screenshot-viewer" style="cursor:pointer;" data-screenshot-kiosk-id="' + escapeHtml(kioskId) + '" data-screenshot-url="' + escapeHtml(screenshotUrl) + '" data-screenshot-ts="OFFLINE" title="' + escapeHtml(DASHBOARD_OPEN_HISTORY_TITLE_TEXT) + '">'
            + '<div class="screenshot-loader">' + escapeHtml(SCREENSHOT_OFFLINE_TEXT) + '</div>'
            + '<span class="screenshot-timestamp">' + escapeHtml(SCREENSHOT_OFFLINE_TEXT) + '</span>'
            + '</div>';
    }

    if (kiosk.screenshot_url && hasFreshScreenshotSinceSessionStart(kiosk.screenshot_timestamp)) {
        var screenshotTs = String(kiosk.screenshot_timestamp || NO_TIMESTAMP_TEXT);
        return '<div class="preview-card js-open-screenshot-viewer" style="cursor:pointer;" data-screenshot-kiosk-id="' + escapeHtml(kioskId) + '" data-screenshot-url="' + escapeHtml(screenshotUrl) + '" data-screenshot-ts="' + escapeHtml(screenshotTs) + '" title="' + escapeHtml(DASHBOARD_ZOOM_LIVE_TITLE_TEXT) + '">'
            + '<img class="screenshot-img" src="' + escapeHtml(appendCacheBuster(screenshotUrl)) + '" alt="Screenshot" loading="lazy">'
            + '<span class="screenshot-timestamp">' + escapeHtml(screenshotTs) + '</span>'
            + '</div>';
    }

    return '<div class="preview-card placeholder">'
        + '<div class="screenshot-loader">⏳ ' + escapeHtml(SCREENSHOT_LOADING_TEXT) + '</div>'
        + '<span class="screenshot-timestamp">' + escapeHtml(SCREENSHOT_NONE_TEXT) + '</span>'
        + '</div>';
}

function parseDashboardTimestampToEpoch(value) {
    if (!value) {
        return null;
    }

    var text = String(value).trim();
    if (!text) {
        return null;
    }

    var normalized = text.replace('T', ' ');
    var match = normalized.match(/^(\d{4})-(\d{2})-(\d{2})\s+(\d{2}):(\d{2})(?::(\d{2}))?$/);
    if (!match) {
        return null;
    }

    var year = parseInt(match[1], 10);
    var month = parseInt(match[2], 10) - 1;
    var day = parseInt(match[3], 10);
    var hour = parseInt(match[4], 10);
    var minute = parseInt(match[5], 10);
    var second = parseInt(match[6] || '0', 10);
    return Math.floor(new Date(year, month, day, hour, minute, second).getTime() / 1000);
}

function hasFreshScreenshotSinceSessionStart(screenshotTimestamp) {
    var screenshotEpoch = parseDashboardTimestampToEpoch(screenshotTimestamp);
    if (screenshotEpoch === null) {
        return false;
    }

    var nowEpoch = Math.floor(Date.now() / 1000);
    return (nowEpoch - screenshotEpoch) <= 300;
}

function openLatestRecentScreenshotOnLoad() {
    if (_autoOpenedRecentScreenshot) {
        return;
    }

    var candidates = Array.prototype.slice.call(document.querySelectorAll('.js-open-screenshot-viewer:not(.placeholder)'));
    if (!candidates.length) {
        return;
    }

    var best = null;
    candidates.forEach(function (card) {
        var tsText = card.getAttribute('data-screenshot-ts') || '';
        var tsEpoch = parseDashboardTimestampToEpoch(tsText);
        if (tsEpoch === null) {
            return;
        }

        var ageSec = Math.floor(Date.now() / 1000) - tsEpoch;
        if (ageSec < 0 || ageSec > 300) {
            return;
        }

        if (!best || tsEpoch > best.tsEpoch) {
            best = {
                card: card,
                tsEpoch: tsEpoch
            };
        }
    });

    if (!best || !best.card) {
        return;
    }

    var kioskId = parseInt(best.card.getAttribute('data-screenshot-kiosk-id') || '0', 10);
    if (!(kioskId > 0)) {
        return;
    }

    _autoOpenedRecentScreenshot = true;
    openScreenshotViewer(
        kioskId,
        best.card.getAttribute('data-screenshot-url') || '',
        best.card.getAttribute('data-screenshot-ts') || ''
    );
}

function refreshSummaryCounters() {
    var rows = Array.prototype.slice.call(document.querySelectorAll('#kiosk-table .kiosk-row'));
    var total = rows.length;
    var online = rows.filter(function (row) {
        var status = String(row.dataset.status || '').toLowerCase();
        return status === 'online' || status === 'online_error' || status === 'online_pending';
    }).length;
    var offline = total - online;

    var totalEl = document.querySelector('.summary-item[data-group-filter="all"] strong');
    var onlineEl = document.querySelector('.summary-item[data-group-filter="online"] strong');
    var offlineEl = document.querySelector('.summary-item[data-group-filter="offline"] strong');
    if (totalEl) {
        totalEl.textContent = String(total);
    }
    if (onlineEl) {
        onlineEl.textContent = String(online);
    }
    if (offlineEl) {
        offlineEl.textContent = String(offline);
    }
}

function refreshDashboardData() {
    if (_dashboardRefreshInFlight) {
        return;
    }

    var rows = Array.prototype.slice.call(document.querySelectorAll('#kiosk-table .kiosk-row[data-kiosk-id]'));
    if (rows.length === 0) {
        return;
    }

    var ids = rows
        .map(function (row) { return parseInt(row.dataset.kioskId, 10) || 0; })
        .filter(function (id) { return id > 0; });
    if (ids.length === 0) {
        return;
    }

    _dashboardRefreshInFlight = true;
    setDashboardRefreshIndicator(true);

    function finalizeDashboardRefresh() {
        _dashboardRefreshInFlight = false;
        setDashboardRefreshIndicator(false);
    }

    fetch('../api/kiosk_details.php?refresh_list=' + encodeURIComponent(ids.join(',')))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success || !Array.isArray(data.kiosks)) {
                finalizeDashboardRefresh();
                return;
            }

            var kioskById = {};
            data.kiosks.forEach(function (kiosk) {
                kioskById[String(parseInt(kiosk.id, 10) || 0)] = kiosk;
            });

            rows.forEach(function (row) {
                var kioskId = String(parseInt(row.dataset.kioskId, 10) || 0);
                var kiosk = kioskById[kioskId];
                if (!kiosk) {
                    return;
                }

                var status = String(kiosk.status || '').toLowerCase();
                row.dataset.status = status;

                var statusBadge = row.querySelector('.kiosk-status-badge');
                if (statusBadge) {
                    statusBadge.classList.remove('status-online', 'status-offline', 'status-warning', 'status-unconfigured', 'status-pending', 'status-error', 'status-online_error', 'status-online_pending');
                    statusBadge.classList.add('status-' + status);
                    if (status === 'online_error') {
                        statusBadge.textContent = '⚠️ ' + STATUS_ONLINE_ERROR_TEXT;
                    } else if (status === 'online_pending') {
                        statusBadge.textContent = '⏳ ' + STATUS_ONLINE_PENDING_TEXT;
                    } else if (status === 'online') {
                        statusBadge.textContent = '🟢 ' + STATUS_ONLINE_TEXT;
                    } else {
                        statusBadge.textContent = '🔴 ' + STATUS_OFFLINE_TEXT;
                    }
                    statusBadge.setAttribute('onclick', "filterByStatusValue('" + escapeHtml(status) + "')");
                }

                var nameLink = row.querySelector('.kiosk-name-link');
                if (nameLink) {
                    nameLink.classList.toggle('kiosk-name-offline', status === 'offline');
                }

                var lastSeenEl = row.querySelector('.kiosk-last-seen');
                if (lastSeenEl) {
                    lastSeenEl.textContent = kiosk.activity_reference || kiosk.last_seen || 'Nikdy';
                }

                var screenshotCell = row.querySelector('.kiosk-screenshot-cell');
                if (screenshotCell) {
                    screenshotCell.innerHTML = renderKioskScreenshotCell(kiosk);
                }
            });

            refreshSummaryCounters();
            applyCombinedFilters();
            finalizeDashboardRefresh();
        })
        .catch(function () {
            finalizeDashboardRefresh();
        });
}

function startDashboardAutoRefresh() {
    if (_dashboardAutoRefreshTimer) {
        clearInterval(_dashboardAutoRefreshTimer);
    }
    refreshDashboardData();
    _dashboardAutoRefreshTimer = setInterval(refreshDashboardData, 10000);
}

function setDashboardRefreshIndicator(isRefreshing) {
    var indicator = document.getElementById('dashboard-refresh-indicator');
    if (!indicator) {
        return;
    }

    if (isRefreshing) {
        indicator.classList.add('active');
        indicator.textContent = '⏳ ' + DASHBOARD_SCREENSHOT_REFRESHING_TEXT;
    } else {
        indicator.classList.remove('active');
    }
}

function openKioskDetail(kioskId, hostname) {
    _currentModalKioskId = kioskId;
    var modal = document.getElementById('kiosk-modal');
    var body  = document.getElementById('kiosk-modal-body');
    document.getElementById('kiosk-modal-title').textContent = hostname || (DASHBOARD_KIOSK_PREFIX_TEXT + kioskId);
    body.innerHTML = '<p class="muted">' + escapeHtml(COMMON_LOADING_TEXT) + '</p>';
    modal.style.display = 'flex';

    if (_screenshotKeepaliveTimer) {
        clearInterval(_screenshotKeepaliveTimer);
        _screenshotKeepaliveTimer = null;
    }

    fetch('../api/kiosk_details.php?id=' + kioskId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                body.innerHTML = buildKioskModalHTML(data);
                if (typeof data.screenshot_watch_active !== 'undefined') {
                    setScreenshotWatchActive(!!data.screenshot_watch_active, _viewerKioskId);
                }
                var loadedImg = document.getElementById('modal-screenshot-img');
                if (loadedImg) {
                    loadedImg.setAttribute('data-base-src', loadedImg.getAttribute('src') || '');
                }
            } else {
                body.innerHTML = '<p class="muted">' + escapeHtml(COMMON_ERROR_PREFIX_TEXT) + ' ' + escapeHtml(data.message || COMMON_UNKNOWN_ERROR_TEXT) + '</p>';
            }
        })
        .catch(function () {
            body.innerHTML = '<p class="muted">' + escapeHtml(COMMON_LOAD_ERROR_TEXT) + '</p>';
        });
}

filterByGroup('all');
startDashboardAutoRefresh();
document.addEventListener('click', handleScreenshotCardClick);
</script>

<?php include '../admin/footer.php'; ?>
