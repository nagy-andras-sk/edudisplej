<?php
/**
 * Legacy DB fixer entrypoint.
 *
 * Deprecated: delegates to cron maintenance runner.
 */

$maintenanceEntrypoint = __DIR__ . '/cron/maintenance/run_maintenance.php';

if (PHP_SAPI !== 'cli') {
    header('Location: cron/maintenance/run_maintenance.php', true, 302);
    exit;
}

$phpBinary = (defined('PHP_BINARY') && PHP_BINARY !== '') ? PHP_BINARY : 'php';
$command = escapeshellarg($phpBinary) . ' ' . escapeshellarg($maintenanceEntrypoint) . ' 2>&1';
$output = [];
$exitCode = 1;

if (function_exists('exec')) {
    @exec($command, $output, $exitCode);
    if (!empty($output)) {
        echo implode(PHP_EOL, $output) . PHP_EOL;
    }
    exit($exitCode);
}

fwrite(STDERR, "Maintenance delegation failed: exec() is not available. Run cron/maintenance/run_maintenance.php directly.\n");
exit(2);

