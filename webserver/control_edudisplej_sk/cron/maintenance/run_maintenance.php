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

function emitMaintenanceOutput(string $line, bool $isCli): void {
    static $httpHeadersSent = false;

    if (!$isCli && !$httpHeadersSent && !headers_sent()) {
        header('Content-Type: text/plain; charset=utf-8');
        $httpHeadersSent = true;
    }

    echo $line;
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

require __DIR__ . '/maintenance_task.php';

$output = trim((string)ob_get_clean());
if ($output !== '') {
    $taskOutput = $output . "\n";
    appendCronLog($cronLog, $taskOutput);
    emitMaintenanceOutput($taskOutput, $isCli);
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
