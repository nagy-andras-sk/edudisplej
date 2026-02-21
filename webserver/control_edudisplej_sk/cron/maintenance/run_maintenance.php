<?php
/**
 * Cron entrypoint for EduDisplej DB maintenance.
 *
 * Intended schedule: every 5 minutes.
 */

$baseDir = dirname(__DIR__, 2);
$runtimeDir = __DIR__ . '/.runtime';
$logDir = $baseDir . '/logs';
$lockFile = $runtimeDir . '/maintenance.lock';
$cronLog = $logDir . '/maintenance-cron.log';

@mkdir($runtimeDir, 0775, true);
@mkdir($logDir, 0775, true);

@set_time_limit(300);
ini_set('max_execution_time', '300');
ini_set('memory_limit', '256M');

$timestamp = date('Y-m-d H:i:s');

$lockHandle = fopen($lockFile, 'c+');
if ($lockHandle === false) {
    file_put_contents($cronLog, "[$timestamp] [ERROR] Cannot open lock file: $lockFile\n", FILE_APPEND);
    exit(2);
}

if (!flock($lockHandle, LOCK_EX | LOCK_NB)) {
    file_put_contents($cronLog, "[$timestamp] [INFO] Previous maintenance run still active, skipping.\n", FILE_APPEND);
    fclose($lockHandle);
    exit(0);
}

ftruncate($lockHandle, 0);
fwrite($lockHandle, (string)getmypid());
fflush($lockHandle);

$start = microtime(true);
file_put_contents($cronLog, "[$timestamp] [INFO] Maintenance start\n", FILE_APPEND);

ob_start();

define('EDUDISPLEJ_DBJAVITO_NO_HTML', true);
define('EDUDISPLEJ_DBJAVITO_ECHO', true);

require $baseDir . '/dbjavito.php';

$output = trim((string)ob_get_clean());
if ($output !== '') {
    file_put_contents($cronLog, $output . "\n", FILE_APPEND);
}

$duration = round(microtime(true) - $start, 3);
file_put_contents($cronLog, '[' . date('Y-m-d H:i:s') . "] [INFO] Maintenance finished in {$duration}s\n", FILE_APPEND);

flock($lockHandle, LOCK_UN);
fclose($lockHandle);

exit(0);
