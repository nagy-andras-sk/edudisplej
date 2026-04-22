<?php
session_start();
require_once '../dbkonfiguracia.php';
require_once '../i18n.php';
require_once '../auth_roles.php';

// Dashboard language must stay Slovak.
$_SESSION['language'] = 'sk';
$_SESSION['lang'] = 'sk';
$current_lang = edudisplej_apply_language_preferences();

$script_name = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$app_root = preg_replace('~/dashboard/quick_messages(?:/index\.php)?$~i', '', $script_name);
$app_root = rtrim((string)$app_root, '/');
if ($app_root === '.' || $app_root === '/') {
    $app_root = '';
}
$login_path = $app_root . '/login';
$admin_dashboard_path = $app_root . '/admin/dashboard.php';

if (!isset($_SESSION['user_id'])) {
    header('Location: ' . $login_path);
    exit();
}

$is_admin = !empty($_SESSION['isadmin']) && empty($_SESSION['admin_acting_company_id']);
if ($is_admin) {
    header('Location: ' . $admin_dashboard_path);
    exit();
}

$company_id = (int)($_SESSION['admin_acting_company_id'] ?? 0);
if ($company_id <= 0) {
    $company_id = (int)($_SESSION['company_id'] ?? 0);
}
if ($company_id <= 0) {
    header('Location: ' . $login_path);
    exit();
}

$special_calendar_enabled = false;
if (!$special_calendar_enabled) {
    $title = t_def('dashboard.special_loop.page_title', 'Event Calendar');
    require_once 'header.php';
    ?>
    <div class="panel" style="margin-top:14px;">
        <div class="panel-title">📅 <?php echo htmlspecialchars(t_def('dashboard.special_loop.unavailable.title', 'Event Calendar is coming soon')); ?></div>
        <p class="muted" style="margin-top:8px;">
            <?php echo htmlspecialchars(t_def('dashboard.special_loop.unavailable.body', 'This feature is temporarily unavailable. We will enable it soon.')); ?>
        </p>
    </div>
    <?php
    require_once 'footer.php';
    exit();
}

$requested_group_id = intval($_GET['group_id'] ?? 0);

$groups = [];
$group_plans = [];
$error = '';

$requested_ym = trim((string)($_GET['ym'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}$/', $requested_ym)) {
    $requested_ym = date('Y-m');
}
$special_workflow_mode = isset($_GET['workflow']) && (string)$_GET['workflow'] === '1';
$special_workflow_start = trim((string)($_GET['wf_start'] ?? ''));
$special_workflow_end = trim((string)($_GET['wf_end'] ?? ''));
if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $special_workflow_start)) {
    $special_workflow_start = '';
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $special_workflow_end)) {
    $special_workflow_end = '';
}

$forced_special_loop_name = '';
if ($special_workflow_start !== '' && $special_workflow_end !== '') {
    $start_norm = str_replace(['-', ':', 'T'], '', $special_workflow_start);
    $end_norm = str_replace(['-', ':', 'T'], '', $special_workflow_end);
    if (preg_match('/^\d{12}$/', $start_norm) && preg_match('/^\d{12}$/', $end_norm)) {
        $forced_special_loop_name = 'special_' . $start_norm . '-' . $end_norm;
    }
}

$month_start = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $requested_ym . '-01 00:00:00');
if (!$month_start) {
    $month_start = new DateTimeImmutable(date('Y-m-01 00:00:00'));
}
$month_end = $month_start->modify('last day of this month')->setTime(23, 59, 59);
$calendar_grid_start = $month_start->modify('-' . ((int)$month_start->format('N') - 1) . ' days')->setTime(0, 0, 0);
$calendar_grid_end = $calendar_grid_start->modify('+41 days')->setTime(23, 59, 59);

$prev_ym = $month_start->modify('-1 month')->format('Y-m');
$next_ym = $month_start->modify('+1 month')->format('Y-m');
$special_css_version = (string)(@filemtime(__DIR__ . '/group_loop/assets/css/app.css') ?: time());
$group_loop_modules_catalog = [];
$available_modules = [];
$unconfigured_module = null;
$turned_off_loop_action = null;
$group_loop_localized_module_names = [];
$group_loop_js_version_app = (string)(@filemtime(__DIR__ . '/group_loop/assets/js/app.js') ?: time());

function edudisplej_parse_plan_datetime($value): ?DateTimeImmutable {
    if ($value === null) {
        return null;
    }
    $text = trim((string)$value);
    if ($text === '') {
        return null;
    }

    $normalized = str_replace('T', ' ', $text);
    if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$/', $normalized)) {
        $normalized .= ':00';
    }

    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $normalized);
    if ($dt instanceof DateTimeImmutable) {
        return $dt;
    }

    $ts = strtotime($normalized);
    if ($ts === false) {
        return null;
    }

    return (new DateTimeImmutable())->setTimestamp($ts);
}

function edudisplej_group_loop_module_emoji(string $moduleKey): string {
    $key = strtolower(trim($moduleKey));
    $map = [
        'clock' => '🕒',
        'default-logo' => '🏷️',
        'text' => '📝',
        'pdf' => '📄',
        'image-gallery' => '🖼️',
        'gallery' => '🖼️',
        'video' => '🎬',
        'weather' => '🌤️',
        'rss' => '📰',
        'turned-off' => '⏻',
        'unconfigured' => '⚙️',
    ];

    return $map[$key] ?? '🧩';
}

function edudisplej_group_loop_module_name(array $module): string {
    $module_key = strtolower(trim((string)($module['module_key'] ?? '')));
    $fallback = (string)($module['name'] ?? '');

    if ($module_key === '') {
        return $fallback;
    }

    return t_def('group_loop.module_name.' . str_replace('-', '_', $module_key), $fallback);
}

function edudisplej_group_loop_module_description(array $module): string {
    $module_key = strtolower(trim((string)($module['module_key'] ?? '')));
    $fallback = trim((string)($module['description'] ?? ''));

    if ($module_key === '') {
        return $fallback;
    }

    if ($module_key === 'turned-off') {
        return t_def('group_loop.turned_off.description', $fallback !== '' ? $fallback : 'Scheduled display power off (content service stop + HDMI off).');
    }

    return t_def('group_loop.module_desc.' . str_replace('-', '_', $module_key), $fallback);
}

try {
    $conn = getDbConnection();

    $conn->query("CREATE TABLE IF NOT EXISTS kiosk_group_calendar_events (
        group_id INT PRIMARY KEY,
        event_json LONGTEXT NOT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_updated_at (updated_at)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $module_stmt = $conn->prepare("SELECT m.*, COALESCE(ml.quantity, 0) as license_quantity
                            FROM modules m
                            LEFT JOIN module_licenses ml ON m.id = ml.module_id AND ml.company_id = ?
                            WHERE m.is_active = 1
                            ORDER BY m.name");
    $module_stmt->bind_param('i', $company_id);
    $module_stmt->execute();
    $module_result = $module_stmt->get_result();
    $seen_module_keys = [];
    while ($row = $module_result->fetch_assoc()) {
        $module_key_raw = strtolower(trim((string)($row['module_key'] ?? '')));
        $module_key = $module_key_raw;

        if ($module_key === 'unconfigured') {
            $row['module_key'] = 'unconfigured';
            $unconfigured_module = $row;
            continue;
        }

        if ((int)($row['license_quantity'] ?? 0) > 0) {
            if ($module_key === '' || isset($seen_module_keys[$module_key])) {
                continue;
            }
            $seen_module_keys[$module_key] = true;
            $row['module_key'] = $module_key;
            $available_modules[] = $row;
        }
    }
    $module_stmt->close();

    if ($unconfigured_module) {
        $turned_off_loop_action = [
            'id' => (int)$unconfigured_module['id'],
            'name' => t_def('group_loop.turned_off.name', 'Turned Off'),
            'description' => t_def('group_loop.turned_off.description', 'Scheduled display power off (content service stop + HDMI off).'),
            'module_key' => 'turned-off',
        ];
    }

    $group_loop_modules_catalog = array_values(array_merge($available_modules, $unconfigured_module ? [$unconfigured_module] : []));
    $group_loop_modules_catalog = array_map(static function (array $module): array {
        $module['name'] = edudisplej_group_loop_module_name($module);
        return $module;
    }, $group_loop_modules_catalog);

    foreach ($group_loop_modules_catalog as $module) {
        $module_key = strtolower(trim((string)($module['module_key'] ?? '')));
        if ($module_key !== '') {
            $group_loop_localized_module_names[$module_key] = (string)($module['name'] ?? '');
        }
    }
    $group_loop_localized_module_names['turned-off'] = t_def('group_loop.turned_off.name', 'Turned Off');

    $group_stmt = $conn->prepare('SELECT id, name FROM kiosk_groups WHERE company_id = ? ORDER BY priority DESC, name ASC');
    $group_stmt->bind_param('i', $company_id);
    $group_stmt->execute();
    $group_result = $group_stmt->get_result();
    while ($row = $group_result->fetch_assoc()) {
        $groups[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
        ];
    }
    $group_stmt->close();

    if (!empty($groups)) {
        $ids = array_map(fn($g) => (int)$g['id'], $groups);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $types = str_repeat('i', count($ids));

        $plan_stmt = $conn->prepare("SELECT group_id, event_json FROM kiosk_group_calendar_events WHERE group_id IN ($placeholders)");
        if ($plan_stmt) {
            $plan_stmt->bind_param($types, ...$ids);
            $plan_stmt->execute();
            $plan_result = $plan_stmt->get_result();
            while ($plan_row = $plan_result->fetch_assoc()) {
                $decoded = json_decode((string)($plan_row['event_json'] ?? ''), true);
                if (is_array($decoded)) {
                    $group_plans[(int)$plan_row['group_id']] = $decoded;
                }
            }
            $plan_stmt->close();
        }
    }

    closeDbConnection($conn);
} catch (Throwable $e) {
    $error = t_def('dashboard.special_loop.load_error', 'Nepodarilo sa načítať plánovač špeciálnych slučiek.');
    error_log('dashboard/quick_messages.php: ' . $e->getMessage());
}

$events_by_date = [];
$event_rows = [];
$styles_by_group = [];
$source_styles_by_group = [];

$special_style_prefix = 'KALENDAR | ';

$is_special_style = static function (array $style) use ($special_style_prefix): bool {
    if ((int)($style['is_special'] ?? 0) === 1) {
        return true;
    }
    $name = trim((string)($style['name'] ?? ''));
    return $name !== '' && strncmp($name, $special_style_prefix, strlen($special_style_prefix)) === 0;
};

$is_turned_off_style = static function (array $style): bool {
    $name = strtolower(trim((string)($style['name'] ?? '')));
    if ($name === 'turned off') {
        return true;
    }

    $items = is_array($style['items'] ?? null) ? $style['items'] : [];
    if (count($items) !== 1) {
        return false;
    }

    return strtolower(trim((string)($items[0]['module_key'] ?? ''))) === 'turned-off';
};

$is_special_or_turned_off_style = static function (array $style) use ($is_special_style, $is_turned_off_style): bool {
    return $is_special_style($style) || $is_turned_off_style($style);
};

$is_special_block = static function (array $block, array $style) use ($special_style_prefix, $is_special_style, $is_turned_off_style): bool {
    if ((int)($block['is_special'] ?? 0) === 1) {
        return true;
    }

    $block_name = trim((string)($block['block_name'] ?? ''));
    if ($block_name !== '' && strncmp($block_name, $special_style_prefix, strlen($special_style_prefix)) === 0) {
        return true;
    }

    return $is_special_style($style) || $is_turned_off_style($style);
};

foreach ($groups as $group) {
    $gid = (int)$group['id'];
    $plan = $group_plans[$gid] ?? null;
    if (!is_array($plan)) {
        continue;
    }

    $styles_by_id = [];
    $source_styles_by_id = [];
    $special_style_flags = [];
    foreach (($plan['loop_styles'] ?? []) as $style) {
        $style_id = (int)($style['id'] ?? 0);
        if ($style_id <= 0) {
            continue;
        }
        $style_name = (string)($style['name'] ?? ('Slučka #' . $style_id));
        $source_styles_by_id[$style_id] = $style_name;
        $special_style = $is_special_style($style);
        $special_style_flags[$style_id] = $special_style;
        if ($special_style) {
            $styles_by_id[$style_id] = $style_name;
        }
    }
    $styles_by_group[$gid] = $styles_by_id;
    $source_styles_by_group[$gid] = $source_styles_by_id;

    foreach (($plan['schedule_blocks'] ?? []) as $block) {
        if ((int)($block['is_active'] ?? 1) !== 1) {
            continue;
        }

        $block_type = (string)($block['block_type'] ?? '');
        if ($block_type !== 'date' && $block_type !== 'datetime_range') {
            continue;
        }

        $style_id = (int)($block['loop_style_id'] ?? 0);
        $style_name = $source_styles_by_id[$style_id] ?? ('Slučka #' . $style_id);
        $style_is_special = !empty($special_style_flags[$style_id]);
        if (!$is_special_block($block, ['name' => $style_name, 'is_special' => $style_is_special ? 1 : 0])) {
            continue;
        }
        $block_name = trim((string)($block['block_name'] ?? ''));
        $label = $block_name !== '' ? $block_name : $style_name;

        if ($block_type === 'date') {
            $specific_date = trim((string)($block['specific_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $specific_date)) {
                continue;
            }

            $start_time = trim((string)($block['start_time'] ?? '00:00:00'));
            $end_time = trim((string)($block['end_time'] ?? '23:59:59'));
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $start_time)) {
                $start_time = '00:00:00';
            }
            if (!preg_match('/^\d{2}:\d{2}(:\d{2})?$/', $end_time)) {
                $end_time = '23:59:59';
            }
            if (strlen($start_time) === 5) {
                $start_time .= ':00';
            }
            if (strlen($end_time) === 5) {
                $end_time .= ':00';
            }

            $start_dt = edudisplej_parse_plan_datetime($specific_date . ' ' . $start_time);
            $end_dt = edudisplej_parse_plan_datetime($specific_date . ' ' . $end_time);
            if (!$start_dt || !$end_dt) {
                continue;
            }

            $date_key = $start_dt->format('Y-m-d');
            if (!isset($events_by_date[$date_key])) {
                $events_by_date[$date_key] = [];
            }
            $events_by_date[$date_key][] = [
                'group' => (string)$group['name'],
                'label' => $label,
                'time' => $start_dt->format('H:i') . ' - ' . $end_dt->format('H:i'),
            ];
            $event_rows[] = [
                'group_id' => $gid,
                'date' => $date_key,
                'group' => (string)$group['name'],
                'label' => $label,
                'time' => $start_dt->format('H:i') . ' - ' . $end_dt->format('H:i'),
            ];
            continue;
        }

        $start_dt = edudisplej_parse_plan_datetime($block['start_datetime'] ?? null);
        $end_dt = edudisplej_parse_plan_datetime($block['end_datetime'] ?? null);
        if (!$start_dt || !$end_dt || $end_dt < $start_dt) {
            continue;
        }

        $iter_start = $start_dt > $calendar_grid_start ? $start_dt : $calendar_grid_start;
        $iter_end = $end_dt < $calendar_grid_end ? $end_dt : $calendar_grid_end;
        if ($iter_end < $iter_start) {
            continue;
        }

        $cursor = $iter_start->setTime(0, 0, 0);
        $last = $iter_end->setTime(0, 0, 0);
        while ($cursor <= $last) {
            $date_key = $cursor->format('Y-m-d');
            if (!isset($events_by_date[$date_key])) {
                $events_by_date[$date_key] = [];
            }
            $events_by_date[$date_key][] = [
                'group' => (string)$group['name'],
                'label' => $label,
                'time' => $start_dt->format('Y-m-d H:i') . ' - ' . $end_dt->format('Y-m-d H:i'),
            ];
            $cursor = $cursor->modify('+1 day');
        }

        $event_rows[] = [
            'group_id' => $gid,
            'date' => $start_dt->format('Y-m-d'),
            'group' => (string)$group['name'],
            'label' => $label,
            'time' => $start_dt->format('Y-m-d H:i') . ' - ' . $end_dt->format('Y-m-d H:i'),
        ];
    }
}

usort($event_rows, static function (array $a, array $b): int {
    $date_cmp = strcmp((string)$a['date'], (string)$b['date']);
    if ($date_cmp !== 0) {
        return $date_cmp;
    }
    return strcmp((string)$a['group'], (string)$b['group']);
});

$weekday_labels = [
    t_def('dashboard.special_loop.weekday.mon', 'Pondelok'),
    t_def('dashboard.special_loop.weekday.tue', 'Utorok'),
    t_def('dashboard.special_loop.weekday.wed', 'Streda'),
    t_def('dashboard.special_loop.weekday.thu', 'Štvrtok'),
    t_def('dashboard.special_loop.weekday.fri', 'Piatok'),
    t_def('dashboard.special_loop.weekday.sat', 'Sobota'),
    t_def('dashboard.special_loop.weekday.sun', 'Nedeľa'),
];

include '../admin/header.php';
?>

    <link rel="stylesheet" href="group_loop/assets/css/app.css?v=<?php echo rawurlencode($special_css_version); ?>">

<style>
.special-hero {
    border: 2px solid #ef8f1b;
    border-radius: 10px;
    background: linear-gradient(130deg, #fff4e5 0%, #fffdf8 100%);
    box-shadow: 0 10px 24px rgba(0, 0, 0, 0.08);
    padding: 14px;
    margin-bottom: 12px;
}
.special-hero h2 {
    margin: 0;
    font-size: 21px;
    color: #7c3400;
}
.special-hero p {
    margin: 6px 0 0;
    color: #6a4a1a;
}
.special-grid {
    display: grid;
    grid-template-columns: minmax(320px, 440px) 1fr;
    gap: 12px;
}
.special-form-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
}
.special-form-grid .field-wide {
    grid-column: 1 / -1;
}
.special-form-grid label {
    display: block;
    margin-bottom: 4px;
    font-size: 12px;
    font-weight: 600;
}
.special-form-grid input,
.special-form-grid select {
    width: 100%;
    border: 1px solid #d8b27f;
    border-radius: 6px;
    padding: 9px 10px;
    background: #fff;
}
.special-state {
    margin-top: 8px;
    font-size: 12px;
    color: #6b7280;
}
.special-stack {
    display: grid;
    gap: 12px;
}
.special-calendar-head {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 8px;
    margin-bottom: 8px;
}
.special-calendar-nav {
    display: flex;
    gap: 8px;
    align-items: center;
}
.special-calendar {
    width: 100%;
    border-collapse: collapse;
    table-layout: fixed;
}
.special-calendar th,
.special-calendar td {
    border: 1px solid #e7eaf0;
    vertical-align: top;
}
.special-calendar th {
    background: #f8fafc;
    font-size: 12px;
    padding: 8px;
}
.special-calendar td {
    height: 100px;
    padding: 6px;
    background: #fff;
}
.special-calendar td.clickable {
    cursor: pointer;
    transition: background-color 0.15s ease, box-shadow 0.15s ease;
}
.special-calendar td.clickable:hover {
    background: #fefce8;
    box-shadow: inset 0 0 0 1px #f59e0b;
}
.special-calendar td.outside {
    background: #f8fafc;
    color: #94a3b8;
}
.special-calendar .day-number {
    font-weight: 700;
    font-size: 12px;
    margin-bottom: 4px;
}
.special-calendar .day-events {
    display: grid;
    gap: 4px;
}
.special-calendar .event-dot {
    font-size: 11px;
    line-height: 1.25;
    background: #fef3c7;
    border: 1px solid #f8d78a;
    border-radius: 4px;
    padding: 2px 4px;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.special-calendar .more {
    font-size: 11px;
    color: #6b7280;
}
.special-events-panel {
    margin: 10px 0 14px 0;
    padding: 12px;
    border: 1px solid #d8e1ea;
    background: linear-gradient(180deg, #fff 0%, #fffaf3 100%);
    border-radius: 10px;
    box-shadow: 0 8px 24px rgba(15, 23, 42, 0.05);
}
.special-events-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    margin-bottom: 10px;
    flex-wrap: wrap;
}
.special-events-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 680px;
}
.special-events-table th,
.special-events-table td {
    border-bottom: 1px solid #edf1f5;
    padding: 10px 8px;
    vertical-align: top;
    text-align: left;
}
.special-events-table th {
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.08em;
    color: #6a4a1a;
    background: #fff8ec;
}
.special-events-table tr:hover td {
    background: #fffdf8;
}
.special-event-badge {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 4px 8px;
    border-radius: 999px;
    background: #fff4d6;
    border: 1px solid #f4d18d;
    color: #7c3400;
    font-size: 12px;
    font-weight: 700;
}
.special-event-actions {
    display: flex;
    flex-wrap: wrap;
    gap: 8px;
}
@media (max-width: 980px) {
    .special-form-grid {
        grid-template-columns: 1fr;
    }

    .special-events-table {
        min-width: 560px;
    }
}
</style>

<?php
$active_group = null;
if (!empty($groups)) {
    foreach ($groups as $group) {
        if ((int)$group['id'] === $requested_group_id) {
            $active_group = $group;
            break;
        }
    }
    if ($active_group === null) {
        $active_group = $groups[0];
        $requested_group_id = (int)$active_group['id'];
    }
}

$selected_group_name = $active_group ? (string)$active_group['name'] : t_def('dashboard.special_loop.group_placeholder', 'Válassz csoportot');

$selected_group_plan = ($active_group && isset($group_plans[(int)$active_group['id']]) && is_array($group_plans[(int)$active_group['id']]))
    ? $group_plans[(int)$active_group['id']]
    : null;
$selected_special_styles = [];
$selected_special_blocks = [];
$special_events_by_day = [];

if (is_array($selected_group_plan)) {
    $style_names_by_id = [];
    foreach (($selected_group_plan['specialStyles'] ?? []) as $style) {
        if (!is_array($style)) {
            continue;
        }
        $style_id = (int)($style['id'] ?? 0);
        if ($style_id <= 0 || !$is_special_or_turned_off_style($style)) {
            continue;
        }

        $style_name = trim((string)($style['name'] ?? ('Loop #' . $style_id)));
        if ($is_turned_off_style($style)) {
            $style_name = 'Turned Off';
        } else {
            $style_name = $special_style_prefix . preg_replace('/^' . preg_quote($special_style_prefix, '/') . '/i', '', $style_name);
        }

        $style['name'] = $style_name;
        $style['is_special'] = 1;
        $selected_special_styles[$style_id] = $style;
        $style_names_by_id[$style_id] = $style_name;
    }

    foreach (($selected_group_plan['specialBlocks'] ?? []) as $block) {
        if (!is_array($block)) {
            continue;
        }

        $style_id = (int)($block['loop_style_id'] ?? 0);
        $block_type = (string)($block['block_type'] ?? '');
        if ($block_type !== 'date' && $block_type !== 'datetime_range') {
            continue;
        }

        $is_special_block_for_group = (int)($block['is_special'] ?? 0) === 1
            || $is_special_style($block)
            || $is_turned_off_style($selected_special_styles[$style_id] ?? []);
        if (!$is_special_block_for_group) {
            continue;
        }

        $block_name = trim((string)($block['block_name'] ?? ''));
        $friendly_name = trim((string)($block['friendly_name'] ?? ''));
        if ($friendly_name === '') {
            $friendly_name = preg_replace('/^' . preg_quote($special_style_prefix, '/') . '/i', '', $block_name);
        }
        if ($friendly_name === '') {
            $friendly_name = ($style_names_by_id[$style_id] ?? ('Loop #' . $style_id));
        }
        $event_purpose = trim((string)($block['event_purpose'] ?? ''));
        $label = $friendly_name;

        if ($block_type === 'date') {
            $specific_date = trim((string)($block['specific_date'] ?? ''));
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $specific_date)) {
                continue;
            }

            $start_time = trim((string)($block['start_time'] ?? '00:00:00'));
            $end_time = trim((string)($block['end_time'] ?? '23:59:59'));
            if (strlen($start_time) === 5) {
                $start_time .= ':00';
            }
            if (strlen($end_time) === 5) {
                $end_time .= ':00';
            }

            $start_dt = edudisplej_parse_plan_datetime($specific_date . ' ' . $start_time);
            $end_dt = edudisplej_parse_plan_datetime($specific_date . ' ' . $end_time);
            if (!$start_dt || !$end_dt) {
                continue;
            }
        } else {
            $start_dt = edudisplej_parse_plan_datetime($block['start_datetime'] ?? null);
            $end_dt = edudisplej_parse_plan_datetime($block['end_datetime'] ?? null);
            if (!$start_dt || !$end_dt || $end_dt < $start_dt) {
                continue;
            }
            $specific_date = $start_dt->format('Y-m-d');
        }

        $now = new DateTimeImmutable();
        $relative_label = '';
        $format_interval = static function (DateInterval $interval): string {
            $parts = [];
            if ($interval->d > 0) {
                $parts[] = t_def('dashboard.special_loop.time.day', '{count} day', ['count' => $interval->d]);
            }
            if ($interval->h > 0) {
                $parts[] = t_def('dashboard.special_loop.time.hour', '{count} hour', ['count' => $interval->h]);
            }
            if ($interval->i > 0 && count($parts) < 2) {
                $parts[] = t_def('dashboard.special_loop.time.minute', '{count} min', ['count' => $interval->i]);
            }
            if (empty($parts)) {
                $parts[] = t_def('dashboard.special_loop.time.less_than_minute', 'less than 1 min');
            }
            return implode(' ', $parts);
        };

        $status_key = 'scheduled';
        $status_label = t_def('dashboard.special_loop.status.scheduled', 'Scheduled');
        if ($now < $start_dt) {
            $relative_label = t_def('dashboard.special_loop.relative.starts_in', 'Starts in {interval}', ['interval' => $format_interval($now->diff($start_dt))]);
        } elseif ($now > $end_dt) {
            $status_key = 'inactive';
            $status_label = t_def('dashboard.special_loop.status.inactive_expired', 'Inactive (expired)');
            $relative_label = t_def('dashboard.special_loop.relative.elapsed', '{interval} elapsed', ['interval' => $format_interval($end_dt->diff($now))]);
        } else {
            $status_key = 'active';
            $status_label = t_def('dashboard.special_loop.status.active', 'Active');
            $relative_label = t_def('dashboard.special_loop.relative.running_remaining', 'Running now, {interval} remaining', ['interval' => $format_interval($now->diff($end_dt))]);
        }

        $selected_special_blocks[] = [
            'id' => (int)($block['id'] ?? 0),
            'group_id' => (int)($active_group['id'] ?? $requested_group_id),
            'block_name' => $label,
            'block_type' => $block_type,
            'specific_date' => $specific_date ?? '',
            'start_time' => trim((string)($block['start_time'] ?? '')),
            'end_time' => trim((string)($block['end_time'] ?? '')),
            'start_datetime' => (string)($block['start_datetime'] ?? ''),
            'end_datetime' => (string)($block['end_datetime'] ?? ''),
            'days_mask' => (string)($block['days_mask'] ?? ''),
            'priority' => (int)($block['priority'] ?? 0),
            'is_active' => (int)($block['is_active'] ?? 1),
            'loop_style_id' => $style_id,
            'loop_style_name' => $style_names_by_id[$style_id] ?? ('Loop #' . $style_id),
            'friendly_name' => $friendly_name,
            'event_purpose' => $event_purpose,
            'status_key' => $status_key,
            'status_label' => $status_label,
            'is_expired' => $status_key === 'inactive' ? 1 : 0,
            'relative_label' => $relative_label,
        ];
    }

    usort($selected_special_blocks, static function (array $a, array $b): int {
        return strcmp((string)($a['start_datetime'] ?: $a['specific_date']), (string)($b['start_datetime'] ?: $b['specific_date']));
    });

    foreach ($selected_special_blocks as $special_block) {
        $day_key = '';
        if (($special_block['block_type'] ?? '') === 'datetime_range') {
            $day_key = substr((string)($special_block['start_datetime'] ?? ''), 0, 10);
        } else {
            $day_key = (string)($special_block['specific_date'] ?? '');
        }

        if ($day_key !== '') {
            $special_events_by_day[$day_key][] = $special_block;
        }
    }
}
?>

<div class="loop-main-header">
    📅 <?php echo htmlspecialchars(t_def('dashboard.special_loop.page_title', 'Eseménynaptár')); ?> • <?php echo htmlspecialchars(t_def('dashboard.special_loop.group', 'Csoport')); ?>:
    <strong><?php echo htmlspecialchars($selected_group_name); ?></strong>
</div>

<?php if ($special_workflow_mode): ?>
    <div style="margin:10px 0 14px 0; padding:10px 12px; border:1px solid #d8e1ea; background:#f8fafc; border-radius:8px; display:flex; align-items:center; justify-content:space-between; gap:10px; flex-wrap:wrap;">
        <div style="font-size:12px; color:#425466; line-height:1.4;">
            <strong><?php echo htmlspecialchars(t_def('dashboard.special_loop.save_hint_title', 'Mentés')); ?></strong><br>
            <?php echo htmlspecialchars(t_def('dashboard.special_loop.save_hint_text', 'Az eseményt a szerkesztőben tudod elmenteni.')); ?>
        </div>
        <button type="button" class="btn btn-primary" onclick="publishLoopPlan()">💾 <?php echo htmlspecialchars(t_def('common.save', 'Save')); ?></button>
    </div>
<?php endif; ?>

<?php if ($error !== ''): ?>
    <div class="alert alert-error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<?php if (!$special_workflow_mode): ?>
<div class="planner-panel" style="margin-bottom:14px;">
    <div class="planner-title"><?php echo htmlspecialchars(t_def('dashboard.special_loop.workflow_title', 'Eseménynaptár')); ?></div>
    <div style="display:flex; flex-wrap:wrap; gap:8px; align-items:center; justify-content:space-between; padding:12px; border:1px solid #e7eaf0; background:#f8fafc; border-radius:8px;">
        <div style="font-size:12px; color:#425466; line-height:1.5;">
            <strong><?php echo htmlspecialchars(t_def('dashboard.special_loop.workflow_steps_title', 'Lépések')); ?></strong><br>
            <?php echo htmlspecialchars(t_def('dashboard.special_loop.workflow_steps_text', 'A heti terv az alap. Az eseménynaptárban ezeket napokra szóló loopokkal írhatod felül.')); ?>
        </div>
        <button type="button" class="btn btn-primary" onclick="openSpecialWorkflowStart()">
            <?php echo htmlspecialchars(t_def('dashboard.special_loop.start_workflow', 'Új esemény')); ?>
        </button>
    </div>
</div>

<div class="special-events-panel" style="margin-bottom:14px;">
    <div class="special-events-header">
        <div>
            <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:#8a4b00; font-weight:700;"><?php echo htmlspecialchars(t_def('dashboard.special_loop.calendar_title', 'Havi eseménynaptár')); ?></div>
            <div style="font-size:13px; color:#6a4a1a; margin-top:4px;"><?php echo htmlspecialchars(t_def('dashboard.special_loop.calendar_hint', 'A napokra betervezett események felülírják a heti alaptervet.')); ?></div>
        </div>
        <div class="special-calendar-nav">
            <a class="btn" href="quick_messages.php?group_id=<?php echo (int)$requested_group_id; ?>&ym=<?php echo htmlspecialchars($prev_ym); ?>" title="<?php echo htmlspecialchars(t_def('group_loop.prev_month', 'Previous month')); ?>">◀</a>
            <strong style="min-width:120px; text-align:center; display:inline-block;"><?php echo htmlspecialchars($month_start->format('Y. m')); ?></strong>
            <a class="btn" href="quick_messages.php?group_id=<?php echo (int)$requested_group_id; ?>&ym=<?php echo htmlspecialchars($next_ym); ?>" title="<?php echo htmlspecialchars(t_def('group_loop.next_month', 'Next month')); ?>">▶</a>
        </div>
    </div>
    <div style="overflow:auto;">
        <table class="special-calendar">
            <thead>
                <tr>
                    <th><?php echo htmlspecialchars(t_def('common.mon', 'Mon')); ?></th>
                    <th><?php echo htmlspecialchars(t_def('common.tue', 'Tue')); ?></th>
                    <th><?php echo htmlspecialchars(t_def('common.wed', 'Wed')); ?></th>
                    <th><?php echo htmlspecialchars(t_def('common.thu', 'Thu')); ?></th>
                    <th><?php echo htmlspecialchars(t_def('common.fri', 'Fri')); ?></th>
                    <th><?php echo htmlspecialchars(t_def('common.sat', 'Sat')); ?></th>
                    <th><?php echo htmlspecialchars(t_def('common.sun', 'Sun')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php for ($week = 0; $week < 6; $week++): ?>
                    <tr>
                        <?php for ($day = 0; $day < 7; $day++):
                            $current_day = $calendar_grid_start->modify('+' . (($week * 7) + $day) . ' days');
                            $day_key = $current_day->format('Y-m-d');
                            $is_outside = $current_day < $month_start || $current_day > $month_end;
                            $day_events = $special_events_by_day[$day_key] ?? [];
                        ?>
                            <td class="<?php echo $is_outside ? 'outside' : 'clickable'; ?>">
                                <div class="day-number"><?php echo htmlspecialchars($current_day->format('j')); ?></div>
                                <div class="day-events">
                                    <?php foreach (array_slice($day_events, 0, 3) as $day_event): ?>
                                        <div class="event-dot" title="<?php echo htmlspecialchars((string)($day_event['friendly_name'] ?? $day_event['block_name'])); ?>">
                                            <?php echo htmlspecialchars((string)($day_event['friendly_name'] ?? $day_event['block_name'])); ?>
                                        </div>
                                    <?php endforeach; ?>
                                    <?php if (count($day_events) > 3): ?>
                                        <div class="more">+<?php echo (int)(count($day_events) - 3); ?> <?php echo htmlspecialchars(t_def('dashboard.special_loop.more_events', 'more')); ?></div>
                                    <?php endif; ?>
                                </div>
                            </td>
                        <?php endfor; ?>
                    </tr>
                <?php endfor; ?>
            </tbody>
        </table>
    </div>
</div>

<?php if ($active_group && !empty($selected_special_blocks)): ?>
    <div class="special-events-panel">
        <div class="special-events-header">
            <div>
                <div style="font-size:11px; text-transform:uppercase; letter-spacing:0.08em; color:#8a4b00; font-weight:700;"><?php echo htmlspecialchars(t_def('dashboard.special_loop.event_list_title', 'Eseménylista')); ?></div>
                <div style="font-size:13px; color:#6a4a1a; margin-top:4px;"><?php echo htmlspecialchars(t_def('dashboard.special_loop.event_list_hint', 'Itt látod a közelgő és már betervezett napokra szóló loopokat.')); ?></div>
            </div>
            <div class="special-calendar-nav">
                <a class="btn" href="quick_messages.php?group_id=<?php echo (int)$requested_group_id; ?>&ym=<?php echo htmlspecialchars($prev_ym); ?>" title="<?php echo htmlspecialchars(t_def('group_loop.prev_month', 'Previous month')); ?>">◀</a>
                <a class="btn" href="quick_messages.php?group_id=<?php echo (int)$requested_group_id; ?>&ym=<?php echo htmlspecialchars($next_ym); ?>" title="<?php echo htmlspecialchars(t_def('group_loop.next_month', 'Next month')); ?>">▶</a>
            </div>
        </div>
        <div style="overflow:auto;">
            <table class="special-events-table">
                <thead>
                    <tr>
                        <th><?php echo htmlspecialchars(t_def('dashboard.special_loop.when', 'Időpont')); ?></th>
                        <th><?php echo htmlspecialchars(t_def('dashboard.special_loop.event_name', 'Esemény neve')); ?></th>
                        <th><?php echo htmlspecialchars(t_def('dashboard.special_loop.event_purpose', 'Cél')); ?></th>
                        <th><?php echo htmlspecialchars(t_def('dashboard.special_loop.loop', 'Loop')); ?></th>
                        <th><?php echo htmlspecialchars(t_def('dashboard.special_loop.status', 'Állapot')); ?></th>
                        <th><?php echo htmlspecialchars(t_def('dashboard.special_loop.actions', 'Műveletek')); ?></th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($selected_special_blocks as $special_block): ?>
                        <tr>
                            <td>
                                <div style="font-weight:700; color:#1f2937;">
                                    <?php echo htmlspecialchars(($special_block['block_type'] === 'datetime_range' ? ($special_block['start_datetime'] . ' → ' . $special_block['end_datetime']) : ($special_block['specific_date'] . ' ' . substr((string)$special_block['start_time'], 0, 5) . ' - ' . substr((string)$special_block['end_time'], 0, 5)))); ?>
                                </div>
                                <div style="font-size:12px; color:#5b6b7c; margin-top:3px;">
                                    <?php echo htmlspecialchars($special_block['relative_label']); ?>
                                </div>
                            </td>
                            <td>
                                <div style="font-weight:700; color:#1f2937;"><?php echo htmlspecialchars((string)($special_block['friendly_name'] ?? $special_block['block_name'])); ?></div>
                            </td>
                            <td style="font-size:12px; color:#425466; line-height:1.4;">
                                <?php echo htmlspecialchars((string)($special_block['event_purpose'] ?? '')); ?>
                            </td>
                            <td>
                                <span class="special-event-badge"><?php echo htmlspecialchars($special_block['loop_style_name']); ?></span>
                            </td>
                            <td style="font-size:12px; color:#425466; line-height:1.4;">
                                <?php echo htmlspecialchars((string)($special_block['status_label'] ?? 'Ütemezve')); ?>
                            </td>
                            <td>
                                <div class="special-event-actions">
                                    <button type="button" class="btn" onclick="openSpecialEventEditor(<?php echo (int)$special_block['id']; ?>)"><?php echo htmlspecialchars(t_def('common.edit', 'Edit')); ?></button>
                                    <button type="button" class="btn" onclick="repeatSpecialEvent(<?php echo (int)$special_block['id']; ?>)"><?php echo htmlspecialchars(t_def('dashboard.special_loop.repeat', 'Ismétlés')); ?></button>
                                    <button type="button" class="btn btn-danger" onclick="deleteSpecialEvent(<?php echo (int)$special_block['id']; ?>)"><?php echo htmlspecialchars(t_def('common.delete', 'Delete')); ?></button>
                                </div>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>
<?php endif; ?>

<div class="loop-main-layout" style="display: <?php echo $special_workflow_mode ? 'grid' : 'none'; ?>;">
    <div class="loop-main-left">
        <div class="loop-builder" id="loop-builder">
            <h2 id="loop-config-title">🔄 <?php echo htmlspecialchars(t_def('group_loop.title', 'Skupinové slučky')); ?></h2>

            <?php if (!$special_workflow_mode): ?>
            <div class="loop-style-selector-panel">
                <div class="loop-style-selector-row">
                    <label for="loop-style-select"><?php echo htmlspecialchars(t_def('group_loop.selected_loop_list', 'Selected loop list')); ?>:</label>
                    <select id="loop-style-select" onchange="openLoopStyleDetail(this.value)"></select>
                    <button class="btn" type="button" onclick="createLoopStyle()">+ <?php echo htmlspecialchars(t_def('group_loop.new_loop', 'New loop')); ?></button>
                    <button class="btn btn-danger" id="loop-style-delete-btn" type="button" onclick="deleteActiveLoopStyle()">🗑️ <?php echo htmlspecialchars(t_def('group_loop.delete_loop', 'Delete loop')); ?></button>
                </div>
            </div>
            <?php endif; ?>

            <div id="loop-workspace-placeholder" class="loop-detail-placeholder" style="display:none; min-height:180px; margin-top:10px;">
                <?php echo htmlspecialchars($special_workflow_mode ? t_def('dashboard.special_loop.editor_loading', 'Načítavam špeciálny loop pre zvolené obdobie...') : t_def('group_loop.placeholder.select_loop_first', 'First, select a loop list from the dropdown. Then the module catalog, active loop editor and preview will appear.')); ?>
            </div>

            <div id="loop-edit-workspace" class="loop-edit-workspace" style="display:grid;">
                <div class="loop-workspace-col loop-workspace-modules">
                    <div class="planner-column-header"><?php echo htmlspecialchars(t_def('group_loop.module_catalog', 'Module catalog')); ?></div>
                    <div id="modules-panel-placeholder" class="loop-detail-placeholder" style="display:none; min-height:120px; margin:10px;">
                        <?php echo htmlspecialchars(t_def('group_loop.placeholder.modules_after_select', 'Available modules appear only after selecting a loop.')); ?>
                    </div>

                    <div id="modules-panel-wrapper" style="display:block; padding:10px;">
                        <div class="modules-panel">
                            <h2>📦 <?php echo htmlspecialchars(t_def('group_loop.available_modules', 'Available modules')); ?></h2>

                            <?php if (!$active_group || !empty($available_modules)): ?>
                                <?php foreach ($available_modules as $module): ?>
                                    <?php $module_key = (string)($module['module_key'] ?? ''); ?>
                                    <?php $module_display_name = edudisplej_group_loop_module_name($module); ?>
                                    <div class="module-item is-collapsed"
                                        data-module-id="<?php echo (int)$module['id']; ?>"
                                        data-module-key="<?php echo htmlspecialchars($module_key, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-module-name="<?php echo htmlspecialchars($module_display_name, ENT_QUOTES, 'UTF-8'); ?>"
                                        data-module-desc="<?php echo htmlspecialchars((string)($module['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                        draggable="true"
                                    >
                                        <div class="module-item-head">
                                            <div class="module-name"><span class="module-icon"><?php echo htmlspecialchars(edudisplej_group_loop_module_emoji($module_key)); ?></span><?php echo htmlspecialchars($module_display_name); ?></div>
                                            <button type="button" class="module-toggle-btn" aria-expanded="false" title="<?php echo htmlspecialchars(t_def('group_loop.toggle_description', 'Toggle description')); ?>">▾</button>
                                        </div>
                                        <div class="module-desc"><?php echo htmlspecialchars($module['description'] ?? ''); ?></div>
                                    </div>
                                <?php endforeach; ?>
                            <?php endif; ?>

                            <?php if ($unconfigured_module): ?>
                                <?php $unconfigured_display_name = edudisplej_group_loop_module_name($unconfigured_module); ?>
                                <div id="unconfiguredModuleItem" class="module-item is-collapsed"
                                    style="display: <?php echo $active_group ? 'none' : 'block'; ?>;"
                                    data-module-id="<?php echo (int)$unconfigured_module['id']; ?>"
                                    data-module-key="<?php echo htmlspecialchars((string)($unconfigured_module['module_key'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    data-module-name="<?php echo htmlspecialchars($unconfigured_display_name, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-module-desc="<?php echo htmlspecialchars((string)($unconfigured_module['description'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>"
                                    draggable="true"
                                >
                                    <div class="module-item-head">
                                        <div class="module-name"><span class="module-icon"><?php echo htmlspecialchars(edudisplej_group_loop_module_emoji((string)($unconfigured_module['module_key'] ?? 'unconfigured'))); ?></span><?php echo htmlspecialchars($unconfigured_display_name); ?></div>
                                        <button type="button" class="module-toggle-btn" aria-expanded="false" title="<?php echo htmlspecialchars(t_def('group_loop.toggle_description', 'Toggle description')); ?>">▾</button>
                                    </div>
                                    <div class="module-desc"><?php echo htmlspecialchars($unconfigured_module['description'] ?? t_def('group_loop.technical_module_desc', 'Technical module – only for empty loop fallback.')); ?></div>
                                </div>
                            <?php endif; ?>

                            <p id="noModulesMessage" style="text-align: center; color: #999; padding: 16px 8px; display: <?php echo (empty($available_modules) && !$unconfigured_module) ? 'block' : 'none'; ?>;"><?php echo htmlspecialchars(t_def('group_loop.no_modules', 'No available modules')); ?></p>
                        </div>
                    </div>
                </div>

                <div class="loop-workspace-col loop-workspace-editor">
                    <?php
                    $is_default_group = false;
                    $is_content_only_mode = false;
                    require __DIR__ . '/group_loop/partials/editor_header.php';
                    ?>
                    <div style="padding:10px;">
                        <div id="loop-detail-placeholder" class="loop-detail-placeholder" style="display:none;">
                                <?php echo htmlspecialchars($special_workflow_mode ? t_def('dashboard.special_loop.editor_loading', 'Načítavam špeciálny loop pre zvolené obdobie...') : t_def('group_loop.select_for_edit', 'Select a loop from the dropdown list for editing.')); ?>
                        </div>

                        <div id="loop-detail-panel" style="display:block;">
                            <div id="loop-container" class="empty" ondragover="allowModuleCatalogDrop(event)" ondragenter="allowModuleCatalogDrop(event)" ondragleave="handleModuleCatalogDragLeave(event)" ondrop="dropCatalogModuleToLoop(event)">
                                <p><?php echo htmlspecialchars(t_def('group_loop.empty_drag_hint', 'No items in loop. Drag modules here from the "Available modules" panel.')); ?></p>
                            </div>

                            <div class="control-panel">
                                <button class="btn btn-danger" onclick="clearLoop()" <?php echo ($active_group === null) ? 'disabled style="opacity:0.6; cursor:not-allowed;"' : ''; ?>>🗑️ <?php echo htmlspecialchars(t_def('group_loop.clear_all', 'Clear all')); ?></button>
                                <div class="total-duration" id="total-duration"><?php echo htmlspecialchars(t_def('group_loop.total_duration_short', 'Total: 0 sec')); ?></div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="loop-workspace-col loop-workspace-preview" id="preview-panel-wrapper">
                    <div class="planner-column-header"><?php echo htmlspecialchars(t_def('group_loop.preview', 'Preview')); ?></div>
                    <div style="padding:10px;">
                        <div class="preview-panel">
                            <h2 id="preview-title">📺 <?php echo htmlspecialchars(t_def('group_loop.preview_title', 'Loop preview')); ?></h2>

                            <div style="margin-bottom: 15px;">
                                <label style="display: block; font-weight: bold; margin-bottom: 5px; color: #333; font-size: 13px;">
                                    <?php echo htmlspecialchars(t_def('group_loop.preview_resolution', 'Resolution preview')); ?>:
                                </label>
                                <select id="resolutionSelector" onchange="updatePreviewResolution()" style="width:100%; padding:8px 12px; border:2px solid #1a3a52; border-radius:5px; font-size:13px; background:white; cursor:pointer; font-weight:500;">
                                    <option value="1920x1080">1920x1080 (16:9 Full HD)</option>
                                    <option value="1280x720">1280x720 (16:9 HD)</option>
                                    <option value="1024x768">1024x768 (4:3 XGA)</option>
                                    <option value="1600x900">1600x900 (16:9 HD+)</option>
                                    <option value="1366x768">1366x768 (16:9 WXGA)</option>
                                </select>
                            </div>

                            <div class="preview-screen" id="previewScreen">
                                <div class="preview-empty" id="previewEmpty">
                                    <p>🎬 <?php echo htmlspecialchars(t_def('group_loop.no_loop', 'No loop')); ?></p>
                                    <p style="font-size: 12px; color: #999; margin-top: 10px;"><?php echo htmlspecialchars(t_def('group_loop.preview_add_modules', 'Add modules for loop preview')); ?></p>
                                </div>
                                <iframe id="previewIframe" class="preview-iframe" style="display: none;"></iframe>
                            </div>

                            <div class="preview-controls">
                                <button class="preview-btn preview-btn-play" id="btnPlay" onclick="startPreview()">▶️ <?php echo htmlspecialchars(t_def('group_loop.play', 'Play')); ?></button>
                                <button class="preview-btn preview-btn-pause" id="btnPause" onclick="pausePreview()" style="display: none;">⏸️ <?php echo htmlspecialchars(t_def('group_loop.pause', 'Pause')); ?></button>
                                <button class="preview-btn preview-btn-stop" id="btnStop" onclick="stopPreview()">⏹️ <?php echo htmlspecialchars(t_def('group_loop.stop', 'Stop')); ?></button>
                            </div>

                            <div class="preview-progress">
                                <div class="preview-progress-bar" id="progressBar" style="width: 0%;">
                                    <span id="progressText">0s / 0s</span>
                                </div>
                            </div>

                            <div class="preview-navigation">
                                <button class="preview-nav-btn" id="btnPrev" onclick="previousModule()" title="<?php echo htmlspecialchars(t_def('group_loop.prev_module', 'Previous module')); ?>">◄</button>
                                <div class="preview-nav-info" id="navInfo">—</div>
                                <button class="preview-nav-btn" id="btnNext" onclick="nextModule()" title="<?php echo htmlspecialchars(t_def('group_loop.next_module', 'Next module')); ?>">►</button>
                            </div>

                            <div class="preview-info" id="previewInfo">
                                <div class="preview-info-row">
                                    <span class="preview-info-label"><?php echo htmlspecialchars(t_def('group_loop.current_module', 'Current module')); ?>:</span>
                                    <span class="preview-info-value" id="currentModule">—</span>
                                </div>
                                <div class="preview-info-row">
                                    <span class="preview-info-label"><?php echo htmlspecialchars(t_def('group_loop.loop_status', 'Loop status')); ?>:</span>
                                    <span class="preview-info-value" id="loopStatus"><?php echo htmlspecialchars(t_def('group_loop.stopped', 'Stopped')); ?></span>
                                </div>
                                <div class="preview-info-row">
                                    <span class="preview-info-label"><?php echo htmlspecialchars(t_def('group_loop.cycles', 'Cycles')); ?>:</span>
                                    <span class="preview-info-value" id="loopCount">0</span>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>



<div id="time-block-modal-host"></div>

<script>
window.SpecialPlannerBootstrap = <?php echo json_encode([
    'groupId' => (int)$requested_group_id,
    'groupName' => $selected_group_name,
    'ym' => $requested_ym,
    'returnUrl' => 'quick_messages.php?group_id=' . rawurlencode((string)$requested_group_id) . '&ym=' . rawurlencode($requested_ym),
    'specialStyles' => array_values($selected_special_styles),
    'specialBlocks' => array_values($selected_special_blocks),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
window.GroupLoopBootstrap = <?php echo json_encode([
    'groupId' => (int)$requested_group_id,
    'companyId' => (int)$company_id,
    'isDefaultGroup' => false,
    'isContentOnlyMode' => false,
    'specialOnly' => true,
    'autoCreateSpecialLoop' => $special_workflow_mode,
    'specialWorkflowStart' => $special_workflow_start,
    'specialWorkflowEnd' => $special_workflow_end,
    'forcedSpecialLoopName' => $forced_special_loop_name,
    'technicalModule' => $unconfigured_module ? [
        'id' => (int)$unconfigured_module['id'],
        'name' => edudisplej_group_loop_module_name($unconfigured_module),
        'description' => $unconfigured_module['description'] ?? ''
    ] : null,
    'turnedOffLoopAction' => $turned_off_loop_action,
    'modulesCatalog' => $group_loop_modules_catalog,
    'localizedModuleNames' => $group_loop_localized_module_names,
    'i18n' => [
        'group_loop.customization' => t_def('group_loop.customization', 'Customization'),
        'group_loop.header.title' => t_def('group_loop.header.title', 'Loop customization'),
        'group_loop.header.group' => t_def('group_loop.header.group', 'Group'),
        'group_loop.title' => t_def('group_loop.title', 'Skupinové slučky'),
        'group_loop.selected_loop_list' => t_def('group_loop.selected_loop_list', 'Selected loop list'),
        'group_loop.new_loop' => t_def('group_loop.new_loop', 'New loop'),
        'group_loop.delete_loop' => t_def('group_loop.delete_loop', 'Delete loop'),
        'group_loop.placeholder.select_loop_first' => t_def('group_loop.placeholder.select_loop_first', 'First, select a loop list from the dropdown. Then the module catalog, active loop editor and preview will appear.'),
        'group_loop.module_catalog' => t_def('group_loop.module_catalog', 'Module catalog'),
        'group_loop.placeholder.modules_after_select' => t_def('group_loop.placeholder.modules_after_select', 'Available modules appear only after selecting a loop.'),
        'group_loop.available_modules' => t_def('group_loop.available_modules', 'Available modules'),
        'group_loop.toggle_description' => t_def('group_loop.toggle_description', 'Toggle description'),
        'group_loop.default_only_unconfigured' => t_def('group_loop.default_only_unconfigured', 'Only the unconfigured module is allowed for default group.'),
        'group_loop.no_modules' => t_def('group_loop.no_modules', 'No available modules'),
        'group_loop.technical_module_desc' => t_def('group_loop.technical_module_desc', 'Technical module – only for empty loop fallback.'),
        'group_loop.edited_loop' => t_def('group_loop.edited_loop', 'Edited loop'),
        'group_loop.rename' => t_def('group_loop.rename', 'Rename'),
        'group_loop.duplicate' => t_def('group_loop.duplicate', 'Duplicate'),
        'group_loop.select_for_edit' => t_def('group_loop.select_for_edit', 'Select a loop from the dropdown list for editing.'),
        'group_loop.empty_drag_hint' => t_def('group_loop.empty_drag_hint', 'No items in loop. Drag modules here from the "Available modules" panel.'),
        'group_loop.clear_all' => t_def('group_loop.clear_all', 'Clear all'),
        'group_loop.total_duration_short' => t_def('group_loop.total_duration_short', 'Total: 0 sec'),
        'group_loop.preview' => t_def('group_loop.preview', 'Preview'),
        'group_loop.preview_title' => t_def('group_loop.preview_title', 'Loop preview'),
        'group_loop.preview_resolution' => t_def('group_loop.preview_resolution', 'Resolution preview'),
        'group_loop.no_loop' => t_def('group_loop.no_loop', 'No loop'),
        'group_loop.preview_add_modules' => t_def('group_loop.preview_add_modules', 'Add modules for loop preview'),
        'group_loop.play' => t_def('group_loop.play', 'Play'),
        'group_loop.pause' => t_def('group_loop.pause', 'Pause'),
        'group_loop.stop' => t_def('group_loop.stop', 'Stop'),
        'group_loop.prev_module' => t_def('group_loop.prev_module', 'Previous module'),
        'group_loop.next_module' => t_def('group_loop.next_module', 'Next module'),
        'group_loop.current_module' => t_def('group_loop.current_module', 'Current module'),
        'group_loop.loop_status' => t_def('group_loop.loop_status', 'Loop status'),
        'group_loop.stopped' => t_def('group_loop.stopped', 'Stopped'),
        'group_loop.cycles' => t_def('group_loop.cycles', 'Cycles')
    ]
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
</script>
<script src="group_loop/assets/js/app.js?v=<?php echo rawurlencode($group_loop_js_version_app); ?>"></script>
<script>
function openSpecialWorkflowStart() {
    var host = document.getElementById('time-block-modal-host');
    if (!host) {
        return;
    }

    host.innerHTML = `
        <div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; z-index:3200; padding:16px;">
            <div style="background:#fff; width:min(560px,96vw); border:1px solid #cfd6dd; padding:18px; border-radius:10px; box-shadow:0 20px 60px rgba(15,23,42,0.18);">
                <h3 style="margin:0 0 10px 0;">Speciális ütemezés - 1. Csoport kiválasztása</h3>
                <div style="font-size:12px; color:#425466; margin-bottom:14px; line-height:1.5;">Válassz egy csoportot, majd folytathatod az időszak megadásával.</div>
                <label for="workflow-group-select" style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Csoport</label>
                <select id="workflow-group-select" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px; margin-bottom:14px;">
                    <?php foreach ($groups as $group): ?>
                        <option value="<?php echo (int)$group['id']; ?>" <?php echo $requested_group_id === (int)$group['id'] ? 'selected' : ''; ?>><?php echo htmlspecialchars($group['name']); ?></option>
                    <?php endforeach; ?>
                </select>
                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" class="btn" onclick="closeTimeBlockModal()">Mégse</button>
                    <button type="button" class="btn btn-primary" onclick="continueSpecialWorkflowToStep2()">Tovább az időszak megadásához</button>
                </div>
            </div>
        </div>
    `;
}

function continueSpecialWorkflowToStep2() {
    var select = document.getElementById('workflow-group-select');
    var groupId = select ? String(select.value || '').trim() : '';
    if (!groupId) {
        return;
    }
    openSpecialWorkflowStep2(groupId);
}

function openSpecialWorkflowStep2(groupId) {
    var host = document.getElementById('time-block-modal-host');
    if (!host) {
        return;
    }

    var now = new Date();
    var nextHour = new Date(now);
    nextHour.setMinutes(0, 0, 0);
    nextHour.setHours(nextHour.getHours() + 1);
    var plus6h = new Date(nextHour.getTime() + (6 * 60 * 60 * 1000));

    var toLocalDate = function(dt) {
        var y = dt.getFullYear();
        var m = String(dt.getMonth() + 1).padStart(2, '0');
        var d = String(dt.getDate()).padStart(2, '0');
        return y + '-' + m + '-' + d;
    };

    var to24hTime = function(dt) {
        var hh = String(dt.getHours()).padStart(2, '0');
        var mm = String(dt.getMinutes()).padStart(2, '0');
        return hh + ':' + mm;
    };

    host.innerHTML = `
        <div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; z-index:3200; padding:16px;">
            <div style="background:#fff; width:min(560px,96vw); border:1px solid #cfd6dd; padding:18px; border-radius:10px; box-shadow:0 20px 60px rgba(15,23,42,0.18);">
                <h3 style="margin:0 0 10px 0;">Speciális ütemezés - 2. Időszak megadása</h3>
                <div style="font-size:12px; color:#425466; margin-bottom:14px; line-height:1.5;">Adj meg egy időszakot (tól-ig). A loop név automatikusan az időszak alapján jöhet létre.</div>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px;">
                    <div>
                        <label for="workflow-start-date" style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Kezdés nap</label>
                        <input type="date" id="workflow-start-date" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;" value="` + toLocalDate(nextHour) + `">
                    </div>
                    <div>
                        <label for="workflow-start-time" style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Kezdés idő (24h)</label>
                        <input type="text" id="workflow-start-time" inputmode="numeric" maxlength="5" pattern="^([01]\\d|2[0-3]):[0-5]\\d$" placeholder="HH:MM" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;" value="` + to24hTime(nextHour) + `">
                    </div>
                    <div>
                        <label for="workflow-end-date" style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Vége nap</label>
                        <input type="date" id="workflow-end-date" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;" value="` + toLocalDate(plus6h) + `">
                    </div>
                    <div>
                        <label for="workflow-end-time" style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Vége idő (24h)</label>
                        <input type="text" id="workflow-end-time" inputmode="numeric" maxlength="5" pattern="^([01]\\d|2[0-3]):[0-5]\\d$" placeholder="HH:MM" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;" value="` + to24hTime(plus6h) + `">
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" class="btn" onclick="openSpecialWorkflowStart()">Vissza a csoport kiválasztáshoz</button>
                    <button type="button" class="btn btn-primary" onclick="finishSpecialWorkflowSetupAndRedirect(` + groupId + `)">Tovább a loop szerkesztéshez</button>
                </div>
            </div>
        </div>
    `;
}

function finishSpecialWorkflowSetupAndRedirect(groupId) {
    var startDateEl = document.getElementById('workflow-start-date');
    var startTimeEl = document.getElementById('workflow-start-time');
    var endDateEl = document.getElementById('workflow-end-date');
    var endTimeEl = document.getElementById('workflow-end-time');
    
    var startDate = startDateEl ? String(startDateEl.value || '').trim() : '';
    var startTime = startTimeEl ? String(startTimeEl.value || '').trim() : '';
    var endDate = endDateEl ? String(endDateEl.value || '').trim() : '';
    var endTime = endTimeEl ? String(endTimeEl.value || '').trim() : '';
    var timePattern = /^([01]\d|2[0-3]):[0-5]\d$/;
    
    var startVal = startDate && startTime ? (startDate + 'T' + startTime) : '';
    var endVal = endDate && endTime ? (endDate + 'T' + endTime) : '';
    
    if (!startDate || !startTime || !endDate || !endTime) {
        alert('Add meg a kezdő és vég dátumot, valamint időt!');
        return;
    }

    if (!timePattern.test(startTime) || !timePattern.test(endTime)) {
        alert('Az időformátum 24h legyen: HH:MM');
        return;
    }
    
    var startTs = Date.parse(startVal);
    var endTs = Date.parse(endVal);
    if (!isFinite(startTs) || !isFinite(endTs) || endTs <= startTs) {
        alert('Az időszak vége legyen később a kezdésnél!');
        return;
    }
    
    var target = 'quick_messages.php?group_id=' + encodeURIComponent(groupId)
        + '&ym=<?php echo urlencode($requested_ym); ?>&workflow=1'
        + '&wf_start=' + encodeURIComponent(startVal)
        + '&wf_end=' + encodeURIComponent(endVal);
    window.location.href = target;
}

function closeTimeBlockModal() {
    var host = document.getElementById('time-block-modal-host');
    if (host) {
        host.innerHTML = '';
    }
}

function getSpecialPlannerBootstrap() {
    return window.SpecialPlannerBootstrap || { groupId: 0, specialStyles: [], specialBlocks: [], returnUrl: 'quick_messages.php' };
}

function getSpecialBlockById(blockId) {
    var normalized = parseInt(blockId, 10);
    if (!normalized) {
        return null;
    }
    var bootstrap = getSpecialPlannerBootstrap();
    return (bootstrap.specialBlocks || []).find(function (block) {
        return parseInt(block.id, 10) === normalized;
    }) || null;
}

function getSpecialStyleById(styleId, fallbackName) {
    var normalized = parseInt(styleId, 10);
    var bootstrap = getSpecialPlannerBootstrap();
    var style = (bootstrap.specialStyles || []).find(function (entry) {
        return parseInt(entry.id, 10) === normalized;
    }) || null;
    return style ? String(style.name || fallbackName || 'Loop') : String(fallbackName || 'Loop');
}

function isTurnedOffSpecialStyle(style) {
    if (!style || typeof style !== 'object') {
        return false;
    }
    var name = String(style.name || '').trim().toLowerCase();
    if (name === 'turned off') {
        return true;
    }
    var items = Array.isArray(style.items) ? style.items : [];
    return items.length === 1 && String(items[0].module_key || '').toLowerCase() === 'turned-off';
}

function openSpecialEventEditor(blockId) {
    var block = getSpecialBlockById(blockId);
    var host = document.getElementById('time-block-modal-host');
    if (!host || !block) {
        return;
    }

    var bootstrap = getSpecialPlannerBootstrap();
    var styleOptions = (bootstrap.specialStyles || []).map(function (style) {
        var selected = parseInt(style.id, 10) === parseInt(block.loop_style_id || 0, 10) ? ' selected' : '';
        return '<option value="' + String(style.id) + '"' + selected + '>' + String(style.name || 'Loop') + '</option>';
    }).join('');

    var isRange = String(block.block_type || 'date') === 'datetime_range';
    var friendlyName = String(block.friendly_name || block.block_name || '').trim();
    var eventPurpose = String(block.event_purpose || '').trim();
    var startDate = isRange ? String(block.start_datetime || '').slice(0, 10) : String(block.specific_date || '').slice(0, 10);
    var endDate = isRange ? String(block.end_datetime || '').slice(0, 10) : String(block.specific_date || '').slice(0, 10);
    var startTime = isRange ? String(block.start_datetime || '').slice(11, 16) : String(block.start_time || '').slice(0, 5);
    var endTime = isRange ? String(block.end_datetime || '').slice(11, 16) : String(block.end_time || '').slice(0, 5);

    host.innerHTML = `
        <div style="position:fixed; inset:0; background:rgba(0,0,0,0.45); display:flex; align-items:center; justify-content:center; z-index:3200; padding:16px;">
            <div style="background:#fff; width:min(640px,96vw); border:1px solid #cfd6dd; padding:18px; border-radius:10px; box-shadow:0 20px 60px rgba(15,23,42,0.18);">
                <h3 style="margin:0 0 10px 0;">Speciális esemény szerkesztése</h3>
                <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px; margin-bottom:14px;">
                    <div style="grid-column:1 / -1;">
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Név</label>
                        <input id="special-edit-name" type="text" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;" value="${friendlyName.replace(/"/g, '&quot;')}">
                    </div>
                    <div style="grid-column:1 / -1;">
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Cél / megjegyzés</label>
                        <input id="special-edit-purpose" type="text" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;" value="${eventPurpose.replace(/"/g, '&quot;')}" placeholder="Pl. vizsgaidőszak, rendezvény, karbantartás">
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Típus</label>
                        <select id="special-edit-type" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;">
                            <option value="date"${isRange ? '' : ' selected'}>Dátum</option>
                            <option value="datetime_range"${isRange ? ' selected' : ''}>Intervallum</option>
                        </select>
                    </div>
                    <div>
                        <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Loop</label>
                        <select id="special-edit-style" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;">
                            ${styleOptions}
                        </select>
                    </div>
                    <div class="special-edit-range-wrap" style="grid-column:1 / -1; ${isRange ? '' : 'display:none;'}">
                        <div style="display:grid; grid-template-columns:1fr 1fr; gap:10px;">
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Kezdés</label>
                                <input id="special-edit-start-datetime" type="datetime-local" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;" value="${String(block.start_datetime || '').replace(' ', 'T').slice(0, 16)}">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Vége</label>
                                <input id="special-edit-end-datetime" type="datetime-local" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;" value="${String(block.end_datetime || '').replace(' ', 'T').slice(0, 16)}">
                            </div>
                        </div>
                    </div>
                    <div class="special-edit-date-time-wrap" style="grid-column:1 / -1; ${isRange ? 'display:none;' : ''}">
                        <div style="display:grid; grid-template-columns:1fr 1fr 1fr; gap:10px;">
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Dátum</label>
                                <input id="special-edit-date" type="date" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;" value="${startDate}">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Kezdés idő</label>
                                <input id="special-edit-start-time" type="text" inputmode="numeric" maxlength="5" placeholder="HH:MM" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;" value="${startTime}">
                            </div>
                            <div>
                                <label style="display:block; font-size:12px; font-weight:600; margin-bottom:6px;">Vége idő</label>
                                <input id="special-edit-end-time" type="text" inputmode="numeric" maxlength="5" placeholder="HH:MM" style="width:100%; padding:8px 10px; border:1px solid #cfd6dd; border-radius:6px;" value="${endTime}">
                            </div>
                        </div>
                    </div>
                </div>
                <div style="display:flex; justify-content:flex-end; gap:8px;">
                    <button type="button" class="btn" onclick="closeTimeBlockModal()">Mégse</button>
                    <button type="button" class="btn btn-primary" onclick="saveSpecialEventEditor(${parseInt(block.id, 10)})">Mentés</button>
                </div>
            </div>
        </div>
    `;

    var typeSelect = document.getElementById('special-edit-type');
    if (typeSelect) {
        typeSelect.addEventListener('change', function () {
            var isDatetimeRange = this.value === 'datetime_range';
            var dateWrap = document.querySelector('.special-edit-date-wrap');
            var rangeWrap = document.querySelector('.special-edit-range-wrap');
            var simpleWrap = document.querySelector('.special-edit-date-time-wrap');
            if (dateWrap) {
                dateWrap.style.display = isDatetimeRange ? 'none' : '';
            }
            if (rangeWrap) {
                rangeWrap.style.display = isDatetimeRange ? '' : 'none';
            }
            if (simpleWrap) {
                simpleWrap.style.display = isDatetimeRange ? 'none' : '';
            }
        });
    }
}

function buildSpecialPayloadFromEditor(blockId) {
    var block = getSpecialBlockById(blockId);
    if (!block) {
        return null;
    }

    var name = String(document.getElementById('special-edit-name')?.value || '').trim();
    var purpose = String(document.getElementById('special-edit-purpose')?.value || '').trim();
    var type = String(document.getElementById('special-edit-type')?.value || 'date').trim();
    var styleId = parseInt(document.getElementById('special-edit-style')?.value || '0', 10);

    if (!name || !styleId) {
        alert('Adj meg nevet és loopot.');
        return null;
    }

    var payloadBlock = null;
    if (type === 'datetime_range') {
        var startDatetime = String(document.getElementById('special-edit-start-datetime')?.value || '').trim();
        var endDatetime = String(document.getElementById('special-edit-end-datetime')?.value || '').trim();
        if (!startDatetime || !endDatetime) {
            alert('Adj meg kezdő és záró időpontot.');
            return null;
        }
        var startTs = Date.parse(startDatetime);
        var endTs = Date.parse(endDatetime);
        if (!Number.isFinite(startTs) || !Number.isFinite(endTs) || endTs <= startTs) {
            alert('Az intervallum vége legyen később a kezdésnél.');
            return null;
        }
        var startObj = new Date(startTs);
        var endObj = new Date(endTs);
        var startDate = `${startObj.getFullYear()}-${String(startObj.getMonth() + 1).padStart(2, '0')}-${String(startObj.getDate()).padStart(2, '0')}`;
        payloadBlock = {
            ...block,
            block_name: name,
            friendly_name: name,
            event_purpose: purpose,
            block_type: 'datetime_range',
            specific_date: startDate,
            start_datetime: `${startDate} ${String(startObj.getHours()).padStart(2, '0')}:${String(startObj.getMinutes()).padStart(2, '0')}:00`,
            end_datetime: `${String(endObj.getFullYear())}-${String(endObj.getMonth() + 1).padStart(2, '0')}-${String(endObj.getDate()).padStart(2, '0')} ${String(endObj.getHours()).padStart(2, '0')}:${String(endObj.getMinutes()).padStart(2, '0')}:00`,
            start_time: `${String(startObj.getHours()).padStart(2, '0')}:${String(startObj.getMinutes()).padStart(2, '0')}:00`,
            end_time: `${String(endObj.getHours()).padStart(2, '0')}:${String(endObj.getMinutes()).padStart(2, '0')}:00`,
            loop_style_id: styleId,
            is_active: 1,
            priority: 400
        };
    } else {
        var startDateVal = String(document.getElementById('special-edit-date')?.value || '').trim();
        var startTimeVal = String(document.getElementById('special-edit-start-time')?.value || '').trim();
        var endTimeVal = String(document.getElementById('special-edit-end-time')?.value || '').trim();
        if (!startDateVal || !startTimeVal || !endTimeVal) {
            alert('Adj meg dátumot és időt.');
            return null;
        }
        payloadBlock = {
            ...block,
            block_name: name,
            friendly_name: name,
            event_purpose: purpose,
            block_type: 'date',
            specific_date: startDateVal,
            start_time: `${startTimeVal}:00`,
            end_time: `${endTimeVal}:00`,
            loop_style_id: styleId,
            is_active: 1,
            priority: 300
        };
    }

    return payloadBlock;
}

function saveSpecialPlanPayload(updatedBlocks, updatedStyles) {
    var bootstrap = getSpecialPlannerBootstrap();
    var payload = {
        specialStyles: updatedStyles,
        specialBlocks: updatedBlocks
    };

    return fetch(`../api/group_calendar/events.php?group_id=${encodeURIComponent(String(bootstrap.groupId || 0))}`, {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify(payload)
    }).then((response) => response.json());
}

function refreshSpecialPlannerPage() {
    var bootstrap = getSpecialPlannerBootstrap();
    window.location.href = bootstrap.returnUrl || 'quick_messages.php';
}

function saveSpecialEventEditor(blockId) {
    var updatedBlock = buildSpecialPayloadFromEditor(blockId);
    if (!updatedBlock) {
        return;
    }

    var bootstrap = getSpecialPlannerBootstrap();
    var updatedBlocks = (bootstrap.specialBlocks || []).map(function (block) {
        return parseInt(block.id, 10) === parseInt(blockId, 10) ? updatedBlock : block;
    });
    var updatedStyles = (bootstrap.specialStyles || []).slice();

    saveSpecialPlanPayload(updatedBlocks, updatedStyles).then(function (data) {
        if (data && data.success) {
            refreshSpecialPlannerPage();
            return;
        }
        alert((data && data.message) ? data.message : 'A mentés nem sikerült.');
    }).catch(function () {
        alert('A mentés nem sikerült.');
    });
}

function repeatSpecialEvent(blockId) {
    var block = getSpecialBlockById(blockId);
    if (!block) {
        return;
    }

    var bootstrap = getSpecialPlannerBootstrap();
    var updatedBlocks = (bootstrap.specialBlocks || []).slice();
    var copy = { ...block };
    copy.id = -Date.now();
    copy.is_active = 1;

    var oneWeekMs = 7 * 24 * 60 * 60 * 1000;
    if (String(copy.block_type || 'date') === 'datetime_range') {
        var startTs = Date.parse(String(copy.start_datetime || '').replace(' ', 'T'));
        var endTs = Date.parse(String(copy.end_datetime || '').replace(' ', 'T'));
        if (!Number.isFinite(startTs) || !Number.isFinite(endTs)) {
            alert('Az ismétléshez érvénytelen intervallum adatok vannak.');
            return;
        }
        var nextStart = new Date(startTs + oneWeekMs);
        var nextEnd = new Date(endTs + oneWeekMs);
        var toPlanner = function (d) {
            return `${d.getFullYear()}-${String(d.getMonth() + 1).padStart(2, '0')}-${String(d.getDate()).padStart(2, '0')} ${String(d.getHours()).padStart(2, '0')}:${String(d.getMinutes()).padStart(2, '0')}:00`;
        };
        copy.start_datetime = toPlanner(nextStart);
        copy.end_datetime = toPlanner(nextEnd);
        copy.specific_date = `${nextStart.getFullYear()}-${String(nextStart.getMonth() + 1).padStart(2, '0')}-${String(nextStart.getDate()).padStart(2, '0')}`;
        copy.start_time = `${String(nextStart.getHours()).padStart(2, '0')}:${String(nextStart.getMinutes()).padStart(2, '0')}:00`;
        copy.end_time = `${String(nextEnd.getHours()).padStart(2, '0')}:${String(nextEnd.getMinutes()).padStart(2, '0')}:00`;
    } else {
        var dateText = String(copy.specific_date || '').trim();
        var baseDate = Date.parse(`${dateText}T00:00:00`);
        if (!Number.isFinite(baseDate)) {
            alert('Az ismétléshez érvénytelen dátum található.');
            return;
        }
        var nextDate = new Date(baseDate + oneWeekMs);
        copy.specific_date = `${nextDate.getFullYear()}-${String(nextDate.getMonth() + 1).padStart(2, '0')}-${String(nextDate.getDate()).padStart(2, '0')}`;
    }

    updatedBlocks.push(copy);
    var updatedStyles = (bootstrap.specialStyles || []).slice();
    saveSpecialPlanPayload(updatedBlocks, updatedStyles).then(function (data) {
        if (data && data.success) {
            refreshSpecialPlannerPage();
            return;
        }
        alert((data && data.message) ? data.message : 'Az ismétlés mentése nem sikerült.');
    }).catch(function () {
        alert('Az ismétlés mentése nem sikerült.');
    });
}

function deleteSpecialEvent(blockId) {
    var block = getSpecialBlockById(blockId);
    if (!block) {
        return;
    }

    if (!confirm('Biztosan törlöd ezt a speciális eseményt?')) {
        return;
    }

    var bootstrap = getSpecialPlannerBootstrap();
    var updatedBlocks = (bootstrap.specialBlocks || []).filter(function (entry) {
        return parseInt(entry.id, 10) !== parseInt(blockId, 10);
    });
    var usedStyleIds = new Set(updatedBlocks.map(function (entry) {
        return parseInt(entry.loop_style_id || 0, 10);
    }));
    var updatedStyles = (bootstrap.specialStyles || []).filter(function (style) {
        return usedStyleIds.has(parseInt(style.id, 10));
    });

    saveSpecialPlanPayload(updatedBlocks, updatedStyles).then(function (data) {
        if (data && data.success) {
            refreshSpecialPlannerPage();
            return;
        }
        alert((data && data.message) ? data.message : 'A törlés nem sikerült.');
    }).catch(function () {
        alert('A törlés nem sikerült.');
    });
}

function openSpecialDayPlannerForDate(dateValue) {
    var normalized = String(dateValue || '').trim();
    if (normalized) {
        var focus = document.getElementById('special-day-focus');
        if (focus) {
            focus.value = normalized;
        }
    }
    if (typeof openSpecialDayPlanner === 'function') {
        openSpecialDayPlanner();
    }
}

document.addEventListener('DOMContentLoaded', function () {
    var search = document.getElementById('special-date-search');
    var focus = document.getElementById('special-day-focus');

    if (search) {
        search.addEventListener('input', function () {
            if (typeof renderSpecialBlocksList === 'function') {
                renderSpecialBlocksList();
            }
        });
    }

    if (focus) {
        focus.addEventListener('change', function () {
            if (typeof renderSpecialBlocksList === 'function') {
                renderSpecialBlocksList();
            }
        });
    }

    if (typeof renderSpecialBlocksList === 'function') {
        renderSpecialBlocksList();
    }
});
</script>

<?php include '../admin/footer.php'; ?>
