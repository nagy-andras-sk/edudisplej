<?php
/**
 * Kiosk Details API
 * Returns detailed information about a kiosk
 */

session_start();
require_once '../dbkonfiguracia.php';
require_once '../kiosk_status.php';
require_once '../auth_roles.php';

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
    
    try {
        $conn = getDbConnection();
        $placeholders = implode(',', array_fill(0, count($kiosk_ids), '?'));
        
        $query = "
             SELECT k.id, k.hostname, k.last_seen, k.status, k.screenshot_url, k.screenshot_timestamp,
                   GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') as group_names,
                   k.version, k.screen_resolution, k.screen_status,
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
            $loop_version_mismatch_raw = (
                ($row['status'] ?? '') !== 'offline'
                && $kiosk_loop_version !== null
                && $server_loop_version !== null
                && $kiosk_loop_version !== $server_loop_version
            );

            $server_loop_ts = parse_loop_version_timestamp($server_loop_version);
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
    
    // Get kiosk data with group information
    $stmt = $conn->prepare("
        SELECT k.*, 
               GROUP_CONCAT(DISTINCT g.name SEPARATOR ', ') as group_names,
                    GROUP_CONCAT(DISTINCT g.id SEPARATOR ',') as group_ids,
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
        $loop_version_mismatch_raw = (
            ($kiosk['status'] ?? '') !== 'offline'
            && $kiosk_loop_version !== null
            && $server_loop_version !== null
            && $kiosk_loop_version !== $server_loop_version
        );

        $server_loop_ts = parse_loop_version_timestamp($server_loop_version);
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
    $resolved_version = normalize_version_text($kiosk['version'] ?? '');
    if ($resolved_version === '' && !empty($kiosk['hw_info'])) {
        $hw_info = json_decode($kiosk['hw_info'], true);
        if (is_array($hw_info)) {
            $resolved_version = extract_version_from_hw_info($hw_info);
        }
    }
    $response['version'] = $resolved_version !== '' ? $resolved_version : null;
    $response['screen_resolution'] = $kiosk['screen_resolution'] ?? null;
    $response['screen_status'] = $kiosk['screen_status'] ?? null;
    
    // Add sync timing information
    $response['last_sync'] = $kiosk['last_sync'] ? date('Y-m-d H:i:s', strtotime($kiosk['last_sync'])) : null;
    $response['loop_last_update'] = $kiosk['loop_last_update'] ? date('Y-m-d H:i:s', strtotime($kiosk['loop_last_update'])) : null;
    $response['kiosk_loop_version'] = $kiosk_loop_version ?? null;
    $response['server_loop_version'] = $server_loop_version ?? null;
    $response['loop_version_mismatch'] = $loop_version_mismatch ?? false;
    $response['loop_update_grace_active'] = $loop_update_grace_active ?? false;
    
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

    $group_mod_query = "SELECT m.name
                        FROM kiosk_group_assignments kga
                        INNER JOIN kiosk_group_modules kgm ON kga.group_id = kgm.group_id
                        INNER JOIN modules m ON kgm.module_id = m.id
                        WHERE kga.kiosk_id = ? AND kgm.is_active = 1
                        ORDER BY kgm.display_order";
    $group_mod_stmt = $conn->prepare($group_mod_query);
    $group_mod_stmt->bind_param("i", $kiosk_id);
    $group_mod_stmt->execute();
    $group_mod_result = $group_mod_stmt->get_result();
    while ($mod = $group_mod_result->fetch_assoc()) {
        $modules[] = $mod['name'];
    }
    $group_mod_stmt->close();

    if (empty($modules)) {
        $mod_query = "SELECT m.name
                      FROM modules m
                      INNER JOIN kiosk_modules km ON m.id = km.module_id
                      WHERE km.kiosk_id = ? AND km.is_active = 1
                      ORDER BY km.display_order";
        $mod_stmt = $conn->prepare($mod_query);
        $mod_stmt->bind_param("i", $kiosk_id);
        $mod_stmt->execute();
        $mod_result = $mod_stmt->get_result();

        while ($mod = $mod_result->fetch_assoc()) {
            $modules[] = $mod['name'];
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

