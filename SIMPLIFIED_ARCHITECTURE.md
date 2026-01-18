# Simplified EduDisplej Architecture (2026-01-18)

## Overview

This document describes the simplified architecture implemented to fix the Raspberry Pi boot loop issue and reduce code complexity.

## Problem

The original code had:
- **Syntax errors** causing X server restart loops
- **Too many lines**: xclient.sh (417 lines), kiosk.sh (290 lines)
- **Complex browser detection** with NEON checks and multiple fallbacks
- **Excessive Chromium flags** (30+ flags causing potential crashes)
- **Complex error handling** with overlays and multiple retry mechanisms
- **High error potential** due to complexity

## Solution

Simplified the code to focus on core functionality:
1. Start X session reliably
2. Detect and launch a browser
3. Display the clock (local or remote content)
4. Collect hardware information

## Changes Made

### xclient.sh: 417 → 209 lines (50% reduction)

**Simplified:**
- Browser detection: Simple priority order (epiphany → chromium → firefox)
- Removed NEON support checks (unnecessary complexity)
- Minimal browser flags (only essential ones)
- Direct logging (no tee, simpler)
- Removed complex error overlays
- Use kill with specific PIDs (not pkill)

**Browser Detection Priority:**
1. **epiphany-browser** - Lightweight, works on all ARM devices
2. **chromium-browser** - Standard Chromium build
3. **chromium** - Alternative Chromium
4. **firefox-esr** - Fallback option

**Browser Flags:**

- **Epiphany**: `--application-mode URL`
- **Chromium**: Only 8 essential flags:
  - `--kiosk` - Fullscreen mode
  - `--no-sandbox` - Required for root operation
  - `--disable-gpu` - Software rendering
  - `--disable-infobars` - No info bars
  - `--noerrdialogs` - No error dialogs
  - `--incognito` - Private mode
  - `--no-first-run` - Skip first run wizard
  - `--disable-translate` - No translation prompts
- **Firefox**: `--kiosk --private-window URL`

### kiosk.sh: 290 → 140 lines (52% reduction)

**Simplified:**
- Removed duplicate browser detection (handled in xclient.sh)
- Removed get_browser_flags (handled in xclient.sh)
- Removed start_browser_kiosk (handled in xclient.sh)
- Simplified cleanup_x_sessions (only X server, not browsers)
- Streamlined stop_kiosk_mode

### Hardware Information Collection

The simplified xclient.sh now calls `hwinfo.sh generate` at X session start, which collects:

- **System**: Hostname, kernel, architecture, OS
- **CPU**: Model, cores, NEON support, temperature
- **Memory**: Total, free, available (in MB)
- **Disk**: Total, used, free, percentage
- **Network**: MAC addresses, IP addresses, gateway, WiFi SSID
- **Display**: Resolution (from xrandr)
- **Raspberry Pi**: Model, serial, firmware, voltage, throttling status
- **Browser**: Installed browsers and versions
- **EduDisplej**: Version, mode, uptime

This information is saved to `/opt/edudisplej/hwinfo.conf` for reference.

## Architecture Flow

```
1. systemd starts chromiumkiosk.service
   ↓
2. Service runs: xinit xclient.sh -- :0 vt1
   ↓
3. X server starts on display :0
   ↓
4. xclient.sh runs:
   a. Load configuration
   b. Detect browser (epiphany → chromium → firefox)
   c. Setup X environment:
      - Disable screensaver
      - Start unclutter (hide cursor)
      - Start openbox (window manager)
   d. Collect hardware info (hwinfo.sh)
   e. Start browser in loop:
      - Kill old browser processes
      - Launch browser with URL
      - Wait for exit
      - Restart after 10s
```

## File Comparison

| File | Original | Simplified | Reduction |
|------|----------|-----------|-----------|
| xclient.sh | 417 lines | 209 lines | 50% |
| kiosk.sh | 290 lines | 140 lines | 52% |
| **Total** | **707 lines** | **349 lines** | **51%** |

## Benefits

1. **Reliability**: Less code = fewer bugs
2. **Maintainability**: Easier to understand and modify
3. **Performance**: Faster startup, less overhead
4. **Compatibility**: Works on all ARM devices (Pi Zero, 1, 2, 3, 4, 5)
5. **Debugging**: Simpler logs, easier to diagnose issues

## Testing

To test the simplified code:

```bash
# Check syntax
bash -n /opt/edudisplej/init/xclient.sh
bash -n /opt/edudisplej/init/kiosk.sh

# Test manually (as root)
export DISPLAY=:0
/opt/edudisplej/init/xclient.sh

# View logs
tail -f /opt/edudisplej/xclient.log

# Check hardware info
cat /opt/edudisplej/hwinfo.conf
```

## Future Improvements

- [ ] Add retry limit for browser crashes
- [ ] Implement simple health check endpoint
- [ ] Add browser preference configuration
- [ ] Create startup screenshot for diagnostics

## Rollback

If issues occur, the old code can be found in git history:

```bash
git show 7f9ce78:webserver/install/init/xclient.sh > xclient.sh.old
git show 7f9ce78:webserver/install/init/kiosk.sh > kiosk.sh.old
```

## Summary

The simplified architecture reduces code complexity by 51% while maintaining all essential functionality. The focus is on reliability and ease of maintenance, making it easier to diagnose and fix issues on Raspberry Pi deployments.
