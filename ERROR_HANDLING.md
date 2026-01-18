# EduDisplej Error Handling Guide

## Overview

This document describes the error handling, self-healing mechanisms, and loop prevention features implemented in EduDisplej to ensure reliable operation and prevent common failure modes.

## Key Features

### 1. File and Line Number Reporting

**All error and warning messages now include file name and line number:**

```
[ERROR] [edudisplej-init.sh:234] Failed to install package: chromium-browser
[WARNING] [minimal-kiosk.sh:87] Primary URL not accessible: https://example.com
```

This makes debugging much easier by showing exactly where in the code an error occurred.

### 2. Infinite Loop Prevention

The system implements multiple safety mechanisms to prevent infinite restart loops:

#### Chromium Browser Restarts
- **Limit**: Maximum 10 restarts
- **Reset**: Counter resets after 30 seconds of stable operation
- **Action**: Exits with FATAL error when limit reached

```
✗ [minimal-kiosk.sh:198] Chromium restart limit reached (10)
✗ [minimal-kiosk.sh:199] FATAL: Too many Chromium failures, stopping to prevent infinite loop
```

#### X Server Restarts
- **Limit**: Maximum 3 restarts in minimal-kiosk.sh
- **Reset**: Counter resets after successful start
- **Action**: Exits with FATAL error when limit reached

```
✗ [minimal-kiosk.sh:157] X server restart limit reached (3)
✗ [minimal-kiosk.sh:158] FATAL: Too many X server failures, stopping to prevent infinite loop
```

#### Main Monitoring Loop Restarts
- **Limit**: Maximum 5 restart attempts in edudisplej-init.sh
- **Reset**: Counter resets after 5 minutes of stable operation
- **Action**: Exits with FATAL error and diagnostic info when limit reached

```
[ERROR] [edudisplej-init.sh:574] X server restart limit reached (5 attempts)
[ERROR] [edudisplej-init.sh:575] FATAL: Too many restart failures, stopping to prevent infinite loop
[ERROR] [edudisplej-init.sh:576] Check logs: session.log, kiosk.log, /var/log/Xorg.0.log
```

### 3. Automatic URL Fallback

The system automatically tries fallback URLs if the configured URL is not accessible:

**Fallback Chain:**
1. Configured URL (from edudisplej.conf)
2. `https://www.time.is` (default clock display)
3. `file:///opt/edudisplej/localweb/clock.html` (offline clock)
4. `about:blank` (minimal fallback)

**URL Verification:**
- Checks URL accessibility before starting Chromium
- Uses curl with 10-second timeout to verify HTTP URLs
- Skips verification for local files and about:blank
- Automatically selects first accessible URL from chain

Example output:
```
⚠ [minimal-kiosk.sh:52] Primary URL not accessible: https://example.com
   [minimal-kiosk.sh:55] Trying fallback: https://www.time.is
✓ [minimal-kiosk.sh:58] Using fallback URL: https://www.time.is
```

### 4. Package Installation Retry Logic

Network operations and package installations now have automatic retry with backoff:

#### apt-get update
- **Attempts**: 3 retries
- **Delay**: 5 seconds between attempts
- **Fallback**: Continues with cached package lists on failure

```
[WARNING] [edudisplej-init.sh:189] apt-get update failed (attempt 1/3)
[WARNING] [edudisplej-init.sh:189] apt-get update failed (attempt 2/3)
[WARNING] [edudisplej-init.sh:196] Continuing with cached package lists...
```

#### apt-get install
- **Attempts**: 2 retries per package
- **Delay**: 10 seconds between attempts
- **Verification**: Checks if package is actually installed after installation

```
[WARNING] [edudisplej-init.sh:271] Installation of chromium-browser failed (attempt 1/2)
[OK] [edudisplej-init.sh:279] Installed browser: chromium-browser
```

### 5. Self-Healing Mechanisms

#### Uptime-Based Counter Reset

The system tracks how long processes run successfully and resets error counters after stable operation:

**Chromium:** Resets after 30 seconds of stable operation
```
   [minimal-kiosk.sh:224] Chromium ran for 125s (>= 30s), resetting restart counter
```

**X Server (main loop):** Resets after 5 minutes (300 seconds) of stable operation
```
[INFO] System ran for 456s, resetting restart counter
```

This allows the system to recover from transient errors while still protecting against persistent failures.

#### Automatic Process Cleanup

Before starting X or Chromium, the system:
1. Lists all existing PIDs
2. Sends TERM signal to gracefully stop processes
3. Waits 1 second
4. Sends KILL signal to any remaining processes
5. Removes stale lock files

```
   [minimal-kiosk.sh:79] Terminating chromium-browser processes: 1234 1235
   [minimal-kiosk.sh:105] Force killing chromium-browser: 1234
✓ [minimal-kiosk.sh:127] X cleanup done
```

### 6. Enhanced Logging

All operations now produce detailed, context-aware logs:

#### Process Lifecycle Tracking
```
✓ [minimal-kiosk.sh:211] Chromium started (PID: 5678, attempt: 2)
✗ [minimal-kiosk.sh:218] Chromium exited after 45s
   [minimal-kiosk.sh:224] Chromium ran for 45s (>= 30s), resetting restart counter
```

#### Stage Markers
```
========== Kiosk Start: Sat Jan 18 21:00:00 UTC 2026 ==========
[minimal-kiosk.sh] Starting with PID 1234
[1/5] [minimal-kiosk.sh:35] Checking dependencies...
[2/5] [minimal-kiosk.sh:73] Cleaning previous X sessions...
[3/5] [minimal-kiosk.sh:132] Setting up openbox...
[4/5] [minimal-kiosk.sh:154] Starting X server...
[5/5] [minimal-kiosk.sh:176] Starting Chromium kiosk...
```

## Troubleshooting

### Common Error Scenarios

#### 1. "Chromium restart limit reached"

**Cause:** Chromium is crashing repeatedly within 30 seconds

**Check:**
```bash
tail -100 /opt/edudisplej/kiosk.log
cat /var/log/Xorg.0.log
```

**Possible fixes:**
- Check if URL is accessible: `curl -I https://your-url.com`
- Verify Chromium is properly installed: `which chromium-browser`
- Check for GPU/display driver issues in Xorg.0.log
- Try different URL or use local clock: edit `/opt/edudisplej/edudisplej.conf`

#### 2. "X server restart limit reached"

**Cause:** X server fails to start

**Check:**
```bash
cat /var/log/Xorg.0.log
ls -la /tmp/.X0-lock
```

**Possible fixes:**
- Check display/GPU configuration
- Verify xorg packages: `dpkg -l | grep xorg`
- Remove stale locks: `sudo rm -f /tmp/.X0-lock /tmp/.X11-unix/X0`
- Check HDMI connection and display

#### 3. "Package installation failed"

**Cause:** Network issues or repository problems

**Check:**
```bash
tail -50 /opt/edudisplej/apt.log
apt-get update
apt-cache policy chromium-browser
```

**Possible fixes:**
- Check internet connection: `ping -c 3 google.com`
- Update package lists manually: `sudo apt-get update`
- Check repository configuration: `cat /etc/apt/sources.list`
- Free up disk space: `df -h`

#### 4. "Primary URL not accessible"

**Cause:** Network issues or URL is down

**Check:**
```bash
curl -I https://your-url.com
ping -c 3 your-domain.com
```

**Result:** System automatically falls back to time.is or local clock

**Manual override:**
```bash
# Edit config to use working URL
sudo nano /opt/edudisplej/edudisplej.conf
# Change KIOSK_URL to working URL
# Then restart
sudo systemctl restart chromiumkiosk-minimal
```

## Log Files

| File | Purpose | Location |
|------|---------|----------|
| session.log | Main init script output | /opt/edudisplej/session.log |
| kiosk.log | Minimal kiosk script output | /opt/edudisplej/kiosk.log |
| apt.log | Package installation logs | /opt/edudisplej/apt.log |
| Xorg.0.log | X server logs | /var/log/Xorg.0.log |

## Configuration

### Restart Limits

Edit the script files to change limits (requires updating init files via install server):

**minimal-kiosk.sh:**
```bash
MAX_CHROMIUM_RESTARTS=10      # Browser restart limit
MAX_X_RESTARTS=3              # X server restart limit
CHROMIUM_MIN_UPTIME=30        # Seconds before resetting counter
```

**edudisplej-init.sh:**
```bash
MAX_RESTART_LOOPS=5           # Main monitoring loop limit
MIN_UPTIME_FOR_RESET=300      # Seconds (5 min) before resetting
```

### Fallback URLs

Edit common.sh or minimal-kiosk.sh:
```bash
FALLBACK_URLS=(
    "https://www.time.is"
    "file:///opt/edudisplej/localweb/clock.html"
    "about:blank"
)
```

## Implementation Details

### Helper Functions in common.sh

**retry_command()** - Generic retry with exponential backoff
```bash
retry_command 3 curl -fsSL https://example.com
```

**check_url()** - Verify URL accessibility
```bash
if check_url "https://example.com"; then
    echo "URL is accessible"
fi
```

**get_working_url()** - Find first accessible URL from configured + fallbacks
```bash
WORKING_URL=$(get_working_url "$KIOSK_URL")
```

## Benefits

1. **Easier Debugging**: File:line numbers in all error messages
2. **No Infinite Loops**: Safety limits prevent runaway restarts
3. **Better Reliability**: Automatic retries for transient failures
4. **Graceful Degradation**: Fallback URLs ensure display always shows something
5. **Self-Healing**: Counters reset after stable operation allows recovery
6. **Clear Diagnostics**: Detailed logging helps identify root causes
7. **Production Ready**: System can run unattended with confidence

## Backwards Compatibility

All changes are backwards compatible:
- Existing configuration files work unchanged
- Log format is enhanced but still human-readable
- System behavior is more robust but functionally equivalent
- No breaking changes to configuration or deployment

## Future Enhancements

Potential improvements for consideration:
- [ ] Email/webhook notifications on FATAL errors
- [ ] Automatic log rotation with compression
- [ ] Health check HTTP endpoint
- [ ] Remote monitoring integration
- [ ] Automatic bug report generation
