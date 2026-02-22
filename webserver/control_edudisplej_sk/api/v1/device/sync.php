<?php
/**
 * Unified Device Sync Endpoint – v1
 * POST /api/v1/device/sync.php
 *
 * Single call that handles:
 *  - Hardware / heartbeat data submission
 *  - Screenshot upload
 *  - Log submission
 *  - Returns configuration (sync_interval, screenshot policy, command queue, module delta flag)
 *
 * Authentication: Bearer token (Authorization: Bearer <token>)
 * Optional request signing: X-EDU-Timestamp, X-EDU-Nonce, X-EDU-Signature
 */

header('Content-Type: application/json');
require_once __DIR__ . '/../../../dbkonfiguracia.php';
require_once __DIR__ . '/../../auth.php';

function v1_sanitize_screenshot_filename($filename) {
    $name = basename((string)$filename);
    $name = str_replace(['\\', '/'], '', $name);
    $name = preg_replace('/[^A-Za-z0-9._-]/', '', $name);

    if ($name === '' || !preg_match('/\.(png|jpe?g)$/i', $name)) {
        return '';
    }

    return $name;
}

function v1_ensure_debug_mode_column(mysqli $conn): void {
    $check = $conn->query("SHOW COLUMNS FROM kiosks LIKE 'debug_mode'");
    if ($check && $check->num_rows > 0) {
        return;
    }

    $conn->query("ALTER TABLE kiosks ADD COLUMN debug_mode TINYINT(1) NOT NULL DEFAULT 0");
}

function v1_assign_default_group_for_company(mysqli $conn, int $kiosk_id, int $company_id): void {
    if ($kiosk_id <= 0 || $company_id <= 0) {
        return;
    }

    $group_stmt = $conn->prepare("SELECT id FROM kiosk_groups WHERE company_id = ? ORDER BY is_default DESC, priority DESC, id ASC LIMIT 1");
    $group_stmt->bind_param("i", $company_id);
    $group_stmt->execute();
    $group_row = $group_stmt->get_result()->fetch_assoc();
    $group_stmt->close();

    if (!$group_row || empty($group_row['id'])) {
        return;
    }

    $target_group_id = (int)$group_row['id'];

    $delete_stmt = $conn->prepare("DELETE FROM kiosk_group_assignments WHERE kiosk_id = ?");
    $delete_stmt->bind_param("i", $kiosk_id);
    $delete_stmt->execute();
    $delete_stmt->close();

    $assign_stmt = $conn->prepare("INSERT INTO kiosk_group_assignments (kiosk_id, group_id) VALUES (?, ?)");
    $assign_stmt->bind_param("ii", $kiosk_id, $target_group_id);
    $assign_stmt->execute();
    $assign_stmt->close();
}

function v1_enforce_screenshot_retention(mysqli $conn, int $kiosk_id, int $max_per_kiosk = 100): void {
    if ($max_per_kiosk < 1) {
        return;
    }

    $screenshots_dir = realpath(__DIR__ . '/../../../screenshots');

    $old_stmt = $conn->prepare(
        "SELECT id, details
           FROM sync_logs
          WHERE kiosk_id = ? AND action = 'screenshot'
          ORDER BY timestamp DESC, id DESC
          LIMIT 100000 OFFSET ?"
    );
    $old_stmt->bind_param("ii", $kiosk_id, $max_per_kiosk);
    $old_stmt->execute();
    $old_result = $old_stmt->get_result();

    $ids_to_delete = [];
    while ($row = $old_result->fetch_assoc()) {
        $ids_to_delete[] = (int)$row['id'];

        $details = json_decode((string)$row['details'], true);
        if (!is_array($details) || empty($details['filename']) || !$screenshots_dir) {
            continue;
        }

        $filename = v1_sanitize_screenshot_filename((string)$details['filename']);
        if ($filename === '') {
            continue;
        }

        $file_path = $screenshots_dir . DIRECTORY_SEPARATOR . $filename;
        if (is_file($file_path)) {
            @unlink($file_path);
        }
    }
    $old_stmt->close();

    if (empty($ids_to_delete)) {
        return;
    }

    $id_list = implode(',', array_map('intval', $ids_to_delete));
    $conn->query("DELETE FROM sync_logs WHERE id IN ($id_list)");
}

$api_company = validate_api_token();

// Read raw body once
$raw_body = file_get_contents('php://input');
$data     = json_decode($raw_body, true) ?? [];

// Optional request signature validation
validate_request_signature($api_company, $raw_body, false);

$response = ['success' => false, 'message' => ''];

// ---------------------------------------------------------------------------
// Helper: parse timestamp value to unix epoch
// ---------------------------------------------------------------------------
function v1_parse_ts($value): ?int {
    if (!$value) return null;
    if (is_numeric($value)) return (int)$value;
    $ts = strtotime($value);
    return $ts ? $ts : null;
}

try {
    $conn = getDbConnection();
    v1_ensure_debug_mode_column($conn);

    // -----------------------------------------------------------------------
    // 1. Identify kiosk
    // -----------------------------------------------------------------------
    $mac      = $data['mac']      ?? '';
    $hostname = $data['hostname'] ?? '';

    if (empty($mac)) {
        $response['message'] = 'MAC address required';
        echo json_encode($response);
        exit;
    }

    // -----------------------------------------------------------------------
    // 2. Hardware / heartbeat data
    // -----------------------------------------------------------------------
    $hw_info          = json_encode($data['hw_info'] ?? []);
    $public_ip        = $_SERVER['REMOTE_ADDR'] ?? '';
    $version          = $data['version']           ?? null;
    $screen_resolution = $data['screen_resolution'] ?? null;
    $screen_status    = $data['screen_status']      ?? null;
    $client_last_update = $data['last_update']      ?? null;

    $stmt = $conn->prepare(
        "UPDATE kiosks
            SET hostname = ?, hw_info = ?, public_ip = ?,
                version = ?, screen_resolution = ?, screen_status = ?,
                status = 'online', last_seen = NOW(), upgrade_started_at = NULL
          WHERE mac = ?"
    );
    $stmt->bind_param("sssssss",
        $hostname, $hw_info, $public_ip,
        $version, $screen_resolution, $screen_status,
        $mac
    );
    $stmt->execute();
    $stmt->close();

    // Fetch kiosk record
    $stmt = $conn->prepare(
        "SELECT k.id, k.device_id, k.sync_interval, k.screenshot_requested,
                COALESCE(k.screenshot_enabled, 0) as screenshot_enabled,
            COALESCE(k.debug_mode, 0) as debug_mode,
                COALESCE(k.screenshot_interval_seconds, 3) as screenshot_interval_seconds,
                k.screenshot_requested_until,
                k.company_id, c.name as company_name, k.loop_last_update
           FROM kiosks k
           LEFT JOIN companies c ON k.company_id = c.id
          WHERE k.mac = ?"
    );
    $stmt->bind_param("s", $mac);
    $stmt->execute();
    $kiosk = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$kiosk) {
        $response['message'] = 'Kiosk not found. Please register first.';
        echo json_encode($response);
        $conn->close();
        exit;
    }

    // Enforce company ownership (with migration handover support)
    if (!empty($kiosk['company_id'])) {
        $current_company_id = (int)$kiosk['company_id'];
        $api_company_id = (int)($api_company['id'] ?? 0);

        if ($current_company_id !== $api_company_id && !api_is_admin_session($api_company)) {
            $migration_stmt = $conn->prepare("SELECT id, target_company_id FROM kiosk_migrations WHERE kiosk_id = ? AND status IN ('queued','in_progress') ORDER BY id DESC LIMIT 1");
            $migration_stmt->bind_param("i", $kiosk['id']);
            $migration_stmt->execute();
            $migration = $migration_stmt->get_result()->fetch_assoc();
            $migration_stmt->close();

            if ($migration && (int)$migration['target_company_id'] === $api_company_id) {
                $conn->begin_transaction();
                try {
                    $update_kiosk_stmt = $conn->prepare("UPDATE kiosks SET company_id = ? WHERE id = ?");
                    $update_kiosk_stmt->bind_param("ii", $api_company_id, $kiosk['id']);
                    $update_kiosk_stmt->execute();
                    $update_kiosk_stmt->close();

                    v1_assign_default_group_for_company($conn, (int)$kiosk['id'], $api_company_id);

                    $migration_update_stmt = $conn->prepare("UPDATE kiosk_migrations SET status = 'completed', completed_at = NOW(), updated_at = CURRENT_TIMESTAMP WHERE id = ?");
                    $migration_update_stmt->bind_param("i", $migration['id']);
                    $migration_update_stmt->execute();
                    $migration_update_stmt->close();

                    $conn->commit();
                    $kiosk['company_id'] = $api_company_id;
                } catch (Throwable $migration_error) {
                    $conn->rollback();
                    throw $migration_error;
                }
            } else {
                api_require_company_match($api_company, $kiosk['company_id'], 'Unauthorized');
            }
        }
    } elseif (!empty($api_company['id']) && !api_is_admin_session($api_company)) {
        $assign = $conn->prepare("UPDATE kiosks SET company_id = ? WHERE id = ?");
        $assign->bind_param("ii", $api_company['id'], $kiosk['id']);
        $assign->execute();
        $assign->close();
        $kiosk['company_id'] = $api_company['id'];
    }

    $kiosk_id = $kiosk['id'];

    // -----------------------------------------------------------------------
    // 3. Screenshot upload (optional, included in same request)
    // -----------------------------------------------------------------------
    $screenshot_uploaded = false;
    $screenshot_data = $data['screenshot'] ?? '';
    if (!empty($screenshot_data)) {
        $custom_filename = $data['screenshot_filename'] ?? '';
        $custom_filename = v1_sanitize_screenshot_filename($custom_filename);
        $filename = !empty($custom_filename)
            ? $custom_filename
            : 'screenshot_' . md5($mac . time()) . '.png';

        $screenshots_dir = __DIR__ . '/../../../screenshots';
        if (!is_dir($screenshots_dir)) {
            mkdir($screenshots_dir, 0755, true);
        }

        $image_data = base64_decode(
            preg_replace('#^data:image/\w+;base64,#i', '', $screenshot_data)
        );

        $is_png  = $image_data !== false && substr($image_data, 0, 8) === "\x89\x50\x4E\x47\x0D\x0A\x1A\x0A";
        $is_jpeg = $image_data !== false && substr($image_data, 0, 3) === "\xFF\xD8\xFF";

        if ($image_data !== false && strlen($image_data) >= 100 && ($is_png || $is_jpeg)) {
            $filepath = $screenshots_dir . '/' . $filename;
            file_put_contents($filepath, $image_data);

            $relative_path = 'screenshots/' . $filename;
            $upd = $conn->prepare(
                "UPDATE kiosks SET screenshot_url = ?, screenshot_timestamp = NOW(), screenshot_requested = 0 WHERE mac = ?"
            );
            $upd->bind_param("ss", $relative_path, $mac);
            $upd->execute();
            $upd->close();
            $screenshot_uploaded = true;

            // Log screenshot
            $ls = $conn->prepare(
                "INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'screenshot', ?)"
            );
            $ls_details = json_encode(['filename' => $filename, 'timestamp' => date('Y-m-d H:i:s')]);
            $ls->bind_param("is", $kiosk_id, $ls_details);
            $ls->execute();
            $ls->close();

            v1_enforce_screenshot_retention($conn, (int)$kiosk_id, 100);
        }
    }

    // -----------------------------------------------------------------------
    // 4. Log submission (optional)
    // -----------------------------------------------------------------------
    $logs_inserted = 0;
    $logs = $data['logs'] ?? [];
    if (!empty($logs)) {
        // Ensure kiosk_logs table exists
        $conn->query("
            CREATE TABLE IF NOT EXISTS kiosk_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                kiosk_id INT NOT NULL,
                log_type VARCHAR(50) NOT NULL,
                log_level VARCHAR(20) NOT NULL,
                message TEXT NOT NULL,
                details TEXT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_kiosk_id (kiosk_id),
                INDEX idx_log_type (log_type),
                INDEX idx_log_level (log_level),
                INDEX idx_created_at (created_at),
                FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        ");

        $log_stmt = $conn->prepare(
            "INSERT INTO kiosk_logs (kiosk_id, log_type, log_level, message, details) VALUES (?, ?, ?, ?, ?)"
        );
        foreach ($logs as $log) {
            $log_type  = $log['type']    ?? 'general';
            $log_level = $log['level']   ?? 'info';
            $message   = $log['message'] ?? '';
            $details   = isset($log['details']) ? json_encode($log['details']) : null;
            if (empty($message)) continue;
            $log_stmt->bind_param("issss", $kiosk_id, $log_type, $log_level, $message, $details);
            if ($log_stmt->execute()) $logs_inserted++;
        }
        $log_stmt->close();

        // Prune old logs
        $conn->query(
            "DELETE FROM kiosk_logs WHERE created_at < DATE_SUB(NOW(), INTERVAL 30 DAY) LIMIT 1000"
        );
    }

    // -----------------------------------------------------------------------
    // 5. Check if loop/configuration needs update
    // -----------------------------------------------------------------------
    $need_update  = false;
    $update_reason = '';
    $stored_last_update = $kiosk['loop_last_update'] ?? null;
    $stored_ts = v1_parse_ts($stored_last_update);
    $client_ts = v1_parse_ts($client_last_update);

    if ($client_ts && (!$stored_ts || $client_ts > $stored_ts)) {
        $stored_ts = $client_ts;
        $stored_last_update = date('Y-m-d H:i:s', $client_ts);
        $upd_loop = $conn->prepare("UPDATE kiosks SET loop_last_update = ? WHERE id = ?");
        $upd_loop->bind_param("si", $stored_last_update, $kiosk_id);
        $upd_loop->execute();
        $upd_loop->close();
    }

    $group_stmt = $conn->prepare(
        "SELECT group_id FROM kiosk_group_assignments WHERE kiosk_id = ? LIMIT 1"
    );
    $group_stmt->bind_param("i", $kiosk_id);
    $group_stmt->execute();
    $group_row = $group_stmt->get_result()->fetch_assoc();
    $group_stmt->close();

    $server_ts = null;
    if ($group_row) {
        $us = $conn->prepare(
            "SELECT MAX(updated_at) as last_server_update, MAX(created_at) as created_at, COUNT(*) as config_count
               FROM kiosk_group_modules WHERE group_id = ? AND is_active = 1"
        );
        $us->bind_param("i", $group_row['group_id']);
        $us->execute();
        $ur = $us->get_result()->fetch_assoc();
        $us->close();
        if ($ur && $ur['config_count'] > 0) {
            $server_ts = v1_parse_ts($ur['last_server_update'] ?? $ur['created_at']);
        }
    } else {
        $us = $conn->prepare(
            "SELECT MAX(updated_at) as last_server_update, MAX(created_at) as created_at, COUNT(*) as config_count
               FROM kiosk_modules WHERE kiosk_id = ? AND is_active = 1"
        );
        $us->bind_param("i", $kiosk_id);
        $us->execute();
        $ur = $us->get_result()->fetch_assoc();
        $us->close();
        if ($ur && $ur['config_count'] > 0) {
            $server_ts = v1_parse_ts($ur['last_server_update'] ?? $ur['created_at']);
        }
    }

    if ($server_ts) {
        if (!$stored_ts) {
            $need_update  = true;
            $update_reason = 'No local loop timestamp';
        } elseif ($server_ts > $stored_ts) {
            $need_update  = true;
            $update_reason = 'Server loop updated';
        }
    }

    // -----------------------------------------------------------------------
    // 6. Log sync action
    // -----------------------------------------------------------------------
    $sync_log = $conn->prepare(
        "INSERT INTO sync_logs (kiosk_id, action, details) VALUES (?, 'v1_sync', ?)"
    );
    $sync_details = json_encode([
        'hostname'    => $hostname,
        'public_ip'   => $public_ip,
        'timestamp'   => date('Y-m-d H:i:s'),
        'needs_update' => $need_update,
        'update_reason' => $update_reason,
        'screenshot_uploaded' => $screenshot_uploaded,
        'logs_inserted' => $logs_inserted,
    ]);
    $sync_log->bind_param("is", $kiosk_id, $sync_details);
    $sync_log->execute();
    $sync_log->close();

    // -----------------------------------------------------------------------
    // 7. Build response
    // -----------------------------------------------------------------------

    // Derive screenshot_requested from TTL: true if requested_until is in the future
    $screenshot_ttl_active = false;
    if (!empty($kiosk['screenshot_requested_until'])) {
        $until_ts = strtotime($kiosk['screenshot_requested_until']);
        $screenshot_ttl_active = $until_ts !== false && $until_ts > time();
    }
    // Fallback: honour legacy boolean flag if TTL not set
    $screenshot_requested = $screenshot_ttl_active || (bool)$kiosk['screenshot_requested'];

    $response['success']                   = true;
    $response['kiosk_id']                  = $kiosk_id;
    $response['device_id']                 = $kiosk['device_id'];
    $response['sync_interval']             = $kiosk['sync_interval'];
    $response['screenshot_requested']      = $screenshot_requested;
    $response['screenshot_enabled']        = (bool)$kiosk['screenshot_enabled'];
    $response['debug_mode']                = (bool)$kiosk['debug_mode'];
    $response['screenshot_interval_seconds'] = (int)$kiosk['screenshot_interval_seconds'];
    $response['company_id']                = $kiosk['company_id'];
    $response['company_name']              = $kiosk['company_name'] ?? '';
    $response['needs_update']              = $need_update;
    if ($need_update) {
        $response['update_reason'] = $update_reason;
        $response['update_action'] = 'restart';
    }
    if ($screenshot_uploaded) {
        $response['screenshot_uploaded'] = true;
    }
    if (!empty($logs)) {
        $response['logs_inserted'] = $logs_inserted;
    }
    $response['api_version'] = 'v1';

    $conn->close();

} catch (Exception $e) {
    $response['message'] = 'Server error';
    error_log('v1/device/sync.php: ' . $e->getMessage());
}

echo json_encode($response);
