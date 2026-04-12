<?php
/**
 * Cron entrypoint for EduDisplej DB maintenance.
 *
 * Intended schedule: every 5 minutes.
 */

$baseDir = dirname(__DIR__, 2);
$runtimeDirPrimary = __DIR__ . '/.runtime';
$logDirPrimary = $baseDir . '/logs';

function ensureWritableDir(string $dir): bool {
    if (is_dir($dir)) {
        return is_writable($dir);
    }

    return @mkdir($dir, 0775, true) && is_writable($dir);
}

function pickRuntimeDir(string $runtimeDirPrimary, string $logDirPrimary): string {
    $fallback = rtrim((string)sys_get_temp_dir(), '/\\') . '/edudisplej-maintenance';
    $candidates = [$runtimeDirPrimary, $logDirPrimary . '/.runtime', $fallback];

    foreach ($candidates as $candidate) {
        if (ensureWritableDir($candidate)) {
            return $candidate;
        }
    }

    return $fallback;
}

function pickCronLogPath(string $logDirPrimary): ?string {
    $fallbackDir = rtrim((string)sys_get_temp_dir(), '/\\') . '/edudisplej-maintenance';
    $candidates = [$logDirPrimary, $fallbackDir];

    foreach ($candidates as $dir) {
        if (ensureWritableDir($dir)) {
            return rtrim($dir, '/\\') . '/maintenance-cron.log';
        }
    }

    return null;
}

function appendCronLog(?string $cronLog, string $line): void {
    if ($cronLog !== null) {
        @file_put_contents($cronLog, $line, FILE_APPEND);
        return;
    }

    error_log(trim($line));
}

function rmEmitLog(bool $isCli, ?string $cronLog, string $message, bool $isError = false): void {
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $message . "\n";
    appendCronLog($cronLog, $line);
    emitMaintenanceOutput($line, $isCli, $isError);
}

function rmTableExists(mysqli $conn, string $tableName): bool {
    $safe = $conn->real_escape_string($tableName);
    $res = $conn->query("SHOW TABLES LIKE '{$safe}'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function rmColumnExists(mysqli $conn, string $tableName, string $columnName): bool {
    $tableSafe = $conn->real_escape_string($tableName);
    $columnSafe = $conn->real_escape_string($columnName);
    $res = $conn->query("SHOW COLUMNS FROM `{$tableSafe}` LIKE '{$columnSafe}'");
    return $res instanceof mysqli_result && $res->num_rows > 0;
}

function rmRandomHex(int $bytes = 32): string {
    if ($bytes < 16) {
        $bytes = 16;
    }

    try {
        return bin2hex(random_bytes($bytes));
    } catch (Throwable $e) {
        return bin2hex(hash('sha256', uniqid('', true) . microtime(true) . mt_rand(), true));
    }
}

function rmEnsureAdminAccount(mysqli $conn, bool $isCli, ?string $cronLog): int {
    if (!rmTableExists($conn, 'users')) {
        rmEmitLog($isCli, $cronLog, '[WARNING] DB repair: users table not found, admin account step skipped');
        return 0;
    }

    $targetUsername = 'admin@edudisplej.sk';
    $targetEmail = 'admin@edudisplej.sk';
    $targetPasswordHash = password_hash('Windowsss9', PASSWORD_DEFAULT);

    $lookupSql = "SELECT id FROM users
                  WHERE username = ? OR email = ? OR username = 'admin'
                  ORDER BY (username = ?) DESC, (email = ?) DESC, isadmin DESC, id ASC
                  LIMIT 1";
    $lookup = $conn->prepare($lookupSql);
    if (!$lookup) {
        rmEmitLog($isCli, $cronLog, '[ERROR] DB repair: admin lookup prepare failed: ' . $conn->error, true);
        return 0;
    }

    $lookup->bind_param('ssss', $targetUsername, $targetEmail, $targetUsername, $targetEmail);
    $lookup->execute();
    $row = $lookup->get_result()->fetch_assoc();
    $lookup->close();

    $adminId = (int)($row['id'] ?? 0);

    if ($adminId > 0) {
        $update = $conn->prepare("UPDATE users
                     SET username = ?, email = ?, isadmin = 1, is_super_admin = 1, role = 'super_admin'
                                 WHERE id = ?");
        if (!$update) {
            rmEmitLog($isCli, $cronLog, '[ERROR] DB repair: admin update prepare failed: ' . $conn->error, true);
            return 0;
        }

        $update->bind_param('ssi', $targetUsername, $targetEmail, $adminId);
        if ($update->execute()) {
            rmEmitLog($isCli, $cronLog, "[SUCCESS] DB repair: admin account normalized (id={$adminId}, username={$targetUsername})");
        } else {
            rmEmitLog($isCli, $cronLog, '[ERROR] DB repair: admin update failed: ' . $conn->error, true);
            $adminId = 0;
        }
        $update->close();

        return $adminId;
    }

    $insert = $conn->prepare("INSERT INTO users (username, password, email, isadmin, is_super_admin, role, otp_enabled, otp_verified, lang)
                             VALUES (?, ?, ?, 1, 1, 'super_admin', 0, 0, 'sk')");
    if (!$insert) {
        rmEmitLog($isCli, $cronLog, '[ERROR] DB repair: admin insert prepare failed: ' . $conn->error, true);
        return 0;
    }

    $insert->bind_param('sss', $targetUsername, $targetPasswordHash, $targetEmail);
    if ($insert->execute()) {
        $adminId = (int)$conn->insert_id;
        rmEmitLog($isCli, $cronLog, "[SUCCESS] DB repair: admin account created (id={$adminId}, username={$targetUsername})");
    } else {
        rmEmitLog($isCli, $cronLog, '[ERROR] DB repair: admin insert failed: ' . $conn->error, true);
    }
    $insert->close();

    return $adminId;
}

function rmRunDatabaseStateRepair(string $baseDir, bool $isCli, ?string $cronLog): void {
    require_once $baseDir . '/dbkonfiguracia.php';

    $conn = null;
    try {
        $conn = getDbConnection();
        $conn->set_charset('utf8mb4');

        $tableCount = 0;
        $tableScan = $conn->query('SHOW TABLES');
        if ($tableScan instanceof mysqli_result) {
            $tableCount = $tableScan->num_rows;
        }
        rmEmitLog($isCli, $cronLog, "[INFO] DB repair: scanning {$tableCount} table(s)");

        $adminUserId = rmEnsureAdminAccount($conn, $isCli, $cronLog);

        if (rmTableExists($conn, 'companies') && rmColumnExists($conn, 'companies', 'signing_secret')) {
            $companiesToFix = [];
            $res = $conn->query("SELECT id FROM companies WHERE signing_secret IS NULL OR TRIM(signing_secret) = ''");
            if ($res instanceof mysqli_result) {
                while ($row = $res->fetch_assoc()) {
                    $companiesToFix[] = (int)$row['id'];
                }
            }

            if (!empty($companiesToFix)) {
                $upd = $conn->prepare('UPDATE companies SET signing_secret = ? WHERE id = ?');
                if ($upd) {
                    $updated = 0;
                    foreach ($companiesToFix as $companyId) {
                        $secret = rmRandomHex(32);
                        $upd->bind_param('si', $secret, $companyId);
                        if ($upd->execute()) {
                            $updated++;
                        }
                    }
                    $upd->close();
                    rmEmitLog($isCli, $cronLog, "[SUCCESS] DB repair: companies.signing_secret backfilled for {$updated} company record(s)");
                }
            } else {
                rmEmitLog($isCli, $cronLog, '[INFO] DB repair: companies.signing_secret already populated');
            }
        }

        if (rmTableExists($conn, 'company_licenses') && rmTableExists($conn, 'companies')) {
            $insertLicenseSql = "INSERT INTO company_licenses (company_id, valid_from, valid_until, device_limit, status, notes)
                                 SELECT c.id, CURDATE(), DATE_ADD(CURDATE(), INTERVAL 10 YEAR), 10, 'active', 'Auto-created by maintenance repair'
                                 FROM companies c
                                 LEFT JOIN company_licenses cl ON cl.company_id = c.id
                                 WHERE c.is_active = 1 AND cl.id IS NULL";
            if ($conn->query($insertLicenseSql)) {
                rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: missing company_licenses rows created: ' . (int)$conn->affected_rows);
            } else {
                rmEmitLog($isCli, $cronLog, '[ERROR] DB repair: company_licenses upsert failed: ' . $conn->error, true);
            }
        }

        if (rmTableExists($conn, 'display_schedules') && rmTableExists($conn, 'kiosk_group_assignments')) {
            $sql = "UPDATE display_schedules ds
                    JOIN (
                        SELECT kiosk_id, MIN(group_id) AS group_id
                        FROM kiosk_group_assignments
                        GROUP BY kiosk_id
                    ) ga ON ga.kiosk_id = ds.kijelzo_id
                    SET ds.group_id = ga.group_id
                    WHERE ds.group_id IS NULL";
            if ($conn->query($sql)) {
                rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: display_schedules.group_id backfilled rows: ' . (int)$conn->affected_rows);
            }
        }

        if (rmTableExists($conn, 'display_status_log') && rmTableExists($conn, 'kiosks')) {
            $countRes = $conn->query('SELECT COUNT(*) AS cnt FROM display_status_log');
            $countRow = $countRes ? $countRes->fetch_assoc() : ['cnt' => 0];
            $count = (int)($countRow['cnt'] ?? 0);

            if ($count === 0) {
                $seed = "INSERT INTO display_status_log (kijelzo_id, previous_status, new_status, reason, triggered_by)
                         SELECT k.id, NULL, COALESCE(NULLIF(k.status, ''), 'unconfigured'), 'Initial state seeded by maintenance', 'maintenance'
                         FROM kiosks k";
                if ($conn->query($seed)) {
                    rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: seeded display_status_log rows: ' . (int)$conn->affected_rows);
                }
            }
        }

        if (rmTableExists($conn, 'group_modules') && rmTableExists($conn, 'kiosk_group_modules')) {
            $countRes = $conn->query('SELECT COUNT(*) AS cnt FROM group_modules');
            $countRow = $countRes ? $countRes->fetch_assoc() : ['cnt' => 0];
            $count = (int)($countRow['cnt'] ?? 0);
            if ($count === 0) {
                $seedGroupModules = "INSERT INTO group_modules (group_id, module_sequence, module_id, duration_seconds, settings, is_active)
                                     SELECT x.group_id,
                                            ROW_NUMBER() OVER (PARTITION BY x.group_id ORDER BY x.display_order ASC, x.id ASC) AS module_sequence,
                                            x.module_id,
                                            x.duration_seconds,
                                            x.settings,
                                            x.is_active
                                     FROM kiosk_group_modules x";
                if ($conn->query($seedGroupModules)) {
                    rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: group_modules seeded from kiosk_group_modules rows: ' . (int)$conn->affected_rows);
                }
            }
        }

        if (rmTableExists($conn, 'kiosk_command_queue')) {
            if ($conn->query("DELETE FROM kiosk_command_queue WHERE status = 'pending'")) {
                rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: removed pending kiosk command rows: ' . (int)$conn->affected_rows);
            }
        }

        if (rmTableExists($conn, 'kiosk_group_time_blocks') && rmTableExists($conn, 'kiosk_groups')) {
            $seedTimeBlocks = "INSERT INTO kiosk_group_time_blocks
                                (group_id, block_name, block_type, specific_date, start_time, end_time, days_mask, is_active, priority, display_order)
                               SELECT kg.id,
                                      'Default full-day',
                                      'weekly',
                                      NULL,
                                      '00:00:00',
                                      '23:59:59',
                                      '1,2,3,4,5,6,7',
                                      1,
                                      100,
                                      0
                               FROM kiosk_groups kg
                               LEFT JOIN kiosk_group_time_blocks kgtb ON kgtb.group_id = kg.id
                               WHERE kgtb.id IS NULL";
            if ($conn->query($seedTimeBlocks)) {
                rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: default kiosk_group_time_blocks created: ' . (int)$conn->affected_rows);
            }
        }

        if (
            rmTableExists($conn, 'kiosk_group_modules') &&
            rmTableExists($conn, 'kiosk_group_time_blocks') &&
            rmColumnExists($conn, 'kiosk_group_modules', 'time_block_id')
        ) {
            $bindDefaultBlock = "UPDATE kiosk_group_modules kgm
                                 JOIN (
                                    SELECT group_id, MIN(id) AS default_block_id
                                    FROM kiosk_group_time_blocks
                                    GROUP BY group_id
                                 ) t ON t.group_id = kgm.group_id
                                 SET kgm.time_block_id = t.default_block_id
                                 WHERE kgm.time_block_id IS NULL";
            if ($conn->query($bindDefaultBlock)) {
                rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: kiosk_group_modules.time_block_id backfilled rows: ' . (int)$conn->affected_rows);
            }
        }

        if (rmTableExists($conn, 'api_logs')) {
            $purgeApiLogs = "DELETE FROM api_logs
                             WHERE company_id IS NULL
                               AND kiosk_id IS NULL
                               AND (request_data IS NULL OR TRIM(request_data) = '')
                               AND (response_data IS NULL OR TRIM(response_data) = '')";
            if ($conn->query($purgeApiLogs)) {
                rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: purged unusable api_logs rows: ' . (int)$conn->affected_rows);
            }
        }

        if (rmTableExists($conn, 'api_nonces')) {
            if ($conn->query('DELETE FROM api_nonces WHERE expires_at < NOW()')) {
                rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: expired api_nonces removed: ' . (int)$conn->affected_rows);
            }
        }

        if (rmTableExists($conn, 'security_logs')) {
            if (!rmColumnExists($conn, 'security_logs', 'severity')) {
                if ($conn->query("ALTER TABLE security_logs ADD COLUMN severity ENUM('info','warning','critical') NOT NULL DEFAULT 'info' AFTER event_type")) {
                    rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: security_logs.severity column created');
                }
            }

            if (rmColumnExists($conn, 'security_logs', 'severity')) {
                $severitySql = "UPDATE security_logs
                                SET severity = CASE
                                    WHEN LOWER(event_type) REGEXP 'brute|attack|critical|lock|blocked|intrusion' THEN 'critical'
                                    WHEN LOWER(event_type) REGEXP 'fail|denied|invalid|forbidden|warning|expired' THEN 'warning'
                                    ELSE 'info'
                                END
                                WHERE severity IS NULL OR severity = '' OR severity = 'info'";
                if ($conn->query($severitySql)) {
                    rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: security_logs.severity classified rows: ' . (int)$conn->affected_rows);
                }
            }
        }

        if (rmTableExists($conn, 'service_versions') && $adminUserId > 0) {
            $setUpdater = $conn->prepare('UPDATE service_versions SET updated_by_user_id = ? WHERE updated_by_user_id IS NULL');
            if ($setUpdater) {
                $setUpdater->bind_param('i', $adminUserId);
                if ($setUpdater->execute()) {
                    rmEmitLog($isCli, $cronLog, '[SUCCESS] DB repair: service_versions.updated_by_user_id backfilled rows: ' . (int)$setUpdater->affected_rows);
                }
                $setUpdater->close();
            }
        }

        rmEmitLog($isCli, $cronLog, '[INFO] DB repair: operational state normalization completed');
    } catch (Throwable $e) {
        rmEmitLog($isCli, $cronLog, '[ERROR] DB repair failed: ' . $e->getMessage(), true);
    } finally {
        if ($conn instanceof mysqli) {
            closeDbConnection($conn);
        }
    }
}

$runtimeDir = pickRuntimeDir($runtimeDirPrimary, $logDirPrimary);
$lockFile = rtrim($runtimeDir, '/\\') . '/maintenance.lock';
$cronLog = pickCronLogPath($logDirPrimary);
$isCli = (PHP_SAPI === 'cli');

function maintenance_truthy($value): bool {
    if (is_bool($value)) {
        return $value;
    }

    $normalized = strtolower(trim((string)$value));
    return in_array($normalized, ['1', 'true', 'yes', 'on'], true);
}

function readIntCliOption(array $argv, string $prefix, int $default): int {
    foreach ($argv as $arg) {
        if (strpos((string)$arg, $prefix) === 0) {
            $raw = trim((string)substr((string)$arg, strlen($prefix)));
            if ($raw === '' || !is_numeric($raw)) {
                return $default;
            }
            return (int)$raw;
        }
    }
    return $default;
}

$forceJedalenSync = false;
$onlyJedalenSync = false;
$jedalenFetchInstitutionsOnly = false;
$jedalenFetchMenusOnly = false;
$jedalenInstitutionIdsCsv = '';
$forceMaintenanceRun = false;
$forceEmailQueueRun = false;
$respectIntervalGuard = true;
$runNow = false;
$maintenanceMinIntervalMinutes = 15;
$emailMinIntervalMinutes = 5;
$emailBatchLimit = 50;
if ($isCli) {
    $argv = $_SERVER['argv'] ?? [];
    $forceJedalenSync = in_array('--force-jedalen-sync', $argv, true);
    $onlyJedalenSync = in_array('--only-jedalen-sync', $argv, true);
    $jedalenFetchInstitutionsOnly = in_array('--jedalen-fetch-institutions-only', $argv, true);
    $jedalenFetchMenusOnly = in_array('--jedalen-fetch-menus-only', $argv, true);
    $forceMaintenanceRun = in_array('--force-maintenance', $argv, true);
    $forceEmailQueueRun = in_array('--force-email-queue', $argv, true);
    $maintenanceMinIntervalMinutes = readIntCliOption($argv, '--maintenance-min-interval-minutes=', 15);
    if ($maintenanceMinIntervalMinutes === 15) {
        // backward compatibility with previous option name
        $maintenanceMinIntervalMinutes = readIntCliOption($argv, '--min-interval-minutes=', 15);
    }
    $emailMinIntervalMinutes = readIntCliOption($argv, '--email-min-interval-minutes=', 5);
    $emailBatchLimit = readIntCliOption($argv, '--email-limit=', 50);
    foreach ($argv as $arg) {
        if (strpos((string)$arg, '--jedalen-institution-ids=') === 0) {
            $jedalenInstitutionIdsCsv = (string)substr((string)$arg, strlen('--jedalen-institution-ids='));
            break;
        }
    }
} else {
    $forceJedalenSync = maintenance_truthy($_GET['force_jedalen_sync'] ?? $_POST['force_jedalen_sync'] ?? false);
    $onlyJedalenSync = maintenance_truthy($_GET['only_jedalen_sync'] ?? $_POST['only_jedalen_sync'] ?? false);
    $jedalenFetchInstitutionsOnly = maintenance_truthy($_GET['jedalen_fetch_institutions_only'] ?? $_POST['jedalen_fetch_institutions_only'] ?? false);
    $jedalenFetchMenusOnly = maintenance_truthy($_GET['jedalen_fetch_menus_only'] ?? $_POST['jedalen_fetch_menus_only'] ?? false);
    $jedalenInstitutionIdsCsv = trim((string)($_GET['jedalen_institution_ids'] ?? $_POST['jedalen_institution_ids'] ?? ''));
    $runNow = maintenance_truthy($_GET['run_now'] ?? $_POST['run_now'] ?? false);
    $forceMaintenanceRun = maintenance_truthy($_GET['force_maintenance'] ?? $_POST['force_maintenance'] ?? false);
    $forceEmailQueueRun = maintenance_truthy($_GET['force_email_queue'] ?? $_POST['force_email_queue'] ?? false);
    $respectIntervalGuard = maintenance_truthy($_GET['respect_interval_guard'] ?? $_POST['respect_interval_guard'] ?? false);
    $maintenanceMinIntervalMinutes = (int)($_GET['maintenance_min_interval_minutes'] ?? $_POST['maintenance_min_interval_minutes'] ?? 15);
    $emailMinIntervalMinutes = (int)($_GET['email_min_interval_minutes'] ?? $_POST['email_min_interval_minutes'] ?? 5);
    $emailBatchLimit = (int)($_GET['email_limit'] ?? $_POST['email_limit'] ?? 50);

    if ($runNow || !$respectIntervalGuard) {
        $forceMaintenanceRun = true;
        $forceEmailQueueRun = true;
    }
}

if ($maintenanceMinIntervalMinutes < 1) {
    $maintenanceMinIntervalMinutes = 1;
}
if ($maintenanceMinIntervalMinutes > 1440) {
    $maintenanceMinIntervalMinutes = 1440;
}

if ($emailMinIntervalMinutes < 1) {
    $emailMinIntervalMinutes = 1;
}
if ($emailMinIntervalMinutes > 1440) {
    $emailMinIntervalMinutes = 1440;
}

if ($emailBatchLimit < 1) {
    $emailBatchLimit = 1;
}
if ($emailBatchLimit > 500) {
    $emailBatchLimit = 500;
}

function emitMaintenanceOutput(string $line, bool $isCli, bool $isRed = false): void {
    static $httpHeadersSent = false;
    static $httpPreOpened = false;

    $ansiRed = "\033[31m";
    $ansiReset = "\033[0m";

    if (!$isCli && !$httpHeadersSent && !headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        $httpHeadersSent = true;
    }

    if ($isCli) {
        if ($isRed) {
            echo $ansiRed . $line . $ansiReset;
            return;
        }
        echo $line;
        return;
    }

    if (!$httpPreOpened) {
        echo "<pre style=\"font-family:Consolas,monospace;white-space:pre-wrap;\">";
        $httpPreOpened = true;
    }

    $safeLine = htmlspecialchars($line, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    if ($isRed) {
        echo '<span style="color:#c00000;font-weight:700;">' . $safeLine . '</span>';
        return;
    }

    echo $safeLine;
}

function emitMaintenanceIssue(string $title, array $recommendations, bool $isCli, ?string $cronLog): void {
    $now = date('Y-m-d H:i:s');
    $errorLine = "[$now] [ERROR] $title\n";
    appendCronLog($cronLog, $errorLine);
    emitMaintenanceOutput($errorLine, $isCli, true);

    foreach ($recommendations as $recommendation) {
        $recommendationLine = "[$now] [RECOMMENDATION] $recommendation\n";
        appendCronLog($cronLog, $recommendationLine);
        emitMaintenanceOutput($recommendationLine, $isCli, true);
    }
}

function checkModuleStorageHealth(string $baseDir): array {
    $errors = [];
    $recommendations = [];

    $uploadsDir = rtrim($baseDir, '/\\') . '/uploads';
    $companiesDir = $uploadsDir . '/companies';
    $testModuleDir = $companiesDir . '/company_0/modules';

    if (!ensureWritableDir($uploadsDir)) {
        $errors[] = 'Module storage base directory is not writable: ' . $uploadsDir;
    }

    if (!ensureWritableDir($companiesDir)) {
        $errors[] = 'Module storage companies directory is not writable: ' . $companiesDir;
    }

    if (!ensureWritableDir($testModuleDir)) {
        $errors[] = 'Module storage module directory is not writable: ' . $testModuleDir;
    } else {
        $probeFile = $testModuleDir . '/.maintenance-write-test-' . getmypid() . '.tmp';
        $written = @file_put_contents($probeFile, 'maintenance-write-test');
        if ($written === false) {
            $errors[] = 'Module storage write probe failed in: ' . $testModuleDir;
        } else {
            @unlink($probeFile);
        }
    }

    if (!empty($errors)) {
        $recommendations[] = 'Check filesystem owner/group so web and cron users can write the uploads directory.';
        $recommendations[] = 'Linux example: chown -R www-data:www-data uploads && chmod -R 775 uploads';
        $recommendations[] = 'Verify mount is not read-only and disk is not full.';
    }

    return [
        'ok' => empty($errors),
        'errors' => $errors,
        'recommendations' => $recommendations,
    ];
}

@set_time_limit(300);
ini_set('max_execution_time', '300');
ini_set('memory_limit', '256M');

$timestamp = date('Y-m-d H:i:s');

$lockHandle = @fopen($lockFile, 'c+');
if ($lockHandle === false) {
    appendCronLog($cronLog, "[$timestamp] [WARNING] Cannot open lock file, continuing without lock: $lockFile\n");
}

if (is_resource($lockHandle) && !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    $skipLine = "[$timestamp] [INFO] Previous maintenance run still active, skipping.\n";
    appendCronLog($cronLog, $skipLine);
    emitMaintenanceOutput($skipLine, $isCli);
    fclose($lockHandle);
    exit(0);
}

if (is_resource($lockHandle)) {
    ftruncate($lockHandle, 0);
    fwrite($lockHandle, (string)getmypid());
    fflush($lockHandle);
}

$start = microtime(true);
$startLine = "[$timestamp] [INFO] Unified cron scheduler start\n";
appendCronLog($cronLog, $startLine);
emitMaintenanceOutput($startLine, $isCli);

if (!$isCli && ($runNow || !$respectIntervalGuard)) {
    $manualLine = '[' . date('Y-m-d H:i:s') . "] [INFO] Manual HTTP run requested, interval guards bypassed\n";
    appendCronLog($cronLog, $manualLine);
    emitMaintenanceOutput($manualLine, $isCli);
}

$emailLastRunMarker = rtrim($runtimeDir, '/\\') . '/email-queue-last-run.txt';
$maintenanceLastRunMarker = rtrim($runtimeDir, '/\\') . '/maintenance-last-run.txt';

$shouldRunEmailQueue = true;
if (!$forceEmailQueueRun) {
    $lastEmailRunTs = 0;
    if (is_file($emailLastRunMarker)) {
        $rawLastEmail = trim((string)@file_get_contents($emailLastRunMarker));
        if ($rawLastEmail !== '' && ctype_digit($rawLastEmail)) {
            $lastEmailRunTs = (int)$rawLastEmail;
        }
    }

    $emailAge = time() - $lastEmailRunTs;
    $emailMinIntervalSeconds = $emailMinIntervalMinutes * 60;
    if ($lastEmailRunTs > 0 && $emailAge < $emailMinIntervalSeconds) {
        $shouldRunEmailQueue = false;
        $emailSkipLine = '[' . date('Y-m-d H:i:s') . "] [INFO] Email queue skipped by interval guard (min={$emailMinIntervalMinutes}m, next in " . ($emailMinIntervalSeconds - $emailAge) . "s).\n";
        appendCronLog($cronLog, $emailSkipLine);
        emitMaintenanceOutput($emailSkipLine, $isCli);
    }
}

if ($shouldRunEmailQueue) {
    $stepEmailLine = '[' . date('Y-m-d H:i:s') . "] [STEP] Email queue step started\n";
    appendCronLog($cronLog, $stepEmailLine);
    emitMaintenanceOutput($stepEmailLine, $isCli);

    @file_put_contents($emailLastRunMarker, (string)time());
    require_once $baseDir . '/admin/db_autofix_bootstrap.php';
    require_once $baseDir . '/email_helper.php';

    $emailQueueResult = process_email_queue($emailBatchLimit);
    $emailLine = '[' . date('Y-m-d H:i:s') . "] [INFO] Email queue: processed={$emailQueueResult['processed']} sent={$emailQueueResult['sent']} failed={$emailQueueResult['failed']} limit={$emailBatchLimit}\n";
    appendCronLog($cronLog, $emailLine);
    emitMaintenanceOutput($emailLine, $isCli);
}

$hasJedalenSpecificFlags = $forceJedalenSync || $onlyJedalenSync || $jedalenFetchInstitutionsOnly || $jedalenFetchMenusOnly || $jedalenInstitutionIdsCsv !== '';
$shouldRunMaintenance = true;

if (!$forceMaintenanceRun && !$hasJedalenSpecificFlags) {
    $lastRunTs = 0;
    if (is_file($maintenanceLastRunMarker)) {
        $rawLast = trim((string)@file_get_contents($maintenanceLastRunMarker));
        if ($rawLast !== '' && ctype_digit($rawLast)) {
            $lastRunTs = (int)$rawLast;
        }
    }

    $age = time() - $lastRunTs;
    $minIntervalSeconds = $maintenanceMinIntervalMinutes * 60;
    if ($lastRunTs > 0 && $age < $minIntervalSeconds) {
        $shouldRunMaintenance = false;
        $nextIn = $minIntervalSeconds - $age;
        $skipLine = '[' . date('Y-m-d H:i:s') . "] [INFO] Maintenance skipped by interval guard (min={$maintenanceMinIntervalMinutes}m, next in {$nextIn}s).\n";
        appendCronLog($cronLog, $skipLine);
        emitMaintenanceOutput($skipLine, $isCli);
    }
}

if ($shouldRunMaintenance) {
    $stepMaintenanceLine = '[' . date('Y-m-d H:i:s') . "] [STEP] Maintenance step started\n";
    appendCronLog($cronLog, $stepMaintenanceLine);
    emitMaintenanceOutput($stepMaintenanceLine, $isCli);

    @file_put_contents($maintenanceLastRunMarker, (string)time());

    require __DIR__ . '/core_version_refresh.php';
    $coreVersionResult = edudisplej_refresh_core_version_manifest(dirname($baseDir));
    if (!empty($coreVersionResult['message'])) {
        $coreLine = '[' . date('Y-m-d H:i:s') . '] ' . $coreVersionResult['message'] . "\n";
        appendCronLog($cronLog, $coreLine);
        emitMaintenanceOutput($coreLine, $isCli, !empty($coreVersionResult['error']));
    }

    ob_start();

    define('EDUDISPLEJ_DBJAVITO_NO_HTML', true);
    define('EDUDISPLEJ_DBJAVITO_ECHO', true);
    define('EDUDISPLEJ_MAINTENANCE_MIGRATE_ASSET_URLS', true);
    define('EDUDISPLEJ_FORCE_JEDALEN_SYNC', $forceJedalenSync);
    define('EDUDISPLEJ_MAINTENANCE_ONLY_JEDALEN_SYNC', $onlyJedalenSync);
    define('EDUDISPLEJ_JEDALEN_FETCH_INSTITUTIONS_ONLY', $jedalenFetchInstitutionsOnly);
    define('EDUDISPLEJ_JEDALEN_FETCH_MENUS_ONLY', $jedalenFetchMenusOnly);
    define('EDUDISPLEJ_JEDALEN_SELECTED_INSTITUTION_IDS_CSV', $jedalenInstitutionIdsCsv);

    if ($forceJedalenSync) {
        $forcedLine = '[' . date('Y-m-d H:i:s') . "] [INFO] Jedalen sync forced by runtime flag\n";
        appendCronLog($cronLog, $forcedLine);
        emitMaintenanceOutput($forcedLine, $isCli);
    }

    require __DIR__ . '/maintenance_task.php';

    $output = trim((string)ob_get_clean());
    if ($output !== '') {
        $taskOutput = $output . "\n";
        appendCronLog($cronLog, $taskOutput);
        emitMaintenanceOutput($taskOutput, $isCli);

        if (stripos($output, 'A modul tárhely nem írható') !== false || stripos($output, 'module storage is not writable') !== false) {
            emitMaintenanceIssue(
                'PDF upload error detected: module storage is not writable.',
                [
                    'Fix write permissions for uploads/companies/.../modules directory.',
                    'Ensure the runtime user has write access to the module storage path.',
                ],
                $isCli,
                $cronLog
            );
        }
    }

    $storageHealth = checkModuleStorageHealth($baseDir);
    if (!$storageHealth['ok']) {
        foreach ($storageHealth['errors'] as $storageError) {
            emitMaintenanceIssue($storageError, $storageHealth['recommendations'], $isCli, $cronLog);
        }
    }

    if (!$onlyJedalenSync) {
        $repairStartLine = '[' . date('Y-m-d H:i:s') . "] [STEP] DB repair step started\n";
        appendCronLog($cronLog, $repairStartLine);
        emitMaintenanceOutput($repairStartLine, $isCli);
        rmRunDatabaseStateRepair($baseDir, $isCli, $cronLog);
    }
}

$duration = round(microtime(true) - $start, 3);
$finishedLine = '[' . date('Y-m-d H:i:s') . "] [INFO] Unified cron scheduler finished in {$duration}s\n";
appendCronLog($cronLog, $finishedLine);
emitMaintenanceOutput($finishedLine, $isCli);

if (is_resource($lockHandle)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit(0);
