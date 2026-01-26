# EduDisplej - Simplified System (v2.0)

## Overview

The EduDisplej system has been simplified to focus on its core functionality: **displaying a local clock HTML file in the surf browser**.

## What Changed

### Before (v1.x)
- Complex API client with remote server communication
- Device registration to central server
- Command execution from remote API
- Screenshot capabilities
- Multiple services and dependencies

### After (v2.0)
- **Simple watchdog service** - monitors system locally
- **Local clock display** - shows time in surf browser
- **No remote dependencies** - fully standalone operation
- **Foundation for future expansion** - clean, simple codebase

## Current System Behavior

### On Boot
1. System starts the kiosk service
2. X server launches
3. Surf browser opens displaying `clock.html`
4. Watchdog service monitors system health

### Watchdog Service
- **File**: `/opt/edudisplej/init/edudisplej-api-client.py`
- **Service**: `edudisplej-api-client.service`
- **Purpose**: Simple monitoring loop (to be expanded later)
- **Interval**: 60 seconds
- **Monitors**:
  - X server status
  - Browser status
- **Logs**: `/opt/edudisplej/logs/watchdog.log`

### Clock Display
- **File**: `/opt/edudisplej/init/clock.html`
- **Browser**: surf (lightweight WebKit browser)
- **Features**:
  - Clean, centered clock display
  - Hours and minutes (large)
  - Seconds (smaller, animated)
  - Black background, white text
  - Responsive design

## Installation

The system installs with a single command:

```bash
curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash
```

## Files Structure

```
/opt/edudisplej/
├── init/
│   ├── edudisplej-api-client.py     # Watchdog service
│   ├── edudisplej-api-client.service # Service file
│   ├── clock.html                    # Clock HTML
│   └── (other kiosk scripts)
└── logs/
    ├── watchdog.log                  # Watchdog logs
    └── watchdog.pid                  # Watchdog PID
```

## What Was Removed

- ❌ API server code (`webserver/server/api/register.php`)
- ❌ Remote API communication
- ❌ Device registration
- ❌ Command execution framework
- ❌ Screenshot functionality
- ❌ Complex API infrastructure

## What Remains

- ✅ Local clock display (`clock.html`)
- ✅ Surf browser integration
- ✅ Simple watchdog monitoring
- ✅ Kiosk mode functionality
- ✅ System initialization scripts
- ✅ Hostname configuration
- ✅ Installation scripts

## Future Expansion

The watchdog service is designed as a foundation for future development:

1. **Enhanced Monitoring**
   - Memory/CPU usage
   - Disk space
   - Network connectivity
   - Automatic service restart

2. **Optional Features**
   - Content rotation
   - VLC integration
   - Multiple display support
   - Remote API (if needed)

3. **Improvements**
   - Better error handling
   - Enhanced logging
   - Configuration management
   - Scheduled tasks

## Technical Details

### Watchdog Service
- **Language**: Python 3 (standard library only)
- **Dependencies**: None (no external packages required)
- **Runs as**: systemd service
- **User**: root
- **Restart**: automatic on failure

### Clock Display
- **Technology**: HTML5 + JavaScript
- **No dependencies**: Pure HTML/CSS/JS
- **Offline**: Works without internet
- **Responsive**: Adapts to screen size

## Service Management

```bash
# Check watchdog status
systemctl status edudisplej-api-client.service

# View watchdog logs
journalctl -u edudisplej-api-client.service -f
cat /opt/edudisplej/logs/watchdog.log

# Restart watchdog
systemctl restart edudisplej-api-client.service

# Check kiosk status
systemctl status edudisplej-kiosk.service
```

## Summary

The system is now **simple, focused, and maintainable**:
- One purpose: display local clock
- One browser: surf
- One monitoring service: watchdog
- Zero remote dependencies
- Clean foundation for future expansion

---

*Last updated: January 2026*
