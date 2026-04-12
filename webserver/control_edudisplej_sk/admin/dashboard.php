<?php
/**
 * Admin Dashboard - Minimal Table View
 * Shows kiosks grouped by company with technical details
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once __DIR__ . '/db_autofix_bootstrap.php';
require_once '../kiosk_status.php';

if (!isset($_SESSION['user_id']) || !isset($_SESSION['isadmin']) || !$_SESSION['isadmin']) {
    header('Location: ../login.php');
    exit();
}

function format_datetime($value) {
    if (!$value) {
        return '-';
    }
    $ts = strtotime($value);
    if (!$ts) {
        return htmlspecialchars($value);
    }
    return date('Y-m-d H:i:s', $ts);
}

function format_percent($value) {
    if ($value === null || $value === '') {
        return '-';
    }
    return number_format((float)$value, 1) . '%';
}

function pick_value($data, $keys) {
    foreach ($keys as $key) {
        if (isset($data[$key]) && $data[$key] !== '' && $data[$key] !== null) {
            return $data[$key];
        }
    }
    return null;
}

function normalize_version_text($value) {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $normalized = strtolower($text);
    $invalid_values = ['unknown', 'ismeretlen', 'neznáme', 'nezname', 'n/a', 'na', '-', '--', 'null', 'undefined'];
    if (in_array($normalized, $invalid_values, true)) {
        return '';
    }

    return $text;
}

function normalize_core_version_text($value) {
    $text = normalize_version_text($value);
    if ($text === '') {
        return '';
    }

    if ($text[0] === 'v' || $text[0] === 'V') {
        $text = substr($text, 1);
    }

    return trim($text);
}

function core_version_sort_key($value) {
    $normalized = normalize_core_version_text($value);
    if ($normalized === '') {
        return '';
    }

    if (preg_match('/^\d{14}$/', $normalized)) {
        return '2' . $normalized;
    }

    if (preg_match('/^\d+\.\d+\.\d+$/', $normalized)) {
        [$major, $minor, $patch] = array_map('intval', explode('.', $normalized, 3));
        return sprintf('1%03d%03d%03d', $major, $minor, $patch);
    }

    if (preg_match('/^\d+$/', $normalized)) {
        return '1' . str_pad($normalized, 14, '0', STR_PAD_LEFT);
    }

    return '0' . strtolower($normalized);
}

function is_core_version_older($current, $target) {
    $current_key = core_version_sort_key($current);
    $target_key = core_version_sort_key($target);

    if ($current_key === '' || $target_key === '') {
        return false;
    }

    return strcmp($current_key, $target_key) < 0;
}

function extract_version_recursive($data) {
    if (!is_array($data)) {
        return '';
    }

    $keys = [
        'version',
        'app_version',
        'software_version',
        'appVersion',
        'softwareVersion',
        'client_version',
        'kiosk_version',
        'build_version',
        'buildVersion',
        'app_build',
        'release',
        'edudisplej_version',
    ];

    foreach ($keys as $key) {
        if (!array_key_exists($key, $data)) {
            continue;
        }
        $candidate = normalize_version_text($data[$key]);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    foreach ($data as $value) {
        if (!is_array($value)) {
            continue;
        }
        $nested = extract_version_recursive($value);
        if ($nested !== '') {
            return $nested;
        }
    }

    return '';
}

function format_eta_seconds($seconds) {
    $seconds = max(0, (int)$seconds);
    if ($seconds < 60) {
        return $seconds . 's';
    }

    $minutes = intdiv($seconds, 60);
    $remaining = $seconds % 60;
    if ($minutes < 60) {
        return $remaining > 0 ? ($minutes . 'm ' . $remaining . 's') : ($minutes . 'm');
    }

    $hours = intdiv($minutes, 60);
    $minutes = $minutes % 60;
    return $minutes > 0 ? ($hours . 'h ' . $minutes . 'm') : ($hours . 'h');
}

function estimate_next_sync_eta($last_sync, $sync_interval, $sync_data_json) {
    $last_sync_ts = $last_sync ? strtotime((string)$last_sync) : false;
    $sync_interval = (int)$sync_interval;
    $sync_data = json_decode((string)$sync_data_json, true);
    if (!is_array($sync_data)) {
        $sync_data = [];
    }

    $next_sync_ts = null;

    if (!empty($sync_data['next_sync_at'])) {
        $candidate = strtotime((string)$sync_data['next_sync_at']);
        if ($candidate !== false) {
            $next_sync_ts = $candidate;
        }
    }

    if ($next_sync_ts === null && isset($sync_data['next_sync_in']) && is_numeric($sync_data['next_sync_in'])) {
        $next_sync_ts = time() + (int)$sync_data['next_sync_in'];
    }

    if ($next_sync_ts === null && $last_sync_ts !== false && $sync_interval > 0) {
        $next_sync_ts = $last_sync_ts + $sync_interval;
    }

    if ($next_sync_ts === null && $last_sync_ts !== false) {
        $avg_keys = ['avg_interval', 'avg_interval_sec', 'avg_interval_seconds'];
        foreach ($avg_keys as $avg_key) {
            if (!isset($sync_data[$avg_key]) || !is_numeric($sync_data[$avg_key])) {
                continue;
            }
            $avg_interval = (int)$sync_data[$avg_key];
            if ($avg_interval > 0) {
                $next_sync_ts = $last_sync_ts + $avg_interval;
                break;
            }
        }
    }

    if ($next_sync_ts === null) {
        return null;
    }

    $eta_seconds = max(0, $next_sync_ts - time());
    return 'ETA: ~' . format_eta_seconds($eta_seconds) . ' (' . date('Y-m-d H:i:s', $next_sync_ts) . ')';
}

function format_last_reboot($uptime_seconds) {
    if ($uptime_seconds === null || $uptime_seconds === '') {
        return '-';
    }
    $uptime_seconds = (int)$uptime_seconds;
    if ($uptime_seconds <= 0) {
        return '-';
    }
    $reboot_ts = time() - $uptime_seconds;
    return date('Y-m-d H:i:s', $reboot_ts);
}

$error = '';
$companies = [];
$kiosks = [];
$company_license_overview = [];
$latest_core_version = '1.1.0';

$versions_file = dirname(__DIR__, 2) . '/install/init/versions.json';
if (is_file($versions_file)) {
    $versions_data = json_decode((string)file_get_contents($versions_file), true);
    if (is_array($versions_data) && !empty($versions_data['system_version'])) {
        $latest_core_version = normalize_version_text($versions_data['system_version']);
    }
}

$filters = [
    'company_id' => isset($_GET['company_id']) ? (int)$_GET['company_id'] : 0,
    'status' => trim($_GET['status'] ?? ''),
    'overuse' => trim($_GET['overuse'] ?? ''),
    'search' => trim($_GET['search'] ?? ''),
    'min_cpu' => isset($_GET['min_cpu']) ? (float)$_GET['min_cpu'] : 0,
    'min_ram' => isset($_GET['min_ram']) ? (float)$_GET['min_ram'] : 0,
    'min_disk' => isset($_GET['min_disk']) ? (float)$_GET['min_disk'] : 0
];

try {
    $conn = getDbConnection();

    $result = $conn->query("SELECT id, name FROM companies ORDER BY name");
    while ($row = $result->fetch_assoc()) {
        $companies[] = $row;
    }

    $license_overview_result = $conn->query("SELECT
            c.id AS company_id,
            COALESCE((
                SELECT cl.device_limit
                FROM company_licenses cl
                WHERE cl.company_id = c.id AND cl.status = 'active'
                ORDER BY cl.valid_until DESC, cl.id DESC
                LIMIT 1
            ), 0) AS purchased,
            (
                SELECT COUNT(*)
                FROM kiosks k2
                WHERE k2.company_id = c.id AND k2.license_active = 1
            ) AS used
        FROM companies c");
    if ($license_overview_result) {
        while ($row = $license_overview_result->fetch_assoc()) {
            $cid = (int)($row['company_id'] ?? 0);
            if ($cid <= 0) {
                continue;
            }
            $company_license_overview[$cid] = [
                'purchased' => (int)($row['purchased'] ?? 0),
                'used' => (int)($row['used'] ?? 0),
            ];
        }
    }

    $query = "
        SELECT
            k.id,
            k.hostname,
            k.friendly_name,
            k.device_id,
            k.mac,
            k.public_ip,
            k.status,
            k.company_id,
            k.loop_last_update,
            k.last_sync,
            k.last_seen,
            k.upgrade_started_at,
            k.version,
            k.hw_info,
            k.screen_resolution,
            k.sync_interval,
            c.name AS company_name,
            h.system_data,
            h.network_data,
            h.sync_data,
            h.timestamp AS health_timestamp
        FROM kiosks k
        LEFT JOIN companies c ON k.company_id = c.id
        LEFT JOIN kiosk_health h ON k.id = h.kiosk_id AND h.timestamp = (
            SELECT MAX(timestamp) FROM kiosk_health WHERE kiosk_id = k.id
        )
        ORDER BY c.name ASC, k.hostname ASC
    ";

    $result = $conn->query($query);
    while ($row = $result->fetch_assoc()) {
        kiosk_apply_effective_status($row);
        $kiosks[] = $row;
    }

    closeDbConnection($conn);
} catch (Exception $e) {
    $error = 'Failed to load dashboard data';
    error_log('Dashboard error: ' . $e->getMessage());
}

$status_counts = [
    'online' => 0,
    'offline' => 0,
    'warning' => 0,
    'unconfigured' => 0,
    'upgrading' => 0,
    'error' => 0
];

$failed_kiosks = [];
foreach ($kiosks as $kiosk) {
    $status = (string)($kiosk['status'] ?? 'offline');
    if (!isset($status_counts[$status])) {
        $status_counts[$status] = 0;
    }
    $status_counts[$status]++;

    if ($status === 'error') {
        $failed_kiosks[] = $kiosk;
    }
}

$filtered = [];
foreach ($kiosks as $kiosk) {
    if ($filters['company_id'] && (int)$kiosk['company_id'] !== $filters['company_id']) {
        continue;
    }

    if ($filters['overuse'] !== '') {
        $filter_company_id = (int)($kiosk['company_id'] ?? 0);
        $filter_purchased = (int)($company_license_overview[$filter_company_id]['purchased'] ?? 0);
        $filter_used = (int)($company_license_overview[$filter_company_id]['used'] ?? 0);
        $filter_is_overused = $filter_used > $filter_purchased;

        if ($filters['overuse'] === '1' && !$filter_is_overused) {
            continue;
        }
        if ($filters['overuse'] === '0' && $filter_is_overused) {
            continue;
        }
    }

    if ($filters['status'] && $kiosk['status'] !== $filters['status']) {
        continue;
    }

    $search = strtolower($filters['search']);
    if ($search !== '') {
        $haystack = strtolower(
            ($kiosk['hostname'] ?? '') . ' ' .
            ($kiosk['friendly_name'] ?? '') . ' ' .
            ($kiosk['device_id'] ?? '') . ' ' .
            ($kiosk['mac'] ?? '') . ' ' .
            ($kiosk['public_ip'] ?? '') . ' ' .
            ($kiosk['company_name'] ?? '')
        );
        if (strpos($haystack, $search) === false) {
            continue;
        }
    }

    $system = json_decode($kiosk['system_data'] ?? '{}', true) ?: [];

    $cpu_usage = (float)($system['cpu_usage'] ?? 0);
    $ram_usage = (float)($system['memory_usage'] ?? 0);
    $disk_usage = (float)($system['disk_usage'] ?? 0);

    if ($filters['min_cpu'] > 0 && $cpu_usage < $filters['min_cpu']) {
        continue;
    }

    if ($filters['min_ram'] > 0 && $ram_usage < $filters['min_ram']) {
        continue;
    }

    if ($filters['min_disk'] > 0 && $disk_usage < $filters['min_disk']) {
        continue;
    }

    $filtered[] = $kiosk;
}

$grouped = [];
foreach ($filtered as $kiosk) {
    $company_id = (int)($kiosk['company_id'] ?? 0);
    $group_key = $company_id > 0 ? 'c_' . $company_id : 'unassigned';
    $company_name = $kiosk['company_name'] ?? 'Unassigned';

    if (!isset($grouped[$group_key])) {
        $grouped[$group_key] = [
            'company_id' => $company_id,
            'company_name' => $company_name,
            'kiosks' => [],
        ];
    }
    $grouped[$group_key]['kiosks'][] = $kiosk;
}

include 'header.php';
?>

<?php if ($error): ?>
    <div class="alert error"><?php echo htmlspecialchars($error); ?></div>
<?php endif; ?>

<div class="panel">
    <div class="panel-title">Overview</div>
    <div class="toolbar">
        <span class="badge success">Online: <?php echo (int)($status_counts['online'] ?? 0); ?></span>
        <span class="badge">Offline: <?php echo (int)($status_counts['offline'] ?? 0); ?></span>
        <span class="badge warning">Upgrading: <?php echo (int)($status_counts['upgrading'] ?? 0); ?></span>
        <span class="badge danger">Upgrade errors: <?php echo (int)($status_counts['error'] ?? 0); ?></span>
        <span class="badge info">Unconfigured: <?php echo (int)($status_counts['unconfigured'] ?? 0); ?></span>
        <span class="badge info">Core version: <?php echo htmlspecialchars($latest_core_version); ?></span>
    </div>

    <?php if (!empty($failed_kiosks)): ?>
        <div style="margin-top:12px;" class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Hostname</th>
                        <th>Institution</th>
                        <th>Status</th>
                        <th>Upgrade started</th>
                        <th>Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($failed_kiosks as $failed): ?>
                        <tr>
                            <td><?php echo (int)$failed['id']; ?></td>
                            <td><?php echo htmlspecialchars((string)($failed['hostname'] ?? '-')); ?></td>
                            <td><?php echo htmlspecialchars((string)($failed['company_name'] ?? '-')); ?></td>
                            <td><span class="badge danger">error</span></td>
                            <td><?php echo format_datetime($failed['upgrade_started_at'] ?? null); ?></td>
                            <td><?php echo format_datetime($failed['last_seen'] ?? null); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<div class="panel">
    <div class="panel-title">Filters</div>
    <form method="get" class="toolbar">
        <div class="form-field">
            <label for="company_id">Institution</label>
            <select id="company_id" name="company_id">
                <option value="">All</option>
                <?php foreach ($companies as $company): ?>
                    <option value="<?php echo (int)$company['id']; ?>" <?php echo $filters['company_id'] === (int)$company['id'] ? 'selected' : ''; ?>>
                        <?php echo htmlspecialchars($company['name']); ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-field">
            <label for="status">Status</label>
            <select id="status" name="status">
                <option value="">All</option>
                <option value="online" <?php echo $filters['status'] === 'online' ? 'selected' : ''; ?>>Online</option>
                <option value="warning" <?php echo $filters['status'] === 'warning' ? 'selected' : ''; ?>>Warning</option>
                <option value="offline" <?php echo $filters['status'] === 'offline' ? 'selected' : ''; ?>>Offline</option>
                <option value="upgrading" <?php echo $filters['status'] === 'upgrading' ? 'selected' : ''; ?>>Upgrading</option>
                <option value="error" <?php echo $filters['status'] === 'error' ? 'selected' : ''; ?>>Error</option>
                <option value="unconfigured" <?php echo $filters['status'] === 'unconfigured' ? 'selected' : ''; ?>>Unconfigured</option>
            </select>
        </div>
        <div class="form-field">
            <label for="overuse">License usage</label>
            <select id="overuse" name="overuse">
                <option value="" <?php echo $filters['overuse'] === '' ? 'selected' : ''; ?>>All</option>
                <option value="1" <?php echo $filters['overuse'] === '1' ? 'selected' : ''; ?>>Overuse only</option>
                <option value="0" <?php echo $filters['overuse'] === '0' ? 'selected' : ''; ?>>Within purchased</option>
            </select>
        </div>
        <div class="form-field" style="min-width: 220px;">
            <label for="search">Search</label>
            <input id="search" name="search" type="text" value="<?php echo htmlspecialchars($filters['search']); ?>" placeholder="hostname, mac, device id">
        </div>
        <div class="form-field">
            <label for="min_cpu">CPU min %</label>
            <input id="min_cpu" name="min_cpu" type="number" step="0.1" value="<?php echo htmlspecialchars((string)$filters['min_cpu']); ?>">
        </div>
        <div class="form-field">
            <label for="min_ram">RAM min %</label>
            <input id="min_ram" name="min_ram" type="number" step="0.1" value="<?php echo htmlspecialchars((string)$filters['min_ram']); ?>">
        </div>
        <div class="form-field">
            <label for="min_disk">Disk min %</label>
            <input id="min_disk" name="min_disk" type="number" step="0.1" value="<?php echo htmlspecialchars((string)$filters['min_disk']); ?>">
        </div>
        <div class="form-field">
            <button type="submit" class="btn btn-primary">Filter</button>
        </div>
        <div class="form-field">
            <a class="btn btn-secondary" href="dashboard.php">Reset</a>
        </div>
    </form>
</div>

<?php if (empty($grouped)): ?>
    <div class="panel">
        <div class="muted">No displays to show.</div>
    </div>
<?php endif; ?>

<?php foreach ($grouped as $group): ?>
    <?php
        $company_id = (int)($group['company_id'] ?? 0);
        $company_name = (string)($group['company_name'] ?? 'Unassigned');
        $company_kiosks = (array)($group['kiosks'] ?? []);
        $purchased = (int)($company_license_overview[$company_id]['purchased'] ?? 0);
        $used = (int)($company_license_overview[$company_id]['used'] ?? count($company_kiosks));
        $is_overused = $used > $purchased;
    ?>
    <div class="panel">
        <div class="panel-title">
            Institution: <?php echo htmlspecialchars($company_name); ?> (<?php echo count($company_kiosks); ?>)
            <?php if ($company_id > 0): ?>
                <span style="margin-left:10px; font-size:12px; font-weight:700; color: <?php echo $is_overused ? '#b23b3b' : '#1f7a39'; ?>;">
                    purchased: <?php echo $purchased; ?> / used: <?php echo $used; ?>
                </span>
                <?php if ($is_overused): ?>
                    <span class="badge" style="margin-left:8px; background:#b23b3b; color:#fff;">OVERUSE</span>
                <?php endif; ?>
            <?php endif; ?>
        </div>
        <div class="table-wrap">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Hostname</th>
                        <th>Device ID</th>
                        <th>Status</th>
                        <th>Details</th>
                        <th>Loop timestamp</th>
                        <th>Last sync</th>
                        <th>Last seen</th>
                        <th>Network name</th>
                        <th>Network strength</th>
                        <th>CPU temp</th>
                        <th>CPU %</th>
                        <th>RAM %</th>
                        <th>Disk %</th>
                        <th>RPI version</th>
                        <th>Core update</th>
                        <th>Screen resolution</th>
                        <th>Last reboot</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($company_kiosks as $kiosk): ?>
                        <?php
                            $system = json_decode($kiosk['system_data'] ?? '{}', true) ?: [];
                            $network = json_decode($kiosk['network_data'] ?? '{}', true) ?: [];
                            $hw_info = json_decode($kiosk['hw_info'] ?? '{}', true) ?: [];
                            $sync = json_decode($kiosk['sync_data'] ?? '{}', true) ?: [];
                            $status = $kiosk['status'] ?? 'unknown';
                            $badge_class = 'info';
                            if ($status === 'online') {
                                $badge_class = 'success';
                            } elseif ($status === 'warning') {
                                $badge_class = 'warning';
                            } elseif ($status === 'upgrading') {
                                $badge_class = 'warning';
                            } elseif ($status === 'offline') {
                                $badge_class = 'danger';
                            } elseif ($status === 'error') {
                                $badge_class = 'danger';
                            }
                            $network_name = pick_value($network, ['wifi_name', 'ssid', 'network_name']);
                            if ($network_name === null) {
                                $network_name = pick_value($hw_info, ['wifi_name', 'ssid', 'network_name']);
                            }

                            $network_signal = pick_value($network, ['wifi_signal', 'signal', 'rssi']);
                            if ($network_signal === null) {
                                $network_signal = pick_value($hw_info, ['wifi_signal', 'signal', 'rssi']);
                            }

                            $cpu_temp_value = pick_value($system, ['cpu_temp', 'temperature', 'temp']);
                            if ($cpu_temp_value === null) {
                                $cpu_temp_value = pick_value($hw_info, ['cpu_temp', 'temperature', 'temp']);
                            }

                            $cpu_usage_value = pick_value($system, ['cpu_usage']);
                            if ($cpu_usage_value === null) {
                                $cpu_usage_value = pick_value($hw_info, ['cpu_usage']);
                            }

                            $memory_usage_value = pick_value($system, ['memory_usage']);
                            if ($memory_usage_value === null) {
                                $memory_usage_value = pick_value($hw_info, ['memory_usage']);
                            }

                            $disk_usage_value = pick_value($system, ['disk_usage']);
                            if ($disk_usage_value === null) {
                                $disk_usage_value = pick_value($hw_info, ['disk_usage']);
                            }

                            $rpi_version = normalize_version_text($kiosk['version'] ?? '');
                            if ($rpi_version === '') {
                                $rpi_version = normalize_version_text(extract_version_recursive($system));
                            }
                            if ($rpi_version === '') {
                                $rpi_version = normalize_version_text(extract_version_recursive($hw_info));
                            }
                            if ($rpi_version === '') {
                                $rpi_version = normalize_version_text(extract_version_recursive($sync));
                            }
                            if ($rpi_version === '') {
                                $rpi_version = pick_value($system, ['rpi_model', 'os_version']);
                            }
                            if (!$rpi_version) {
                                $rpi_version = pick_value($hw_info, ['rpi_model', 'os_version', 'os']);
                            }

                            $uptime_seconds = pick_value($system, ['uptime', 'uptime_seconds']);
                            if ($uptime_seconds === null) {
                                $uptime_seconds = pick_value($hw_info, ['uptime_seconds']);
                            }
                            $last_reboot = format_last_reboot($uptime_seconds);

                            if ($network_name === null || $network_name === '') {
                                $network_name = '-';
                            }
                            if ($network_signal === null || $network_signal === '') {
                                $network_signal = '-';
                            }
                            if ($cpu_temp_value === null || $cpu_temp_value === '') {
                                $cpu_temp_value = '-';
                            }
                            if ($rpi_version === null || $rpi_version === '') {
                                $rpi_version = '-';
                            }

                            $needs_core_update = false;
                            if ($latest_core_version !== '' && $rpi_version !== '-') {
                                $needs_core_update = is_core_version_older($rpi_version, $latest_core_version);
                            }

                            $next_sync_eta = estimate_next_sync_eta(
                                $kiosk['last_sync'] ?? null,
                                $kiosk['sync_interval'] ?? 0,
                                $kiosk['sync_data'] ?? null
                            );
                        ?>
                        <tr>
                            <td class="nowrap"><?php echo (int)$kiosk['id']; ?></td>
                            <td>
                                <?php echo htmlspecialchars($kiosk['hostname'] ?? '-'); ?>
                                <?php if (!empty($kiosk['friendly_name'])): ?>
                                    <div class="muted"><?php echo htmlspecialchars($kiosk['friendly_name']); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="mono"><?php echo htmlspecialchars($kiosk['device_id'] ?? '-'); ?></td>
                            <td><span class="badge <?php echo $badge_class; ?>"><?php echo htmlspecialchars($status); ?></span></td>
                            <td class="nowrap"><a class="btn btn-small" href="kiosk_details.php?id=<?php echo (int)$kiosk['id']; ?>">View</a></td>
                            <td class="nowrap"><?php echo format_datetime($kiosk['loop_last_update'] ?? null); ?></td>
                            <td class="nowrap">
                                <?php echo format_datetime($kiosk['last_sync'] ?? null); ?>
                                <?php if ($next_sync_eta !== null): ?>
                                    <div class="muted" style="font-size:11px;"><?php echo htmlspecialchars($next_sync_eta); ?></div>
                                <?php endif; ?>
                            </td>
                            <td class="nowrap"><?php echo format_datetime($kiosk['last_seen'] ?? null); ?></td>
                            <td><?php echo htmlspecialchars((string)$network_name); ?></td>
                            <td><?php echo htmlspecialchars((string)$network_signal); ?></td>
                            <td><?php echo htmlspecialchars((string)$cpu_temp_value); ?></td>
                            <td><?php echo format_percent($cpu_usage_value); ?></td>
                            <td><?php echo format_percent($memory_usage_value); ?></td>
                            <td><?php echo format_percent($disk_usage_value); ?></td>
                            <td><?php echo htmlspecialchars((string)$rpi_version); ?></td>
                            <td class="nowrap">
                                <?php if ($needs_core_update): ?>
                                    <button
                                        type="button"
                                        class="btn btn-small btn-warning"
                                        data-kiosk-id="<?php echo (int)$kiosk['id']; ?>"
                                        data-hostname="<?php echo htmlspecialchars((string)($kiosk['hostname'] ?? '-')); ?>"
                                        data-target-version="<?php echo htmlspecialchars($latest_core_version); ?>"
                                        onclick="queueCoreUpdate(this)"
                                    >Force core update</button>
                                <?php else: ?>
                                    <span class="badge success">Current</span>
                                <?php endif; ?>
                            </td>
                            <td><?php echo htmlspecialchars($kiosk['screen_resolution'] ?? '-'); ?></td>
                            <td class="nowrap"><?php echo htmlspecialchars((string)$last_reboot); ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endforeach; ?>

<script>
async function queueCoreUpdate(button) {
    const kioskId = button.dataset.kioskId;
    const hostname = button.dataset.hostname || 'this kiosk';
    const targetVersion = button.dataset.targetVersion || '';
    const suffix = targetVersion ? ' to ' + targetVersion : '';

    if (!window.confirm('Queue a core-only update for ' + hostname + suffix + '?')) {
        return;
    }

    const originalText = button.textContent;
    button.disabled = true;
    button.textContent = 'Queueing...';

    try {
        const response = await fetch('../api/kiosk/queue_core_update.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json'
            },
            body: JSON.stringify({ kiosk_id: parseInt(kioskId, 10) })
        });

        const data = await response.json().catch(() => ({}));
        if (!response.ok || !data.success) {
            throw new Error(data.message || 'Failed to queue core update');
        }

        window.location.reload();
    } catch (error) {
        alert(error.message || 'Failed to queue core update');
        button.disabled = false;
        button.textContent = originalText;
    }
}
</script>

<?php include 'footer.php'; ?>
