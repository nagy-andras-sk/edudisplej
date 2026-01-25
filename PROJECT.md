# EduDisplej - Project Documentation

## Project Overview

**EduDisplej** is a kiosk system designed for Raspberry Pi and other Linux devices. It automatically launches a web browser or terminal display in full-screen mode after system boot. The system is designed to be simple to install, reliable, and easy to maintain.

### Version Information
- **Current Version:** 2.0
- **Last Updated:** January 2026
- **Repository:** https://github.com/nagy-andras-sk/edudisplej

---

## Project History & Evolution

### Phase 1: Initial Development
The project started as a simple kiosk system that would:
- Boot a Raspberry Pi into a full-screen browser
- Display web content (primarily time.is or custom URLs)
- Provide automatic recovery if the browser crashed
- Support multiple browser types (Chromium, Epiphany)

**Key files created:**
- `install.sh` - Main installation script
- `edudisplej-init.sh` - System initialization
- Various shell scripts for package management

### Phase 2: Modularity & Reliability
The system evolved to become more modular with:
- Separate scripts for checking (`edudisplej-checker.sh`)
- Separate scripts for installation (`edudisplej-installer.sh`)
- Common functions library (`common.sh`)
- Watchdog service to monitor system health
- Support for multiple architectures (ARMv6, ARMv7, x86)

**Key improvements:**
- Better error handling
- Progress indicators during installation
- Backup mechanism during updates
- Idempotent installation (can be re-run safely)

### Phase 3: Terminal Mode
Shifted focus from browser-only to terminal display:
- Terminal-based display with ASCII art
- System status information
- Network monitoring
- Lightweight operation

### Phase 4: Simplification (v2.0)
**Objectives:**
1. **Simplify to core functionality** - Focus on local clock display
2. **Add surf browser** - Lightweight browsing option  
3. **Automatic hostname configuration** - Based on MAC address
4. **Basic watchdog** - Simple monitoring loop for future expansion

**Key changes:**
- Created `edudisplej-system.sh` - Unified system management
- Created `edudisplej-hostname.sh` - Automatic hostname configuration
- Created `edudisplej-api-client.py` - Simple watchdog service (no remote API)
- Optimized installation process
- Better documentation
- Removed API server code - system now fully local

---

## System Architecture

### Directory Structure

```
/opt/edudisplej/                          # Main application directory
├── init/                                 # Initialization scripts
│   ├── common.sh                         # Common functions library
│   ├── edudisplej-system.sh             # Unified system management
│   ├── edudisplej-init.sh               # Main initialization script
│   ├── edudisplej-hostname.sh           # Hostname configuration
│   ├── edudisplej-api-client.py         # Watchdog service (local monitoring)
│   ├── kiosk-start.sh                    # X server startup wrapper
│   ├── kiosk.sh                          # Kiosk mode functions
│   ├── edudisplej_terminal_script.sh    # Terminal display script
│   ├── edudisplej-kiosk.service         # Systemd service definition
│   ├── edudisplej-watchdog.service      # Watchdog service
│   ├── edudisplej-api-client.service    # Watchdog service file
│   └── clock.html                        # Local clock HTML page
├── localweb/                             # Local web files
│   └── clock.html                        # Offline clock page
├── logs/                                 # Log files
│   ├── watchdog.log                     # Watchdog logs
│   └── watchdog.pid                     # Watchdog PID file
├── data/                                 # Data directory
│   ├── packages.json                     # Package tracking
│   └── installed_packages.txt            # Backup package tracking
├── edudisplej.conf                       # Configuration file
├── session.log                           # Current session log
├── session.log.old                       # Previous session log
├── apt.log                               # APT operations log
├── .kiosk_mode                           # Saved kiosk mode
├── .console_user                         # Saved user
├── .user_home                            # User home directory
├── .kiosk_configured                     # Flag: kiosk packages installed
├── .kiosk_system_configured              # Flag: kiosk system configured
└── .hostname_configured                  # Flag: hostname configured

/home/[user]/                             # User home directory
├── .xinitrc                              # X initialization file
└── .config/openbox/autostart             # Openbox autostart config

/etc/systemd/system/
├── edudisplej-kiosk.service              # Main kiosk service
├── edudisplej-watchdog.service           # Watchdog service
└── edudisplej-api-client.service         # Watchdog service (symlink)

/etc/sudoers.d/
└── edudisplej                            # Sudo permissions for init script
```

### Key Components

#### 1. Installation System (`install.sh`)
- Downloads all necessary scripts from the server
- Configures systemd services
- Sets up user permissions
- Installs required packages (including surf browser)
- Handles ARMv6 specific fixes

#### 2. Initialization System (`edudisplej-init.sh`)
- Loads common functions and modules
- Configures hostname based on MAC address
- Checks system readiness
- Installs missing packages
- Configures kiosk environment

#### 3. System Management (`edudisplej-system.sh`) - NEW
- Unified package management
- System health checks
- Combines functionality from checker and installer

#### 4. Hostname Configuration (`edudisplej-hostname.sh`) - NEW
- Automatically sets hostname to `edudisplej-XXXXXX`
- XXXXXX = last 6 characters of primary MAC address
- Runs once, before first boot
- Updates `/etc/hostname` and `/etc/hosts`

#### 5. Watchdog Service (`edudisplej-api-client.py`)
- Simple Python-based monitoring service
- Checks system health periodically (every 60 seconds)
- Monitors X server and browser status
- Logs monitoring data
- Foundation for future development
- No remote API calls - fully local operation

#### 6. Legacy Watchdog Service (`edudisplej-watchdog.sh`)
- Shell-based watchdog (legacy)
- Can be replaced by Python watchdog service

---

## Boot/Startup Sequence

### Boot Flow Diagram

```
┌─────────────────────────────────────────────────────────────┐
│                    SYSTEM BOOT (GRUB/Bootloader)            │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                    KERNEL INITIALIZATION                     │
│  - Load kernel modules                                       │
│  - Initialize hardware                                       │
│  - Mount root filesystem                                     │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                    SYSTEMD INIT                              │
│  - Start basic system services                               │
│  - Initialize network                                        │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│              EDUDISPLEJ SERVICES START                       │
│  - edudisplej-kiosk.service (ExecStart: kiosk-start.sh)     │
│  - edudisplej-watchdog.service                              │
│  - edudisplej-api-client.service (NEW)                      │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│              KIOSK-START.SH EXECUTION                        │
│  1. Check if first boot (.kiosk_system_configured flag)     │
│  2. If first boot → Run edudisplej-init.sh                  │
│  3. Kill existing X servers                                  │
│  4. Clean stale lock files                                   │
│  5. Setup XDG_RUNTIME_DIR                                    │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│            EDUDISPLEJ-INIT.SH (First Boot Only)             │
│  1. Load common.sh (shared functions)                        │
│  2. Load edudisplej-system.sh (system management)           │
│  3. Configure hostname (edudisplej-hostname.sh) - NEW       │
│  4. Show boot screen with system info                        │
│  5. Check internet connection                                │
│  6. Check system readiness:                                  │
│     - Base packages (openbox, xinit, xterm, curl, etc.)     │
│     - Kiosk packages (xterm, xdotool, figlet, surf) - NEW  │
│     - Configuration files (.xinitrc, autostart)             │
│  7. If not ready → Install missing packages                 │
│  8. Configure kiosk system:                                  │
│     - Disable display managers                               │
│     - Setup X server permissions                             │
│     - Create .xinitrc                                        │
│     - Create Openbox autostart                               │
│     - Set .kiosk_system_configured flag                     │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                   START X SERVER                             │
│  Command: startx -- :0 vt1 -keeptty -nolisten tcp           │
│  - X server starts on display :0                             │
│  - Runs on virtual terminal 1 (vt1)                          │
│  - Executes ~/.xinitrc                                       │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│                    .XINITRC EXECUTION                        │
│  Command: exec openbox-session                               │
│  - Starts Openbox window manager                             │
│  - Reads Openbox configuration                               │
│  - Executes ~/.config/openbox/autostart                     │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│           OPENBOX AUTOSTART EXECUTION                        │
│  1. Wait for X server to be ready (xset q check)            │
│  2. Configure display:                                       │
│     - Detect connected output (xrandr)                       │
│     - Set resolution and primary output                      │
│  3. Disable screen blanking (xset)                           │
│  4. Hide cursor (unclutter)                                  │
│  5. Set black background (xsetroot)                          │
│  6. Launch terminal:                                         │
│     xterm -fullscreen -e edudisplej_terminal_script.sh      │
└────────────────────────┬────────────────────────────────────┘
                         │
                         ▼
┌─────────────────────────────────────────────────────────────┐
│           TERMINAL DISPLAY RUNNING                           │
│  - Shows EduDisplej banner                                   │
│  - Displays system information                               │
│  - Network status                                            │
│  - Ready for API commands (via api-client) - NEW            │
└─────────────────────────────────────────────────────────────┘


┌─────────────────────────────────────────────────────────────┐
│          PARALLEL: WATCHDOG SERVICE                          │
│  1. Start on boot (edudisplej-api-client.service)          │
│  2. Monitor system health every 60 seconds                   │
│     - Check if X server is running                           │
│     - Check if browser is running                            │
│  3. Log monitoring data                                      │
│  4. Foundation for future expansion                          │
└─────────────────────────────────────────────────────────────┘
```

### Startup Timing

1. **System Boot** → **Systemd Init**: ~5-10 seconds
2. **Systemd Init** → **Services Start**: ~2-3 seconds
3. **Services Start** → **X Server Ready**: ~3-5 seconds (first boot: +30-60s for package installation)
4. **X Server Ready** → **Browser Display**: ~1-2 seconds

**Total Boot Time:**
- Normal boot: ~10-20 seconds
- First boot (with installation): ~40-80 seconds

---

## Watchdog Service

### Overview
The watchdog service is a simple Python-based monitoring loop that checks system health.

### Features
- Monitors X server status
- Monitors browser (surf/chromium/epiphany) status
- Logs monitoring data to `/opt/edudisplej/logs/watchdog.log`
- Runs continuously with 60-second check interval
- Foundation for future expansion

### Future Development
The watchdog service is designed to be extended with:
- Automatic service restart on failure
- Memory/CPU monitoring
- Disk space checks
- Network connectivity monitoring
- Custom alerting
- Remote API integration (optional)

---

## Configuration

### System Configuration File
Location: `/opt/edudisplej/edudisplej.conf`

```bash
# EduDisplej Configuration
KIOSK_URL="https://www.time.is"
KIOSK_MODE="chromium"  # or "epiphany" for ARMv6
LANG="sk"  # or "en"
```

### Environment Variables

- `DISPLAY=:0` - X display
- `HOME=/opt/edudisplej` - Application home
- `USER=edudisplej` - Service user

---

## Package Dependencies

### Base Packages
- `openbox` - Window manager
- `xinit` - X initialization
- `xterm` - Terminal emulator
- `unclutter` - Hide cursor
- `curl` - HTTP client
- `x11-utils` - X utilities
- `xserver-xorg` - X server
- `x11-xserver-utils` - X server utilities
- `python3-xdg` - Python XDG support

### Kiosk Packages
- `xterm` - Terminal emulator
- `xdotool` - X automation
- `figlet` - ASCII art
- `dbus-x11` - D-Bus X11 support
- `surf` - Lightweight browser (NEW)

### Browser Options
- `chromium-browser` - Full browser (ARMv7+)
- `epiphany-browser` - Lightweight browser (ARMv6)
- `surf` - Minimal browser (NEW, all architectures)

### API Client Dependencies
- `python3` - Python runtime
- `python3-requests` - HTTP library (optional, for registration)

---

## Maintenance & Operations

### Installation
```bash
curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash
```

### Update System
```bash
curl -fsSL https://install.edudisplej.sk/init/update.sh | sudo bash
```

### Service Management
```bash
# Check status
systemctl status edudisplej-kiosk.service
systemctl status edudisplej-watchdog.service
systemctl status edudisplej-api-client.service  # Watchdog service

# View logs
journalctl -u edudisplej-kiosk.service -f
journalctl -u edudisplej-api-client.service -f  # Watchdog logs

# Restart services
systemctl restart edudisplej-kiosk.service
systemctl restart edudisplej-api-client.service  # Restart watchdog
```

### Manual Operations
```bash
# Stop kiosk mode
sudo /opt/edudisplej/init/kiosk.sh stop_kiosk_mode

# Configure hostname
sudo /opt/edudisplej/init/edudisplej-hostname.sh

# Check system status
sudo /opt/edudisplej/init/edudisplej-init.sh
```

### Log Files
- `/opt/edudisplej/session.log` - Current session
- `/opt/edudisplej/session.log.old` - Previous session
- `/opt/edudisplej/apt.log` - Package installation
- `/opt/edudisplej/logs/watchdog.log` - Watchdog service logs
- `/tmp/openbox-autostart.log` - Openbox startup
- `/tmp/kiosk-startup.log` - Kiosk startup

---

## Troubleshooting

### Display Issues
1. Check if X is running: `pgrep Xorg`
2. Check display output: `DISPLAY=:0 xrandr`
3. Review startup logs: `cat /tmp/kiosk-startup.log`

### Network Issues
1. Check connection: `ping google.com`
2. Check interface: `ip addr show`
3. Review logs: `journalctl -u NetworkManager`

### Watchdog Service Issues
1. Check service: `systemctl status edudisplej-api-client.service`
2. View logs: `cat /opt/edudisplej/logs/watchdog.log`
3. Test manually: `python3 /opt/edudisplej/init/edudisplej-api-client.py`

### Package Installation Issues
1. Update package lists: `sudo apt-get update`
2. Check disk space: `df -h`
3. Review APT logs: `cat /opt/edudisplej/apt.log`

---

## Future Development Plans

### Short Term (Next Release)
1. Enhanced watchdog with automatic service restart
2. Browser content rotation support
3. Configuration improvements
4. Better error handling
5. Improved logging

### Medium Term
1. Optional remote API integration
2. VLC integration for video playback
3. Scheduled content rotation
4. System monitoring and alerts
5. Automatic updates

### Long Term
1. Multi-display support
2. Content scheduling system
3. Analytics and reporting
4. Web dashboard for management (optional)
5. Cloud-based content delivery (optional)

---

## Contributing

For contributions, please:
1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Submit a pull request

---

## License

*(Add license information here)*

---

## Contact & Support

- **Repository:** https://github.com/nagy-andras-sk/edudisplej
- **Issues:** https://github.com/nagy-andras-sk/edudisplej/issues

---

*Last updated: January 2026*
