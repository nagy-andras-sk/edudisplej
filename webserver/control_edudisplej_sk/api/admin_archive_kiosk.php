<?php
/**
 * Admin archive kiosk API.
 * Archives kiosk metadata, then deletes kiosk and related operational data.
 */

session_start();
header('Content-Type: application/json');

require_once '../dbkonfiguracia.php';
require_once '../logging.php';
require_once '../kiosk_archive.php';

function table_exists(mysqli $conn, string $table_name): bool {
    $stmt = $conn->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1');
    $stmt->bind_param('s', $table_name);
    $stmt->execute();
    $exists = $stmt->get_result()->num_rows > 0;
    $stmt->close();
    return $exists;
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
    $archive_note = trim((string)($payload['archive_note'] ?? ''));
    if ($archive_note === '') {
        $archive_note = null;
    }

    if ($kiosk_id <= 0) {
        $response['message'] = 'Invalid kiosk id';
        echo json_encode($response);
        exit();
    }

    $conn = getDbConnection();
    $conn->begin_transaction();

    $kiosk_stmt = $conn->prepare('SELECT * FROM kiosks WHERE id = ? LIMIT 1');
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

    $archive_result = edudisplej_archive_kiosk(
        $conn,
        $kiosk,
        (int)$_SESSION['user_id'],
        'admin_delete',
        $archive_note
    );

    if (empty($archive_result['success'])) {
        throw new RuntimeException($archive_result['message'] ?? 'Archive failed');
    }

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

    $delete_kiosk_stmt = $conn->prepare('DELETE FROM kiosks WHERE id = ? LIMIT 1');
    $delete_kiosk_stmt->bind_param('i', $kiosk_id);
    $delete_kiosk_stmt->execute();
    $deleted_kiosk_rows = $delete_kiosk_stmt->affected_rows;
    $delete_kiosk_stmt->close();

    if ($deleted_kiosk_rows !== 1) {
        throw new RuntimeException('Kiosk delete failed');
    }

    $conn->commit();

    log_security_event(
        'kiosk_archived',
        (int)$_SESSION['user_id'],
        (string)($_SESSION['username'] ?? 'admin'),
        get_client_ip(),
        get_user_agent(),
        [
            'kiosk_id' => $kiosk_id,
            'archive_id' => (int)($archive_result['archive_id'] ?? 0),
            'kiosk_hostname' => (string)($kiosk['hostname'] ?? ''),
            'company_id' => (int)($kiosk['company_id'] ?? 0),
        ]
    );

    closeDbConnection($conn);

    $response['success'] = true;
    $response['message'] = 'Kiosk archived and deleted successfully';
    $response['archive_id'] = (int)($archive_result['archive_id'] ?? 0);
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

    error_log('admin_archive_kiosk: ' . $e->getMessage());
    http_response_code(500);
    $response['message'] = 'Server error';
    echo json_encode($response);
}
