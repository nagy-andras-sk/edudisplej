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
$can_edit_kiosk_details = !edudisplej_is_content_editor();

if ($session_role === 'content_editor') {
    header('Location: content_editor_index.php');
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
          SELECT k.id, k.hostname, k.friendly_name, k.status, k.location, k.last_seen,
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
        $loop_version_mismatch = (
            $row['status'] !== 'offline'
            && $kiosk_loop_version !== null
            && !empty($server_loop_version)
            && $kiosk_loop_version !== $server_loop_version
        );

        if ($loop_version_mismatch) {
            $row['status'] = 'online_error';
        }

        $row['kiosk_loop_version'] = $kiosk_loop_version;
        $row['server_loop_version'] = $server_loop_version;
        $row['loop_version_mismatch'] = $loop_version_mismatch;

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
$online  = count(array_filter($kiosks, fn($k) => in_array($k['status'], ['online', 'online_error'], true)));
$offline = $total - $online;

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

    .kiosk-screenshot-cell .preview-card.placeholder .screenshot-loader {
        font-weight: 700;
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
    <strong><?php echo htmlspecialchars(t_def('dashboard.filter.active', 'Aktív szűrés:')); ?></strong> <?php echo htmlspecialchars(t_def('dashboard.filter.location_based', 'hely alapú')); ?> — <span id="active-location-filter-name"></span>
    <button type="button" class="btn" style="margin-left:10px;" onclick="clearLocationFilter()"><?php echo htmlspecialchars(t_def('dashboard.filter.clear', 'Szűrés törlése')); ?></button>
</div>

<!-- Kiosk table -->
<div class="table-wrap">
    <table class="minimal-table" id="kiosk-table">
        <thead>
            <tr>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.name', 'Megnevezés')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.status', 'Státusz')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.location', 'Hely')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.group', 'Csoport')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.current_content', 'Aktuális tartalom')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.last_sync', 'Utolsó szinkron')); ?></th>
                <th><?php echo htmlspecialchars(t_def('dashboard.col.screen', 'Képernyő')); ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($kiosks)): ?>
                <tr><td colspan="7" style="text-align:center;color:#999;padding:20px;"><?php echo htmlspecialchars(t_def('dashboard.no_registered_kiosk', 'Nincs regisztrált kijelző.')); ?></td></tr>
            <?php else: ?>
                <?php
                $dashboard_now = new DateTimeImmutable('now');
                foreach ($kiosks as $k):
                    $gids = $k['group_ids'] ? array_map('intval', explode(',', $k['group_ids'])) : [];
                    $data_groups = implode(' ', array_map(fn($id) => 'g' . $id, $gids));
                    $location_value = trim((string)($k['location'] ?? ''));
                    $location_value_lc = strtolower($location_value);
                    $last_seen_str = $k['last_seen'] ? date('Y-m-d H:i', strtotime($k['last_seen'])) : 'Soha';
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
                    $screenshot_ts_json = json_encode($screenshot_ts ?? t_def('dashboard.screenshot.no_timestamp', 'Nincs időbélyeg'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
                    if ($screenshot_ts_json === false) {
                        $screenshot_ts_json = json_encode(t_def('dashboard.screenshot.no_timestamp', 'Nincs időbélyeg'));
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
                            <span class="status-badge kiosk-status-badge status-<?php echo htmlspecialchars($k['status']); ?>" style="cursor:pointer;" onclick="filterByStatusValue('<?php echo htmlspecialchars($k['status'], ENT_QUOTES, 'UTF-8'); ?>')" title="<?php echo htmlspecialchars(t_def('dashboard.filter.status_title', 'Szűrés erre a státuszra')); ?>">
                                <?php if ($k['status'] === 'online_error'): ?>
                                    ⚠️ <?php echo htmlspecialchars(t_def('dashboard.status.online_error', 'Online-Hiba')); ?>
                                <?php elseif ($k['status'] === 'online'): ?>
                                    🟢 <?php echo htmlspecialchars(t('dashboard.status.online')); ?>
                                <?php else: ?>
                                    🔴 <?php echo htmlspecialchars(t('dashboard.status.offline')); ?>
                                <?php endif; ?>
                            </span>
                        </td>
                        <td>
                            <?php if ($location_value !== ''): ?>
                                <a href="#" onclick="filterByLocationValue(<?php echo json_encode($location_value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT); ?>); return false;" title="<?php echo htmlspecialchars(t_def('dashboard.filter.location_title', 'Szűrés erre a helyre')); ?>">
                                    <?php echo htmlspecialchars($location_value); ?>
                                </a>
                            <?php else: ?>
                                —
                            <?php endif; ?>
                        </td>
                        <td class="muted">
                            <?php if (!empty($gids) && !empty($group_names_arr)): ?>
                                <?php foreach ($gids as $idx => $gid):
                                    $gname = $group_names_arr[$idx] ?? (t_def('dashboard.group_prefix', 'Csoport #') . $gid);
                                ?>
                                    <a href="group_loop/index.php?id=<?php echo (int)$gid; ?>" title="<?php echo htmlspecialchars(t_def('dashboard.group.edit', 'Csoport szerkesztése')); ?>"><?php echo htmlspecialchars($gname); ?></a><?php echo $idx < (count($gids) - 1) ? ', ' : ''; ?>
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
                            <?php if (in_array($k['status'], ['online', 'online_error'], true) && $k['screenshot_url']): ?>
                                  <div class="preview-card" style="cursor:pointer;" onclick="openScreenshotViewer(<?php echo (int)$k['id']; ?>, <?php echo htmlspecialchars($screenshot_url_json, ENT_QUOTES, 'UTF-8'); ?>, <?php echo htmlspecialchars($screenshot_ts_json, ENT_QUOTES, 'UTF-8'); ?>);" title="<?php echo htmlspecialchars(t_def('dashboard.screenshot.zoom_live', 'Nagyítás és élő frissítés')); ?>">
                                    <img class="screenshot-img"
                                         src="<?php echo htmlspecialchars($k['screenshot_url']); ?>"
                                         alt="Screenshot"
                                         loading="lazy">
                                    <span class="screenshot-timestamp"><?php echo htmlspecialchars($screenshot_ts ?? t_def('dashboard.screenshot.no_timestamp', 'Nincs időbélyeg')); ?></span>
                                </div>
                            <?php elseif ($k['status'] === 'offline'): ?>
                                <div class="preview-card placeholder" style="cursor:pointer;" onclick="openScreenshotViewer(<?php echo (int)$k['id']; ?>, <?php echo htmlspecialchars($screenshot_url_json, ENT_QUOTES, 'UTF-8'); ?>, 'OFFLINE');" title="<?php echo htmlspecialchars(t_def('dashboard.screenshot.open_history', 'Előzmények megnyitása')); ?>">
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
            <span class="kiosk-modal-title"><?php echo htmlspecialchars(t_def('dashboard.screenshot.large_view', 'Képernyőkép nagy nézet')); ?></span>
            <button class="kiosk-modal-close" onclick="closeScreenshotViewer()" aria-label="<?php echo htmlspecialchars(t('dashboard.modal.close')); ?>">&times;</button>
        </div>
        <div class="kiosk-modal-body" style="text-align:center;">
            <div id="screenshot-viewer-stage" style="position:relative;display:inline-block;max-width:100%;">
                <img id="screenshot-viewer-img" src="" alt="Képernyőkép" style="display:block;width:min(860px, calc(100vw - 80px));height:min(70vh, calc((100vw - 80px) * 0.5625));max-width:100%;object-fit:contain;border:1px solid #d0d6dc;border-radius:6px;background:#f8f9fb;">
                <div id="screenshot-viewer-placeholder" style="display:none;width:min(860px, calc(100vw - 80px));height:min(70vh, calc((100vw - 80px) * 0.5625));max-width:100%;border:1px solid #d0d6dc;border-radius:6px;background:repeating-linear-gradient(135deg, #d7dbe1 0 14px, #c8ced6 14px 28px);"></div>
                <div id="screenshot-viewer-timestamp" style="position:absolute;right:8px;bottom:8px;background:rgba(0,0,0,0.78);color:#fff;padding:4px 8px;border-radius:4px;font-size:12px;line-height:1.2;">—</div>
            </div>

            <div style="margin-top:10px;max-width:860px;margin-left:auto;margin-right:auto;text-align:left;">
                <div style="display:flex;justify-content:space-between;font-size:11px;color:#666;margin-bottom:4px;">
                    <span id="history-timeline-oldest"><?php echo htmlspecialchars(t_def('dashboard.history.oldest', 'Legrégebbi')); ?>: —</span>
                    <span id="history-timeline-latest"><?php echo htmlspecialchars(t_def('dashboard.history.latest', 'Legfrissebb')); ?>: —</span>
                </div>
                <input type="range" id="history-timeline" min="0" max="0" value="0" step="0.001" oninput="onTimelineInput(this.value)" style="width:100%;direction:rtl;">
                <div id="history-timeline-current" style="margin-top:4px;font-size:12px;color:#444;text-align:right;"><?php echo htmlspecialchars(t_def('dashboard.history.current', 'Aktuális')); ?>: —</div>
                <div id="history-timeline-labels" style="margin-top:6px;display:flex;justify-content:space-between;align-items:flex-start;gap:2px;min-height:92px;"></div>
            </div>

            <div style="margin-top:10px;display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;">
                <button type="button" class="btn" id="history-player-play" onclick="startHistoryPlayer()">▶ <?php echo htmlspecialchars(t_def('dashboard.history.play', 'Lejátszás')); ?></button>
                <button type="button" class="btn" id="history-player-stop" onclick="stopHistoryPlayer()">⏸ <?php echo htmlspecialchars(t_def('dashboard.history.pause', 'Szünet')); ?></button>
                <button type="button" class="btn" onclick="previousHistoryFrame()"><?php echo htmlspecialchars(t_def('dashboard.history.prev', '◀ Előző')); ?></button>
                <button type="button" class="btn" onclick="nextHistoryFrame()"><?php echo htmlspecialchars(t_def('dashboard.history.next', 'Következő ▶')); ?></button>
                <span id="history-player-status" class="muted"><?php echo htmlspecialchars(t('dashboard.screenshot.none')); ?></span>
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
                    <button type="button" class="btn btn-primary" onclick="applyHistoryFilter()"><?php echo htmlspecialchars(t_def('dashboard.history.filter', 'Szűrés')); ?></button>
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
var _historyCurrentIndex = 0;
var _historyPlayerTimer = null;
var _historyPlaybackElapsedMs = 0;
var _dashboardAutoRefreshTimer = null;
var _summaryFilter = 'all';
var _quickFilter = { type: null, value: null, label: null };
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
    $no_timestamp_text_json = json_encode(t_def('dashboard.screenshot.no_timestamp', 'Nincs időbélyeg'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $no_timestamp_text_json !== false ? $no_timestamp_text_json : '"No timestamp"';
?>;
var SCREENSHOT_OFFLINE_TEXT = 'OFFLINE';
var COMMON_LOADING_TEXT = <?php
    $common_loading_text_json = json_encode(t_def('common.loading', 'Betöltés…'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_loading_text_json !== false ? $common_loading_text_json : '"Loading..."';
?>;
var COMMON_LOAD_ERROR_TEXT = <?php
    $common_load_error_text_json = json_encode(t_def('common.load_error', 'Betöltési hiba.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_load_error_text_json !== false ? $common_load_error_text_json : '"Load error."';
?>;
var COMMON_ERROR_PREFIX_TEXT = <?php
    $common_error_prefix_text_json = json_encode(t_def('common.error_prefix', 'Hiba:'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_error_prefix_text_json !== false ? $common_error_prefix_text_json : '"Error:"';
?>;
var COMMON_UNKNOWN_TEXT = <?php
    $common_unknown_text_json = json_encode(t_def('common.unknown', 'ismeretlen'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_unknown_text_json !== false ? $common_unknown_text_json : '"unknown"';
?>;
var HISTORY_OFFLINE_SINCE_TEXT = <?php
    $history_offline_since_text_json = json_encode(t_def('dashboard.history.offline_since', 'OFFLINE SINCE:'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $history_offline_since_text_json !== false ? $history_offline_since_text_json : '"OFFLINE SINCE:"';
?>;
var HISTORY_PAGE_TEXT = <?php
    $history_page_text_json = json_encode(t_def('dashboard.history.page', 'Oldal'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $history_page_text_json !== false ? $history_page_text_json : '"Page"';
?>;
var HISTORY_NO_RESULTS_TEXT = <?php
    $history_no_results_text_json = json_encode(t_def('dashboard.history.no_results', 'Nincs találat.'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $history_no_results_text_json !== false ? $history_no_results_text_json : '"No results."';
?>;
var HISTORY_TIME_TEXT = <?php
    $history_time_text_json = json_encode(t_def('dashboard.history.time', 'Időpont'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $history_time_text_json !== false ? $history_time_text_json : '"Time"';
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
    $status_online_error_text_json = json_encode(t_def('dashboard.status.online_error', 'Online-Hiba'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $status_online_error_text_json !== false ? $status_online_error_text_json : '"Online error"';
?>;
var DASHBOARD_COL_NAME_TEXT = <?php
    $dashboard_col_name_text_json = json_encode(t_def('dashboard.col.name', 'Megnevezés'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_name_text_json !== false ? $dashboard_col_name_text_json : '"Name"';
?>;
var DASHBOARD_COL_LOCATION_TEXT = <?php
    $dashboard_col_location_text_json = json_encode(t_def('dashboard.col.location', 'Hely'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_location_text_json !== false ? $dashboard_col_location_text_json : '"Location"';
?>;
var DASHBOARD_COL_GROUP_TEXT = <?php
    $dashboard_col_group_text_json = json_encode(t_def('dashboard.col.group', 'Csoport'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_group_text_json !== false ? $dashboard_col_group_text_json : '"Group"';
?>;
var DASHBOARD_COL_STATUS_TEXT = <?php
    $dashboard_col_status_text_json = json_encode(t_def('dashboard.col.status', 'Státusz'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_status_text_json !== false ? $dashboard_col_status_text_json : '"Status"';
?>;
var DASHBOARD_COL_LAST_SYNC_TEXT = <?php
    $dashboard_col_last_sync_text_json = json_encode(t_def('dashboard.col.last_sync', 'Utolsó szinkron'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_last_sync_text_json !== false ? $dashboard_col_last_sync_text_json : '"Last sync"';
?>;
var DASHBOARD_COL_VERSION_TEXT = <?php
    $dashboard_col_version_text_json = json_encode(t_def('dashboard.col.version', 'Verzió'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_version_text_json !== false ? $dashboard_col_version_text_json : '"Version"';
?>;
var DASHBOARD_ACTION_SAVE_TEXT = <?php
    $dashboard_action_save_text_json = json_encode(t_def('common.save', 'Mentés'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
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
var DASHBOARD_LOOP_STATUS_MATCH_TEXT = <?php
    $dashboard_loop_status_match_text_json = json_encode(t_def('dashboard.loop.status_match', '✓ Egyezik'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_loop_status_match_text_json !== false ? $dashboard_loop_status_match_text_json : '"✓ Match"';
?>;
var DASHBOARD_COL_MODULES_TEXT = <?php
    $dashboard_col_modules_text_json = json_encode(t_def('dashboard.col.modules', 'Modulok'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $dashboard_col_modules_text_json !== false ? $dashboard_col_modules_text_json : '"Modules"';
?>;
var COMMON_UNKNOWN_ERROR_TEXT = <?php
    $common_unknown_error_text_json = json_encode(t_def('common.unknown_error', 'Ismeretlen hiba'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT);
    echo $common_unknown_error_text_json !== false ? $common_unknown_error_text_json : '"Unknown error"';
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
            showBySummary = (status === 'online' || status === 'online_error');
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
    filterByGroup((normalized === 'online' || normalized === 'online_error') ? 'online' : 'offline');
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
        + '&per_page=20';
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

            _historyItems = items;
            _historyCurrentIndex = 0;

            renderScreenshotHistoryRows(items);
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
    items.forEach(function (item, index) {
        var timestamp = item.timestamp || '—';
        var screenshotUrl = item.screenshot_url || '';
        var isOfflineMarker = !!item.is_offline_marker;
        var thumbHtml = '';
        if (isOfflineMarker) {
            thumbHtml = '<div class="history-offline-marker" data-index="' + index + '" style="display:inline-block;padding:10px 12px;border:1px solid #e5b4b4;border-radius:4px;background:#fdecec;color:#9f1d1d;font-weight:700;cursor:pointer;">'
                + escapeHtml(item.label || (HISTORY_OFFLINE_SINCE_TEXT + ' ' + (item.offline_since || COMMON_UNKNOWN_TEXT)))
                + '</div>';
        } else {
            thumbHtml = screenshotUrl
                ? '<img src="' + escapeHtml(appendCacheBuster(screenshotUrl)) + '" data-index="' + index + '" class="history-thumb-img" alt="Screenshot" style="width:120px;height:68px;object-fit:cover;border:1px solid #d0d6dc;border-radius:4px;cursor:pointer;">'
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
            var idx = parseInt(imgEl.getAttribute('data-index'), 10);
            if (isNaN(idx)) {
                return;
            }
            stopHistoryPlayer();
            showHistoryItemByIndex(idx);
        });
    });

    tbody.querySelectorAll('.history-offline-marker').forEach(function (markerEl) {
        markerEl.addEventListener('click', function () {
            var idx = parseInt(markerEl.getAttribute('data-index'), 10);
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
        oldestLabel.textContent = 'Legrégebbi: —';
        latestLabel.textContent = 'Legfrissebb: —';
        currentLabel.textContent = 'Aktuális: —';
        labelsWrap.innerHTML = '';
        return;
    }

    timeline.min = '0';
    timeline.max = String(_historyItems.length - 1);
    timeline.value = String(_historyCurrentIndex);

    var oldestItem = _historyItems[_historyItems.length - 1] || null;
    var latestItem = _historyItems[0] || null;
    var currentItem = _historyItems[_historyCurrentIndex] || null;

    oldestLabel.textContent = 'Legrégebbi: ' + (oldestItem && oldestItem.timestamp ? oldestItem.timestamp : '—');
    latestLabel.textContent = 'Legfrissebb: ' + (latestItem && latestItem.timestamp ? latestItem.timestamp : '—');
    currentLabel.textContent = 'Aktuális: ' + (currentItem && currentItem.timestamp ? currentItem.timestamp : '—');

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
    if (_viewerKioskId) {
        stopScreenshotTTL(_viewerKioskId);
    }
    _viewerKioskId = null;
    _viewerImageBase = '';
    _historyPage = 1;
    _historyTotalPages = 1;
    _historyItems = [];
    _historyCurrentIndex = 0;
    setScreenshotViewerMedia('', false);
    setScreenshotViewerTimestamp('—');
    updateHistoryTimeline();
}

function handleScreenshotBackdropClick(event) {
    if (event.target === document.getElementById('screenshot-viewer-modal')) {
        closeScreenshotViewer();
    }
}

function requestScreenshotTTL(kioskId) {
    fetch('../api/screenshot_request.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ kiosk_id: kioskId, ttl_seconds: 60 })
    }).catch(function () {});
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
        + '<tr><th>' + escapeHtml(DASHBOARD_COL_STATUS_TEXT) + '</th><td>' + (data.status === 'online_error' ? ('⚠️ ' + escapeHtml(STATUS_ONLINE_ERROR_TEXT)) : (data.status === 'online' ? ('🟢 ' + escapeHtml(STATUS_ONLINE_TEXT)) : ('🔴 ' + escapeHtml(STATUS_OFFLINE_TEXT))) ) + '</td></tr>'
        + '<tr><th>Hostname</th><td class="mono">' + escapeHtml(data.hostname || '—') + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_COL_GROUP_TEXT) + '</th><td>' + escapeHtml(data.group_names || '—') + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_COL_LAST_SYNC_TEXT) + '</th><td>' + escapeHtml(data.last_seen || '—') + '</td></tr>'
        + '<tr><th>MAC</th><td class="mono">' + escapeHtml(data.mac || '—') + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_COL_VERSION_TEXT) + '</th><td>' + escapeHtml(data.version || COMMON_UNKNOWN_TEXT) + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_LOOP_KIOSK_VERSION_TEXT) + '</th><td class="mono">' + escapeHtml(data.kiosk_loop_version || 'n/a') + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_LOOP_SERVER_VERSION_TEXT) + '</th><td class="mono">' + escapeHtml(data.server_loop_version || 'n/a') + '</td></tr>'
        + '<tr><th>' + escapeHtml(DASHBOARD_LOOP_STATUS_TEXT) + '</th><td>' + (data.status === 'offline' ? escapeHtml(DASHBOARD_LOOP_STATUS_OFFLINE_TEXT) : (data.loop_version_mismatch ? escapeHtml(DASHBOARD_LOOP_STATUS_MISMATCH_TEXT) : escapeHtml(DASHBOARD_LOOP_STATUS_MATCH_TEXT))) + '</td></tr>'
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
    if (!friendlyName || !location || !group) {
        return;
    }

    fetch('../api/kiosk_details.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({
            id: parseInt(kioskId, 10),
            friendly_name: friendlyName.value,
            location: location.value,
            group_id: group.value ? parseInt(group.value, 10) : null
        })
    })
    .then(function (r) { return r.json(); })
    .then(function (data) {
        if (!data.success) {
            alert('⚠️ ' + (data.message || 'Mentési hiba'));
            return;
        }
        window.location.reload();
    })
    .catch(function () {
        alert('⚠️ Mentési hiba.');
    });
}

function renderKioskScreenshotCell(kiosk) {
    var status = String(kiosk.status || '').toLowerCase();
    var kioskId = parseInt(kiosk.id, 10) || 0;
    var screenshotUrl = String(kiosk.screenshot_url || '');
    if (status === 'offline') {
        return '<div class="preview-card placeholder" style="cursor:pointer;" onclick="openScreenshotViewer(' + kioskId + ', ' + JSON.stringify(screenshotUrl) + ', ' + JSON.stringify(SCREENSHOT_OFFLINE_TEXT) + ');" title="Előzmények megnyitása">'
            + '<div class="screenshot-loader">' + escapeHtml(SCREENSHOT_OFFLINE_TEXT) + '</div>'
            + '<span class="screenshot-timestamp">' + escapeHtml(SCREENSHOT_OFFLINE_TEXT) + '</span>'
            + '</div>';
    }

    if (kiosk.screenshot_url) {
        var screenshotTs = String(kiosk.screenshot_timestamp || NO_TIMESTAMP_TEXT);
        return '<div class="preview-card" style="cursor:pointer;" onclick="openScreenshotViewer(' + kioskId + ', ' + JSON.stringify(screenshotUrl) + ', ' + JSON.stringify(screenshotTs) + ');" title="Nagyítás és élő frissítés">'
            + '<img class="screenshot-img" src="' + escapeHtml(screenshotUrl) + '" alt="Screenshot" loading="lazy">'
            + '<span class="screenshot-timestamp">' + escapeHtml(screenshotTs) + '</span>'
            + '</div>';
    }

    return '<div class="preview-card placeholder">'
        + '<div class="screenshot-loader">⏳ ' + escapeHtml(SCREENSHOT_LOADING_TEXT) + '</div>'
        + '<span class="screenshot-timestamp">' + escapeHtml(SCREENSHOT_NONE_TEXT) + '</span>'
        + '</div>';
}

function refreshSummaryCounters() {
    var rows = Array.prototype.slice.call(document.querySelectorAll('#kiosk-table .kiosk-row'));
    var total = rows.length;
    var online = rows.filter(function (row) {
        var status = String(row.dataset.status || '').toLowerCase();
        return status === 'online' || status === 'online_error';
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

    fetch('../api/kiosk_details.php?refresh_list=' + encodeURIComponent(ids.join(',')))
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (!data || !data.success || !Array.isArray(data.kiosks)) {
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
                    statusBadge.classList.remove('status-online', 'status-offline', 'status-warning', 'status-unconfigured', 'status-pending', 'status-error', 'status-online_error');
                    statusBadge.classList.add('status-' + status);
                    if (status === 'online_error') {
                        statusBadge.textContent = '⚠️ ' + STATUS_ONLINE_ERROR_TEXT;
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
                    lastSeenEl.textContent = kiosk.last_seen || 'Soha';
                }

                var screenshotCell = row.querySelector('.kiosk-screenshot-cell');
                if (screenshotCell) {
                    screenshotCell.innerHTML = renderKioskScreenshotCell(kiosk);
                }
            });

            refreshSummaryCounters();
            applyCombinedFilters();
        })
        .catch(function () {});
}

function startDashboardAutoRefresh() {
    if (_dashboardAutoRefreshTimer) {
        clearInterval(_dashboardAutoRefreshTimer);
    }
    _dashboardAutoRefreshTimer = setInterval(refreshDashboardData, 30000);
}

function openKioskDetail(kioskId, hostname) {
    _currentModalKioskId = kioskId;
    var modal = document.getElementById('kiosk-modal');
    var body  = document.getElementById('kiosk-modal-body');
    document.getElementById('kiosk-modal-title').textContent = hostname || ('Kiosk #' + kioskId);
    body.innerHTML = '<p class="muted">' + escapeHtml(COMMON_LOADING_TEXT) + '</p>';
    modal.style.display = 'flex';

    requestScreenshotTTL(kioskId);
    if (_screenshotKeepaliveTimer) {
        clearInterval(_screenshotKeepaliveTimer);
    }
    _screenshotKeepaliveTimer = setInterval(function () {
        if (_currentModalKioskId) {
            requestScreenshotTTL(_currentModalKioskId);
            var img = document.getElementById('modal-screenshot-img');
            if (img) {
                var base = img.getAttribute('data-base-src') || img.getAttribute('src') || '';
                if (base.indexOf('&t=') !== -1) {
                    base = base.replace(/&t=\d+$/, '');
                }
                if (base.indexOf('?t=') !== -1) {
                    base = base.replace(/\?t=\d+$/, '');
                }
                img.setAttribute('data-base-src', base);
                img.src = appendCacheBuster(base);
            }
        }
    }, 45000);

    fetch('../api/kiosk_details.php?id=' + kioskId)
        .then(function (r) { return r.json(); })
        .then(function (data) {
            if (data.success) {
                body.innerHTML = buildKioskModalHTML(data);
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
</script>

<?php include '../admin/footer.php'; ?>
