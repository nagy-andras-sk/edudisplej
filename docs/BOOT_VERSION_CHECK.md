# Boot-time Core Version Check and Auto-Update

## Overview

This feature ensures that every time a kiosk boots up, it automatically:
1. Checks the current core version (`/opt/edudisplej/VERSION`)
2. Fetches the target version from the server (`versions.json`)
3. Compares versions
4. If an update is needed, automatically runs the core update script
5. Logs all activities for auditing

## Files

### Boot-Time Update Script
- **File**: `webserver/install/init/edudisplej-boot-update.sh`
- **Purpose**: Checks core version and triggers update if needed
- **Location on kiosk**: `/opt/edudisplej/init/edudisplej-boot-update.sh`
- **Executable**: Yes
- **Run as**: root

### Systemd Service
- **File**: `webserver/install/init/edudisplej-boot-version-check.service`
- **Purpose**: Systemd unit that runs boot script at startup
- **Type**: Type=oneshot (runs once per boot)
- **Timing**: 
  - After: network-online.target, systemd-user-sessions.service
  - Before: edudisplej-kiosk.service
- **Location on kiosk**: `/etc/systemd/system/edudisplej-boot-version-check.service`
- **Enabled**: Yes (runs at every boot)

### Deployment Script
- **File**: `webserver/install/init/deploy-boot-version-check.sh`
- **Purpose**: Quickly install and enable the boot service
- **Usage**: `sudo bash /path/to/deploy-boot-version-check.sh`

## Installation

### Method 1: Automatic (via deploy script)
```bash
# On any kiosk
sudo bash /opt/edudisplej/init/deploy-boot-version-check.sh
```

### Method 2: Manual
```bash
# Copy files (already in place if update.sh was used)
sudo cp /opt/edudisplej/init/edudisplej-boot-version-check.service /etc/systemd/system/

# Make script executable
sudo chmod +x /opt/edudisplej/init/edudisplej-boot-update.sh

# Reload systemd and enable
sudo systemctl daemon-reload
sudo systemctl enable edudisplej-boot-version-check.service
```

## How It Works

### Boot Sequence
1. System boots → kernel loads
2. Early systemd services start (network, etc.)
3. `edudisplej-boot-version-check.service` activates (OneShot)
4. Runs `/opt/edudisplej/init/edudisplej-boot-update.sh` as root
5. Script:
   - Reads local `/opt/edudisplej/VERSION`
   - Fetches server `versions.json` OR cached sync response OR local fallback
   - Compares versions using semantic versioning
   - If `local < server`: triggers `update.sh --core-only --source=boot`
   - Waits for update to complete (max 10 minutes)
   - Logs results to `/opt/edudisplej/logs/boot_update.log`
6. Service finishes (success or with warning if update failed)
7. `edudisplej-kiosk.service` continues and starts the display

### Version Comparison Logic

```bash
# Parse versions as: MAJOR.MINOR.PATCH → integer comparison
# Example:
# 1.0.5  = 001000005
# 1.1.0  = 001001000
# 1.1.2  = 001001002

# If local < target: update needed
# If local == target: no update
# If local > target: no update (already newer)
```

### Logging

All activities are logged to: `/opt/edudisplej/logs/boot_update.log`

Example log entry:
```
[2026-04-11 18:45:23] [INFO] ========================================
[2026-04-11 18:45:23] [INFO] EduDisplej Boot-time Version Check
[2026-04-11 18:45:23] [INFO] ========================================
[2026-04-11 18:45:23] [INFO] Current version: 1.1.0
[2026-04-11 18:45:24] [SUCCESS] Retrieved target version: 1.1.2
[2026-04-11 18:45:24] [INFO] Target version: 1.1.2
[2026-04-11 18:45:24] [INFO] Version mismatch detected: 1.1.0 < 1.1.2
[2026-04-11 18:45:24] [INFO] === Starting boot-time core update ===
[2026-04-11 18:47:15] [SUCCESS] Core update completed successfully
[2026-04-11 18:47:15] [SUCCESS] Core update successful
[2026-04-11 18:47:15] [INFO] Boot version check completed (exit code: 0)
```

## Verification

### Check Service Status
```bash
# View current status
systemctl status edudisplej-boot-version-check.service

# View recent systemd journal logs
journalctl -u edudisplej-boot-version-check.service -n 50 --no-pager

# View service logs with timestamps
journalctl -u edudisplej-boot-version-check.service --since "1 hour ago"
```

### Check Boot Log
```bash
# View the detailed boot update log
tail -f /opt/edudisplej/logs/boot_update.log

# Search for specific boot
grep "Boot-time Version Check" /opt/edudisplej/logs/boot_update.log
```

### Check Current Version
```bash
# Read local version
cat /opt/edudisplej/VERSION

# Check if update process is running
pgrep -f "update.sh"

# Check last sync response (cached from edudisplej_sync_service.sh)
cat /opt/edudisplej/last_sync_response.json
```

## Safety Features

1. **Lock File**: `/tmp/edudisplej_boot_update.lock`
   - Prevents multiple simultaneous checks
   - Auto-expires after 60 seconds

2. **Timeout Protection**: 
   - Max 10 minutes (600 seconds) for update process
   - If timeout exceeded, kiosk boots anyway with warning

3. **Cooldown**: 
   - Respects existing core update cooldown from sync service
   - Avoids rapid retry loops

4. **Non-blocking**: 
   - Even if update fails, kiosk starts normally
   - Failure logged as WARNING, not ERROR
   - Display shows with current (old) version

5. **Fallback Chain**:
   - Try remote `versions.json`
   - Try cached sync response
   - Try local `versions.json`
   - If all fail: skip update, continue with boot

## Troubleshooting

### Service not starting at boot
```bash
# Check if service is enabled
systemctl is-enabled edudisplej-boot-version-check.service

# Enable if not enabled
sudo systemctl enable edudisplej-boot-version-check.service

# Check for syntax errors in service file
sudo systemctl status edudisplej-boot-version-check.service
```

### Update stuck in boot
```bash
# Check if update.sh is stalled
ps aux | grep update.sh

# Kill stalled update if needed
sudo pkill -f "update.sh"

# Check boot update log for errors
tail -100 /opt/edudisplej/logs/boot_update.log

# Check update process log
tail -100 /opt/edudisplej/logs/boot_update_process.log
```

### Version not updating
```bash
# Verify script has execute permission
ls -la /opt/edudisplej/init/edudisplej-boot-update.sh

# Test script manually
sudo bash /opt/edudisplej/init/edudisplej-boot-update.sh

# Check if token file exists
ls -la /opt/edudisplej/lic/token

# Check server versions.json is reachable
curl -s https://install.edudisplej.sk/init/versions.json | jq .
```

### Logs not being written
```bash
# Check log directory permissions
ls -la /opt/edudisplej/logs/

# Ensure directory exists
sudo mkdir -p /opt/edudisplej/logs/

# Set permissions
sudo chmod 775 /opt/edudisplej/logs/
```

## Integration with Existing Systems

- **Sync Service**: Does NOT conflict with `edudisplej_sync_service.sh`
  - Sync service runs every 5 minutes by default
  - Boot service runs at startup before display
  - Both respect the same core update cooldown

- **Update Script**: Uses existing `/opt/edudisplej/init/update.sh`
  - Same script, same mechanism
  - Just triggered differently (at boot vs. periodically)

- **Display Service**: 
  - Waits for boot version check to complete
  - Ensures core is current before displaying content

## Performance Impact

- **Boot Time**: 5-10 seconds added (or 5+ minutes if update needed)
- **Network**: One small HTTP request to fetch `versions.json`
- **CPU**: Minimal (just JSON parsing and version comparison)
- **Memory**: Negligible

## Best Practices

1. **Monitor Logs**: Regularly check boot logs to ensure updates are happening
2. **Test Updates**: Test new core versions on a single kiosk first
3. **Off-peak Updates**: Schedule major updates for off-business hours
4. **Rollback Plan**: Keep previous version available in case of issues
5. **Network Stability**: Ensure reliable network connection to servers

## Configuration

To modify boot-time behavior, edit `/opt/edudisplej/init/edudisplej-boot-update.sh` and change:

```bash
# Maximum time to wait for update (seconds)
UPDATE_TIMEOUT=600  # Change to 900 for 15 minutes

# Retry cooldown (inherited from sync service if set)
# Uses system environment variable if available
EDUDISPLEJ_CORE_UPDATE_RETRY_COOLDOWN
```

Then reload systemd:
```bash
sudo systemctl daemon-reload
```

## Related Documentation

- [Core Update And Versioning](../CORE_UPDATE_AND_VERSIONS.md)
- [Installation Guide](../INSTALLATION_GUIDE.md)
- [Optimization Implementation Guide](../OPTIMIZATION_IMPLEMENTATION_GUIDE.md)
