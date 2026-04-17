# Global EduDisplej Service Resilience Improvement Guide

## Issue Description

**Problem:** EduDisplej kiosk devices (e.g., 10.153.162.7) were going offline repeatedly. The `edudisplej-kiosk` service was configured with `Restart=on-failure`, which only restarts the service if it exits with a failure code. However, some crashes may not be detected as failures by systemd, leaving the service in a stopped state.

**Impact:** Devices become unresponsive and unavailable for displaying content until manually restarted or until the watchdog service detects and corrects the issue.

## Solution Implemented

Update systemd service configurations to use `Restart=always` and increase restart time limits for more resilient operation.

### Changes Made to edudisplej-kiosk.service

| Parameter | Before | After | Reason |
|-----------|--------|-------|--------|
| `Restart` | `on-failure` | `always` | Restart service immediately on any exit, not just failures |
| `RestartSec` | `8` | `10` | Give system more time to stabilize between restarts |
| `StartLimitIntervalSec` | `120` | `300` | Increase time window for counting restart attempts |
| `StartLimitBurst` | `4` | `5` | Allow more restart attempts before giving up |

### Service File Location
```
/etc/systemd/system/edudisplej-kiosk.service
```

## Deployment Instructions

### Method 1: Single Device (Command Line)

```bash
# SSH into the device
ssh edudisplej@<IP_ADDRESS>

# Update restart policy
sudo sed -i 's/^Restart=on-failure/Restart=always/' /etc/systemd/system/edudisplej-kiosk.service
sudo sed -i 's/^RestartSec=8/RestartSec=10/' /etc/systemd/system/edudisplej-kiosk.service
sudo sed -i 's/^StartLimitIntervalSec=120/StartLimitIntervalSec=300/' /etc/systemd/system/edudisplej-kiosk.service
sudo sed -i 's/^StartLimitBurst=4/StartLimitBurst=5/' /etc/systemd/system/edudisplej-kiosk.service

# Reload systemd
sudo systemctl daemon-reload

# Verify changes
systemctl show -p Restart -p RestartSec -p StartLimitIntervalSec -p StartLimitBurst edudisplej-kiosk
```

### Method 2: Batch Deployment (PowerShell Script)

```powershell
# Define target devices
$devices = @("10.153.162.7", "10.153.162.8", "10.153.162.9")
$username = "edudisplej"
$password = "edudisplej"

foreach ($device in $devices) {
    Write-Host "[*] Updating $device..."
    
    # Update all settings
    & echo "y" | plink -l $username -pw $password $device `
        "sudo sed -i 's/^Restart=on-failure/Restart=always/' /etc/systemd/system/edudisplej-kiosk.service && " + `
        "sudo sed -i 's/^RestartSec=8/RestartSec=10/' /etc/systemd/system/edudisplej-kiosk.service && " + `
        "sudo sed -i 's/^StartLimitIntervalSec=120/StartLimitIntervalSec=300/' /etc/systemd/system/edudisplej-kiosk.service && " + `
        "sudo sed -i 's/^StartLimitBurst=4/StartLimitBurst=5/' /etc/systemd/system/edudisplej-kiosk.service && " + `
        "sudo systemctl daemon-reload"
    
    Write-Host "[+] $device updated"
}

Write-Host "[+] Batch deployment complete!"
```

### Method 3: Configuration Management (Ansible/Puppet)

Update your configuration management system to deploy the modified service file:

```bash
# Copy updated service file to all devices
ansible all -i inventory.ini -m copy \
    -a "src=/path/to/edudisplej-kiosk.service dest=/etc/systemd/system/edudisplej-kiosk.service" \
    -b

# Reload systemd
ansible all -i inventory.ini -m systemd \
    -a "daemon_reload=yes" \
    -b
```

### Method 4: Complete Service File Replacement

If you prefer to replace the entire file, here's the updated content:

```ini
[Unit]
Description=EduDisplej Kiosk Mode - Display System
Documentation=https://github.com/nagy-andras-sk/edudisplej
After=network-online.target systemd-user-sessions.service
Wants=network-online.target
Conflicts=getty@tty1.service
OnFailure=getty@tty1.service
StartLimitIntervalSec=300
StartLimitBurst=5

[Service]
Type=simple
User=edudisplej
Group=edudisplej
WorkingDirectory=/home/edudisplej
Environment=HOME=/home/edudisplej
Environment=USER=edudisplej
Environment=DISPLAY=:0
Environment=XDG_RUNTIME_DIR=/run/user/1000

# TTY configuration for tty1 (main console)
StandardInput=tty
StandardOutput=journal
StandardError=journal
TTYPath=/dev/tty1
TTYReset=yes
TTYVHangup=yes
TTYVTDisallocate=yes

# Run wrapper script to handle initialization and X startup
ExecStart=/opt/edudisplej/init/kiosk-start.sh

# Enhanced restart policy - now always restarts for better reliability
Restart=always
RestartSec=10

# Security and resource limits
# Allow access to graphics and input devices
SupplementaryGroups=video input tty

[Install]
WantedBy=multi-user.target
```

## Verification

After applying the changes, verify on each device:

```bash
# Check if changes were applied
grep -E '^(Restart|RestartSec|StartLimit)' /etc/systemd/system/edudisplej-kiosk.service

# Expected output:
# StartLimitIntervalSec=300
# StartLimitBurst=5
# Restart=always
# RestartSec=10

# Verify service is running
systemctl status edudisplej-kiosk

# Monitor service restarts
journalctl -u edudisplej-kiosk -f
```

## Monitoring and Troubleshooting

### View Recent Restart History
```bash
journalctl -u edudisplej-kiosk --since "1 hour ago" | grep -E "(Start|Restart|Stop)"
```

### Check for Repeated Restarts (Infinite Loop)
```bash
# If service keeps restarting immediately, check logs for errors
journalctl -u edudisplej-kiosk -n 100 | tail -50
journalctl -u edudisplej-kiosk -x  # Show relevant context messages
```

### Rollback (if needed)
```bash
# Restore previous settings
sudo sed -i 's/^Restart=always/Restart=on-failure/' /etc/systemd/system/edudisplej-kiosk.service
sudo sed -i 's/^RestartSec=10/RestartSec=8/' /etc/systemd/system/edudisplej-kiosk.service
sudo sed -i 's/^StartLimitIntervalSec=300/StartLimitIntervalSec=120/' /etc/systemd/system/edudisplej-kiosk.service
sudo sed -i 's/^StartLimitBurst=5/StartLimitBurst=4/' /etc/systemd/system/edudisplej-kiosk.service
sudo systemctl daemon-reload
```

## System Architecture Benefits

1. **Automatic Recovery**: Service automatically restarts on any crash without manual intervention
2. **Smarter Rate Limiting**: Increased restart window (300s) allows more recovery attempts while preventing CPU burnout
3. **Watchdog Redundancy**: Watchdog service (`edudisplej-watchdog`) still monitors for deeper issues
4. **Graceful Degradation**: Even if multiple restarts occur, the system continues trying to recover

## Testing Recommendation

**Before global rollout, test on 2-3 devices:**
1. Apply changes using Method 1 (single device)
2. Monitor for 24 hours
3. Check `/var/log/syslog` and `journalctl` for any issues
4. Verify screenshot updates and content display work correctly
5. Then roll out to all devices using Method 2 or 3

## Related Files
- [edudisplej-watchdog.service configuration](../docs/SERVICE_OVERVIEW.md)
- [Device offline incident log](../docs/INCIDENT_2026-04-14_FALSE_OFFLINE_TWO_KIOSKS.md)
- [Service architecture documentation](../docs/CRON_MAINTENANCE.md)

## Version History
- **2026-04-15**: Initial global deployment guide for improved kiosk service resilience
- **Applied to**: 10.153.162.7 (test device)
- **Status**: Ready for rollout to all production devices
