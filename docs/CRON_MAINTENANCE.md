# Cron Maintenance Migration

`dbjavito.php` can now run in both modes:

- Web/manual mode (HTML output)
- Cron/CLI mode (no HTML)

Dedicated cron runner endpoint:

- `webserver/control_edudisplej_sk/cron.php`

## Recommended schedule

- `webserver/control_edudisplej_sk/cron.php --maintenance-min-interval-minutes=15 --email-min-interval-minutes=5 --email-limit=50`
	- Run every 5 minutes (single cron entry)
	- Purpose: unified scheduler decides which internal tasks should run now

## Install helper

- `webserver/control_edudisplej_sk/cron/maintenance/install_cron.sh`

## Notes

- Uses file-lock to avoid concurrent runs.
- Includes timeout/memory guard for stable cron execution.
- `cron.php` is the single cron endpoint; internally it executes the unified scheduler with interval-throttling per task.
- Logs written under:
	- `webserver/control_edudisplej_sk/logs/maintenance-cron.log`
