# Cron Maintenance Migration

`dbjavito.php` can now run in both modes:

- Web/manual mode (HTML output)
- Cron/CLI mode (no HTML)

Dedicated cron runner:

- `webserver/control_edudisplej_sk/cron/maintenance/run_maintenance.php`

## Recommended schedule

- Every 5 minutes

## Install helper

- `webserver/control_edudisplej_sk/cron/maintenance/install_cron.sh`

## Notes

- Uses file-lock to avoid concurrent runs.
- Includes timeout/memory guard for stable cron execution.
- Logs written under `webserver/control_edudisplej_sk/logs/maintenance-cron.log`.
