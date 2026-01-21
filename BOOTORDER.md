# EduDisplej Boot Order - Simplified Architecture

## Goal
After boot, display a terminal window on the main screen (tty1) running `edudisplej_terminal_script.sh` in an Openbox environment.

## Boot Sequence (Simplified)

```
┌─────────────────────────────────────────────────────────────────────┐
│ 1. SYSTEM BOOT                                                      │
│    └─ Bootloader → kernel → systemd                                │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 2. SYSTEMD TARGET: multi-user.target                               │
│    └─ network-online.target (optional, not blocking)               │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 3. SYSTEMD SERVICE: edudisplej-kiosk.service                       │
│    File: /etc/systemd/system/edudisplej-kiosk.service             │
│    User: <console_user> (auto-detected, usually pi or first user)  │
│    TTY: /dev/tty1 (main console, not vt7!)                         │
│    ExecStart: /opt/edudisplej/init/kiosk-start.sh                 │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 4. WRAPPER SCRIPT: kiosk-start.sh                                  │
│    File: /opt/edudisplej/init/kiosk-start.sh                      │
│    ┌───────────────────────────────────────────────────────────┐  │
│    │ 4a. Check flag: .kiosk_system_configured                  │  │
│    │     ├─ If NOT exists → run edudisplej-init.sh (first boot)│  │
│    │     └─ If exists → skip init, system already configured   │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 4b. terminate_xorg() - Kill any existing X servers        │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 4c. exec startx -- :0 vt1                                 │  │
│    │     (Starts X on vt1 = main console, NOT vt7!)           │  │
│    └───────────────────────────────────────────────────────────┘  │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 5. X SERVER STARTUP: startx                                        │
│    ├─ Reads: ~/.xinitrc                                           │
│    └─ Starts: Xorg :0 vt1 (on tty1, the main console!)           │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 6. XINITRC: ~/.xinitrc                                             │
│    File: /home/<user>/.xinitrc                                     │
│    Content: exec openbox-session                                   │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 7. WINDOW MANAGER: openbox-session                                │
│    ├─ Starts: /usr/bin/openbox                                    │
│    └─ Reads: ~/.config/openbox/autostart                         │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 8. OPENBOX AUTOSTART: ~/.config/openbox/autostart                 │
│    File: /home/<user>/.config/openbox/autostart                   │
│    ┌───────────────────────────────────────────────────────────┐  │
│    │ 8a. Configure display (xrandr - auto-detect outputs)      │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 8b. Disable screensaver (xset -dpms, s off, s noblank)   │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 8c. Hide cursor (unclutter -idle 1)                      │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 8d. Launch terminal:                                      │  │
│    │     xterm -fullscreen                                      │  │
│    │           -e /opt/edudisplej/init/edudisplej_terminal_script.sh │
│    └───────────────────────────────────────────────────────────┘  │
│    Log: /tmp/openbox-autostart.log                                │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 9. TERMINAL SCRIPT: edudisplej_terminal_script.sh                 │
│    File: /opt/edudisplej/init/edudisplej_terminal_script.sh       │
│    ┌───────────────────────────────────────────────────────────┐  │
│    │ - Display EduDisplej banner                               │  │
│    │ - Show system information (hostname, IP, resolution)      │  │
│    │ - Provide interactive bash shell                          │  │
│    └───────────────────────────────────────────────────────────┘  │
└─────────────────────────────────────────────────────────────────────┘
```

## Key Changes from Previous Version

### ✅ FIXED: VT Allocation
- **Before**: X server started on vt7 (wrong VT)
- **After**: X server starts on vt1 (main console)
- **Impact**: Display appears on primary screen immediately

### ✅ SIMPLIFIED: Script Flow
- **Before**: Complex chain through kiosk-launcher.sh → browser
- **After**: Direct path: Openbox autostart → xterm → terminal script
- **Impact**: Clear, predictable boot sequence

### ✅ CROSS-PLATFORM: No Raspberry Pi Assumptions
- **Before**: Hardcoded Raspberry Pi specifics
- **After**: Auto-detects display outputs, works on Debian/Ubuntu/etc
- **Impact**: Works on any Linux system with X11

### ✅ GOAL MET: Terminal Display
- **Before**: Browser-centric, complex kiosk setup
- **After**: Simple terminal with edudisplej_terminal_script.sh
- **Impact**: Exactly what was requested

## File Structure (Simplified)

```
/opt/edudisplej/
├── init/
│   ├── kiosk-start.sh ────────── Wrapper for systemd service
│   ├── edudisplej-init.sh ────── First-time setup (packages, config)
│   ├── edudisplej_terminal_script.sh ── Terminal display script (THE GOAL!)
│   ├── common.sh ─────────────── Shared functions
│   ├── edudisplej-checker.sh ── System checks
│   └── edudisplej-installer.sh ─ Package installer
│
/home/<user>/
├── .xinitrc ──────────────────── Launches openbox-session
└── .config/openbox/
    └── autostart ─────────────── Launches xterm with terminal script
```

## Log File Locations

| Log Purpose | File Path | Content |
|------------|-----------|---------|
| Init log | `/opt/edudisplej/session.log` | First-time setup output |
| APT log | `/opt/edudisplej/apt.log` | Package installations |
| Systemd | `journalctl -u edudisplej-kiosk.service` | Service output |
| Xorg | `/tmp/xorg-startup.log` | X server startup |
| Openbox | `/tmp/openbox-autostart.log` | Openbox autostart execution |
| Watchdog | `/tmp/edudisplej-watchdog.log` | Process monitoring |

## Debugging Commands

```bash
# Check service status
sudo systemctl status edudisplej-kiosk.service

# View live logs
sudo journalctl -u edudisplej-kiosk.service -f

# Check X server
ps aux | grep Xorg
echo $DISPLAY

# Check which VT X is running on
ps aux | grep Xorg | grep -o 'vt[0-9]*'
# Should show: vt1 (not vt7!)

# View Openbox autostart log
cat /tmp/openbox-autostart.log

# Check terminal process
ps aux | grep edudisplej_terminal_script.sh

# Verify display output
xrandr  # Run as console user on DISPLAY=:0
```

## Troubleshooting

### Display on wrong VT
**Symptom**: Nothing appears on screen, but system is running
**Check**: `ps aux | grep Xorg | grep -o 'vt[0-9]*'`
**Expected**: `vt1`
**Fix**: Edit kiosk-start.sh, ensure `startx -- :0 vt1` (not vt7)

### Terminal not appearing
**Check**: `cat /tmp/openbox-autostart.log`
**Look for**: "Launching EduDisplej terminal..."
**Fix**: Verify edudisplej_terminal_script.sh is executable

### First boot taking long
**Expected**: First boot installs packages (Xorg, Openbox, xterm)
**Check**: `tail -f /opt/edudisplej/session.log`
**Note**: Subsequent boots are fast (packages already installed)

## Cross-Platform Compatibility

This simplified architecture works on:
- ✅ Raspberry Pi (all models)
- ✅ Debian 10+
- ✅ Ubuntu 20.04+
- ✅ Any Linux with systemd and X11

**No Raspberry Pi specific code** - auto-detects:
- Display outputs (xrandr auto-detection)
- User account (first user with UID 1000)
- Architecture (for package selection)

## Watchdog Service (Paralelne / Parallel)

```
┌─────────────────────────────────────────────────────────────────────┐
│ SYSTEMD SERVICE: edudisplej-watchdog.service                       │
│ After: edudisplej-kiosk.service                                    │
│ ExecStart: /opt/edudisplej/init/edudisplej-watchdog.sh            │
│                                                                     │
│ Každých 10 sekúnd / Every 10 seconds:                              │
│ ├─ Check Xorg running                                              │
│ ├─ Check openbox running                                           │
│ ├─ Check xterm running                                             │
│ ├─ Check browser running                                           │
│ └─ Log to: /tmp/edudisplej-watchdog.log                           │
└─────────────────────────────────────────────────────────────────────┘
```

## Strom závislostí súborov / File Dependency Tree

```
/opt/edudisplej/
├── init/
│   ├── kiosk-start.sh ──────────┐
│   │   └─ calls: startx         │
│   │                             ▼
│   ├── edudisplej-init.sh ◄─── (prvý štart / first boot)
│   │   ├─ source: common.sh
│   │   ├─ source: edudisplej-checker.sh
│   │   ├─ source: edudisplej-installer.sh
│   │   ├─ creates: ~/.xinitrc
│   │   ├─ creates: ~/.config/openbox/autostart
│   │   └─ creates: ~/kiosk-launcher.sh
│   │
│   ├── edudisplej-watchdog.sh ◄─── (paralelné monitorovanie / parallel monitoring)
│   │   └─ monitors all processes
│   │
│   ├── common.sh ◄────────────┬─ (zdieľané funkcie / shared functions)
│   ├── edudisplej-checker.sh ─┤
│   └── edudisplej-installer.sh ┘
│
/home/edudisplej/
├── .xinitrc ────────────────────┐
│   └─ calls: openbox-session   │
│                                 ▼
├── .config/openbox/
│   └── autostart ───────────────┐
│       └─ calls: xterm + ...    │
│                                 ▼
└── kiosk-launcher.sh ◄──────────┘
    └─ launches: chromium/epiphany
```

## Umiestnenia log súborov / Log File Locations

| Typ logu / Log type | Súbor / File | Obsah / Content |
|-----------|------|---------|
| Init log | `/opt/edudisplej/session.log` | edudisplej-init.sh output (s časovými značkami / with timestamps) |
| APT log | `/opt/edudisplej/apt.log` | Inštalácie balíkov / Package installations |
| Systemd | `journalctl -u edudisplej-kiosk.service` | Service stdout/stderr (s časovými značkami / with timestamps) |
| Xorg | `/tmp/xorg-startup.log` | X server startup |
| Openbox | `/tmp/openbox-autostart.log` | Openbox autostart execution (s časovými značkami / with timestamps) |
| Launcher | `/tmp/kiosk-launcher.log` | kiosk-launcher.sh execution (s časovými značkami / with timestamps) |
| Watchdog | `/tmp/edudisplej-watchdog.log` | Process monitoring (s časovými značkami / with timestamps) |

## Príkazy na ladenie / Debugging Commands

```bash
# 1. Stav služby / Service status
sudo systemctl status edudisplej-kiosk.service
sudo systemctl status edudisplej-watchdog.service

# 2. Live log služby / Live service log
sudo journalctl -u edudisplej-kiosk.service -f
sudo journalctl -u edudisplej-watchdog.service -f

# 3. Openbox autostart log
cat /tmp/openbox-autostart.log

# 4. Kiosk launcher log
cat /tmp/kiosk-launcher.log

# 5. Watchdog status
cat /tmp/edudisplej-watchdog.log

# 6. Process check
ps aux | grep -E "(Xorg|openbox|xterm|chromium)"

# 7. X connection test
DISPLAY=:0 xdpyinfo | head -n 3

# 8. Všetky logy naraz / All logs at once
echo "=== OPENBOX AUTOSTART LOG ===" && cat /tmp/openbox-autostart.log 2>/dev/null || echo "No log"
echo "=== KIOSK LAUNCHER LOG ===" && cat /tmp/kiosk-launcher.log 2>/dev/null || echo "No log"
echo "=== WATCHDOG LOG ===" && tail -20 /tmp/edudisplej-watchdog.log 2>/dev/null || echo "No log"
echo "=== SYSTEMD LOG ===" && sudo journalctl -u edudisplej-kiosk.service -n 50 --no-pager
```

## Časové značky / Timestamps

Všetky logy teraz obsahujú časové značky vo formáte:
All logs now contain timestamps in format:
```
[YYYY-MM-DD HH:MM:SS] [LOG_LEVEL] message
```

Príklad / Example:
```
[2024-01-15 14:23:45] [INFO] Starting X server...
[2024-01-15 14:23:46] [SUCCESS] X connection OK
[2024-01-15 14:23:47] [WARNING] Browser not running
```

## Riešenie problémov / Troubleshooting

### Čierna obrazovka po štarte / Black screen after boot

1. Skontrolujte či bežia procesy / Check if processes are running:
   ```bash
   ps aux | grep -E "(Xorg|openbox|xterm|chromium)"
   ```

2. Pozrite sa na watchdog log pre prehľad / Check watchdog log for overview:
   ```bash
   cat /tmp/edudisplej-watchdog.log
   ```

3. Skontrolujte kde zlyhalo / Check where it failed:
   ```bash
   # Xorg nezačal / Xorg didn't start?
   cat /tmp/xorg-startup.log
   
   # Openbox nezačal / Openbox didn't start?
   cat /tmp/openbox-autostart.log
   
   # xterm nezačal / xterm didn't start?
   cat /tmp/kiosk-launcher.log
   ```

4. Skontrolujte systemd logy / Check systemd logs:
   ```bash
   sudo journalctl -u edudisplej-kiosk.service -n 100
   ```

### Xorg beží ale nič sa nezobrazuje / Xorg runs but nothing displays

1. Skontrolujte či openbox beží / Check if openbox is running:
   ```bash
   pgrep openbox && echo "Running" || echo "NOT running"
   ```

2. Skontrolujte openbox autostart log:
   ```bash
   cat /tmp/openbox-autostart.log
   ```

3. Skontrolujte či xterm beží / Check if xterm is running:
   ```bash
   pgrep xterm && echo "Running" || echo "NOT running"
   ```

### xterm beží ale prehliadač nie / xterm runs but browser doesn't

1. Skontrolujte launcher log:
   ```bash
   cat /tmp/kiosk-launcher.log
   ```

2. Skúste spustiť prehliadač manuálne / Try launching browser manually:
   ```bash
   DISPLAY=:0 chromium-browser --version
   ```

## Zmeny v tejto verzii / Changes in This Version

✅ Pridané časové značky do všetkých logov / Added timestamps to all logs
✅ Podrobné logovanie Openbox autostart / Detailed Openbox autostart logging
✅ Podrobné logovanie kiosk-launcher.sh / Detailed kiosk-launcher.sh logging
✅ Presmerovanie stdout/stderr do systemd journal / stdout/stderr redirect to systemd journal
✅ Logovanie štartu X servera / X server startup logging
✅ Watchdog služba pre monitorovanie / Watchdog service for monitoring
✅ Táto dokumentácia / This documentation
