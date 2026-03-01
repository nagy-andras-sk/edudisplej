<?php
/**
 * Cron entrypoint for Email Queue processing.
 *
 * Recommended schedule: every 1 minute.
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
    $fallback = rtrim((string)sys_get_temp_dir(), '/\\') . '/edudisplej-email-queue';
    $candidates = [$runtimeDirPrimary, $logDirPrimary . '/.runtime', $fallback];

    foreach ($candidates as $candidate) {
        if (ensureWritableDir($candidate)) {
            return $candidate;
        }
    }

    return $fallback;
}

function pickCronLogPath(string $logDirPrimary): ?string {
    $fallbackDir = rtrim((string)sys_get_temp_dir(), '/\\') . '/edudisplej-email-queue';
    $candidates = [$logDirPrimary, $fallbackDir];

    foreach ($candidates as $dir) {
        if (ensureWritableDir($dir)) {
            return rtrim($dir, '/\\') . '/email-queue-cron.log';
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
$lockFile = rtrim($runtimeDir, '/\\') . '/email-queue.lock';
$cronLog = pickCronLogPath($logDirPrimary);

@set_time_limit(120);
ini_set('max_execution_time', '120');
ini_set('memory_limit', '192M');

$timestamp = date('Y-m-d H:i:s');
$lockHandle = @fopen($lockFile, 'c+');
if ($lockHandle === false) {
    appendCronLog($cronLog, "[$timestamp] [WARNING] Cannot open lock file, continuing without lock: $lockFile\n");
}

if (is_resource($lockHandle) && !flock($lockHandle, LOCK_EX | LOCK_NB)) {
    appendCronLog($cronLog, "[$timestamp] [INFO] Previous email queue run still active, skipping.\n");
    fclose($lockHandle);
    exit(0);
}

if (is_resource($lockHandle)) {
    ftruncate($lockHandle, 0);
    fwrite($lockHandle, (string)getmypid());
    fflush($lockHandle);
}

$start = microtime(true);
appendCronLog($cronLog, "[$timestamp] [INFO] Email queue processing start\n");

require_once $baseDir . '/admin/db_autofix_bootstrap.php';
require_once $baseDir . '/email_helper.php';

$limit = 50;
if (PHP_SAPI === 'cli') {
    $argv = $_SERVER['argv'] ?? [];
    foreach ($argv as $arg) {
        if (strpos((string)$arg, '--limit=') === 0) {
            $value = (int)substr((string)$arg, strlen('--limit='));
            if ($value > 0) {
                $limit = min(500, $value);
            }
            break;
        }
    }
}

$result = process_email_queue($limit);
$duration = round(microtime(true) - $start, 3);
appendCronLog(
    $cronLog,
    '[' . date('Y-m-d H:i:s') . "] [INFO] Email queue processed={$result['processed']} sent={$result['sent']} failed={$result['failed']} limit={$limit} duration={$duration}s\n"
);

if (is_resource($lockHandle)) {
    flock($lockHandle, LOCK_UN);
    fclose($lockHandle);
}

exit(0);
