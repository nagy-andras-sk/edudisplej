# Maintenance Cron

This folder contains the dedicated cron runner for DB maintenance.

## Entrypoint

- `run_maintenance.php` (primary)
- `run_maintenance.sh` (shell wrapper)

## Install 5-minute cron

```bash
bash cron/maintenance/install_cron.sh
```

It installs:

```cron
*/5 * * * * php /.../cron/maintenance/run_maintenance.php >> /.../logs/maintenance-cron.log 2>&1
```

## Behavior

- file lock prevents overlapping runs
- max execution time is limited
- logs are written to `logs/maintenance-cron.log`
- runs `maintenance_task.php` directly in console mode
