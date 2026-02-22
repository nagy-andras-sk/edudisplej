<?php
/**
 * Database Structure Auto-Fixer
 * EduDisplej Control Panel
 * 
 * This script automatically checks and fixes the database structure
 * to match the expected schema. Run this whenever you need to update
 * the database structure.
 */

$baseDir = dirname(__DIR__, 2);
require_once $baseDir . '/dbkonfiguracia.php';

// Enable error reporting for debugging
error_reporting(E_ALL);
ini_set('display_errors', 1);

$results = [];
$errors = [];

if (!defined('EDUDISPLEJ_DBJAVITO_ECHO')) {
    define('EDUDISPLEJ_DBJAVITO_ECHO', PHP_SAPI === 'cli');
}

if (!defined('EDUDISPLEJ_DBJAVITO_NO_HTML')) {
    define('EDUDISPLEJ_DBJAVITO_NO_HTML', false);
}

function logResult($message, $type = 'info') {
    global $results;
    $results[] = ['type' => $type, 'message' => $message];
    // Console log
    if (EDUDISPLEJ_DBJAVITO_ECHO) {
        echo "[" . strtoupper($type) . "] " . $message . "\n";
    }
}

function logError($message) {
    global $errors;
    $errors[] = $message;
    logResult($message, 'error');
}

function isSafeCleanupTableName(string $tableName): bool {
    $tableName = strtolower(trim($tableName));
    if ($tableName === '') {
        return false;
    }

    $patterns = [
        '/^tmp_/',
        '/^backup_/',
        '/_backup$/',
        '/_old$/',
        '/_legacy$/',
        '/^old_/'
    ];

    foreach ($patterns as $pattern) {
        if (preg_match($pattern, $tableName)) {
            return true;
        }
    }

    return false;
}

function safeDeleteByQuery(mysqli $conn, string $sql, string $label): void {
    if ($conn->query($sql)) {
        $affected = (int)$conn->affected_rows;
        if ($affected > 0) {
            logResult("Cleanup: $label ($affected rows removed)", 'success');
        } else {
            logResult("Cleanup: $label (no rows)", 'info');
        }
    } else {
        logError("Cleanup failed for '$label': " . $conn->error);
    }
}

function cleanupModuleFilesystem(string $baseDir): void {
    $modulesDir = realpath($baseDir . '/modules');
    if ($modulesDir === false || !is_dir($modulesDir)) {
        logResult('Cleanup: modules directory not found, skipping file cleanup', 'warning');
        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($modulesDir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );

    $removedFiles = 0;
    $removedDirs = 0;

    foreach ($iterator as $pathInfo) {
        $path = $pathInfo->getPathname();

        if ($pathInfo->isFile()) {
            $name = $pathInfo->getFilename();
            if (preg_match('/\.(bak|old|tmp|orig)$/i', $name) || preg_match('/^~\$/', $name)) {
                if (@unlink($path)) {
                    $removedFiles++;
                    logResult('Cleanup: removed legacy file ' . str_replace('\\', '/', $path), 'success');
                }
            }
        } elseif ($pathInfo->isDir()) {
            $name = strtolower($pathInfo->getFilename());
            if (in_array($name, ['old', 'backup', 'legacy', 'tmp'], true)) {
                $files = @scandir($path);
                if (is_array($files) && count($files) <= 2) {
                    if (@rmdir($path)) {
                        $removedDirs++;
                        logResult('Cleanup: removed empty legacy folder ' . str_replace('\\', '/', $path), 'success');
                    }
                }
            }
        }
    }

    logResult("Cleanup: module filesystem summary - files removed: $removedFiles, folders removed: $removedDirs", 'info');
}

function edudisplej_maintenance_asset_url_to_api(string $value): string {
    $raw = trim($value);
    if ($raw === '') {
        return $value;
    }

    if (stripos($raw, 'module_asset_file.php') !== false) {
        return $value;
    }

    $candidate = $raw;
    if (preg_match('/^https?:\/\//i', $candidate)) {
        $parsedPath = (string)parse_url($candidate, PHP_URL_PATH);
        if ($parsedPath !== '') {
            $candidate = $parsedPath;
        }
    } else {
        $parsedPath = (string)parse_url($candidate, PHP_URL_PATH);
        if ($parsedPath !== '') {
            $candidate = $parsedPath;
        }
    }

    $candidate = urldecode($candidate);
    $candidate = str_replace('\\', '/', $candidate);

    $needle = 'uploads/companies/';
    $idx = stripos($candidate, $needle);
    if ($idx === false) {
        return $value;
    }

    $relPath = substr($candidate, $idx);
    $relPath = ltrim((string)$relPath, '/');
    $relPath = preg_replace('#/+#', '/', $relPath);

    if ($relPath === '' || strpos($relPath, '..') !== false) {
        return $value;
    }

    return '../../api/group_loop/module_asset_file.php?path=' . rawurlencode($relPath);
}

function edudisplej_maintenance_migrate_settings_payload(&$payload): bool {
    if (!is_array($payload)) {
        return false;
    }

    $changed = false;

    foreach ($payload as $key => &$value) {
        if (is_array($value)) {
            if (edudisplej_maintenance_migrate_settings_payload($value)) {
                $changed = true;
            }
            continue;
        }

        if (!is_string($value)) {
            continue;
        }

        if ($key === 'pdfAssetUrl') {
            $normalized = edudisplej_maintenance_asset_url_to_api($value);
            if ($normalized !== $value) {
                $value = $normalized;
                $changed = true;
            }
            continue;
        }

        if ($key === 'imageUrlsJson') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $listChanged = false;
                foreach ($decoded as $index => $urlItem) {
                    if (!is_string($urlItem)) {
                        continue;
                    }
                    $normalized = edudisplej_maintenance_asset_url_to_api($urlItem);
                    if ($normalized !== $urlItem) {
                        $decoded[$index] = $normalized;
                        $listChanged = true;
                    }
                }
                if ($listChanged) {
                    $reencoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                    if ($reencoded !== false) {
                        $value = $reencoded;
                        $changed = true;
                    }
                }
            }
            continue;
        }

        if ($key === 'imageUrls') {
            continue;
        }
    }
    unset($value);

    if (isset($payload['imageUrls']) && is_array($payload['imageUrls'])) {
        foreach ($payload['imageUrls'] as $idx => $urlValue) {
            if (!is_string($urlValue)) {
                continue;
            }
            $normalized = edudisplej_maintenance_asset_url_to_api($urlValue);
            if ($normalized !== $urlValue) {
                $payload['imageUrls'][$idx] = $normalized;
                $changed = true;
            }
        }
    }

    return $changed;
}

function edudisplej_maintenance_migrate_json_column(mysqli $conn, string $table, string $idColumn, string $jsonColumn): void {
    $sql = "SELECT $idColumn AS rec_id, $jsonColumn AS json_payload FROM $table WHERE $jsonColumn IS NOT NULL AND $jsonColumn <> '' AND $jsonColumn LIKE '%uploads/companies/%'";
    $result = $conn->query($sql);
    if (!$result) {
        logError("Asset URL migration query failed for $table.$jsonColumn: " . $conn->error);
        return;
    }

    $rowsUpdated = 0;
    while ($row = $result->fetch_assoc()) {
        $recordId = (int)($row['rec_id'] ?? 0);
        $rawJson = (string)($row['json_payload'] ?? '');
        if ($recordId <= 0 || $rawJson === '') {
            continue;
        }

        $decoded = json_decode($rawJson, true);
        if (!is_array($decoded)) {
            continue;
        }

        if (!edudisplej_maintenance_migrate_settings_payload($decoded)) {
            continue;
        }

        $encoded = json_encode($decoded, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($encoded === false) {
            logError("Asset URL migration encode failed for $table.$jsonColumn id=$recordId");
            continue;
        }

        $update = $conn->prepare("UPDATE $table SET $jsonColumn = ? WHERE $idColumn = ? LIMIT 1");
        if (!$update) {
            logError("Asset URL migration prepare failed for $table.$jsonColumn id=$recordId: " . $conn->error);
            continue;
        }

        $update->bind_param('si', $encoded, $recordId);
        if ($update->execute()) {
            $rowsUpdated++;
        } else {
            logError("Asset URL migration update failed for $table.$jsonColumn id=$recordId: " . $conn->error);
        }
        $update->close();
    }

    logResult("Asset URL migration on $table.$jsonColumn: $rowsUpdated row(s) updated", $rowsUpdated > 0 ? 'success' : 'info');
}

function edudisplej_maintenance_migrate_legacy_asset_urls(mysqli $conn): void {
    logResult('Starting legacy asset URL migration (uploads -> API endpoint)...', 'info');
    edudisplej_maintenance_migrate_json_column($conn, 'kiosk_modules', 'id', 'settings');
    edudisplej_maintenance_migrate_json_column($conn, 'kiosk_group_modules', 'id', 'settings');
    edudisplej_maintenance_migrate_json_column($conn, 'kiosk_group_loop_plans', 'group_id', 'plan_json');
}

function edudisplej_maintenance_ensure_job_tables(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS maintenance_job_runs (
        job_key VARCHAR(120) PRIMARY KEY,
        last_run_at DATETIME NULL,
        last_status VARCHAR(20) NOT NULL DEFAULT 'idle',
        last_message VARCHAR(1000) NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function edudisplej_maintenance_ensure_settings_table(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS maintenance_settings (
        setting_key VARCHAR(120) PRIMARY KEY,
        setting_value VARCHAR(2000) NOT NULL DEFAULT '',
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $defaults = [
        'jedalen_sync_enabled' => '1',
        'jedalen_sync_window_start' => '0',
        'jedalen_sync_window_end' => '5',
        'jedalen_sync_regions' => 'TT,NR,TN,BB,PO,KE,BA,ZA',
        'jedalen_sync_every_cycle' => '0',
    ];

    $stmt = $conn->prepare("INSERT IGNORE INTO maintenance_settings (setting_key, setting_value) VALUES (?, ?)");
    if (!$stmt) {
        logError('Jedalen sync: failed to prepare default settings insert: ' . $conn->error);
        return;
    }

    foreach ($defaults as $key => $value) {
        $stmt->bind_param('ss', $key, $value);
        $stmt->execute();
    }
    $stmt->close();
}

function edudisplej_maintenance_load_settings_map(mysqli $conn): array {
    $map = [];
    $result = $conn->query("SELECT setting_key, setting_value FROM maintenance_settings");
    if (!$result) {
        logError('Jedalen sync: failed to read maintenance settings: ' . $conn->error);
        return $map;
    }

    while ($row = $result->fetch_assoc()) {
        $map[(string)($row['setting_key'] ?? '')] = (string)($row['setting_value'] ?? '');
    }

    return $map;
}

function edudisplej_maintenance_get_jedalen_sync_config(mysqli $conn): array {
    edudisplej_maintenance_ensure_settings_table($conn);
    $map = edudisplej_maintenance_load_settings_map($conn);

    $enabledRaw = strtolower(trim((string)($map['jedalen_sync_enabled'] ?? '1')));
    $enabled = in_array($enabledRaw, ['1', 'true', 'yes', 'on'], true);

    $windowStart = (int)($map['jedalen_sync_window_start'] ?? 0);
    if ($windowStart < 0 || $windowStart > 23) {
        $windowStart = 0;
    }

    $windowEnd = (int)($map['jedalen_sync_window_end'] ?? 5);
    if ($windowEnd < 0 || $windowEnd > 23) {
        $windowEnd = 5;
    }

    $regionsRaw = (string)($map['jedalen_sync_regions'] ?? 'TT,NR,TN,BB,PO,KE,BA,ZA');
    $regions = [];
    foreach (explode(',', $regionsRaw) as $part) {
        $region = strtoupper(trim($part));
        if ($region === '' || !preg_match('/^[A-Z]{2}$/', $region)) {
            continue;
        }
        $regions[$region] = true;
    }

    if (empty($regions)) {
        $regions = ['TT' => true, 'NR' => true, 'TN' => true, 'BB' => true, 'PO' => true, 'KE' => true, 'BA' => true, 'ZA' => true];
    }

    return [
        'enabled' => $enabled,
        'window_start' => $windowStart,
        'window_end' => $windowEnd,
        'regions' => array_values(array_keys($regions)),
        'every_cycle' => in_array(strtolower(trim((string)($map['jedalen_sync_every_cycle'] ?? '0'))), ['1', 'true', 'yes', 'on'], true),
    ];
}

function edudisplej_maintenance_ensure_meal_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS meal_plan_sites (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL DEFAULT 0,
        site_key VARCHAR(80) NOT NULL,
        site_name VARCHAR(150) NOT NULL,
        base_url VARCHAR(500) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_company_site_key (company_id, site_key),
        INDEX idx_company_active (company_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS meal_plan_institutions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL DEFAULT 0,
        site_id INT NOT NULL,
        external_key VARCHAR(120) NOT NULL DEFAULT '',
        institution_name VARCHAR(220) NOT NULL,
        city VARCHAR(180) NOT NULL DEFAULT '',
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_site_active (company_id, site_id, is_active),
        UNIQUE KEY uq_company_external (company_id, site_id, external_key)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS meal_plan_items (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL DEFAULT 0,
        institution_id INT NOT NULL,
        menu_date DATE NOT NULL,
        breakfast TEXT NULL,
        snack_am TEXT NULL,
        lunch TEXT NULL,
        snack_pm TEXT NULL,
        dinner TEXT NULL,
        source_type VARCHAR(20) NOT NULL DEFAULT 'manual',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_company_institution_date (company_id, institution_id, menu_date),
        INDEX idx_company_institution_date (company_id, institution_id, menu_date)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $seed_check = $conn->query("SELECT id FROM meal_plan_sites WHERE company_id = 0 AND site_key = 'jedalen.sk' LIMIT 1");
    if ($seed_check && $seed_check->num_rows === 0) {
        $conn->query("INSERT INTO meal_plan_sites (company_id, site_key, site_name, base_url, is_active) VALUES (0, 'jedalen.sk', 'Jedalen.sk', 'https://www.jedalen.sk', 1)");
    }
}

function edudisplej_maintenance_ensure_room_occupancy_schema(mysqli $conn): void {
    $conn->query("CREATE TABLE IF NOT EXISTS room_occupancy_rooms (
        id INT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        room_key VARCHAR(120) NOT NULL,
        room_name VARCHAR(220) NOT NULL,
        capacity INT NOT NULL DEFAULT 0,
        is_active TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_company_room_key (company_id, room_key),
        INDEX idx_company_active (company_id, is_active)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

    $conn->query("CREATE TABLE IF NOT EXISTS room_occupancy_events (
        id BIGINT AUTO_INCREMENT PRIMARY KEY,
        company_id INT NOT NULL,
        room_id INT NOT NULL,
        event_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        event_title VARCHAR(260) NOT NULL,
        event_note TEXT NULL,
        source_type VARCHAR(20) NOT NULL DEFAULT 'manual',
        external_ref VARCHAR(160) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_company_room_date (company_id, room_id, event_date),
        UNIQUE KEY uq_external_event (company_id, room_id, event_date, external_ref),
        UNIQUE KEY uq_manual_slot (company_id, room_id, event_date, start_time, end_time, event_title)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function edudisplej_maintenance_mark_job_run(mysqli $conn, string $jobKey, string $status, string $message): void {
    $trimmed = trim($message);
    $safeMessage = function_exists('mb_substr')
        ? mb_substr($trimmed, 0, 1000, 'UTF-8')
        : substr($trimmed, 0, 1000);
    $stmt = $conn->prepare("INSERT INTO maintenance_job_runs (job_key, last_run_at, last_status, last_message)
                           VALUES (?, NOW(), ?, ?)
                           ON DUPLICATE KEY UPDATE last_run_at = VALUES(last_run_at), last_status = VALUES(last_status), last_message = VALUES(last_message), updated_at = CURRENT_TIMESTAMP");
    if (!$stmt) {
        logError("Jedalen sync: failed to prepare job run update: " . $conn->error);
        return;
    }

    $stmt->bind_param('sss', $jobKey, $status, $safeMessage);
    if (!$stmt->execute()) {
        logError("Jedalen sync: failed to persist job run state: " . $conn->error);
    }
    $stmt->close();
}

function edudisplej_maintenance_should_run_jedalen_today(mysqli $conn, string $jobKey, array $syncConfig = []): array {
    $isForced = defined('EDUDISPLEJ_FORCE_JEDALEN_SYNC') && EDUDISPLEJ_FORCE_JEDALEN_SYNC;
    if ($isForced) {
        return ['run' => true, 'reason' => 'force_requested'];
    }

    if (array_key_exists('enabled', $syncConfig) && !$syncConfig['enabled']) {
        return ['run' => false, 'reason' => 'disabled'];
    }

    $hour = (int)date('G');
    $windowStart = isset($syncConfig['window_start']) ? (int)$syncConfig['window_start'] : 0;
    $windowEnd = isset($syncConfig['window_end']) ? (int)$syncConfig['window_end'] : 5;
    $inWindow = false;
    if ($windowStart <= $windowEnd) {
        $inWindow = ($hour >= $windowStart && $hour <= $windowEnd);
    } else {
        $inWindow = ($hour >= $windowStart || $hour <= $windowEnd);
    }

    if (!$inWindow) {
        return ['run' => false, 'reason' => 'outside_night_window'];
    }

    $stmt = $conn->prepare("SELECT last_run_at FROM maintenance_job_runs WHERE job_key = ? LIMIT 1");
    if (!$stmt) {
        return ['run' => true, 'reason' => 'no_state_read'];
    }
    $stmt->bind_param('s', $jobKey);
    $stmt->execute();
    $row = $stmt->get_result()->fetch_assoc();
    $stmt->close();

    if (!$row || empty($row['last_run_at'])) {
        return ['run' => true, 'reason' => 'first_run'];
    }

    $lastRunDate = date('Y-m-d', strtotime((string)$row['last_run_at']));
    $today = date('Y-m-d');
    if ($lastRunDate === $today) {
        return ['run' => false, 'reason' => 'already_ran_today'];
    }

    return ['run' => true, 'reason' => 'new_day'];
}

function edudisplej_maintenance_should_force_jedalen_sync_for_missing_data(mysqli $conn, array $targets): array {
    if (empty($targets)) {
        return ['run' => false, 'reason' => 'no_targets'];
    }

    $missing = [];
    $checkedPairs = [];

    $stmt = $conn->prepare("SELECT id FROM meal_plan_items WHERE company_id = ? AND institution_id = ? LIMIT 1");
    if (!$stmt) {
        return ['run' => false, 'reason' => 'missing_check_prepare_failed'];
    }

    foreach ($targets as $target) {
        $siteKey = strtolower((string)($target['site_key'] ?? ''));
        if ($siteKey !== 'jedalen.sk') {
            continue;
        }

        $companyId = (int)($target['company_id'] ?? 0);
        $institutionId = (int)($target['institution_id'] ?? 0);
        if ($companyId <= 0 || $institutionId <= 0) {
            continue;
        }

        $pairKey = $companyId . '|' . $institutionId;
        if (isset($checkedPairs[$pairKey])) {
            continue;
        }
        $checkedPairs[$pairKey] = true;

        $stmt->bind_param('ii', $companyId, $institutionId);
        if (!$stmt->execute()) {
            continue;
        }
        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            $missing[] = ['company_id' => $companyId, 'institution_id' => $institutionId];
        }
    }

    $stmt->close();

    if (empty($missing)) {
        return ['run' => false, 'reason' => 'all_targets_have_data'];
    }

    return [
        'run' => true,
        'reason' => 'missing_institution_data',
        'missing_targets' => $missing,
        'missing_count' => count($missing),
    ];
}

function edudisplej_maintenance_should_run_jedalen_by_updated_today(mysqli $conn, array $targets): array {
    if (empty($targets)) {
        return ['run' => false, 'reason' => 'no_targets'];
    }

    $checkedPairs = [];
    $missingToday = [];

    $stmt = $conn->prepare("SELECT id FROM meal_plan_items
                           WHERE company_id = ?
                             AND institution_id = ?
                             AND DATE(updated_at) = CURDATE()
                           LIMIT 1");
    if (!$stmt) {
        return ['run' => true, 'reason' => 'updated_today_check_prepare_failed'];
    }

    foreach ($targets as $target) {
        $siteKey = strtolower((string)($target['site_key'] ?? ''));
        if ($siteKey !== 'jedalen.sk') {
            continue;
        }

        $companyId = (int)($target['company_id'] ?? 0);
        $institutionId = (int)($target['institution_id'] ?? 0);
        if ($companyId <= 0 || $institutionId <= 0) {
            continue;
        }

        $pairKey = $companyId . '|' . $institutionId;
        if (isset($checkedPairs[$pairKey])) {
            continue;
        }
        $checkedPairs[$pairKey] = true;

        $stmt->bind_param('ii', $companyId, $institutionId);
        if (!$stmt->execute()) {
            $missingToday[] = ['company_id' => $companyId, 'institution_id' => $institutionId, 'reason' => 'query_failed'];
            continue;
        }

        $row = $stmt->get_result()->fetch_assoc();
        if (!$row) {
            $missingToday[] = ['company_id' => $companyId, 'institution_id' => $institutionId, 'reason' => 'not_updated_today'];
        }
    }

    $stmt->close();

    if (empty($missingToday)) {
        return ['run' => false, 'reason' => 'all_targets_updated_today'];
    }

    return [
        'run' => true,
        'reason' => 'some_targets_not_updated_today',
        'missing_targets' => $missingToday,
        'missing_count' => count($missingToday),
    ];
}

function edudisplej_maintenance_http_get(string $url, int $timeout = 25): string {
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (EduDisplej-Maintenance/1.0)',
            CURLOPT_HTTPHEADER => ['Accept: text/html,application/xhtml+xml'],
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode >= 400) {
            throw new RuntimeException('HTTP request failed for ' . $url . ' (' . ($err ?: ('HTTP ' . $httpCode)) . ')');
        }
        return (string)$body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => "User-Agent: Mozilla/5.0 (EduDisplej-Maintenance/1.0)\r\n",
        ]
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('HTTP request failed for ' . $url);
    }
    return (string)$body;
}

function edudisplej_maintenance_http_post_form(string $url, array $fields, int $timeout = 25): string {
    $payload = http_build_query($fields);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (EduDisplej-Maintenance/1.0)',
            CURLOPT_HTTPHEADER => [
                'Accept: text/html,application/xhtml+xml',
                'Content-Type: application/x-www-form-urlencoded',
            ],
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
        ]);
        $body = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);

        if ($body === false || $httpCode >= 400) {
            throw new RuntimeException('HTTP POST request failed for ' . $url . ' (' . ($err ?: ('HTTP ' . $httpCode)) . ')');
        }
        return (string)$body;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'POST',
            'timeout' => $timeout,
            'header' => "User-Agent: Mozilla/5.0 (EduDisplej-Maintenance/1.0)\r\nContent-Type: application/x-www-form-urlencoded\r\n",
            'content' => $payload,
        ]
    ]);
    $body = @file_get_contents($url, false, $context);
    if ($body === false) {
        throw new RuntimeException('HTTP POST request failed for ' . $url);
    }
    return (string)$body;
}

function edudisplej_maintenance_normalize_name(string $text): string {
    $value = trim(html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    if ($value === '') {
        return '';
    }

    $value = function_exists('mb_strtolower') ? mb_strtolower($value, 'UTF-8') : strtolower($value);
    $value = strtr($value, [
        'á' => 'a', 'ä' => 'a', 'č' => 'c', 'ď' => 'd', 'é' => 'e', 'ě' => 'e', 'í' => 'i',
        'ĺ' => 'l', 'ľ' => 'l', 'ň' => 'n', 'ó' => 'o', 'ô' => 'o', 'ö' => 'o', 'ő' => 'o',
        'ŕ' => 'r', 'ř' => 'r', 'š' => 's', 'ť' => 't', 'ú' => 'u', 'ů' => 'u', 'ü' => 'u',
        'ű' => 'u', 'ý' => 'y', 'ž' => 'z'
    ]);
    $value = preg_replace('/[^a-z0-9]+/u', ' ', $value);
    return trim((string)$value);
}

function edudisplej_maintenance_url_join(string $base, string $href): string {
    if (preg_match('/^https?:\/\//i', $href)) {
        return $href;
    }

    $base = rtrim($base, '/') . '/';
    if (strpos($href, '/') === 0) {
        $parts = parse_url($base);
        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)($parts['host'] ?? 'www.jedalen.sk');
        return $scheme . '://' . $host . $href;
    }

    return $base . ltrim($href, '/');
}

function edudisplej_maintenance_parse_int_csv(string $raw): array {
    $result = [];
    foreach (explode(',', $raw) as $part) {
        $value = (int)trim((string)$part);
        if ($value <= 0) {
            continue;
        }
        $result[$value] = true;
    }

    return array_values(array_map('intval', array_keys($result)));
}

function edudisplej_maintenance_normalize_jedalen_menu_url(string $url): string {
    $value = trim($url);
    if ($value === '') {
        return '';
    }

    if (!preg_match('/^https?:\/\//i', $value)) {
        $value = 'https://www.jedalen.sk/' . ltrim($value, '/');
    }

    $value = preg_replace('/^http:\/\//i', 'https://', $value);
    $parts = parse_url($value);
    if (!$parts) {
        return $value;
    }

    $host = strtolower((string)($parts['host'] ?? ''));
    if ($host === 'jedalen.sk') {
        $value = preg_replace('/^https:\/\/jedalen\.sk/i', 'https://www.jedalen.sk', $value);
    }

    if (preg_match('/(?:\?|&)Ident=([^&]+)/i', $value, $m)) {
        $ident = trim((string)urldecode((string)$m[1]));
        if ($ident !== '') {
            return 'https://www.jedalen.sk/Pages/EatMenu?Ident=' . rawurlencode($ident);
        }
    }

    return $value;
}

function edudisplej_maintenance_fetch_jedalen_institutions(array $regions): array {
    $baseUrl = 'https://www.jedalen.sk/';
    $items = [];

    foreach ($regions as $region) {
        $url = $baseUrl . '?RC=' . rawurlencode($region);
        $html = edudisplej_maintenance_http_get($url);

        $doc = new DOMDocument();
        libxml_use_internal_errors(true);
        $doc->loadHTML($html);
        libxml_clear_errors();

        $xpath = new DOMXPath($doc);
        $anchors = $xpath->query("//a[contains(@href, 'Pages/EatMenu?Ident=') or contains(@href, 'Pages/EatMenu?ident=')]");
        if (!$anchors) {
            continue;
        }

        foreach ($anchors as $a) {
            $name = trim((string)$a->textContent);
            $href = trim((string)$a->getAttribute('href'));
            if ($name === '' || $href === '') {
                continue;
            }

            $menuUrl = edudisplej_maintenance_url_join($baseUrl, $href);
            if (!preg_match('/(?:\?|&)Ident=([^&]+)/i', $menuUrl, $match)) {
                continue;
            }

            $ident = trim((string)urldecode((string)$match[1]));
            if ($ident === '') {
                continue;
            }

            $canonicalMenuUrl = 'https://www.jedalen.sk/Pages/EatMenu?Ident=' . rawurlencode($ident);
            $items[] = [
                'region' => (string)$region,
                'institution_ident' => $ident,
                'institution_name' => $name,
                'institution_name_normalized' => edudisplej_maintenance_normalize_name($name),
                'menu_url' => $canonicalMenuUrl,
            ];
        }
    }

    $dedupe = [];
    $uniqueItems = [];
    foreach ($items as $item) {
        $identKey = trim((string)($item['institution_ident'] ?? ''));
        if ($identKey === '') {
            $identKey = trim((string)($item['menu_url'] ?? ''));
        }
        if ($identKey === '' || isset($dedupe[$identKey])) {
            continue;
        }
        $dedupe[$identKey] = true;
        $uniqueItems[] = $item;
    }

    return $uniqueItems;
}

function edudisplej_maintenance_resolve_menu_date(string $dayText, string $monthText): ?string {
    $day = (int)preg_replace('/[^0-9]/', '', $dayText);
    $month = (int)preg_replace('/[^0-9]/', '', $monthText);

    if ($month <= 0) {
        $normalizedMonth = edudisplej_maintenance_normalize_name($monthText);
        $monthMap = [
            'januar' => 1, 'jan' => 1,
            'februar' => 2, 'feb' => 2,
            'marec' => 3, 'mar' => 3,
            'april' => 4, 'apr' => 4,
            'maj' => 5,
            'jun' => 6, 'juni' => 6, 'junius' => 6,
            'jul' => 7, 'juli' => 7, 'julius' => 7,
            'august' => 8, 'aug' => 8,
            'september' => 9, 'sep' => 9,
            'oktober' => 10, 'okt' => 10,
            'november' => 11, 'nov' => 11,
            'december' => 12, 'dec' => 12,
        ];
        $month = (int)($monthMap[$normalizedMonth] ?? 0);
    }

    if ($day <= 0 || $month <= 0 || $month > 12 || $day > 31) {
        return null;
    }

    $year = (int)date('Y');
    $candidate = DateTime::createFromFormat('Y-n-j', $year . '-' . $month . '-' . $day);
    if (!$candidate) {
        return null;
    }

    $now = new DateTimeImmutable('now');
    if ($candidate < $now->sub(new DateInterval('P60D'))) {
        $candidate->modify('+1 year');
    }

    return $candidate->format('Y-m-d');
}

function edudisplej_maintenance_classify_meal_slot(string $category): string {
    $value = edudisplej_maintenance_normalize_name($category);

    if ($value === '') {
        return 'lunch';
    }
    if (preg_match('/(ranajk|reggeli|breakfast)/', $value)) {
        return 'breakfast';
    }
    if (preg_match('/(desiata|tizorai|snack am|snackam)/', $value)) {
        return 'snack_am';
    }
    if (preg_match('/(obed|ebed|lunch)/', $value)) {
        return 'lunch';
    }
    if (preg_match('/(olovrant|uzsonna|snack pm|snackpm)/', $value)) {
        return 'snack_pm';
    }
    if (preg_match('/(vecera|vacsora|dinner)/', $value)) {
        return 'dinner';
    }
    return 'lunch';
}

function edudisplej_maintenance_clean_jedalen_meal_text(string $text): string {
    $value = trim(html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
    if ($value === '') {
        return '';
    }

    $value = preg_replace('/\bbody=\[[\s\S]*$/iu', '', $value);
    $value = preg_replace('/\b(header|windowlock|cssbody|cssheader|doubleclickstop|singleclickstop|requireclick|hideselects|fade)=\[[^\]]*\]/iu', '', $value);
    $value = preg_replace('/\be\.hod\.[^\n\r]*$/iu', '', $value);
    $value = preg_replace('/[ \t]{2,}/u', ' ', (string)$value);
    $value = preg_replace('/\n{3,}/u', "\n\n", (string)$value);

    return trim((string)$value);
}

function edudisplej_maintenance_extract_jedalen_menu_from_html(string $html): array {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($html);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $dayTables = $xpath->query("//table[contains(concat(' ', normalize-space(@class), ' '), ' menu-day ') or .//*[contains(concat(' ', normalize-space(@class), ' '), ' menu-tdmenu-title ')]]");

    $result = [];
    if (!$dayTables) {
        return $result;
    }

    foreach ($dayTables as $table) {
        $dayNode = $xpath->query(".//td[contains(concat(' ', normalize-space(@class), ' '), ' menu-day-date ')]//div[contains(concat(' ', normalize-space(@class), ' '), ' menu-day-date-day ')]", $table);
        $monthNode = $xpath->query(".//td[contains(concat(' ', normalize-space(@class), ' '), ' menu-day-date ')]//div[contains(concat(' ', normalize-space(@class), ' '), ' menu-day-date-month ')]", $table);

        $dayText = $dayNode && $dayNode->length > 0 ? trim((string)$dayNode->item(0)->textContent) : '';
        $monthText = $monthNode && $monthNode->length > 0 ? trim((string)$monthNode->item(0)->textContent) : '';

        if ($dayText === '' || $monthText === '') {
            $dateNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' menu-day-date ')]", $table);
            if ($dateNode && $dateNode->length > 0) {
                $dateText = trim((string)$dateNode->item(0)->textContent);
                if (preg_match('/(\d{1,2})\s*\.\s*(\d{1,2})\s*\.?/u', $dateText, $dm)) {
                    $dayText = (string)$dm[1];
                    $monthText = (string)$dm[2];
                }
            }
        }

        if ($dayText === '' || $monthText === '') {
            $tableText = trim((string)$table->textContent);
            if (preg_match('/(\d{1,2})\s*\.\s*(\d{1,2})\s*\.?/u', $tableText, $dm)) {
                $dayText = (string)$dm[1];
                $monthText = (string)$dm[2];
            }
        }

        $menuDate = edudisplej_maintenance_resolve_menu_date($dayText, $monthText);
        if (!$menuDate) {
            continue;
        }

        if (!isset($result[$menuDate])) {
            $result[$menuDate] = [
                'breakfast' => [],
                'snack_am' => [],
                'lunch' => [],
                'snack_pm' => [],
                'dinner' => [],
            ];
        }

        $rows = $xpath->query('.//tr', $table);
        if (!$rows) {
            continue;
        }

        $currentSlot = 'lunch';
        foreach ($rows as $tr) {
            $titleNode = $xpath->query(".//td[contains(concat(' ', normalize-space(@class), ' '), ' menu-tdmenu-title ')] | .//span[contains(concat(' ', normalize-space(@class), ' '), ' menu-tdmenu-title ')]", $tr);
            $categoryNode = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' menu-kindname-td ') or contains(concat(' ', normalize-space(@class), ' '), ' menu-kindname ')]", $tr);
            $category = ($categoryNode && $categoryNode->length > 0) ? trim((string)$categoryNode->item(0)->textContent) : '';
            if ($category !== '') {
                $currentSlot = edudisplej_maintenance_classify_meal_slot($category);
            }

            if (!$titleNode || $titleNode->length === 0) {
                continue;
            }

            $mealName = edudisplej_maintenance_clean_jedalen_meal_text((string)$titleNode->item(0)->textContent);
            if ($mealName === '') {
                continue;
            }

            if (preg_match('/^\d+\.\s*$/u', $mealName)) {
                continue;
            }

            $allergenNodes = $xpath->query('.//img[@title]', $tr);
            $allergens = [];
            if ($allergenNodes) {
                foreach ($allergenNodes as $img) {
                    $title = trim((string)$img->getAttribute('title'));
                    if ($title !== '') {
                        $allergens[] = $title;
                    }
                }
            }

            $allergenTextNodes = $xpath->query(".//*[contains(concat(' ', normalize-space(@class), ' '), ' eat-menu-sensitive ')]", $tr);
            if ($allergenTextNodes) {
                foreach ($allergenTextNodes as $node) {
                    $text = trim((string)$node->textContent);
                    if ($text !== '') {
                        $allergens[] = $text;
                    }
                }
            }

            $slot = $category !== '' ? edudisplej_maintenance_classify_meal_slot($category) : $currentSlot;
            $line = $mealName;
            if (!empty($allergens)) {
                $line .= ' (Allergének: ' . implode(', ', array_unique($allergens)) . ')';
            }

            $result[$menuDate][$slot][] = $line;
        }
    }

    return $result;
}

function edudisplej_maintenance_jedalen_week_postback_html(string $menuUrl, string $currentHtml, string $eventTarget): ?string {
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML($currentHtml);
    libxml_clear_errors();

    $xpath = new DOMXPath($doc);
    $hiddenInputs = $xpath->query("//input[@type='hidden' and @name]");
    if (!$hiddenInputs) {
        return null;
    }

    $fields = [];
    foreach ($hiddenInputs as $input) {
        $name = trim((string)$input->getAttribute('name'));
        if ($name === '') {
            continue;
        }
        $fields[$name] = (string)$input->getAttribute('value');
    }

    $fields['__EVENTTARGET'] = $eventTarget;
    $fields['__EVENTARGUMENT'] = '';

    return edudisplej_maintenance_http_post_form($menuUrl, $fields);
}

function edudisplej_maintenance_parse_jedalen_menu(string $menuUrl): array {
    $html = edudisplej_maintenance_http_get($menuUrl);
    $result = edudisplej_maintenance_extract_jedalen_menu_from_html($html);
    if (!empty($result)) {
        return $result;
    }

    $eventTargets = [
        'ctl00$MainPanel$DayItems1$lnkNextWeek',
        'ctl00$MainPanel$DayItems1$lnkNextWeek',
        'ctl00$MainPanel$DayItems1$lnkPreviousWeek',
    ];

    $currentHtml = $html;
    foreach ($eventTargets as $eventTarget) {
        try {
            $nextHtml = edudisplej_maintenance_jedalen_week_postback_html($menuUrl, $currentHtml, $eventTarget);
        } catch (Throwable $e) {
            continue;
        }

        if (!is_string($nextHtml) || trim($nextHtml) === '') {
            continue;
        }

        $currentHtml = $nextHtml;
        $parsed = edudisplej_maintenance_extract_jedalen_menu_from_html($currentHtml);
        if (!empty($parsed)) {
            return $parsed;
        }
    }

    return [];
}

function edudisplej_maintenance_get_used_meal_targets(mysqli $conn): array {
    $targets = [];

    $configuredQuery = "SELECT mi.company_id, mi.id AS institution_id, ms.site_key
                        FROM meal_plan_institutions mi
                        INNER JOIN meal_plan_sites ms ON ms.id = mi.site_id
                        WHERE mi.is_active = 1
                          AND ms.is_active = 1
                          AND mi.company_id > 0";
    $configuredResult = $conn->query($configuredQuery);
    if (!$configuredResult) {
        logError('Jedalen sync: failed to query configured meal targets: ' . $conn->error);
    } else {
        while ($row = $configuredResult->fetch_assoc()) {
            $companyId = (int)($row['company_id'] ?? 0);
            $institutionId = (int)($row['institution_id'] ?? 0);
            $siteKey = strtolower(trim((string)($row['site_key'] ?? '')));

            if ($companyId <= 0 || $institutionId <= 0 || $siteKey === '') {
                continue;
            }

            $key = $companyId . '|' . $siteKey . '|' . $institutionId;
            if (!isset($targets[$key])) {
                $targets[$key] = [
                    'company_id' => $companyId,
                    'site_key' => $siteKey,
                    'institution_id' => $institutionId,
                    'group_ids' => [],
                ];
            }
        }
    }

    $query = "SELECT kgm.group_id, kg.company_id, kgm.settings
              FROM kiosk_group_modules kgm
              INNER JOIN kiosk_groups kg ON kg.id = kgm.group_id
              LEFT JOIN modules m ON m.id = kgm.module_id
              WHERE kgm.is_active = 1
                AND (LOWER(COALESCE(kgm.module_key, '')) = 'meal-menu' OR LOWER(COALESCE(m.module_key, '')) = 'meal-menu')";
    $result = $conn->query($query);
    if (!$result) {
        logError('Jedalen sync: failed to query used meal targets: ' . $conn->error);
        return [];
    }

    while ($row = $result->fetch_assoc()) {
        $settingsRaw = (string)($row['settings'] ?? '');
        $settings = json_decode($settingsRaw, true);
        if (!is_array($settings)) {
            continue;
        }

        $institutionId = (int)($settings['institutionId'] ?? 0);
        $companyId = (int)($row['company_id'] ?? 0);
        $groupId = (int)($row['group_id'] ?? 0);
        $siteKey = strtolower(trim((string)($settings['siteKey'] ?? 'jedalen.sk')));

        if ($institutionId <= 0 || $companyId <= 0 || $groupId <= 0 || $siteKey === '') {
            continue;
        }

        $key = $companyId . '|' . $siteKey . '|' . $institutionId;
        if (!isset($targets[$key])) {
            $targets[$key] = [
                'company_id' => $companyId,
                'site_key' => $siteKey,
                'institution_id' => $institutionId,
                'group_ids' => [],
            ];
        }
        $targets[$key]['group_ids'][$groupId] = true;
    }

    return array_values(array_map(static function (array $entry): array {
        $entry['group_ids'] = array_values(array_map('intval', array_keys($entry['group_ids'])));
        return $entry;
    }, $targets));
}

function edudisplej_maintenance_get_meal_targets_for_institution_ids(mysqli $conn, array $institutionIds): array {
    $institutionIds = array_values(array_unique(array_filter(array_map('intval', $institutionIds), static function (int $id): bool {
        return $id > 0;
    })));
    if (empty($institutionIds)) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($institutionIds), '?'));
    $types = str_repeat('i', count($institutionIds));
    $sql = "SELECT mi.id AS institution_id, mi.company_id, ms.site_key
            FROM meal_plan_institutions mi
            INNER JOIN meal_plan_sites ms ON ms.id = mi.site_id
            WHERE mi.id IN ($placeholders)
              AND mi.is_active = 1
              AND ms.is_active = 1";
    $stmt = $conn->prepare($sql);
    if (!$stmt) {
        throw new RuntimeException('Jedalen sync: selected institution lookup prepare failed: ' . $conn->error);
    }

    $stmt->bind_param($types, ...$institutionIds);
    $stmt->execute();
    $result = $stmt->get_result();

    $targets = [];
    $targetsByKey = [];
    while ($row = $result->fetch_assoc()) {
        $institutionId = (int)($row['institution_id'] ?? 0);
        $companyId = (int)($row['company_id'] ?? 0);
        $siteKey = strtolower(trim((string)($row['site_key'] ?? '')));

        if ($institutionId <= 0 || $siteKey === '') {
            continue;
        }

        $key = $companyId . '|' . $siteKey . '|' . $institutionId;
        $targetsByKey[$key] = [
            'company_id' => $companyId,
            'site_key' => $siteKey,
            'institution_id' => $institutionId,
            'group_ids' => [],
        ];
    }
    $stmt->close();

    $moduleSql = "SELECT kgm.group_id, kg.company_id, kgm.settings
                  FROM kiosk_group_modules kgm
                  INNER JOIN kiosk_groups kg ON kg.id = kgm.group_id
                  LEFT JOIN modules m ON m.id = kgm.module_id
                  WHERE kgm.is_active = 1
                    AND (LOWER(COALESCE(kgm.module_key, '')) = 'meal-menu' OR LOWER(COALESCE(m.module_key, '')) = 'meal-menu')";
    $moduleResult = $conn->query($moduleSql);
    if ($moduleResult) {
        while ($row = $moduleResult->fetch_assoc()) {
            $settings = json_decode((string)($row['settings'] ?? ''), true);
            if (!is_array($settings)) {
                continue;
            }

            $institutionId = (int)($settings['institutionId'] ?? 0);
            $companyId = (int)($row['company_id'] ?? 0);
            $groupId = (int)($row['group_id'] ?? 0);
            $siteKey = strtolower(trim((string)($settings['siteKey'] ?? 'jedalen.sk')));
            if ($institutionId <= 0 || $companyId <= 0 || $groupId <= 0 || $siteKey === '') {
                continue;
            }

            $key = $companyId . '|' . $siteKey . '|' . $institutionId;
            if (!isset($targetsByKey[$key])) {
                continue;
            }
            $targetsByKey[$key]['group_ids'][$groupId] = true;
        }
    }

    foreach ($targetsByKey as $entry) {
        $entry['group_ids'] = array_values(array_map('intval', array_keys((array)$entry['group_ids'])));
        $targets[] = $entry;
    }

    return $targets;
}

function edudisplej_maintenance_bootstrap_jedalen_targets(mysqli $conn, array $syncConfig): array {
    $regions = array_values(array_unique(array_map(static function ($region) {
        return strtoupper(trim((string)$region));
    }, (array)($syncConfig['regions'] ?? []))));
    if (empty($regions)) {
        $regions = ['TT', 'NR', 'TN', 'BB', 'PO', 'KE', 'BA', 'ZA'];
    }

    $siteStmt = $conn->prepare("SELECT id FROM meal_plan_sites WHERE company_id = 0 AND site_key = 'jedalen.sk' LIMIT 1");
    if (!$siteStmt) {
        throw new RuntimeException('Jedalen bootstrap: site lookup prepare failed: ' . $conn->error);
    }
    $siteStmt->execute();
    $siteRow = $siteStmt->get_result()->fetch_assoc();
    $siteStmt->close();
    $globalSiteId = (int)($siteRow['id'] ?? 0);
    if ($globalSiteId <= 0) {
        throw new RuntimeException('Jedalen bootstrap: global jedalen.sk site missing');
    }

    $jedalenInstitutions = edudisplej_maintenance_fetch_jedalen_institutions($regions);
    if (empty($jedalenInstitutions)) {
        return [
            'targets' => [],
            'global_stored' => 0,
            'companies_total' => 0,
            'companies_matched' => 0,
        ];
    }

    $upsertGlobalStmt = $conn->prepare("INSERT INTO meal_plan_institutions (company_id, site_id, external_key, institution_name, city, is_active)
                                        VALUES (0, ?, ?, ?, '', 1)
                                        ON DUPLICATE KEY UPDATE
                                            institution_name = VALUES(institution_name),
                                            city = VALUES(city),
                                            is_active = 1,
                                            updated_at = CURRENT_TIMESTAMP,
                                            id = LAST_INSERT_ID(id)");
    if (!$upsertGlobalStmt) {
        throw new RuntimeException('Jedalen bootstrap: global institution upsert prepare failed: ' . $conn->error);
    }

    $globalStored = 0;
    $dedupe = [];
    foreach ($jedalenInstitutions as $item) {
        $menuUrl = trim((string)($item['menu_url'] ?? ''));
        $instName = trim((string)($item['institution_name'] ?? ''));
        if ($menuUrl === '' || $instName === '') {
            continue;
        }
        if (isset($dedupe[$menuUrl])) {
            continue;
        }
        $dedupe[$menuUrl] = true;
        $upsertGlobalStmt->bind_param('iss', $globalSiteId, $menuUrl, $instName);
        if ($upsertGlobalStmt->execute()) {
            $globalStored++;
        }
    }
    $upsertGlobalStmt->close();

    $jedalenByName = [];
    foreach ($jedalenInstitutions as $item) {
        $norm = (string)($item['institution_name_normalized'] ?? '');
        if ($norm === '') {
            continue;
        }
        if (!isset($jedalenByName[$norm])) {
            $jedalenByName[$norm] = [];
        }
        $jedalenByName[$norm][] = $item;
    }

    $companyRows = $conn->query("SELECT DISTINCT kg.company_id, kgm.group_id, c.name AS company_name
                                 FROM kiosk_group_modules kgm
                                 INNER JOIN kiosk_groups kg ON kg.id = kgm.group_id
                                 INNER JOIN companies c ON c.id = kg.company_id
                                 LEFT JOIN modules m ON m.id = kgm.module_id
                                 WHERE kgm.is_active = 1
                                   AND kg.company_id > 0
                                   AND (LOWER(COALESCE(kgm.module_key, '')) = 'meal-menu' OR LOWER(COALESCE(m.module_key, '')) = 'meal-menu')");
    if (!$companyRows) {
        throw new RuntimeException('Jedalen bootstrap: failed to query meal-menu companies: ' . $conn->error);
    }

    $companies = [];
    while ($row = $companyRows->fetch_assoc()) {
        $companyId = (int)($row['company_id'] ?? 0);
        $groupId = (int)($row['group_id'] ?? 0);
        if ($companyId <= 0 || $groupId <= 0) {
            continue;
        }
        if (!isset($companies[$companyId])) {
            $companies[$companyId] = [
                'company_name' => trim((string)($row['company_name'] ?? '')),
                'group_ids' => [],
            ];
        }
        $companies[$companyId]['group_ids'][$groupId] = true;
    }

    $upsertCompanyStmt = $conn->prepare("INSERT INTO meal_plan_institutions (company_id, site_id, external_key, institution_name, city, is_active)
                                         VALUES (?, ?, ?, ?, '', 1)
                                         ON DUPLICATE KEY UPDATE
                                            institution_name = VALUES(institution_name),
                                            city = VALUES(city),
                                            is_active = 1,
                                            updated_at = CURRENT_TIMESTAMP,
                                            id = LAST_INSERT_ID(id)");
    if (!$upsertCompanyStmt) {
        throw new RuntimeException('Jedalen bootstrap: company institution upsert prepare failed: ' . $conn->error);
    }

    $targets = [];
    $companiesMatched = 0;

    foreach ($companies as $companyId => $info) {
        $companyNorm = edudisplej_maintenance_normalize_name((string)($info['company_name'] ?? ''));
        if ($companyNorm === '') {
            continue;
        }

        $matched = null;
        if (!empty($jedalenByName[$companyNorm])) {
            $matched = $jedalenByName[$companyNorm][0];
        } else {
            foreach ($jedalenInstitutions as $candidate) {
                $candNorm = (string)($candidate['institution_name_normalized'] ?? '');
                if ($candNorm === '') {
                    continue;
                }
                if ((strlen($companyNorm) >= 5 && strpos($candNorm, $companyNorm) !== false)
                    || (strlen($candNorm) >= 5 && strpos($companyNorm, $candNorm) !== false)) {
                    $matched = $candidate;
                    break;
                }
            }
        }

        if (!$matched) {
            continue;
        }

        $menuUrl = trim((string)($matched['menu_url'] ?? ''));
        $instName = trim((string)($matched['institution_name'] ?? ''));
        if ($menuUrl === '' || $instName === '') {
            continue;
        }

        $upsertCompanyStmt->bind_param('iiss', $companyId, $globalSiteId, $menuUrl, $instName);
        if (!$upsertCompanyStmt->execute()) {
            continue;
        }

        $institutionId = (int)$conn->insert_id;
        if ($institutionId <= 0) {
            continue;
        }

        $targets[] = [
            'company_id' => (int)$companyId,
            'site_key' => 'jedalen.sk',
            'institution_id' => $institutionId,
            'group_ids' => array_values(array_map('intval', array_keys((array)$info['group_ids']))),
        ];
        $companiesMatched++;
    }

    $upsertCompanyStmt->close();

    return [
        'targets' => $targets,
        'global_stored' => $globalStored,
        'companies_total' => count($companies),
        'companies_matched' => $companiesMatched,
    ];
}

function edudisplej_maintenance_refresh_group_plan_versions(mysqli $conn, array $targets): int {
    if (empty($targets)) {
        return 0;
    }

    $maxUpdatedStmt = $conn->prepare("SELECT MAX(updated_at) AS max_updated
                                     FROM meal_plan_items
                                     WHERE company_id = ? AND institution_id = ?");
    if (!$maxUpdatedStmt) {
        logError('Jedalen sync: failed to prepare max-updated lookup: ' . $conn->error);
        return 0;
    }

    $planStmt = $conn->prepare("SELECT plan_version FROM kiosk_group_loop_plans WHERE group_id = ? LIMIT 1");
    if (!$planStmt) {
        $maxUpdatedStmt->close();
        logError('Jedalen sync: failed to prepare plan-version lookup: ' . $conn->error);
        return 0;
    }

    $updateStmt = $conn->prepare("UPDATE kiosk_group_loop_plans SET plan_version = ?, updated_at = CURRENT_TIMESTAMP WHERE group_id = ?");
    if (!$updateStmt) {
        $maxUpdatedStmt->close();
        $planStmt->close();
        logError('Jedalen sync: failed to prepare loop version refresh: ' . $conn->error);
        return 0;
    }

    $groupMaxVersion = [];
    foreach ($targets as $target) {
        $companyId = (int)($target['company_id'] ?? 0);
        $institutionId = (int)($target['institution_id'] ?? 0);
        $groupIds = array_values(array_unique(array_map('intval', (array)($target['group_ids'] ?? []))));
        if ($companyId <= 0 || $institutionId <= 0 || empty($groupIds)) {
            continue;
        }

        $maxUpdatedStmt->bind_param('ii', $companyId, $institutionId);
        if (!$maxUpdatedStmt->execute()) {
            continue;
        }
        $row = $maxUpdatedStmt->get_result()->fetch_assoc();
        $maxUpdated = trim((string)($row['max_updated'] ?? ''));
        if ($maxUpdated === '') {
            continue;
        }

        $versionToken = (int)(strtotime($maxUpdated) * 1000);
        if ($versionToken <= 0) {
            continue;
        }

        foreach ($groupIds as $groupId) {
            if (!isset($groupMaxVersion[$groupId]) || $groupMaxVersion[$groupId] < $versionToken) {
                $groupMaxVersion[$groupId] = $versionToken;
            }
        }
    }

    $updated = 0;
    foreach ($groupMaxVersion as $groupId => $versionToken) {
        $groupId = (int)$groupId;
        if ($groupId <= 0) {
            continue;
        }

        $planStmt->bind_param('i', $groupId);
        $currentVersion = 0;
        if ($planStmt->execute()) {
            $row = $planStmt->get_result()->fetch_assoc();
            $currentVersion = (int)($row['plan_version'] ?? 0);
        }

        if ($versionToken <= $currentVersion) {
            continue;
        }

        $versionTokenStr = (string)$versionToken;
        $updateStmt->bind_param('si', $versionTokenStr, $groupId);
        if ($updateStmt->execute()) {
            $updated++;
        }
    }

    $maxUpdatedStmt->close();
    $planStmt->close();
    $updateStmt->close();

    return $updated;
}

function edudisplej_maintenance_run_jedalen_daily_sync(mysqli $conn): void {
    $jobKey = 'jedalen_daily_sync';
    edudisplej_maintenance_ensure_job_tables($conn);
    edudisplej_maintenance_ensure_meal_schema($conn);

    $syncConfig = edudisplej_maintenance_get_jedalen_sync_config($conn);
    $institutionsOnly = defined('EDUDISPLEJ_JEDALEN_FETCH_INSTITUTIONS_ONLY') && EDUDISPLEJ_JEDALEN_FETCH_INSTITUTIONS_ONLY;
    $menusOnly = defined('EDUDISPLEJ_JEDALEN_FETCH_MENUS_ONLY') && EDUDISPLEJ_JEDALEN_FETCH_MENUS_ONLY;
    $selectedInstitutionIds = [];
    if (defined('EDUDISPLEJ_JEDALEN_SELECTED_INSTITUTION_IDS_CSV')) {
        $selectedInstitutionIds = edudisplej_maintenance_parse_int_csv((string)EDUDISPLEJ_JEDALEN_SELECTED_INSTITUTION_IDS_CSV);
    }

    $isForced = defined('EDUDISPLEJ_FORCE_JEDALEN_SYNC') && EDUDISPLEJ_FORCE_JEDALEN_SYNC;
    if (!$isForced && empty($syncConfig['enabled'])) {
        logResult('Jedalen sync skipped: disabled by maintenance settings.', 'info');
        return;
    }

    if ($institutionsOnly) {
        $bootstrap = edudisplej_maintenance_bootstrap_jedalen_targets($conn, $syncConfig);
        $globalStored = (int)($bootstrap['global_stored'] ?? 0);
        $companiesTotal = (int)($bootstrap['companies_total'] ?? 0);
        $companiesMatched = (int)($bootstrap['companies_matched'] ?? 0);
        $message = "Institution list refreshed: $globalStored, matched companies: $companiesMatched/$companiesTotal";
        edudisplej_maintenance_mark_job_run($conn, $jobKey, 'success', $message);
        logResult('Jedalen sync completed (institutions only): ' . $message, 'success');
        return;
    }

    if (!empty($selectedInstitutionIds)) {
        $targets = edudisplej_maintenance_get_meal_targets_for_institution_ids($conn, $selectedInstitutionIds);
        logResult('Jedalen sync: selected institutions mode, requested IDs: ' . implode(',', $selectedInstitutionIds) . ', resolved targets: ' . count($targets), 'info');
    } else {
        $targets = edudisplej_maintenance_get_used_meal_targets($conn);
    }

    if (empty($targets)) {
        $bootstrap = edudisplej_maintenance_bootstrap_jedalen_targets($conn, $syncConfig);
        if (!empty($selectedInstitutionIds)) {
            $targets = edudisplej_maintenance_get_meal_targets_for_institution_ids($conn, $selectedInstitutionIds);
        } else {
            $targets = is_array($bootstrap['targets'] ?? null) ? $bootstrap['targets'] : [];
        }

        $globalStored = (int)($bootstrap['global_stored'] ?? 0);
        $companiesTotal = (int)($bootstrap['companies_total'] ?? 0);
        $companiesMatched = (int)($bootstrap['companies_matched'] ?? 0);
        logResult("Jedalen bootstrap: institution list stored: $globalStored, matched companies: $companiesMatched/$companiesTotal", 'info');

        if (empty($targets)) {
            if (!empty($selectedInstitutionIds)) {
                $message = 'No matching institutions found for selected IDs.';
                edudisplej_maintenance_mark_job_run($conn, $jobKey, 'success', $message);
                logResult('Jedalen sync: ' . $message, 'warning');
                return;
            }

            edudisplej_maintenance_mark_job_run($conn, $jobKey, 'success', 'No configured meal-menu targets found (bootstrap matched 0 companies).');
            logResult('Jedalen sync: no configured targets and auto-bootstrap found no company match; skipping fetch.', 'info');
            return;
        }

        logResult('Jedalen sync: using auto-bootstrapped targets for this run.', 'info');
    }

    $forceRun = edudisplej_maintenance_should_force_jedalen_sync_for_missing_data($conn, $targets);
    $updatedTodayRun = edudisplej_maintenance_should_run_jedalen_by_updated_today($conn, $targets);
    $everyCycle = !empty($syncConfig['every_cycle']);

    if ($menusOnly) {
        logResult('Jedalen sync: manual menus-only mode (selected institutions).', 'info');
    } elseif ($everyCycle) {
        logResult('Jedalen sync: running on every maintenance cycle for loop-linked institutions (every_cycle=1).', 'info');
    } elseif (!empty($forceRun['run'])) {
        logResult('Jedalen sync: running now (missing cached data for used institutions: ' . (int)($forceRun['missing_count'] ?? 0) . ').', 'info');
    } elseif (empty($updatedTodayRun['run'])) {
        logResult('Jedalen sync skipped: all loop-linked institutions already updated today (updated_at).', 'info');
        return;
    } else {
        logResult('Jedalen sync: running now (institutions not updated today: ' . (int)($updatedTodayRun['missing_count'] ?? 0) . ').', 'info');
    }

    try {
        $institutionIds = array_values(array_unique(array_map(static function (array $target): int {
            return (int)$target['institution_id'];
        }, $targets)));
        $idPlaceholders = implode(',', array_fill(0, count($institutionIds), '?'));
        $types = str_repeat('i', count($institutionIds));

        $instSql = "SELECT mi.id, mi.company_id, mi.external_key, mi.institution_name, mi.city, ms.site_key
                    FROM meal_plan_institutions mi
                    INNER JOIN meal_plan_sites ms ON ms.id = mi.site_id
                    WHERE mi.id IN ($idPlaceholders) AND mi.is_active = 1";
        $instStmt = $conn->prepare($instSql);
        if (!$instStmt) {
            throw new RuntimeException('Jedalen sync: institution lookup prepare failed: ' . $conn->error);
        }

        $instStmt->bind_param($types, ...$institutionIds);
        $instStmt->execute();
        $instResult = $instStmt->get_result();
        $institutionsById = [];
        while ($row = $instResult->fetch_assoc()) {
            $institutionsById[(int)$row['id']] = $row;
        }
        $instStmt->close();

        $regions = array_values(array_unique(array_map(static function ($region) {
            return strtoupper(trim((string)$region));
        }, (array)($syncConfig['regions'] ?? []))));
        if (empty($regions)) {
            $regions = ['TT', 'NR', 'TN', 'BB', 'PO', 'KE', 'BA', 'ZA'];
        }
        $jedalenInstitutions = edudisplej_maintenance_fetch_jedalen_institutions($regions);
        $jedalenByName = [];
        foreach ($jedalenInstitutions as $item) {
            $norm = (string)($item['institution_name_normalized'] ?? '');
            if ($norm === '') {
                continue;
            }
            if (!isset($jedalenByName[$norm])) {
                $jedalenByName[$norm] = [];
            }
            $jedalenByName[$norm][] = $item;
        }

        $upsertStmt = $conn->prepare("INSERT INTO meal_plan_items (company_id, institution_id, menu_date, breakfast, snack_am, lunch, snack_pm, dinner, source_type)
                                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'server')
                                     ON DUPLICATE KEY UPDATE
                                         breakfast = VALUES(breakfast),
                                         snack_am = VALUES(snack_am),
                                         lunch = VALUES(lunch),
                                         snack_pm = VALUES(snack_pm),
                                         dinner = VALUES(dinner),
                                         source_type = 'server',
                                         updated_at = CURRENT_TIMESTAMP");
        if (!$upsertStmt) {
            throw new RuntimeException('Jedalen sync: upsert prepare failed: ' . $conn->error);
        }

        $successTargetsForVersion = [];
        $targetsProcessed = 0;
        $menuDaysStored = 0;

        foreach ($targets as $target) {
            $institutionId = (int)$target['institution_id'];
            $targetCompany = (int)$target['company_id'];
            $siteKey = strtolower((string)($target['site_key'] ?? ''));

            if ($siteKey !== 'jedalen.sk') {
                continue;
            }

            $inst = $institutionsById[$institutionId] ?? null;
            if (!$inst) {
                logResult("Jedalen sync: institution #$institutionId not found in meal_plan_institutions", 'warning');
                continue;
            }

            $nameNorm = edudisplej_maintenance_normalize_name((string)($inst['institution_name'] ?? ''));
            $menuUrl = '';
            $externalKey = trim((string)($inst['external_key'] ?? ''));
            if ($externalKey !== '' && preg_match('/^https?:\/\//i', $externalKey)) {
                $menuUrl = edudisplej_maintenance_normalize_jedalen_menu_url($externalKey);
            }

            if ($menuUrl === '' && $nameNorm !== '' && !empty($jedalenByName[$nameNorm])) {
                $menuUrl = edudisplej_maintenance_normalize_jedalen_menu_url((string)($jedalenByName[$nameNorm][0]['menu_url'] ?? ''));
            }

            if ($menuUrl === '') {
                logResult("Jedalen sync: no matching jedalen.sk source found for institution #$institutionId ({$inst['institution_name']})", 'warning');
                continue;
            }

            $parsedDays = edudisplej_maintenance_parse_jedalen_menu($menuUrl);
            if (empty($parsedDays)) {
                logResult("Jedalen sync: no menu rows parsed for institution #$institutionId ($menuUrl)", 'warning');
                continue;
            }

            $institutionStoredCount = 0;
            foreach ($parsedDays as $menuDate => $dayMeals) {
                $breakfast = implode("\n", array_values(array_unique($dayMeals['breakfast'] ?? [])));
                $snackAm = implode("\n", array_values(array_unique($dayMeals['snack_am'] ?? [])));
                $lunch = implode("\n", array_values(array_unique($dayMeals['lunch'] ?? [])));
                $snackPm = implode("\n", array_values(array_unique($dayMeals['snack_pm'] ?? [])));
                $dinner = implode("\n", array_values(array_unique($dayMeals['dinner'] ?? [])));

                $upsertStmt->bind_param(
                    'iissssss',
                    $targetCompany,
                    $institutionId,
                    $menuDate,
                    $breakfast,
                    $snackAm,
                    $lunch,
                    $snackPm,
                    $dinner
                );
                if ($upsertStmt->execute()) {
                    $menuDaysStored++;
                    $institutionStoredCount++;
                }
            }

            if ($institutionStoredCount > 0) {
                $successTargetsForVersion[] = [
                    'company_id' => (int)$targetCompany,
                    'institution_id' => (int)$institutionId,
                    'group_ids' => array_values(array_unique(array_map('intval', (array)($target['group_ids'] ?? [])))),
                ];
            }
            $targetsProcessed++;
        }

        $upsertStmt->close();

        $refreshedGroups = edudisplej_maintenance_refresh_group_plan_versions($conn, $successTargetsForVersion);
        $message = "Targets processed: $targetsProcessed, menu days stored: $menuDaysStored, refreshed groups: $refreshedGroups";
        edudisplej_maintenance_mark_job_run($conn, $jobKey, 'success', $message);
        logResult('Jedalen sync completed: ' . $message, 'success');
    } catch (Throwable $e) {
        $message = 'Jedalen sync failed: ' . $e->getMessage();
        edudisplej_maintenance_mark_job_run($conn, $jobKey, 'error', $message);
        logError($message);
    }
}

try {
    @set_time_limit(240);

    $conn = getDbConnection();
    
    // Set charset explicitly
    if (!$conn->set_charset("utf8mb4")) {
        logError("Failed to set charset utf8mb4: " . $conn->error);
        // Try fallback to utf8
        if (!$conn->set_charset("utf8")) {
            throw new Exception("Cannot set any UTF-8 charset");
        }
        logResult("Using utf8 charset (fallback)", 'warning');
        $charset = 'utf8';
        $collation = 'utf8_unicode_ci';
    } else {
        logResult("Charset set to utf8mb4", 'success');
        $charset = 'utf8mb4';
        $collation = 'utf8mb4_unicode_ci';
    }

    $onlyJedalenSync = defined('EDUDISPLEJ_MAINTENANCE_ONLY_JEDALEN_SYNC') && EDUDISPLEJ_MAINTENANCE_ONLY_JEDALEN_SYNC;
    if ($onlyJedalenSync) {
        logResult('Only Jedalen sync mode enabled; skipping full maintenance pipeline.', 'info');
        edudisplej_maintenance_run_jedalen_daily_sync($conn);
        closeDbConnection($conn);
        return;
    }
    
    logResult("Connected to database successfully", 'success');
    
    // Log MySQL version
    $version = $conn->query("SELECT VERSION() as version")->fetch_assoc();
    logResult("MySQL/MariaDB version: " . $version['version'], 'info');
    
    // Log current charset settings
    $charset_result = $conn->query("SHOW VARIABLES LIKE 'character_set%'");
    while ($row = $charset_result->fetch_assoc()) {
        logResult("DB Setting: " . $row['Variable_name'] . " = " . $row['Value'], 'info');
    }
    
    // Define expected schema
    $expected_tables = [
        'users' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'username' => "varchar(255) NOT NULL",
                'password' => "varchar(255) NOT NULL",
                'email' => "varchar(255) DEFAULT NULL",
                'lang' => "varchar(5) NOT NULL DEFAULT 'sk'",
                'isadmin' => "tinyint(1) NOT NULL DEFAULT 0",
                'is_super_admin' => "tinyint(1) NOT NULL DEFAULT 0",
                'role' => "enum('super_admin','admin','content_editor','viewer') DEFAULT 'viewer'",
                'company_id' => "int(11) DEFAULT NULL",
                'otp_enabled' => "tinyint(1) NOT NULL DEFAULT 0",
                'otp_secret' => "varchar(255) DEFAULT NULL",
                'otp_verified' => "tinyint(1) NOT NULL DEFAULT 0",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'last_login' => "timestamp NULL DEFAULT NULL"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['username'],
            'foreign_keys' => [
                'users_company_fk' => "FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL"
            ]
        ],
        'companies' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'name' => "varchar(255) NOT NULL",
                'license_key' => "varchar(255) DEFAULT NULL",
                'api_token' => "varchar(255) DEFAULT NULL",
                'token_created_at' => "timestamp NULL DEFAULT NULL",
                'is_active' => "tinyint(1) NOT NULL DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'signing_secret' => "varchar(256) DEFAULT NULL COMMENT 'HMAC-SHA256 signing secret for request signature validation'"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['license_key', 'api_token'],
            'foreign_keys' => []
        ],
        'kiosks' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'hostname' => "text DEFAULT NULL",
                'friendly_name' => "varchar(255) DEFAULT NULL",
                'installed' => "datetime NOT NULL DEFAULT current_timestamp()",
                'mac' => "text NOT NULL",
                'device_id' => "varchar(20) DEFAULT NULL",
                'public_ip' => "varchar(45) DEFAULT NULL",
                'last_seen' => "timestamp NULL DEFAULT NULL",
                'hw_info' => "text DEFAULT NULL",
                'version' => "varchar(50) DEFAULT NULL",
                'screen_resolution' => "varchar(50) DEFAULT NULL",
                'screen_status' => "varchar(20) DEFAULT NULL",
                'loop_last_update' => "datetime DEFAULT NULL",
                'last_sync' => "datetime DEFAULT NULL",
                'screenshot_url' => "text DEFAULT NULL",
                'screenshot_enabled' => "tinyint(1) DEFAULT 1",
                'debug_mode' => "tinyint(1) DEFAULT 0",
                'screenshot_requested' => "tinyint(1) DEFAULT 0",
                'screenshot_timestamp' => "timestamp NULL DEFAULT NULL",
                'screenshot_requested_until' => "datetime DEFAULT NULL",
                'screenshot_interval_seconds' => "int(11) DEFAULT 3",
                'status' => "enum('online','offline','pending','unconfigured','upgrading','error') DEFAULT 'unconfigured'",
                'upgrade_started_at' => "datetime DEFAULT NULL",
                'company_id' => "int(11) DEFAULT NULL",
                'location' => "text DEFAULT NULL",
                'comment' => "text DEFAULT NULL",
                'sync_interval' => "int(11) DEFAULT 300",
                'is_configured' => "tinyint(1) DEFAULT 0"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kiosks_company_fk' => "FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE SET NULL"
            ]
        ],
        'kiosk_groups' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'name' => "varchar(255) NOT NULL",
                'company_id' => "int(11) DEFAULT NULL",
                'description' => "text DEFAULT NULL",
                'priority' => "int(11) NOT NULL DEFAULT 0",
                'is_default' => "tinyint(1) NOT NULL DEFAULT 0",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['company_id,name'],
            'foreign_keys' => [
                'kiosk_groups_company_fk' => "FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_group_assignments' => [
            'columns' => [
                'kiosk_id' => "int(11) NOT NULL",
                'group_id' => "int(11) NOT NULL"
            ],
            'primary_key' => 'kiosk_id,group_id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kga_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE",
                'kga_group_fk' => "FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE"
            ]
        ],
        'sync_logs' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) DEFAULT NULL",
                'timestamp' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'action' => "varchar(255) DEFAULT NULL",
                'details' => "text DEFAULT NULL"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'sync_logs_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE SET NULL"
            ]
        ],
        'modules' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'module_key' => "varchar(100) NOT NULL",
                'name' => "varchar(255) NOT NULL",
                'description' => "text DEFAULT NULL",
                'is_active' => "tinyint(1) DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['module_key'],
            'foreign_keys' => []
        ],
        'module_licenses' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'company_id' => "int(11) NOT NULL",
                'module_id' => "int(11) NOT NULL",
                'quantity' => "int(11) DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'ml_company_fk' => "FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE",
                'ml_module_fk' => "FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE"
            ]
        ],
        'group_modules' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'group_id' => "int(11) NOT NULL",
                'module_sequence' => "int(11) NOT NULL",
                'module_id' => "int(11) NOT NULL",
                'duration_seconds' => "int(11) DEFAULT 10",
                'settings' => "text DEFAULT NULL",
                'is_active' => "tinyint(1) DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'gm_group_fk' => "FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE",
                'gm_module_fk' => "FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_group_modules' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'group_id' => "int(11) NOT NULL",
                'module_id' => "int(11) NOT NULL",
                'module_key' => "varchar(100) DEFAULT NULL",
                'display_order' => "int(11) DEFAULT 0",
                'duration_seconds' => "int(11) DEFAULT 10",
                'settings' => "text DEFAULT NULL",
                'is_active' => "tinyint(1) DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kgm_group_fk' => "FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE",
                'kgm_module_fk' => "FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_group_loop_plans' => [
            'columns' => [
                'group_id' => "int(11) NOT NULL",
                'plan_json' => "longtext NOT NULL",
                'plan_version' => "bigint(20) NOT NULL DEFAULT 0",
                'updated_at' => "timestamp NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()"
            ],
            'primary_key' => 'group_id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kglp_group_fk' => "FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_modules' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) NOT NULL",
                'module_id' => "int(11) NOT NULL",
                'module_key' => "varchar(100) DEFAULT NULL",
                'display_order' => "int(11) DEFAULT 0",
                'duration_seconds' => "int(11) DEFAULT 10",
                'settings' => "text DEFAULT NULL",
                'is_active' => "tinyint(1) DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'km_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE",
                'km_module_fk' => "FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE"
            ]
        ],
        // Health monitoring and command execution tables
        'kiosk_health' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) NOT NULL",
                'status' => "varchar(50) NOT NULL DEFAULT 'unknown'",
                'system_data' => "json DEFAULT NULL",
                'services_data' => "json DEFAULT NULL",
                'network_data' => "json DEFAULT NULL",
                'sync_data' => "json DEFAULT NULL",
                'timestamp' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kh_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_health_logs' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) NOT NULL",
                'status' => "varchar(50) NOT NULL",
                'details' => "json DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'khl_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_command_queue' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) NOT NULL",
                'command_type' => "varchar(50) NOT NULL",
                'command' => "text NOT NULL",
                'status' => "varchar(50) NOT NULL DEFAULT 'pending'",
                'output' => "longtext DEFAULT NULL",
                'error' => "longtext DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'executed_at' => "timestamp NULL DEFAULT NULL"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kcq_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE"
            ]
        ],
        'kiosk_command_logs' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kiosk_id' => "int(11) NOT NULL",
                'command_id' => "int(11) NOT NULL",
                'action' => "varchar(50) NOT NULL",
                'details' => "json DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'kcl_kiosk_fk' => "FOREIGN KEY (kiosk_id) REFERENCES kiosks(id) ON DELETE CASCADE",
                'kcl_command_fk' => "FOREIGN KEY (command_id) REFERENCES kiosk_command_queue(id) ON DELETE CASCADE"
            ]
        ],
        'company_licenses' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'company_id' => "int(11) NOT NULL",
                'valid_from' => "date NOT NULL",
                'valid_until' => "date NOT NULL",
                'device_limit' => "int(11) NOT NULL DEFAULT 10",
                'status' => "varchar(20) NOT NULL DEFAULT 'active'",
                'notes' => "text DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => [
                'company_licenses_company_fk' => "FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE"
            ]
        ],
        'system_settings' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'setting_key' => "varchar(100) NOT NULL",
                'setting_value' => "longtext DEFAULT NULL",
                'is_encrypted' => "tinyint(1) NOT NULL DEFAULT 0",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['setting_key'],
            'foreign_keys' => []
        ],
        'email_templates' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'template_key' => "varchar(100) NOT NULL",
                'lang' => "varchar(5) NOT NULL DEFAULT 'en'",
                'subject' => "varchar(255) NOT NULL",
                'body_html' => "longtext DEFAULT NULL",
                'body_text' => "longtext DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['template_key,lang'],
            'foreign_keys' => []
        ],
        'email_logs' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'template_key' => "varchar(100) DEFAULT NULL",
                'to_email' => "varchar(255) NOT NULL",
                'subject' => "varchar(255) NOT NULL",
                'result' => "varchar(20) NOT NULL",
                'error_message' => "text DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => [],
            'foreign_keys' => []
        ],
        'service_versions' => [
            'columns' => [
                'id' => "int(11) NOT NULL AUTO_INCREMENT",
                'service_name' => "varchar(255) NOT NULL",
                'version_token' => "varchar(64) NOT NULL",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()",
                'updated_by_user_id' => "int(11) DEFAULT NULL"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['service_name'],
            'foreign_keys' => [
                'service_versions_user_fk' => "FOREIGN KEY (updated_by_user_id) REFERENCES users(id) ON DELETE SET NULL"
            ]
        ],
        'api_nonces' => [
            'columns' => [
                'id' => "bigint(20) NOT NULL AUTO_INCREMENT",
                'nonce' => "varchar(128) NOT NULL",
                'company_id' => "int(11) NOT NULL",
                'expires_at' => "datetime NOT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'id',
            'unique_keys' => ['nonce'],
            'foreign_keys' => []
        ],
        'display_schedules' => [
            'columns' => [
                'schedule_id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kijelzo_id' => "int(11) NOT NULL",
                'group_id' => "int(11) DEFAULT NULL",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()",
                'updated_at' => "timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()"
            ],
            'primary_key' => 'schedule_id',
            'unique_keys' => [],
            'foreign_keys' => [
                'display_schedules_kijelzo_fk' => "FOREIGN KEY (kijelzo_id) REFERENCES kiosks(id) ON DELETE CASCADE",
                'display_schedules_group_fk' => "FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE SET NULL"
            ]
        ],
        'schedule_time_slots' => [
            'columns' => [
                'slot_id' => "int(11) NOT NULL AUTO_INCREMENT",
                'schedule_id' => "int(11) NOT NULL",
                'day_of_week' => "int(1) NOT NULL COMMENT '0=Sunday, 6=Saturday'",
                'start_time' => "time NOT NULL",
                'end_time' => "time NOT NULL",
                'is_enabled' => "tinyint(1) NOT NULL DEFAULT 1",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'slot_id',
            'unique_keys' => [],
            'foreign_keys' => [
                'schedule_time_slots_schedule_fk' => "FOREIGN KEY (schedule_id) REFERENCES display_schedules(schedule_id) ON DELETE CASCADE"
            ]
        ],
        'schedule_special_days' => [
            'columns' => [
                'special_day_id' => "int(11) NOT NULL AUTO_INCREMENT",
                'schedule_id' => "int(11) NOT NULL",
                'date' => "date NOT NULL",
                'start_time' => "time DEFAULT NULL",
                'end_time' => "time DEFAULT NULL",
                'is_enabled' => "tinyint(1) NOT NULL DEFAULT 1",
                'reason' => "varchar(255) DEFAULT NULL COMMENT 'Holiday, maintenance, etc.'",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'special_day_id',
            'unique_keys' => [],
            'foreign_keys' => [
                'schedule_special_days_schedule_fk' => "FOREIGN KEY (schedule_id) REFERENCES display_schedules(schedule_id) ON DELETE CASCADE"
            ]
        ],
        'display_status_log' => [
            'columns' => [
                'log_id' => "int(11) NOT NULL AUTO_INCREMENT",
                'kijelzo_id' => "int(11) NOT NULL",
                'previous_status' => "varchar(50) DEFAULT NULL",
                'new_status' => "varchar(50) NOT NULL",
                'reason' => "varchar(255) DEFAULT NULL",
                'triggered_by' => "varchar(100) DEFAULT 'daemon' COMMENT 'script, daemon, admin, api, etc.'",
                'created_at' => "timestamp NOT NULL DEFAULT current_timestamp()"
            ],
            'primary_key' => 'log_id',
            'unique_keys' => [],
            'foreign_keys' => [
                'display_status_log_kijelzo_fk' => "FOREIGN KEY (kijelzo_id) REFERENCES kiosks(id) ON DELETE CASCADE"
            ]
        ]
    ];
    
    // Check and create tables
    foreach ($expected_tables as $table_name => $table_def) {
        $table_exists = false;
        
        logResult("Processing table: $table_name", 'info');
        
        // Check if table exists
        $result = $conn->query("SHOW TABLES LIKE '$table_name'");
        if ($result->num_rows > 0) {
            $table_exists = true;
            logResult("Table '$table_name' exists", 'info');
            
            // Check columns
            $result = $conn->query("DESCRIBE $table_name");
            $existing_columns = [];
            while ($row = $result->fetch_assoc()) {
                $existing_columns[$row['Field']] = $row;
            }
            
            // Add missing columns
            foreach ($table_def['columns'] as $col_name => $col_def) {
                if (!isset($existing_columns[$col_name])) {
                    $sql = "ALTER TABLE $table_name ADD COLUMN $col_name $col_def";
                    logResult("Executing SQL: $sql", 'info');
                    if ($conn->query($sql)) {
                        logResult("Added column '$col_name' to table '$table_name'", 'success');
                    } else {
                        logError("Failed to add column '$col_name' to table '$table_name': " . $conn->error);
                    }
                }
            }

            // Fix kiosks.status column if type/enum values are outdated
            if ($table_name === 'kiosks' && isset($existing_columns['status'])) {
                $current_type = strtolower($existing_columns['status']['Type'] ?? '');
                $expected_type = strtolower($table_def['columns']['status']);

                if ($current_type !== '' && $current_type !== $expected_type) {
                    $sql = "ALTER TABLE $table_name MODIFY COLUMN status " . $table_def['columns']['status'];
                    logResult("Executing SQL: $sql", 'info');
                    if ($conn->query($sql)) {
                        logResult("Updated column 'status' in table '$table_name' to expected enum set", 'success');
                    } else {
                        logError("Failed to update column 'status' in table '$table_name': " . $conn->error);
                    }
                } else {
                    logResult("Column 'status' in table '$table_name' already supports expected enum values", 'info');
                }
            }
        } else {
            // Create table
            logResult("Table '$table_name' does not exist. Creating...", 'warning');
            
            $columns_sql = [];
            foreach ($table_def['columns'] as $col_name => $col_def) {
                $columns_sql[] = "$col_name $col_def";
            }
            
            // Add primary key
            if (strpos($table_def['primary_key'], ',') !== false) {
                $columns_sql[] = "PRIMARY KEY (" . $table_def['primary_key'] . ")";
            } else {
                $columns_sql[] = "PRIMARY KEY (" . $table_def['primary_key'] . ")";
            }
            
            // Add unique keys
            foreach ($table_def['unique_keys'] as $uk) {
                $columns_sql[] = "UNIQUE KEY ($uk)";
            }
            
            $sql = "CREATE TABLE $table_name (\n  " . implode(",\n  ", $columns_sql) . "\n) ENGINE=InnoDB DEFAULT CHARSET=$charset COLLATE=$collation";
            
            logResult("Executing SQL: $sql", 'info');
            
            if ($conn->query($sql)) {
                logResult("Created table '$table_name'", 'success');
            } else {
                logError("Failed to create table '$table_name': " . $conn->error);
            }
        }
    }
    
    // Add foreign keys (do this after all tables are created)
    logResult("Checking foreign key constraints...", 'info');
    foreach ($expected_tables as $table_name => $table_def) {
        if (!empty($table_def['foreign_keys'])) {
            // Get existing foreign keys
            $result = $conn->query("SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS 
                                    WHERE TABLE_SCHEMA = '" . DB_NAME . "' 
                                    AND TABLE_NAME = '$table_name' 
                                    AND CONSTRAINT_TYPE = 'FOREIGN KEY'");
            $existing_fks = [];
            while ($row = $result->fetch_assoc()) {
                $existing_fks[$row['CONSTRAINT_NAME']] = true;
            }
            
            foreach ($table_def['foreign_keys'] as $fk_name => $fk_def) {
                if (!isset($existing_fks[$fk_name])) {
                    $sql = "ALTER TABLE $table_name ADD CONSTRAINT $fk_name $fk_def";
                    logResult("Executing SQL: $sql", 'info');
                    if ($conn->query($sql)) {
                        logResult("Added foreign key '$fk_name' to table '$table_name'", 'success');
                    } else {
                        // Foreign key might fail if referenced table doesn't exist yet or data integrity issue
                        logError("Failed to add foreign key '$fk_name' to table '$table_name': " . $conn->error);
                    }
                }
            }
        }
    }
    
    // Create default admin user if not exists
    $result = $conn->query("SELECT id FROM users WHERE username = 'admin'");
    if ($result->num_rows == 0) {
        $default_password = password_hash('admin123', PASSWORD_DEFAULT);
        $username = 'admin';
        $email = 'admin@edudisplej.sk';
        $isadmin = 1;
        $stmt = $conn->prepare("INSERT INTO users (username, password, email, isadmin) VALUES (?, ?, ?, ?)");
        $stmt->bind_param("sssi", $username, $default_password, $email, $isadmin);
        if ($stmt->execute()) {
            logResult("Created default admin user (username: admin, password: admin123)", 'success');
        } else {
            logError("Failed to create default admin user: " . $conn->error);
        }
        $stmt->close();
    } else {
        logResult("Default admin user already exists", 'info');
    }
    
    // Create default company if not exists
    $result = $conn->query("SELECT id FROM companies WHERE name = 'Default Company'");
    if ($result->num_rows == 0) {
        $company_name = 'Default Company';
        $stmt = $conn->prepare("INSERT INTO companies (name) VALUES (?)");
        $stmt->bind_param("s", $company_name);
        if ($stmt->execute()) {
            logResult("Created default company", 'success');
        } else {
            logError("Failed to create default company: " . $conn->error);
        }
        $stmt->close();
    } else {
        logResult("Default company already exists", 'info');
    }
    
    // Create default modules if not exist
    $default_modules = [
        ['key' => 'clock', 'name' => 'Clock & Time', 'description' => 'Display date and time with customizable formats and colors'],
        ['key' => 'default-logo', 'name' => 'Default Logo', 'description' => 'Display EduDisplej logo with version number and customizable text'],
        ['key' => 'text', 'name' => 'Text', 'description' => 'Display richly formatted text with optional scroll mode and background image'],
        ['key' => 'meal-menu', 'name' => 'Meal Menu', 'description' => 'Display school meal plan with source/institution filtering and offline fallback'],
        ['key' => 'room-occupancy', 'name' => 'Room Occupancy', 'description' => 'Display room occupancy schedule with manual and external API sync support'],
        ['key' => 'unconfigured', 'name' => 'Unconfigured Display', 'description' => 'Default screen for unconfigured kiosks']
    ];
    
    foreach ($default_modules as $module) {
        $stmt = $conn->prepare("SELECT id, name, description, is_active FROM modules WHERE module_key = ?");
        $stmt->bind_param("s", $module['key']);
        $stmt->execute();
        $existing = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$existing) {
            $stmt = $conn->prepare("INSERT INTO modules (module_key, name, description, is_active) VALUES (?, ?, ?, 1)");
            $stmt->bind_param("sss", $module['key'], $module['name'], $module['description']);
            if ($stmt->execute()) {
                logResult("Created module: " . $module['name'], 'success');
            } else {
                logError("Failed to create module '" . $module['name'] . "': " . $conn->error);
            }
            $stmt->close();
            continue;
        }

        $existing_name = (string)($existing['name'] ?? '');
        $existing_description = (string)($existing['description'] ?? '');
        if ($existing_name !== (string)$module['name'] || $existing_description !== (string)$module['description']) {
            $module_id = (int)$existing['id'];
            $stmt = $conn->prepare("UPDATE modules SET name = ?, description = ? WHERE id = ?");
            $stmt->bind_param("ssi", $module['name'], $module['description'], $module_id);
            if ($stmt->execute()) {
                logResult("Updated module metadata: " . $module['name'], 'success');
            } else {
                logError("Failed to update module metadata '" . $module['name'] . "': " . $conn->error);
            }
            $stmt->close();
        } else {
            logResult("Module '" . $module['name'] . "' already synced", 'info');
        }
    }

    // Normalize legacy datetime aliases to canonical 'clock' module
    $legacy_aliases = ['datetime', 'dateclock'];
    $clock_id = 0;

    $stmt = $conn->prepare("SELECT id FROM modules WHERE module_key = 'clock' LIMIT 1");
    $stmt->execute();
    $clock_row = $stmt->get_result()->fetch_assoc();
    $stmt->close();
    $clock_id = (int)($clock_row['id'] ?? 0);

    if ($clock_id > 0) {
        foreach ($legacy_aliases as $legacy_key) {
            $stmt = $conn->prepare("SELECT id FROM modules WHERE module_key = ? LIMIT 1");
            $stmt->bind_param("s", $legacy_key);
            $stmt->execute();
            $legacy_row = $stmt->get_result()->fetch_assoc();
            $stmt->close();

            $legacy_id = (int)($legacy_row['id'] ?? 0);
            if ($legacy_id <= 0) {
                continue;
            }

            safeDeleteByQuery(
                $conn,
                "UPDATE kiosk_modules SET module_id = $clock_id, module_key = 'clock' WHERE module_id = $legacy_id OR module_key = '$legacy_key'",
                "kiosk_modules migrated from '$legacy_key' to 'clock'"
            );

            safeDeleteByQuery(
                $conn,
                "UPDATE kiosk_group_modules SET module_id = $clock_id, module_key = 'clock' WHERE module_id = $legacy_id OR module_key = '$legacy_key'",
                "kiosk_group_modules migrated from '$legacy_key' to 'clock'"
            );

            safeDeleteByQuery(
                $conn,
                "UPDATE group_modules SET module_id = $clock_id WHERE module_id = $legacy_id",
                "group_modules migrated from '$legacy_key' to 'clock'"
            );

            // Merge duplicate module_licenses into canonical clock licenses per company
            $license_sum_stmt = $conn->prepare("SELECT company_id, SUM(quantity) AS qty FROM module_licenses WHERE module_id = ? GROUP BY company_id");
            $license_sum_stmt->bind_param("i", $legacy_id);
            $license_sum_stmt->execute();
            $license_sum_result = $license_sum_stmt->get_result();

            while ($license_row = $license_sum_result->fetch_assoc()) {
                $company_id = (int)($license_row['company_id'] ?? 0);
                $quantity_to_merge = (int)($license_row['qty'] ?? 0);
                if ($company_id <= 0 || $quantity_to_merge <= 0) {
                    continue;
                }

                $existing_clock_license_stmt = $conn->prepare("SELECT id, quantity FROM module_licenses WHERE company_id = ? AND module_id = ? LIMIT 1");
                $existing_clock_license_stmt->bind_param("ii", $company_id, $clock_id);
                $existing_clock_license_stmt->execute();
                $existing_clock_license = $existing_clock_license_stmt->get_result()->fetch_assoc();
                $existing_clock_license_stmt->close();

                if ($existing_clock_license) {
                    $clock_license_id = (int)$existing_clock_license['id'];
                    $new_qty = (int)$existing_clock_license['quantity'] + $quantity_to_merge;
                    $update_clock_license_stmt = $conn->prepare("UPDATE module_licenses SET quantity = ? WHERE id = ?");
                    $update_clock_license_stmt->bind_param("ii", $new_qty, $clock_license_id);
                    if (!$update_clock_license_stmt->execute()) {
                        logError("Failed to merge license qty for company $company_id from '$legacy_key' into 'clock': " . $conn->error);
                    }
                    $update_clock_license_stmt->close();
                } else {
                    $insert_clock_license_stmt = $conn->prepare("INSERT INTO module_licenses (company_id, module_id, quantity) VALUES (?, ?, ?)");
                    $insert_clock_license_stmt->bind_param("iii", $company_id, $clock_id, $quantity_to_merge);
                    if (!$insert_clock_license_stmt->execute()) {
                        logError("Failed to create merged clock license for company $company_id: " . $conn->error);
                    }
                    $insert_clock_license_stmt->close();
                }
            }
            $license_sum_stmt->close();

            safeDeleteByQuery(
                $conn,
                "DELETE FROM module_licenses WHERE module_id = $legacy_id",
                "module_licenses cleaned for legacy module '$legacy_key'"
            );

            $delete_legacy_stmt = $conn->prepare("DELETE FROM modules WHERE id = ?");
            $delete_legacy_stmt->bind_param("i", $legacy_id);
            if ($delete_legacy_stmt->execute()) {
                logResult("Removed legacy alias module '$legacy_key'", 'success');
            } else {
                logError("Failed to remove legacy alias module '$legacy_key': " . $conn->error);
            }
            $delete_legacy_stmt->close();
        }
    }
    
    // Verify data integrity: check kiosk_modules for missing module_id values
    logResult("Verifying data integrity...", 'info');
    
    // Backfill module_key from module_id where possible (helps keep API payloads consistent)
    safeDeleteByQuery(
        $conn,
        "UPDATE kiosk_modules km JOIN modules m ON km.module_id = m.id SET km.module_key = m.module_key WHERE (km.module_key IS NULL OR km.module_key = '') AND km.module_id IS NOT NULL AND km.module_id > 0",
        "kiosk_modules module_key backfilled from module_id"
    );

    safeDeleteByQuery(
        $conn,
        "UPDATE kiosk_group_modules kgm JOIN modules m ON kgm.module_id = m.id SET kgm.module_key = m.module_key WHERE (kgm.module_key IS NULL OR kgm.module_key = '') AND kgm.module_id IS NOT NULL AND kgm.module_id > 0",
        "kiosk_group_modules module_key backfilled from module_id"
    );

    // Check for orphaned kiosk_modules entries (where module_key exists but module_id is NULL)
    $result = $conn->query("SELECT km.id, km.kiosk_id, km.module_key FROM kiosk_modules km WHERE km.module_id IS NULL OR km.module_id = 0");
    if ($result && $result->num_rows > 0) {
        logResult("Found " . $result->num_rows . " kiosk_modules entries with missing module_id", 'warning');
        
        // Fix them by finding the module_id from module_key
        while ($row = $result->fetch_assoc()) {
            if ($row['module_key']) {
                $fix_stmt = $conn->prepare("
                    UPDATE kiosk_modules km 
                    SET km.module_id = (SELECT id FROM modules WHERE module_key = ? LIMIT 1)
                    WHERE km.id = ?
                ");
                $fix_stmt->bind_param("si", $row['module_key'], $row['id']);
                if ($fix_stmt->execute()) {
                    logResult("Fixed kiosk_modules entry id=" . $row['id'] . " by module_key='" . $row['module_key'] . "'", 'success');
                } else {
                    logError("Failed to fix kiosk_modules entry id=" . $row['id'] . ": " . $conn->error);
                }
                $fix_stmt->close();
            }
        }
    } else {
        logResult("All kiosk_modules entries have valid module_id values", 'success');
    }
    
    // Check for orphaned kiosk_group_modules entries
    $result = $conn->query("SELECT kgm.id, kgm.module_key FROM kiosk_group_modules kgm WHERE kgm.module_id IS NULL OR kgm.module_id = 0");
    if ($result && $result->num_rows > 0) {
        logResult("Found " . $result->num_rows . " kiosk_group_modules entries with missing module_id", 'warning');
        
        while ($row = $result->fetch_assoc()) {
            if ($row['module_key']) {
                $fix_stmt = $conn->prepare("
                    UPDATE kiosk_group_modules kgm 
                    SET kgm.module_id = (SELECT id FROM modules WHERE module_key = ? LIMIT 1)
                    WHERE kgm.id = ?
                ");
                $fix_stmt->bind_param("si", $row['module_key'], $row['id']);
                if ($fix_stmt->execute()) {
                    logResult("Fixed kiosk_group_modules entry id=" . $row['id'] . " by module_key='" . $row['module_key'] . "'", 'success');
                } else {
                    logError("Failed to fix kiosk_group_modules entry id=" . $row['id'] . ": " . $conn->error);
                }
                $fix_stmt->close();
            }
        }
    }
    
    // Initialize display scheduling system - create default schedules for kiosks without schedules
    logResult("Initializing display scheduling system...", 'info');
    
    // Get all kiosks without schedules
    $result = $conn->query("
        SELECT k.id, k.friendly_name 
        FROM kiosks k
        LEFT JOIN display_schedules ds ON k.id = ds.kijelzo_id
        WHERE ds.schedule_id IS NULL
    ");
    
    if ($result && $result->num_rows > 0) {
        while ($kiosk = $result->fetch_assoc()) {
            $kijelzo_id = $kiosk['id'];
            $kiosk_name = $kiosk['friendly_name'] ?? 'Kiosk #' . $kijelzo_id;
            
            // Get the kiosk's group_id if it belongs to a group
            $group_result = $conn->query("
                SELECT group_id FROM kiosk_group_assignments 
                WHERE kiosk_id = $kijelzo_id LIMIT 1
            ");
            $group_id = null;
            if ($group_result && $group_result->num_rows > 0) {
                $group_row = $group_result->fetch_assoc();
                $group_id = $group_row['group_id'];
            }
            
            // Create default schedule (22:00-06:00 OFF, rest ON)
            $stmt = $conn->prepare("INSERT INTO display_schedules (kijelzo_id, group_id) VALUES (?, ?)");
            $stmt->bind_param("ii", $kijelzo_id, $group_id);
            
            if ($stmt->execute()) {
                $schedule_id = $conn->insert_id;
                
                // Add time slots for each day of week (0=Sunday, 6=Saturday)
                $stmt_slot = $conn->prepare("
                    INSERT INTO schedule_time_slots 
                    (schedule_id, day_of_week, start_time, end_time, is_enabled) 
                    VALUES (?, ?, ?, ?, ?)
                ");
                
                if (!$stmt_slot) {
                    logError("Failed to prepare time slot insert: " . $conn->error);
                    continue;
                }
                
                $slots_created = 0;
                for ($day = 0; $day < 7; $day++) {
                    // OFF: 22:00 - 06:00
                    $start_off = '22:00:00';
                    $end_off = '06:00:00';
                    $is_off = 0;
                    
                    $stmt_slot->bind_param("iissi", $schedule_id, $day, $start_off, $end_off, $is_off);
                    if ($stmt_slot->execute()) {
                        $slots_created++;
                    } else {
                        logError("Failed to create OFF slot for kiosk $kijelzo_id, day $day: " . $conn->error);
                    }
                }
                
                $stmt_slot->close();
                
                if ($slots_created === 7) {
                    logResult("Created default schedule for '$kiosk_name' (22:00-06:00 OFF) with $slots_created time slots", 'success');
                } else {
                    logError("Failed to create all time slots for kiosk $kijelzo_id (created: $slots_created/7)");
                }
            } else {
                logError("Failed to create default schedule for kiosk $kijelzo_id: " . $conn->error);
            }
            
            $stmt->close();
        }
    } else {
        logResult("All kiosks have display schedules configured", 'info');
    }
    
    // Create indexes for health monitoring and command execution tables
    logResult("Creating indexes for new tables...", 'info');
    
    $indexes = [
        "CREATE INDEX IF NOT EXISTS idx_kiosk_health_timestamp ON kiosk_health(timestamp)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_health_status ON kiosk_health(status)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_health_kiosk ON kiosk_health(kiosk_id)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_health_logs_kiosk ON kiosk_health_logs(kiosk_id)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_health_logs_created ON kiosk_health_logs(created_at)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_queue_status ON kiosk_command_queue(status)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_queue_kiosk ON kiosk_command_queue(kiosk_id)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_queue_created ON kiosk_command_queue(created_at)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_logs_kiosk ON kiosk_command_logs(kiosk_id)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_logs_command ON kiosk_command_logs(command_id)",
        "CREATE INDEX IF NOT EXISTS idx_kiosk_command_logs_created ON kiosk_command_logs(created_at)",
        // Display scheduling system indexes
        "CREATE INDEX IF NOT EXISTS idx_display_schedules_kijelzo ON display_schedules(kijelzo_id)",
        "CREATE INDEX IF NOT EXISTS idx_display_schedules_group ON display_schedules(group_id)",
        "CREATE INDEX IF NOT EXISTS idx_schedule_time_slots_schedule ON schedule_time_slots(schedule_id)",
        "CREATE INDEX IF NOT EXISTS idx_schedule_time_slots_day ON schedule_time_slots(day_of_week)",
        "CREATE INDEX IF NOT EXISTS idx_schedule_special_days_schedule ON schedule_special_days(schedule_id)",
        "CREATE INDEX IF NOT EXISTS idx_schedule_special_days_date ON schedule_special_days(date)",
        "CREATE INDEX IF NOT EXISTS idx_display_status_log_kijelzo ON display_status_log(kijelzo_id)",
        "CREATE INDEX IF NOT EXISTS idx_display_status_log_created ON display_status_log(created_at)"
    ];
    
    foreach ($indexes as $index_sql) {
        if ($conn->query($index_sql)) {
            logResult("Index created: " . substr($index_sql, 0, 50) . "...", 'success');
        } else {
            logError("Failed to create index: " . $conn->error);
        }
    }

    // Migrate legacy stored asset URLs in module settings to protected API endpoint URLs
    if (!defined('EDUDISPLEJ_MAINTENANCE_MIGRATE_ASSET_URLS') || EDUDISPLEJ_MAINTENANCE_MIGRATE_ASSET_URLS) {
        edudisplej_maintenance_migrate_legacy_asset_urls($conn);
    } else {
        logResult('Legacy asset URL migration skipped by configuration flag', 'info');
    }

    // Cleanup phase: remove unused/orphaned remnants safely
    logResult("Starting cleanup of unused remnants...", 'info');

    // Remove unresolved orphan mappings where module reference cannot be restored
    safeDeleteByQuery(
        $conn,
        "DELETE km FROM kiosk_modules km LEFT JOIN modules m ON km.module_id = m.id WHERE (km.module_id IS NULL OR km.module_id = 0 OR m.id IS NULL) AND (km.module_key IS NULL OR km.module_key = '' OR NOT EXISTS (SELECT 1 FROM modules mx WHERE mx.module_key = km.module_key))",
        "kiosk_modules unresolved orphan entries"
    );

    safeDeleteByQuery(
        $conn,
        "DELETE kgm FROM kiosk_group_modules kgm LEFT JOIN modules m ON kgm.module_id = m.id WHERE (kgm.module_id IS NULL OR kgm.module_id = 0 OR m.id IS NULL) AND (kgm.module_key IS NULL OR kgm.module_key = '' OR NOT EXISTS (SELECT 1 FROM modules mx WHERE mx.module_key = kgm.module_key))",
        "kiosk_group_modules unresolved orphan entries"
    );

    safeDeleteByQuery(
        $conn,
        "DELETE ml FROM module_licenses ml LEFT JOIN modules m ON ml.module_id = m.id LEFT JOIN companies c ON ml.company_id = c.id WHERE m.id IS NULL OR c.id IS NULL",
        "module_licenses orphan entries"
    );

    safeDeleteByQuery(
        $conn,
        "DELETE kga FROM kiosk_group_assignments kga LEFT JOIN kiosks k ON kga.kiosk_id = k.id LEFT JOIN kiosk_groups kg ON kga.group_id = kg.id WHERE k.id IS NULL OR kg.id IS NULL",
        "kiosk_group_assignments orphan entries"
    );

    // Remove expired nonces (security/maintenance)
    safeDeleteByQuery(
        $conn,
        "DELETE FROM api_nonces WHERE expires_at < NOW()",
        "expired api_nonces"
    );

    // Remove deprecated modules only if fully unused and no filesystem artifact exists
    $deprecated_module_keys = ['namedays', 'split_clock_namedays'];
    foreach ($deprecated_module_keys as $deprecated_key) {
        $stmt = $conn->prepare("SELECT id FROM modules WHERE module_key = ? LIMIT 1");
        $stmt->bind_param("s", $deprecated_key);
        $stmt->execute();
        $module_row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        if (!$module_row) {
            continue;
        }

        $module_id = (int)$module_row['id'];
        $usage_result = $conn->query("SELECT
                (SELECT COUNT(*) FROM kiosk_modules WHERE module_id = $module_id) AS kiosk_usage,
                (SELECT COUNT(*) FROM kiosk_group_modules WHERE module_id = $module_id) AS group_usage,
                (SELECT COUNT(*) FROM module_licenses WHERE module_id = $module_id) AS license_usage");
        $usage = $usage_result ? $usage_result->fetch_assoc() : null;

        $total_usage = (int)($usage['kiosk_usage'] ?? 0) + (int)($usage['group_usage'] ?? 0) + (int)($usage['license_usage'] ?? 0);
        if ($total_usage === 0) {
            $del_stmt = $conn->prepare("DELETE FROM modules WHERE id = ?");
            $del_stmt->bind_param("i", $module_id);
            if ($del_stmt->execute()) {
                logResult("Cleanup: removed unused deprecated module '$deprecated_key'", 'success');
            } else {
                logError("Cleanup failed for deprecated module '$deprecated_key': " . $conn->error);
            }
            $del_stmt->close();
        } else {
            logResult("Cleanup: deprecated module '$deprecated_key' kept (still used)", 'warning');
        }
    }

    // Remove only clearly legacy/temporary empty tables
    $existing_tables_result = $conn->query("SHOW TABLES");
    $existing_tables = [];
    while ($row = $existing_tables_result->fetch_array()) {
        $existing_tables[] = (string)$row[0];
    }

    $expected_table_names = array_keys($expected_tables);
    foreach ($existing_tables as $table_name) {
        if (in_array($table_name, $expected_table_names, true)) {
            continue;
        }

        if (!isSafeCleanupTableName($table_name)) {
            continue;
        }

        $count_res = $conn->query("SELECT COUNT(*) AS cnt FROM `$table_name`");
        $count_row = $count_res ? $count_res->fetch_assoc() : ['cnt' => null];
        $cnt = isset($count_row['cnt']) ? (int)$count_row['cnt'] : -1;

        if ($cnt === 0) {
            if ($conn->query("DROP TABLE `$table_name`")) {
                logResult("Cleanup: dropped empty legacy table '$table_name'", 'success');
            } else {
                logError("Cleanup failed to drop table '$table_name': " . $conn->error);
            }
        } elseif ($cnt > 0) {
            logResult("Cleanup: legacy table '$table_name' kept (contains $cnt rows)", 'warning');
        }
    }

    cleanupModuleFilesystem($baseDir);
    
    // Register core modules if not already exists
    registerCoreModules($conn);

    // Ensure room occupancy schema (rooms + event slots)
    edudisplej_maintenance_ensure_room_occupancy_schema($conn);

    // Automated meal sync for used Jedalen institutions (nightly once/day, or forced when used institution has no cached data)
    edudisplej_maintenance_run_jedalen_daily_sync($conn);
    
    closeDbConnection($conn);
    logResult("Database structure check completed", 'success');
    
} catch (Exception $e) {
    logError("Fatal error: " . $e->getMessage());
    echo "\nStack trace:\n" . $e->getTraceAsString() . "\n";
}

function registerCoreModules(mysqli $conn): void {
    $coreModules = [
        [
            'module_key' => 'clock',
            'name' => 'Óra',
            'description' => 'Digitális vagy analóg óra kijelzés',
            'is_active' => 1
        ],
        [
            'module_key' => 'default-logo',
            'name' => 'Alapértelmezett logó',
            'description' => 'Egyedi logó vagy szöveg megjelenítés',
            'is_active' => 1
        ],
        [
            'module_key' => 'text',
            'name' => 'Szöveg',
            'description' => 'Formázott szöveges tartalom kijelzése',
            'is_active' => 1
        ],
        [
            'module_key' => 'pdf',
            'name' => 'PDF Megjelenítő',
            'description' => 'PDF dokumentumok kijelzése és navigációja',
            'is_active' => 1
        ],
        [
            'module_key' => 'image-gallery',
            'name' => 'Képgaléria',
            'description' => 'Több kép megjelenítése slideshow vagy kollázs módokban',
            'is_active' => 1
        ],
        [
            'module_key' => 'video',
            'name' => 'Videó lejátszó',
            'description' => 'Optimalizált MP4 videó lejátszása gyenge hardveren',
            'is_active' => 1
        ],
        [
            'module_key' => 'meal-menu',
            'name' => 'Étrend',
            'description' => 'Iskolai étlap megjelenítése forrás + intézmény alapú beállításokkal',
            'is_active' => 1
        ],
        [
            'module_key' => 'room-occupancy',
            'name' => 'Terem foglaltság',
            'description' => 'Termek napi foglaltságának megjelenítése kézi és külső API adatokkal',
            'is_active' => 1
        ],
        [
            'module_key' => 'unconfigured',
            'name' => 'Beállítás nélküli',
            'description' => 'Technikai segédmodul üres loop-hoz',
            'is_active' => 1
        ]
    ];

    foreach ($coreModules as $module) {
        $stmt = $conn->prepare("SELECT id FROM modules WHERE module_key = ? LIMIT 1");
        if (!$stmt) {
            logError("Module registration: prepare failed for '{$module['module_key']}'");
            continue;
        }

        $stmt->bind_param("s", $module['module_key']);
        $stmt->execute();
        $result = $stmt->get_result();
        $exists = $result->fetch_assoc();
        $stmt->close();

        if ($exists) {
            $update = $conn->prepare("UPDATE modules SET name = ?, description = ? WHERE module_key = ?");
            if (!$update) {
                logError("Module registration: update prepare failed for '{$module['module_key']}'");
                continue;
            }

            $update->bind_param(
                "sss",
                $module['name'],
                $module['description'],
                $module['module_key']
            );
            
            if ($update->execute()) {
                logResult("Module '{$module['module_key']}' updated", 'info');
            } else {
                logError("Module registration: update failed for '{$module['module_key']}': " . $conn->error);
            }
            $update->close();
        } else {
            $insert = $conn->prepare("INSERT INTO modules (module_key, name, description, is_active) VALUES (?, ?, ?, ?)");
            if (!$insert) {
                logError("Module registration: insert prepare failed for '{$module['module_key']}'");
                continue;
            }

            $insert->bind_param(
                "sssi",
                $module['module_key'],
                $module['name'],
                $module['description'],
                $module['is_active']
            );
            
            if ($insert->execute()) {
                logResult("Module '{$module['module_key']}' registered", 'success');
            } else {
                logError("Module registration: insert failed for '{$module['module_key']}': " . $conn->error);
            }
            $insert->close();
        }
    }
}

if (EDUDISPLEJ_DBJAVITO_NO_HTML || PHP_SAPI === 'cli') {
    return;
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Database Auto-Fixer - EduDisplej</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Courier New', monospace;
            background: #1e1e1e;
            color: #d4d4d4;
            padding: 20px;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        
        h1 {
            color: #4ec9b0;
            margin-bottom: 20px;
            font-size: 28px;
        }
        
        .result {
            padding: 10px 15px;
            margin-bottom: 5px;
            border-radius: 3px;
            font-size: 14px;
            line-height: 1.6;
        }
        
        .result-info {
            background: #264f78;
            color: #9cdcfe;
        }
        
        .result-success {
            background: #0e6027;
            color: #4ec9b0;
        }
        
        .result-warning {
            background: #5a3e1c;
            color: #dcdcaa;
        }
        
        .result-error {
            background: #5a1d1d;
            color: #f48771;
        }
        
        .summary {
            margin-top: 30px;
            padding: 20px;
            background: #2d2d30;
            border-radius: 5px;
        }
        
        .summary h2 {
            color: #569cd6;
            margin-bottom: 15px;
        }
        
        .summary p {
            margin-bottom: 10px;
        }
        
        .btn {
            display: inline-block;
            padding: 10px 20px;
            background: #0e639c;
            color: white;
            text-decoration: none;
            border-radius: 3px;
            margin-top: 20px;
            transition: background 0.3s;
        }
        
        .btn:hover {
            background: #1177bb;
        }
        
        .timestamp {
            color: #858585;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>⚙️ Database Structure Auto-Fixer</h1>
        <p class="timestamp">Executed: <?php echo date('Y-m-d H:i:s'); ?></p>
        
        <div style="margin-top: 30px;">
            <?php foreach ($results as $result): ?>
                <div class="result result-<?php echo $result['type']; ?>">
                    <?php 
                    $icon = match($result['type']) {
                        'success' => '✓',
                        'error' => '✗',
                        'warning' => '⚠',
                        default => 'ℹ'
                    };
                    echo $icon . ' ' . htmlspecialchars($result['message']); 
                    ?>
                </div>
            <?php endforeach; ?>
        </div>
        
        <div class="summary">
            <h2>Summary</h2>
            <p><strong>Total operations:</strong> <?php echo count($results); ?></p>
            <p><strong>Errors:</strong> <?php echo count($errors); ?></p>
            <p><strong>Status:</strong> 
                <?php if (empty($errors)): ?>
                    <span style="color: #4ec9b0;">✓ All operations completed successfully</span>
                <?php else: ?>
                    <span style="color: #f48771;">✗ Some operations failed - please check the log above</span>
                <?php endif; ?>
            </p>
            
            <a href="admin/index.php" class="btn">← Back to Admin Panel</a>
            <a href="run_maintenance.php" class="btn" style="background: #0e6027;">↻ Run Again</a>
        </div>
    </div>
</body>
</html>

