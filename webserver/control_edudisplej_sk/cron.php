<?php
/**
 * Unified cron entrypoint for all scheduled jobs.
 *
 * Schedule this single file (recommended: every 5 minutes).
 * Internal scheduler decides what to run now (email queue, maintenance,
 * meal sync and all maintenance_task.php sub-jobs).
 */

require_once __DIR__ . '/cron/maintenance/run_maintenance.php';
