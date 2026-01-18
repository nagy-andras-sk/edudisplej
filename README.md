# EduDisplej

EduDisplej is a Raspberry Pi-based digital signage solution that runs in kiosk mode using chromium-browser. It provides a robust, unattended installation system for Debian/Ubuntu/Raspberry Pi OS.

## Boot Flow (Chromium-based)

- install.sh installs and registers the init service that launches [install/init/edudisplej-init.sh](webserver/install/init/edudisplej-init.sh) on boot
- init script loads modules, installs any missing required packages (chromium-browser, openbox, xinit, unclutter, curl), shows its current version, and self-updates from the install server when a newer bundle is available
- shows system summary (saved mode, kiosk URL, language, IP/gateway, Wi-Fi SSID, resolution, hostname)
- 10s countdown appears; press F12 or M during countdown to open the configuration menu, otherwise the saved mode starts automatically
- kiosk mode now uses chromium-browser in kiosk mode via X/Openbox; it restarts chromium-browser if it exits

## Version

**EDUDISPLEJ INSTALLER v. 28 12 2025**

## Key Features

- **Automatic Error Recovery**: Self-healing mechanisms with retry logic
- **Clear Error Messages**: All errors show file name and line number for easy debugging
- **Loop Prevention**: Safety limits prevent infinite restart loops
- **URL Fallback**: Automatic fallback to working URLs if primary fails
- **Detailed Logging**: Comprehensive logs with timestamps and context
- **Unattended Operation**: Designed to run reliably without manual intervention

See [ERROR_HANDLING.md](ERROR_HANDLING.md) for detailed documentation on error handling features.

## Quick Installation

Install EduDisplej on your Raspberry Pi or any Debian-based system with a single command:

```bash
# Quick installation with default settings (recommended)

curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash
```

## Minimal Kiosk Mode

The system includes a minimal kiosk service that provides a bulletproof X11 + Chromium kiosk startup:

### Manual Testing

```bash
# 1. Run minimal kiosk directly
sudo /opt/edudisplej/init/minimal-kiosk.sh

# 2. Check logs
tail -f /opt/edudisplej/kiosk.log

# 3. Verify X server
DISPLAY=:0 xdpyinfo

# 4. Check Chromium process
ps aux | grep chromium

# 5. Check service status
systemctl status chromiumkiosk-minimal
```

### Debug Commands

```bash
# View all logs
tail -f /opt/edudisplej/kiosk.log
tail -f /opt/edudisplej/service.log
tail -f /opt/edudisplej/session.log

# Restart minimal kiosk service
sudo systemctl restart chromiumkiosk-minimal

# Check for X server errors
cat /var/log/Xorg.0.log

# Test X display
DISPLAY=:0 xrandr
```

### Log Files

- `/opt/edudisplej/kiosk.log` - Minimal kiosk script logs (includes [file:line] for errors)
- `/opt/edudisplej/service.log` - Systemd service logs  
- `/opt/edudisplej/session.log` - Main init script logs (includes [file:line] for errors)
- `/opt/edudisplej/xclient.log` - X client logs (legacy)
- `/opt/edudisplej/apt.log` - Package installation logs
- `/var/log/Xorg.0.log` - X server error logs

