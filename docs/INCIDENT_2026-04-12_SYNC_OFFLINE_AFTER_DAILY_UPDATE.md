# Incident Report - 2026-04-12

## Title

Displays marked offline after daily update check (sync service self-termination during update)

## Summary

Multiple displays were shown as offline in the control panel.
On the investigated kiosk (10.153.162.7), both of these services were not running:

- edudisplej-sync.service
- edudisplej-screenshot-service.service

The root cause was a sync-script update path that executed update.sh in the same process context. During update, service restarts stopped the running sync process, heartbeat stopped, and the dashboard marked displays offline.

## Impact

- No regular heartbeat/sync from affected kiosks
- Displays appear offline in dashboard even if kiosk mode may still render locally
- Screenshot stream also stops when screenshot service is down

## Detection

Issue was confirmed via SSH diagnostic session on 10.153.162.7.

### Key findings

- systemctl showed sync and screenshot services as inactive/dead before recovery
- sync.log stopped at lines equivalent to:
  - Checking for system updates...
  - Running system update (this may take a few minutes)...
- Last successful sync entries were from previous day, then no new heartbeat until manual restart

## Timeline (sample kiosk)

- 2026-04-11 around 11:20: sync entered daily update check
- update path restarted services
- sync/screenshot services ended up inactive
- 2026-04-12 12:49: services manually restarted, kiosk recovered
- 2026-04-12 12:53+: fresh successful sync entries visible again

## Root Cause

In check_and_update() inside the sync script, update.sh was invoked in blocking mode:

- bash "/opt/edudisplej/init/update.sh" >> "$LOG_DIR/update.log" 2>&1

At the same time, update.sh performs service restart logic for edudisplej services. Because sync was not detached, this could terminate the currently running sync loop during update/restart sequence.

Result: sync process died and did not continue heartbeat cycle.

## Immediate Remediation

On affected kiosk:

1. Restart services:
   - systemctl restart edudisplej-sync.service
   - systemctl restart edudisplej-screenshot-service.service
2. Verify active state:
   - systemctl is-active edudisplej-sync.service
   - systemctl is-active edudisplej-screenshot-service.service
3. Verify fresh sync log entries in /opt/edudisplej/logs/sync.log

## Permanent Fix

File changed:

- webserver/install/init/edudisplej_sync_service.sh

Change in check_and_update():

- Run update in detached mode (nohup + background)
- Add duplicate-run guard via pgrep so only one update process can run

New behavior:

- Daily update launch no longer ties lifecycle to current sync shell loop
- Update-triggered restarts do not permanently kill sync heartbeat flow

## Validation

After patch + service restart on 10.153.162.7:

- edudisplej-sync.service: active
- edudisplej-screenshot-service.service: active
- sync.log shows new successful registration/sync cycles after recovery

## Rollout Guidance

1. Deploy patched edudisplej_sync_service.sh to all kiosks.
2. Restart sync service fleet-wide in maintenance window.
3. Monitor for 24h across daily update window:
   - no mass offline events
   - sync.log continues after update check lines
4. Add alert rule:
   - if edudisplej-sync.service inactive for > 2 sync intervals, trigger incident alert.

## Prevention

- Any long-running maintenance task started from sync loop must be detached.
- Avoid synchronous update calls from heartbeat-critical process paths.
- Add post-update health assertion:
  - sync service must be active after update procedure.
