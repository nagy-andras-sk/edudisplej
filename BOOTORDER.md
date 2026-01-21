# EduDisplej Boot Order - Simplified & Optimized

## Goal
After boot, display a fullscreen terminal on the main screen (tty1) running `edudisplej_terminal_script.sh`.

## Simple Boot Flow

```
System Boot
    ↓
systemd starts edudisplej-kiosk.service on tty1
    ↓
kiosk-start.sh (26 lines)
    ├─ First boot? Run edudisplej-init.sh
    ├─ Kill any old X servers
    └─ Start: startx -- :0 vt1
         ↓
    ~/.xinitrc (3 lines)
         └─ exec openbox-session
              ↓
         ~/.config/openbox/autostart (40 lines)
              ├─ Configure display (auto-detect)
              ├─ Disable screensaver
              └─ Launch: xterm -fullscreen -e edudisplej_terminal_script.sh
                   ↓
              Terminal with banner + system info visible ✅
```

## Key Points

✅ **Simple**: Only 3 key scripts (kiosk-start.sh, .xinitrc, autostart)  
✅ **Reliable**: Direct path, no complex logic  
✅ **Cross-platform**: Works on any Linux with systemd + X11  
✅ **VT1**: Display on main console, not vt7  
✅ **Maintainable**: Easy to understand and modify  

## Files

```
/opt/edudisplej/init/
├── kiosk-start.sh           # 26 lines - Start X on vt1
├── edudisplej-init.sh       # First boot setup
├── edudisplej_terminal_script.sh  # 52 lines - Terminal display
└── edudisplej-kiosk.service # Systemd service

/home/<user>/
├── .xinitrc                 # 3 lines - Start Openbox
└── .config/openbox/
    └── autostart            # 40 lines - Launch terminal
```

## Verification

```bash
# Verify X is on vt1
ps aux | grep Xorg | grep -o 'vt[0-9]*'  # Should show: vt1

# Check terminal running
ps aux | grep edudisplej_terminal_script

# View logs
cat /tmp/openbox-autostart.log
```

## Troubleshooting

**Terminal not visible?**
```bash
# Check X is running
ps aux | grep Xorg

# Check Openbox started
ps aux | grep openbox

# Check xterm launched
ps aux | grep xterm

# View log
cat /tmp/openbox-autostart.log
```

**Wrong VT?**
```bash
ps aux | grep Xorg | grep -o 'vt[0-9]*'
# If not vt1, edit /opt/edudisplej/init/kiosk-start.sh
```

## Summary

- **Total**: ~150 lines of core code (simplified from 400+)
- **Boot time**: Fast - no unnecessary waits or checks
- **Reliability**: Simple = fewer failure points
- **Goal**: Terminal on main screen EVERY TIME ✅

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
