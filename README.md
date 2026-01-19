# EduDisplej

EduDisplej is a Raspberry Pi-based digital signage solution that runs in kiosk mode. It provides a robust, unattended installation system for Debian/Ubuntu/Raspberry Pi OS.

**Two installation modes are available:**
- **ARMv6 Kiosk (Pi 1 / Zero 1)**: Uses Epiphany browser with visible terminal launcher
- **Standard Kiosk (Pi 2+)**: Uses Chromium browser with minimal background service

## ARMv6 Kiosk Mode (Raspberry Pi 1 / Zero 1)

For older Raspberry Pi devices with ARMv6 architecture (Pi 1, Zero 1 - **not** Zero 2), use the specialized ARMv6 installer that uses Epiphany browser instead of Chromium.

### Target Devices
- Raspberry Pi 1 (Model A, A+, B, B+)
- Raspberry Pi Zero 1 (NOT Zero 2)
- Any ARMv6 (armv6l) device

### What This Script Does
- Installs Xorg, Openbox, xterm, Epiphany browser, and utilities (unclutter, xdotool, figlet)
- Disables/removes conflicting display managers (lightdm, lxdm, sddm, gdm3, plymouth)
- Configures auto-login on tty1 (no Display Manager)
- Auto-starts X server + Openbox on boot
- Launches xterm with a visible terminal showing:
  - ASCII "EDUDISPLEJ" banner
  - 5-second countdown
  - Epiphany browser in fullscreen mode
- Implements browser watchdog (auto-restart on close)
- Disables DPMS/screensaver and hides cursor

### What This Script Does NOT Do
- Does **not** install Chromium (not available on ARMv6)
- Does **not** use systemd service for kiosk (uses direct X auto-start)
- Does **not** provide interactive configuration menu
- Does **not** auto-update from remote server

### Installation

```bash
# Download and run the ARMv6 installer
curl -O https://raw.githubusercontent.com/nagy-andras-sk/edudisplej/main/install-kiosk.sh
sudo bash install-kiosk.sh
```

The script is idempotent and safe to run multiple times.

### URL Configuration

There are several ways to set the kiosk URL:

**Option 1: Edit kiosk-launcher.sh directly (simplest)**
```bash
sudo -u <KIOSK_USER> nano ~/kiosk-launcher.sh
# Change: URL="${1:-https://example.com}"
# To:     URL="${1:-https://your-url.com}"
```

**Option 2: Pass URL as environment variable**
```bash
# Edit ~/.config/openbox/autostart
xterm -fa Monospace -fs 14 -geometry 120x36+20+20 -e "bash -c 'URL=https://your-url.com \$HOME/kiosk-launcher.sh'" &
```

**Option 3: Pass URL as script argument**
```bash
# Edit ~/.config/openbox/autostart
xterm -fa Monospace -fs 14 -geometry 120x36+20+20 -e "$HOME/kiosk-launcher.sh https://your-url.com" &
```

### Testing Without Reboot

```bash
# Test as the kiosk user
sudo -u <KIOSK_USER> DISPLAY=:0 XDG_VTNR=1 startx -- :0 vt1
```

### Reboot to Start Kiosk

```bash
sudo reboot
```

After reboot:
1. System auto-logins on tty1 as configured user
2. X server starts automatically on :0
3. Openbox window manager launches
4. xterm opens with visible terminal
5. Terminal shows EDUDISPLEJ banner and countdown
6. Epiphany browser launches in fullscreen
7. If browser closes, watchdog restarts it automatically

### Manual Control

```bash
# Restart X server (use the alias created by installer)
xrestart

# Or manually:
pkill -9 Xorg
sleep 1
startx -- :0 vt1

# Stop kiosk (kill X server)
pkill -9 Xorg

# Check what's running
ps aux | grep -E "Xorg|openbox|epiphany|xterm"
```

### Files Created by Installer

- `/etc/systemd/system/getty@tty1.service.d/autologin.conf` - Auto-login configuration
- `~/.profile` - Auto-start X on tty1
- `~/.xinitrc` - Start Openbox session
- `~/.config/openbox/autostart` - Openbox startup configuration
- `~/kiosk-launcher.sh` - Terminal launcher script
- `~/.bashrc` - xrestart alias

## Standard Kiosk Mode (Raspberry Pi 2+ with Chromium)

For newer Raspberry Pi devices (Pi 2, 3, 4, 5, Zero 2), use the standard installer that uses Chromium browser.

### Installation

```bash
# Quick installation with default settings (recommended)
curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash
```

### Boot Flow

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

## Minimal Kiosk Mode (Chromium)

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

