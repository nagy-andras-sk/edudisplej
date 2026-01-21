# EduDisplej Boot Order a Závislostný Diagram / Boot Order and Dependency Diagram

## Spúšťací proces / Boot Sequence

```
┌─────────────────────────────────────────────────────────────────────┐
│ 1. SYSTEM BOOT                                                      │
│    └─ Raspberry Pi bootloader → kernel → systemd                   │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 2. SYSTEMD TARGET: multi-user.target                               │
│    └─ network-online.target                                        │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 3. SYSTEMD SERVICE: edudisplej-kiosk.service                       │
│    Súbor / File: /etc/systemd/system/edudisplej-kiosk.service     │
│    User: edudisplej (alebo / or CONSOLE_USER)                     │
│    ExecStart: /opt/edudisplej/init/kiosk-start.sh                 │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 4. WRAPPER SCRIPT: kiosk-start.sh                                  │
│    Súbor / File: /opt/edudisplej/init/kiosk-start.sh              │
│    ┌───────────────────────────────────────────────────────────┐  │
│    │ 4a. Check flag: .kiosk_system_configured                  │  │
│    │     ├─ AK NIE / IF NOT → sudo edudisplej-init.sh         │  │
│    │     └─ AK ÁNO / IF YES → skip init                       │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 4b. terminate_xorg() - Kill existing X servers            │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 4c. exec startx -- :0 vt1 2>&1 | tee /tmp/xorg-startup.log│  │
│    └───────────────────────────────────────────────────────────┘  │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 5. X SERVER STARTUP: startx                                        │
│    ├─ Reads: ~/.xinitrc                                           │
│    └─ Starts: /usr/lib/xorg/Xorg :0 vt1                          │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 6. XINITRC: ~/.xinitrc                                             │
│    Súbor / File: /home/edudisplej/.xinitrc                        │
│    Obsah / Content: exec openbox-session                           │
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
│    Súbor / File: /home/edudisplej/.config/openbox/autostart      │
│    ┌───────────────────────────────────────────────────────────┐  │
│    │ 8a. xset -dpms / xset s off / xset s noblank              │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 8b. unclutter -idle 1 &                                    │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 8c. xterm -e "$HOME/kiosk-launcher.sh" &                  │  │
│    └───────────────────────────────────────────────────────────┘  │
│    Log: /tmp/openbox-autostart.log                                │
└────────────────────────────┬────────────────────────────────────────┘
                             ▼
┌─────────────────────────────────────────────────────────────────────┐
│ 9. KIOSK LAUNCHER: ~/kiosk-launcher.sh                            │
│    Súbor / File: /home/edudisplej/kiosk-launcher.sh               │
│    ┌───────────────────────────────────────────────────────────┐  │
│    │ 9a. X connection test (xdpyinfo)                          │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 9b. Show ASCII logo (figlet)                              │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 9c. F2 countdown (raspi-config option)                    │  │
│    ├───────────────────────────────────────────────────────────┤  │
│    │ 9d. Launch browser (chromium/epiphany)                    │  │
│    │     └─ chromium-browser --kiosk $URL                      │  │
│    └───────────────────────────────────────────────────────────┘  │
│    Log: /tmp/kiosk-launcher.log                                   │
└─────────────────────────────────────────────────────────────────────┘
```

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
