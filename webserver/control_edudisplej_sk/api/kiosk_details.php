<?php
/**
 * Kiosk Details API
 * Returns detailed information about a kiosk
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../kiosk_status.php';
require_once '../auth_roles.php';
require_once '../i18n.php';

header('Content-Type: application/json');

$response = [
    'success' => false,
    'message' => ''
];

function normalize_version_text($value) {
    $text = trim((string)$value);
    if ($text === '') {
        return '';
    }

    $normalized = strtolower($text);
    $invalid_values = ['unknown', 'ismeretlen', 'n/a', 'na', '-', '--', 'null', 'undefined'];
    if (in_array($normalized, $invalid_values, true)) {
        return '';
    }

    return $text;
}

function extract_version_from_hw_info($hw_info) {
    if (!is_array($hw_info)) {
        return '';
    }

    $direct_keys = [
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
    ];

    foreach ($direct_keys as $key) {
        if (!array_key_exists($key, $hw_info)) {
            continue;
        }
        $candidate = normalize_version_text($hw_info[$key]);
        if ($candidate !== '') {
            return $candidate;
        }
    }

    foreach ($hw_info as $value) {
        if (is_array($value)) {
            $nested = extract_version_from_hw_info($value);
            if ($nested !== '') {
                return $nested;
            }
        }
    }

    return '';
}

function extract_version_from_payload($payload) {
    if (!is_array($payload)) {
        return '';
    }

    $candidate = extract_version_from_hw_info($payload);
    if ($candidate !== '') {
        return $candidate;
    }

    foreach ($payload as $value) {
        if (!is_array($value)) {
            continue;
        }
        $nested = extract_version_from_payload($value);
        if ($nested !== '') {
            return $nested;
        }
    }

    return '';
}

function resolve_kiosk_version(array $row): ?string {
    $version = normalize_version_text($row['version'] ?? '');
    if ($version !== '') {
        return $version;
    }

    $hw_info = json_decode((string)($row['hw_info'] ?? '{}'), true);
    if (is_array($hw_info)) {
        $version = extract_version_from_payload($hw_info);
        if ($version !== '') {
            return $version;
        }
    }

    $system_data = json_decode((string)($row['system_data'] ?? '{}'), true);
    if (is_array($system_data)) {
        $version = extract_version_from_payload($system_data);
        if ($version !== '') {
            return $version;
        }
    }

    $sync_data = json_decode((string)($row['sync_data'] ?? '{}'), true);
    if (is_array($sync_data)) {
        $version = extract_version_from_payload($sync_data);
        if ($version !== '') {
            return $version;
        }
    }

    return null;
}

function touch_kiosk_details_activity(mysqli $conn, int $user_id): void {
    if ($user_id <= 0) {
        return;
    }

    $stmt = $conn->prepare("UPDATE users SET last_activity_at = NOW() WHERE id = ?");
    if (!$stmt) {
        return;
    }

    $stmt->bind_param('i', $user_id);
    $stmt->execute();
    $stmt->close();
}

function format_eta_seconds(int $seconds): string {
    $seconds = max(0, $seconds);
    if ($seconds < 60) {
        return $seconds . 's';
    }

    $minutes = intdiv($seconds, 60);
    $remaining_seconds = $seconds % 60;
    if ($minutes < 60) {
        return $remaining_seconds > 0 ? ($minutes . 'm ' . $remaining_seconds . 's') : ($minutes . 'm');
    }

    $hours = intdiv($minutes, 60);
    $remaining_minutes = $minutes % 60;
    return $remaining_minutes > 0 ? ($hours . 'h ' . $remaining_minutes . 'm') : ($hours . 'h');
}

function estimate_next_sync_eta_text(array $row): ?string {
    $last_sync_ts = !empty($row['last_sync']) ? strtotime((string)$row['last_sync']) : false;
    $sync_interval = (int)($row['sync_interval'] ?? 0);

    $sync_data = json_decode((string)($row['sync_data'] ?? '{}'), true);
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

    if ($next_sync_ts === null) {
        return null;
    }

    $eta_seconds = max(0, $next_sync_ts - time());
    return 'ETA: ~' . format_eta_seconds($eta_seconds) . ' (' . date('Y-m-d H:i:s', $next_sync_ts) . ')';
}

function normalize_loop_version_value($value) {
    if ($value === null || $value === '') {
        return null;
    }

    if (is_numeric($value)) {
        $num = (string)$value;
        if (strlen($num) === 14) {
            return $num;
        }

        $ts = (int)$value;
        if ($ts > 0) {
            return date('YmdHis', $ts);
        }
    }

    $ts = strtotime((string)$value);
    if ($ts === false) {
        return null;
    }

    return date('YmdHis', $ts);
}

function parse_loop_version_timestamp($value) {
    $normalized = normalize_loop_version_value($value);
    if ($normalized === null) {
        return null;
    }

    $dt = DateTimeImmutable::createFromFormat('YmdHis', $normalized);
    if ($dt instanceof DateTimeImmutable) {
        return $dt->getTimestamp();
    }

    return null;
}

function normalize_screenshot_url($raw_url) {
    if ($raw_url === null) {
        return null;
    }

    $path = trim((string)$raw_url);
    if ($path === '') {
        return null;
    }

    $path = str_replace('\\', '/', $path);
    $path = preg_replace('/^[^A-Za-z0-9\/._-]+/u', '', $path);
    if ($path === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    if (strpos($path, 'screenshots/') === 0) {
        return '../' . $path;
    }

    if (preg_match('#(^|/)screenshots/([^/]+)$#i', $path, $matches)) {
        return '../screenshots/' . $matches[2];
    }

    if (preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g|webp|gif)$/i', $path)) {
        return '../screenshots/' . $path;
    }

    return '../' . ltrim($path, '/');
}

function edudisplej_translate_module_name(string $module_key, string $fallback_name): string {
    $map = [
        'default-logo' => 'group_loop.module_name.default_logo',
        'meal-menu' => 'group_loop.module_name.meal_menu',
        'clock' => 'group_loop.module_name.clock',
        'text' => 'group_loop.module_name.text',
        'pdf' => 'group_loop.module_name.pdf',
        'image-gallery' => 'group_loop.module_name.image_gallery',
        'video' => 'group_loop.module_name.video',
        'room-occupancy' => 'group_loop.module_name.room_occupancy',
        'unconfigured' => 'group_loop.module_name.unconfigured',
    ];

    $normalized = strtolower(trim($module_key));
    if ($normalized !== '' && isset($map[$normalized])) {
        return t_def($map[$normalized], $fallback_name);
    }

    return $fallback_name;
}

// Check authentication
if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    $response['message'] = 'Not authenticated';
    echo json_encode($response);
    exit();
}

// Handle bulk refresh for dashboard
if (isset($_GET['refresh_list'])) {
    $kiosk_ids = array_map('intval', explode(',', $_GET['refresh_list']));
    $company_id = $_SESSION['company_id'] ?? null;
    $user_id = (int)($_SESSION['user_id'] ?? 0);
    
    try {
        $conn = getDbConnection();
        touch_kiosk_details_activity($conn, (int)$user_id);
        $placeholders = implode(',', array_fill(0, count($kiosk_ids), '?'));
        
        $query = "
                         SELECT k.id, k.hostname, k.last_seen, k.status, k.screenshot_url, k.screenshot_timestamp,
                   GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') as group_names,
                   k.version, k.screen_resolution, k.screen_status,
                                 k.hw_info, h.system_data, h.sync_data,
                 k.last_sync, k.loop_last_update,
                 (SELECT DATE_FORMAT(MAX(COALESCE(kgm.updated_at, kgm.created_at)), '%Y%m%d%H%i%s')
                 FROM kiosk_group_assignments kga2
                 JOIN kiosk_group_modules kgm ON kgm.group_id = kga2.group_id
                WHERE kga2.kiosk_id = k.id AND kgm.is_active = 1) AS group_server_loop_version,
                         (SELECT DATE_FORMAT(MAX(km.created_at), '%Y%m%d%H%i%s')
                 FROM kiosk_modules km
                WHERE km.kiosk_id = k.id AND km.is_active = 1) AS kiosk_server_loop_version
            FROM kiosks k 
            LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
            LEFT JOIN kiosk_groups g ON kga.group_id = g.id
            LEFT JOIN kiosk_health h ON k.id = h.kiosk_id AND h.timestamp = (
                SELECT MAX(timestamp) FROM kiosk_health WHERE kiosk_id = k.id
            )
            WHERE k.id IN ($placeholders) AND k.company_id = ?
            GROUP BY k.id
        ";
        
        $stmt = $conn->prepare($query);
        $types = str_repeat('i', count($kiosk_ids)) . 'i';
        $params = array_merge($kiosk_ids, [$company_id]);
        $stmt->bind_param($types, ...$params);
        $stmt->execute();
        $result = $stmt->get_result();
        
        $kiosks = [];
        while ($row = $result->fetch_assoc()) {
            kiosk_apply_effective_status($row);

            $kiosk_loop_version = normalize_loop_version_value($row['loop_last_update'] ?? null);
            $server_loop_version = normalize_loop_version_value($row['group_server_loop_version'] ?? null)
                ?? normalize_loop_version_value($row['kiosk_server_loop_version'] ?? null);
            $kiosk_loop_ts = parse_loop_version_timestamp($kiosk_loop_version);
            $server_loop_ts = parse_loop_version_timestamp($server_loop_version);
            $server_loop_is_newer = false;
            if ($kiosk_loop_version !== null && $server_loop_version !== null && $kiosk_loop_version !== $server_loop_version) {
                if ($kiosk_loop_ts !== null && $server_loop_ts !== null) {
                    $server_loop_is_newer = $server_loop_ts > $kiosk_loop_ts;
                } else {
                    $server_loop_is_newer = strcmp((string)$server_loop_version, (string)$kiosk_loop_version) > 0;
                }
            }

            $loop_version_mismatch_raw = (
                ($row['status'] ?? '') !== 'offline'
                && $kiosk_loop_version !== null
                && $server_loop_version !== null
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
            } elseif ($loop_update_grace_active && (($row['status'] ?? '') !== 'offline')) {
                $row['status'] = 'online_pending';
            }

            $row['kiosk_loop_version'] = $kiosk_loop_version;
            $row['server_loop_version'] = $server_loop_version;
            $row['loop_version_mismatch'] = $loop_version_mismatch;
            $row['loop_update_grace_active'] = $loop_update_grace_active;

            $row['screenshot_url'] = !empty($row['screenshot_url'])
                ? ('../api/screenshot_file.php?kiosk_id=' . (int)$row['id'])
                : null;
            $row['version'] = resolve_kiosk_version($row);
            $row['next_sync_eta'] = estimate_next_sync_eta_text($row);
            $kiosks[] = $row;
        }
        $stmt->close();
        closeDbConnection($conn);
        
        echo json_encode([
            'success' => true,
            'kiosks' => $kiosks
        ]);
        exit();
        
    } catch (Exception $e) {
        echo json_encode([
            'success' => false,
            'message' => 'Database error: ' . $e->getMessage()
        ]);
        exit();
    }
}

$kiosk_id = intval($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$company_id = $_SESSION['company_id'] ?? null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents('php://input'), true);
    $kiosk_id_post = intval($data['id'] ?? 0);
    $friendly_name = trim((string)($data['friendly_name'] ?? ''));
    $location = trim((string)($data['location'] ?? ''));
    $group_id = isset($data['group_id']) ? intval($data['group_id']) : null;
    $screenshot_enabled = isset($data['screenshot_enabled']) ? (intval($data['screenshot_enabled']) ? 1 : 0) : null;

    if ($kiosk_id_post <= 0) {
        $response['message'] = 'Invalid kiosk ID';
        echo json_encode($response);
        exit();
    }

    try {
        $conn = getDbConnection();
        touch_kiosk_details_activity($conn, (int)$user_id);
        $conn->begin_transaction();

        $verify_stmt = $conn->prepare("SELECT id FROM kiosks WHERE id = ? AND company_id = ? LIMIT 1");
        $verify_stmt->bind_param("ii", $kiosk_id_post, $company_id);
        $verify_stmt->execute();
        $verify_result = $verify_stmt->get_result();
        $kiosk_exists = $verify_result->fetch_assoc();
        $verify_stmt->close();

        if (!$kiosk_exists) {
            $conn->rollback();
            $response['message'] = 'Kiosk not found or access denied';
            echo json_encode($response);
            exit();
        }

        $update_stmt = $conn->prepare("UPDATE kiosks SET friendly_name = ?, location = ? WHERE id = ? AND company_id = ?");
        $update_stmt->bind_param("ssii", $friendly_name, $location, $kiosk_id_post, $company_id);
        $update_stmt->execute();
        $update_stmt->close();

        if ($screenshot_enabled !== null) {
            $screenshot_stmt = $conn->prepare("UPDATE kiosks SET screenshot_enabled = ? WHERE id = ? AND company_id = ?");
            $screenshot_stmt->bind_param("iii", $screenshot_enabled, $kiosk_id_post, $company_id);
            $screenshot_stmt->execute();
            $screenshot_stmt->close();
        }

        if ($group_id !== null && $group_id > 0) {
            $group_stmt = $conn->prepare("SELECT id FROM kiosk_groups WHERE id = ? AND company_id = ? LIMIT 1");
            $group_stmt->bind_param("ii", $group_id, $company_id);
            $group_stmt->execute();
            $group_ok = $group_stmt->get_result()->fetch_assoc();
            $group_stmt->close();

            if (!$group_ok) {
                $conn->rollback();
                $response['message'] = 'Invalid group';
                echo json_encode($response);
                exit();
            }

            $delete_stmt = $conn->prepare("DELETE FROM kiosk_group_assignments WHERE kiosk_id = ?");
            $delete_stmt->bind_param("i", $kiosk_id_post);
            $delete_stmt->execute();
            $delete_stmt->close();

            $assign_stmt = $conn->prepare("INSERT INTO kiosk_group_assignments (kiosk_id, group_id) VALUES (?, ?)");
            $assign_stmt->bind_param("ii", $kiosk_id_post, $group_id);
            $assign_stmt->execute();
            $assign_stmt->close();
        }

        $conn->commit();
        closeDbConnection($conn);

        echo json_encode([
            'success' => true,
            'message' => 'Kiosk updated successfully'
        ]);
        exit();
    } catch (Exception $e) {
        if (isset($conn) && $conn instanceof mysqli) {
            $conn->rollback();
            closeDbConnection($conn);
        }
        $response['message'] = 'Database error: ' . $e->getMessage();
        error_log($e->getMessage());
        echo json_encode($response);
        exit();
    }
}

if (!$kiosk_id) {
    $response['message'] = 'Kiosk ID is required';
    echo json_encode($response);
    exit();
}

try {
    $conn = getDbConnection();
    touch_kiosk_details_activity($conn, (int)$user_id);
    
    // Get kiosk data with group information
    $stmt = $conn->prepare("
        SELECT k.*, 
               GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') as group_names,
                    GROUP_CONCAT(DISTINCT g.id SEPARATOR ',') as group_ids,
                    k.screenshot_requested_until,
                    h.system_data, h.sync_data,
                    (SELECT DATE_FORMAT(MAX(COALESCE(kgm.updated_at, kgm.created_at)), '%Y%m%d%H%i%s')
                        FROM kiosk_group_assignments kga2
                        JOIN kiosk_group_modules kgm ON kgm.group_id = kga2.group_id
                      WHERE kga2.kiosk_id = k.id AND kgm.is_active = 1) AS group_server_loop_version,
                    (SELECT DATE_FORMAT(MAX(km.created_at), '%Y%m%d%H%i%s')
                        FROM kiosk_modules km
                      WHERE km.kiosk_id = k.id AND km.is_active = 1) AS kiosk_server_loop_version
        FROM kiosks k 
        LEFT JOIN kiosk_group_assignments kga ON k.id = kga.kiosk_id
        LEFT JOIN kiosk_groups g ON kga.group_id = g.id
        LEFT JOIN kiosk_health h ON k.id = h.kiosk_id AND h.timestamp = (
            SELECT MAX(timestamp) FROM kiosk_health WHERE kiosk_id = k.id
        )
        WHERE k.id = ? AND k.company_id = ?
        GROUP BY k.id
    ");
    $stmt->bind_param("ii", $kiosk_id, $company_id);
    $stmt->execute();
    $result = $stmt->get_result();
    $kiosk = $result->fetch_assoc();
    $stmt->close();

    if ($kiosk) {
        kiosk_apply_effective_status($kiosk);

        $kiosk_loop_version = normalize_loop_version_value($kiosk['loop_last_update'] ?? null);
        $server_loop_version = normalize_loop_version_value($kiosk['group_server_loop_version'] ?? null)
            ?? normalize_loop_version_value($kiosk['kiosk_server_loop_version'] ?? null);
        $kiosk_loop_ts = parse_loop_version_timestamp($kiosk_loop_version);
        $server_loop_ts = parse_loop_version_timestamp($server_loop_version);
        $server_loop_is_newer = false;
        if ($kiosk_loop_version !== null && $server_loop_version !== null && $kiosk_loop_version !== $server_loop_version) {
            if ($kiosk_loop_ts !== null && $server_loop_ts !== null) {
                $server_loop_is_newer = $server_loop_ts > $kiosk_loop_ts;
            } else {
                $server_loop_is_newer = strcmp((string)$server_loop_version, (string)$kiosk_loop_version) > 0;
            }
        }

        $loop_version_mismatch_raw = (
            ($kiosk['status'] ?? '') !== 'offline'
            && $kiosk_loop_version !== null
            && $server_loop_version !== null
            && $server_loop_is_newer
        );

        $loop_update_grace_active = (
            $loop_version_mismatch_raw
            && $server_loop_ts !== null
            && (time() - $server_loop_ts) <= 900
        );

        $loop_version_mismatch = $loop_version_mismatch_raw && !$loop_update_grace_active;

        if ($loop_version_mismatch) {
            $kiosk['status'] = 'online_error';
        } elseif ($loop_update_grace_active && (($kiosk['status'] ?? '') !== 'offline')) {
            $kiosk['status'] = 'online_pending';
        }
    }
    
    if (!$kiosk) {
        $response['message'] = 'Kiosk not found or access denied';
        echo json_encode($response);
        exit();
    }
    
    $response['success'] = true;
    $response['id'] = $kiosk['id'];
    $response['hostname'] = $kiosk['hostname'];
    $response['friendly_name'] = $kiosk['friendly_name'] ?? null;
    $response['mac'] = $kiosk['mac'];
    $response['status'] = $kiosk['status'];
    $response['location'] = $kiosk['location'];
    $response['last_seen'] = $kiosk['last_seen'] ? date('Y-m-d H:i', strtotime($kiosk['last_seen'])) : 'Never';
    $response['sync_interval'] = (int)$kiosk['sync_interval'];
    $response['screenshot_enabled'] = (bool)$kiosk['screenshot_enabled'];
    $response['screenshot_url'] = !empty($kiosk['screenshot_url'])
        ? ('../api/screenshot_file.php?kiosk_id=' . (int)$kiosk_id)
        : null;
    $response['screenshot_timestamp'] = $kiosk['screenshot_timestamp'] ? date('Y-m-d H:i:s', strtotime($kiosk['screenshot_timestamp'])) : null;
    
    // Add group information
    $response['group_names'] = $kiosk['group_names'] ?? null;
    $response['group_ids'] = $kiosk['group_ids'] ?? null;
    
    // Add technical information
    $response['version'] = resolve_kiosk_version($kiosk);
    $response['screen_resolution'] = $kiosk['screen_resolution'] ?? null;
    $response['screen_status'] = $kiosk['screen_status'] ?? null;
    
    // Add sync timing information
    $response['last_sync'] = $kiosk['last_sync'] ? date('Y-m-d H:i:s', strtotime($kiosk['last_sync'])) : null;
    $response['next_sync_eta'] = estimate_next_sync_eta_text($kiosk);
    $response['loop_last_update'] = $kiosk['loop_last_update'] ? date('Y-m-d H:i:s', strtotime($kiosk['loop_last_update'])) : null;
    $response['kiosk_loop_version'] = $kiosk_loop_version ?? null;
    $response['server_loop_version'] = $server_loop_version ?? null;
    $response['loop_version_mismatch'] = $loop_version_mismatch ?? false;
    $response['loop_update_grace_active'] = $loop_update_grace_active ?? false;
    $response['screenshot_requested_until'] = $kiosk['screenshot_requested_until'] ?? null;
    $response['screenshot_watch_active'] = !empty($kiosk['screenshot_requested_until']) && strtotime((string)$kiosk['screenshot_requested_until']) > time();
    
    // Parse HW info
    if ($kiosk['hw_info']) {
        try {
            $hw_data = json_decode($kiosk['hw_info'], true);
            $response['hw_info'] = $hw_data;
        } catch (Exception $e) {
            $response['hw_info'] = null;
        }
    }
    
    // Get assigned modules
    $modules = [];

    $group_mod_query = "SELECT m.module_key, m.name
                        FROM kiosk_group_assignments kga
                        INNER JOIN kiosk_group_modules kgm ON kga.group_id = kgm.group_id
                        INNER JOIN modules m ON kgm.module_id = m.id
                        WHERE kga.kiosk_id = ? AND kgm.is_active = 1
                        ORDER BY kgm.display_order";
    $group_mod_stmt = $conn->prepare($group_mod_query);
    $group_mod_stmt->bind_param("i", $kiosk_id);
    $group_mod_stmt->execute();
    $group_mod_result = $group_mod_stmt->get_result();
    $module_seen = [];
    while ($mod = $group_mod_result->fetch_assoc()) {
        $module_key = (string)($mod['module_key'] ?? '');
        $module_name = (string)($mod['name'] ?? '');
        $translated_name = edudisplej_translate_module_name($module_key, $module_name);
        $dedupe_key = strtolower(trim($module_key !== '' ? $module_key : $translated_name));
        if ($dedupe_key !== '' && isset($module_seen[$dedupe_key])) {
            continue;
        }
        if ($dedupe_key !== '') {
            $module_seen[$dedupe_key] = true;
        }
        $modules[] = $translated_name;
    }
    $group_mod_stmt->close();

    if (empty($modules)) {
        $mod_query = "SELECT m.module_key, m.name
                      FROM modules m
                      INNER JOIN kiosk_modules km ON m.id = km.module_id
                      WHERE km.kiosk_id = ? AND km.is_active = 1
                      ORDER BY km.display_order";
        $mod_stmt = $conn->prepare($mod_query);
        $mod_stmt->bind_param("i", $kiosk_id);
        $mod_stmt->execute();
        $mod_result = $mod_stmt->get_result();

        while ($mod = $mod_result->fetch_assoc()) {
            $module_key = (string)($mod['module_key'] ?? '');
            $module_name = (string)($mod['name'] ?? '');
            $translated_name = edudisplej_translate_module_name($module_key, $module_name);
            $dedupe_key = strtolower(trim($module_key !== '' ? $module_key : $translated_name));
            if ($dedupe_key !== '' && isset($module_seen[$dedupe_key])) {
                continue;
            }
            if ($dedupe_key !== '') {
                $module_seen[$dedupe_key] = true;
            }
            $modules[] = $translated_name;
        }
        $mod_stmt->close();
    }
    
    $response['modules'] = $modules;
    
    closeDbConnection($conn);
    
} catch (Exception $e) {
    $response['message'] = 'Database error: ' . $e->getMessage();
    error_log($e->getMessage());
}

echo json_encode($response);
?>

