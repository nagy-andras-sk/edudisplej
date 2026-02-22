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

$forceJedalenSync = false;
$onlyJedalenSync = false;
$jedalenFetchInstitutionsOnly = false;
$jedalenFetchMenusOnly = false;
$jedalenInstitutionIdsCsv = '';
if ($isCli) {
    $argv = $_SERVER['argv'] ?? [];
    $forceJedalenSync = in_array('--force-jedalen-sync', $argv, true);
    $onlyJedalenSync = in_array('--only-jedalen-sync', $argv, true);
    $jedalenFetchInstitutionsOnly = in_array('--jedalen-fetch-institutions-only', $argv, true);
    $jedalenFetchMenusOnly = in_array('--jedalen-fetch-menus-only', $argv, true);
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
$startLine = "[$timestamp] [INFO] Maintenance start\n";
appendCronLog($cronLog, $startLine);
emitMaintenanceOutput($startLine, $isCli);

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

$duration = round(microtime(true) - $start, 3);
$finishedLine = '[' . date('Y-m-d H:i:s') . "] [INFO] Maintenance finished in {$duration}s\n";
appendCronLog($cronLog, $finishedLine);
emitMaintenanceOutput($finishedLine, $isCli);

if (is_resource($lockHandle)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit(0);
