<?php
/**
 * Admin hard delete kiosk API.
 * Allowed only for admin users acting in default company context.
 */

session_start();
header('Content-Type: application/json');

require_once '../dbkonfiguracia.php';
require_once '../logging.php';

function table_exists(mysqli $conn, string $table_name): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stmt->bind_param('s', $table_name);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
}

function get_default_company_id(mysqli $conn): int {
    $default_company_id = 0;

    if (table_exists($conn, 'system_settings')) {
        $stmt = $conn->prepare("SELECT setting_value FROM system_settings WHERE setting_key = 'admin_default_company_id' LIMIT 1");
        if ($stmt) {
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            $default_company_id = (int)($row['setting_value'] ?? 0);
        }
    }

    if ($default_company_id <= 0 && table_exists($conn, 'companies')) {
        $fallback_stmt = $conn->prepare("SELECT id FROM companies WHERE name IN ('Default Institution', 'Default Company') ORDER BY id ASC LIMIT 1");
        if ($fallback_stmt) {
            $fallback_stmt->execute();
            $fallback_row = $fallback_stmt->get_result()->fetch_assoc();
            $fallback_stmt->close();
            $default_company_id = (int)($fallback_row['id'] ?? 0);
        }
    }

    return $default_company_id;
}

function normalize_screenshot_filename(string $value): ?string {
    $trimmed = trim($value);
    if ($trimmed === '') {
        return null;
    }

    $path = parse_url($trimmed, PHP_URL_PATH);
    if (!is_string($path) || $path === '') {
        $path = $trimmed;
    }

    $path = str_replace('\\', '/', $path);
    $candidate = basename($path);
    if ($candidate === '' || $candidate === '.' || $candidate === '..') {
        return null;
    }

    if (!preg_match('/^[A-Za-z0-9._-]+\.(png|jpe?g|webp|gif)$/i', $candidate)) {
        return null;
    }

    return $candidate;
}

function collect_screenshot_filenames(mysqli $conn, int $kiosk_id, ?string $kiosk_screenshot_url): array {
    $files = [];

    $direct = normalize_screenshot_filename((string)$kiosk_screenshot_url);
    if ($direct !== null) {
        $files[$direct] = true;
    }

    if (table_exists($conn, 'sync_logs')) {
        $stmt = $conn->prepare("SELECT details FROM sync_logs WHERE kiosk_id = ? AND action = 'screenshot'");
        $stmt->bind_param('i', $kiosk_id);
        $stmt->execute();
        $result = $stmt->get_result();
        while ($row = $result->fetch_assoc()) {
            $details = json_decode((string)($row['details'] ?? ''), true);
            if (!is_array($details)) {
                continue;
            }

            $from_filename = normalize_screenshot_filename((string)($details['filename'] ?? ''));
            if ($from_filename !== null) {
                $files[$from_filename] = true;
            }

            $from_url = normalize_screenshot_filename((string)($details['screenshot_url'] ?? ''));
            if ($from_url !== null) {
                $files[$from_url] = true;
            }
        }
        $stmt->close();
    }

    return array_keys($files);
}

$response = ['success' => false, 'message' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    $response['message'] = 'Method not allowed';
    echo json_encode($response);
    exit();
}

if (empty($_SESSION['user_id']) || empty($_SESSION['isadmin'])) {
    http_response_code(403);
    $response['message'] = 'Unauthorized';
    echo json_encode($response);
    exit();
}

try {
    $payload = json_decode(file_get_contents('php://input'), true);
    $kiosk_id = (int)($payload['kiosk_id'] ?? 0);
    if ($kiosk_id <= 0) {
        $response['message'] = 'Invalid kiosk id';
        echo json_encode($response);
        exit();
    }

    $conn = getDbConnection();
    $conn->begin_transaction();

    $kiosk_stmt = $conn->prepare('SELECT id, company_id, hostname, screenshot_url FROM kiosks WHERE id = ? LIMIT 1');
    $kiosk_stmt->bind_param('i', $kiosk_id);
    $kiosk_stmt->execute();
    $kiosk = $kiosk_stmt->get_result()->fetch_assoc();
    $kiosk_stmt->close();

    if (!$kiosk) {
        $conn->rollback();
        $response['message'] = 'Kiosk not found';
        closeDbConnection($conn);
        echo json_encode($response);
        exit();
    }

    $default_company_id = get_default_company_id($conn);
    $kiosk_company_id = (int)($kiosk['company_id'] ?? 0);
    $session_company_id = (int)($_SESSION['company_id'] ?? 0);
    $acting_company_id = (int)($_SESSION['admin_acting_company_id'] ?? 0);

    $scope_ok = true;
    if ($session_company_id > 0 && $session_company_id !== $default_company_id) {
        $scope_ok = false;
    }
    if ($acting_company_id > 0 && $acting_company_id !== $default_company_id) {
        $scope_ok = false;
    }

    if (
        $default_company_id <= 0
        || $kiosk_company_id !== $default_company_id
        || !$scope_ok
    ) {
        $conn->rollback();
        $response['message'] = 'Hard delete is allowed only from admin dashboard in default company context';
        closeDbConnection($conn);
        echo json_encode($response);
        exit();
    }

    $screenshot_filenames = collect_screenshot_filenames($conn, $kiosk_id, (string)($kiosk['screenshot_url'] ?? ''));

    $cleanup_tables = [
        'api_logs' => 'DELETE FROM api_logs WHERE kiosk_id = ?',
        'sync_logs' => 'DELETE FROM sync_logs WHERE kiosk_id = ?',
        'kiosk_logs' => 'DELETE FROM kiosk_logs WHERE kiosk_id = ?',
        'kiosk_health_logs' => 'DELETE FROM kiosk_health_logs WHERE kiosk_id = ?',
        'kiosk_health' => 'DELETE FROM kiosk_health WHERE kiosk_id = ?',
        'kiosk_command_logs' => 'DELETE FROM kiosk_command_logs WHERE kiosk_id = ?',
        'kiosk_command_queue' => 'DELETE FROM kiosk_command_queue WHERE kiosk_id = ?',
        'kiosk_modules' => 'DELETE FROM kiosk_modules WHERE kiosk_id = ?',
        'kiosk_group_assignments' => 'DELETE FROM kiosk_group_assignments WHERE kiosk_id = ?',
        'display_schedules' => 'DELETE FROM display_schedules WHERE kijelzo_id = ?',
        'display_status_log' => 'DELETE FROM display_status_log WHERE kijelzo_id = ?',
        'kiosk_migrations' => 'DELETE FROM kiosk_migrations WHERE kiosk_id = ?',
    ];

    foreach ($cleanup_tables as $table_name => $sql) {
        if (!table_exists($conn, $table_name)) {
            continue;
        }
        $stmt = $conn->prepare($sql);
        $stmt->bind_param('i', $kiosk_id);
        $stmt->execute();
        $stmt->close();
    }

    $delete_kiosk_stmt = $conn->prepare('DELETE FROM kiosks WHERE id = ? AND company_id = ? LIMIT 1');
    $delete_kiosk_stmt->bind_param('ii', $kiosk_id, $default_company_id);
    $delete_kiosk_stmt->execute();
    $deleted_kiosk_rows = $delete_kiosk_stmt->affected_rows;
    $delete_kiosk_stmt->close();

    if ($deleted_kiosk_rows !== 1) {
        throw new RuntimeException('Kiosk delete failed');
    }

    $conn->commit();

    $deleted_files = 0;
    $screenshots_dir = realpath(__DIR__ . '/../screenshots');
    if ($screenshots_dir && is_dir($screenshots_dir)) {
        foreach ($screenshot_filenames as $filename) {
            $target = $screenshots_dir . DIRECTORY_SEPARATOR . $filename;
            $target_real = realpath($target);
            if ($target_real === false || strpos($target_real, $screenshots_dir) !== 0 || !is_file($target_real)) {
                continue;
            }
            if (@unlink($target_real)) {
                $deleted_files++;
            }
        }
    }

    log_security_event(
        'kiosk_hard_deleted',
        (int)$_SESSION['user_id'],
        (string)($_SESSION['username'] ?? 'admin'),
        get_client_ip(),
        get_user_agent(),
        [
            'kiosk_id' => $kiosk_id,
            'kiosk_hostname' => (string)($kiosk['hostname'] ?? ''),
            'company_id' => $default_company_id,
            'deleted_screenshot_files' => $deleted_files,
        ]
    );

    closeDbConnection($conn);

    $response['success'] = true;
    $response['message'] = 'Kiosk hard deleted successfully';
    $response['deleted_screenshot_files'] = $deleted_files;
    echo json_encode($response);
} catch (Throwable $e) {
    if (isset($conn) && $conn instanceof mysqli) {
        try {
            $conn->rollback();
        } catch (Throwable $rollback_error) {
            // no-op
        }
        closeDbConnection($conn);
    }

    error_log('admin_hard_delete_kiosk: ' . $e->getMessage());
    http_response_code(500);
    $response['message'] = 'Server error';
    echo json_encode($response);
}
