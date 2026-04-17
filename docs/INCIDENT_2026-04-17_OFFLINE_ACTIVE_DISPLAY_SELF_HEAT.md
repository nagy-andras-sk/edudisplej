# INCIDENT 2026-04-17 - OFFLINE STATUS WHILE DISPLAY WAS ACTIVE

## Summary

Two kiosks could still render content on screen, but the control panel marked them as offline.
The issue reproduced on host `10.153.162.7` and affected status-related background services.

## Impact

- Display playback stayed active (`edudisplej-kiosk.service` running).
- Backend-facing telemetry/sync services became inactive, causing false offline state.
- Remote command execution and health reporting became unreliable.

## Symptoms Observed

- `edudisplej-sync.service`, `edudisplej-health.service`, and `edudisplej-command-executor.service` were inactive.
- `edudisplej-health.service` repeatedly failed with:
  - `Permission denied: /opt/edudisplej/health_status.json`
- Prior self-heal timer got stuck in elapsed state (`Trigger: n/a`), so no periodic recovery happened.

## Root Cause

1. Service stop/start windows during update flow could leave multiple core services inactive.
2. `health_status.json` ownership drifted to root-owned state, while health service runs as non-root user.
3. Existing self-heal mechanism was not robust enough across all update/error states and could stop scheduling periodic runs.

## Permanent Fix Implemented in Core

### 1) New global self-heal unit set

Added core files under `webserver/install/init`:

- `edudisplej-self-heat.sh`
- `edudisplej-self-heat.service`
- `edudisplej-self-heat.timer`

Behavior:

- Runs every minute via systemd timer.
- Monitors critical units and restarts inactive ones:
  - `edudisplej-sync.service`
  - `edudisplej-health.service`
  - `edudisplej-command-executor.service`
  - `edudisplej-kiosk.service`
  - `edudisplej-watchdog.service`
  - `edudisplej-screenshot-service.service`
- Repairs health status file permissions on each run.

### 2) Installer/update hardening

- `structure.json` now includes self-heat script/service/timer as deployable core artifacts.
- `install.sh` and `update.sh` both ensure self-heat units are installed/enabled/started.
- `update.sh` now has robust fallback service installation even when structure parsing fails.
- `update.sh` service restart phase now tolerates missing/list failures and uses a critical service fallback list.
- `install.sh` and `update.sh` enforce writable `health_status.json` for runtime user.

### 3) Health service resilience

- `edudisplej-health.sh` no longer hard-crashes the service on status file write/report failure.
- Uses fail-safe write behavior and continues loop with fallback logging.

## Verification Checklist

- `systemctl is-active edudisplej-sync.service` -> `active`
- `systemctl is-active edudisplej-health.service` -> `active`
- `systemctl is-active edudisplej-command-executor.service` -> `active`
- `systemctl is-active edudisplej-self-heat.timer` -> `active`
- `systemctl list-timers edudisplej-self-heat.timer` shows next run scheduled.
- `ls -l /opt/edudisplej/health_status.json` shows writable ownership for runtime user.

## Operational Notes

- If a kiosk appears offline while the display is visibly active, prioritize checking health/sync/cmd-executor first.
- Self-heat timer should be considered mandatory in fleet baseline.
- During update incidents, inspect `update.log`/`core_update.log` for stop-start sequences and partial restarts.
