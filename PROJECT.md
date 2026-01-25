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

### Phase 4: Current Optimization (v2.0)
**Objectives:**
1. **Consolidate redundant scripts** - Reduce complexity
2. **Add surf browser** - Lightweight browsing option
3. **Automatic hostname configuration** - Based on MAC address
4. **API infrastructure** - Remote management capability

**Key changes:**
- Created `edudisplej-system.sh` - Unified system management
- Created `edudisplej-hostname.sh` - Automatic hostname configuration
- Created `edudisplej-api-client.py` - API client for remote management
- Optimized installation process
- Better documentation

---

## System Architecture

### Directory Structure

```
/opt/edudisplej/                          # Main application directory
├── init/                                 # Initialization scripts
│   ├── common.sh                         # Common functions library
│   ├── edudisplej-system.sh             # Unified system management (NEW)
│   ├── edudisplej-init.sh               # Main initialization script
│   ├── edudisplej-hostname.sh           # Hostname configuration (NEW)
│   ├── edudisplej-api-client.py         # API client service (NEW)
│   ├── edudisplej-checker.sh            # System checker (LEGACY)
│   ├── edudisplej-installer.sh          # Package installer (LEGACY)
│   ├── kiosk-start.sh                    # X server startup wrapper
│   ├── kiosk.sh                          # Kiosk mode functions
│   ├── edudisplej_terminal_script.sh    # Terminal display script
│   ├── edudisplej-kiosk.service         # Systemd service definition
│   ├── edudisplej-watchdog.service      # Watchdog service
│   ├── edudisplej-api-client.service    # API client service (NEW)
│   └── clock.html                        # Fallback HTML page
├── localweb/                             # Local web files
│   └── clock.html                        # Offline clock page
├── api/                                  # API client data (NEW)
│   ├── api-client.log                   # API client logs
│   └── api-client.pid                   # API client PID file
├── screenshots/                          # Screenshots directory (NEW)
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
└── .hostname_configured                  # Flag: hostname configured (NEW)

/home/[user]/                             # User home directory
├── .xinitrc                              # X initialization file
└── .config/openbox/autostart             # Openbox autostart config

/etc/systemd/system/
├── edudisplej-kiosk.service              # Main kiosk service
├── edudisplej-watchdog.service           # Watchdog service
└── edudisplej-api-client.service         # API client service (NEW)

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

#### 5. API Client (`edudisplej-api-client.py`) - NEW
- Python-based service for remote management
- Registers device with central server
- Executes commands from server:
  - `restart_browser` - Restart web browser
  - `screenshot` - Capture screenshot
  - `launch_program` - Launch programs (e.g., VLC)
  - `get_status` - Report device status
- Foundation for future development

#### 6. Watchdog Service (`edudisplej-watchdog.sh`)
- Monitors system health
- Restarts services if they fail
- Logs monitoring data

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
│          PARALLEL: API CLIENT SERVICE                        │
│  1. Start on boot (edudisplej-api-client.service)          │
│  2. Register device with central server                      │
│     - Send hostname and MAC address                          │
│     - Receive device ID                                      │
│  3. Poll for commands periodically                           │
│  4. Execute commands:                                        │
│     - restart_browser                                        │
│     - screenshot                                             │
│     - launch_program                                         │
│     - get_status                                             │
│  5. Report results back to server                            │
└─────────────────────────────────────────────────────────────┘
```

### Startup Timing

1. **System Boot** → **Systemd Init**: ~5-10 seconds
2. **Systemd Init** → **Services Start**: ~2-3 seconds
3. **Services Start** → **X Server Ready**: ~3-5 seconds (first boot: +30-60s for package installation)
4. **X Server Ready** → **Terminal Display**: ~1-2 seconds

**Total Boot Time:**
- Normal boot: ~10-20 seconds
- First boot (with installation): ~40-80 seconds

---

## API Infrastructure Design

### Overview
The API infrastructure allows remote management of EduDisplej devices through a central server.

### Architecture

```
┌─────────────────────┐
│  Central Server     │
│  server.edudisplej  │
│  .sk                │
│                     │
│  - PHP API          │
│  - MySQL Database   │
│  - Device Registry  │
└──────────┬──────────┘
           │
           │ HTTPS
           │
           ▼
┌─────────────────────┐
│  EduDisplej Device  │
│                     │
│  API Client         │
│  (Python Service)   │
│                     │
│  - Poll for         │
│    commands         │
│  - Execute          │
│  - Report results   │
└─────────────────────┘
```

### API Endpoints

#### Server Side (PHP)
- **POST /api/register.php**
  - Register device
  - Parameters: `hostname`, `mac`
  - Response: `device_id`

*(Future endpoints to be implemented)*
- **GET /api/commands.php?device_id={id}**
  - Get pending commands for device
  
- **POST /api/report.php**
  - Report command execution results

#### Client Side (Python)

**Commands supported:**

1. **restart_browser**
   - Kills browser processes
   - Browser auto-restarts via kiosk system
   
2. **screenshot**
   - Takes screenshot using scrot or imagemagick
   - Saves to `/opt/edudisplej/screenshots/`
   - Parameters: `filename` (optional)
   
3. **launch_program**
   - Launches arbitrary program
   - Parameters: `program`, `args` (optional)
   - Example: Launch VLC player
   
4. **get_status**
   - Returns device status
   - Includes: hostname, MAC, uptime, load, X status, browser status

### Security Considerations

**Current Implementation:**
- HTTPS communication with server
- Basic request validation
- No authentication yet (placeholder for future)

**Future Improvements:**
- Device authentication tokens
- Encrypted command transmission
- Command signing/verification
- Rate limiting
- Audit logging

### Development Roadmap

**Phase 1 (Current):**
- ✅ Basic client-server registration
- ✅ Command framework
- ✅ Local command execution

**Phase 2 (Next):**
- [ ] Server-side command queue
- [ ] Command polling mechanism
- [ ] Result reporting
- [ ] Basic authentication

**Phase 3 (Future):**
- [ ] Web dashboard for device management
- [ ] Real-time command execution
- [ ] Screenshot upload to server
- [ ] Remote VNC/console access
- [ ] Batch operations
- [ ] Scheduled tasks

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
systemctl status edudisplej-api-client.service

# View logs
journalctl -u edudisplej-kiosk.service -f
journalctl -u edudisplej-api-client.service -f

# Restart services
systemctl restart edudisplej-kiosk.service
systemctl restart edudisplej-api-client.service
```

### Manual Operations
```bash
# Stop kiosk mode
sudo /opt/edudisplej/init/kiosk.sh stop_kiosk_mode

# Configure hostname
sudo /opt/edudisplej/init/edudisplej-hostname.sh

# Take screenshot manually
DISPLAY=:0 scrot /opt/edudisplej/screenshots/manual.png

# Check system status
sudo /opt/edudisplej/init/edudisplej-init.sh
```

### Log Files
- `/opt/edudisplej/session.log` - Current session
- `/opt/edudisplej/session.log.old` - Previous session
- `/opt/edudisplej/apt.log` - Package installation
- `/opt/edudisplej/api/api-client.log` - API client logs
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

### API Client Issues
1. Check service: `systemctl status edudisplej-api-client.service`
2. View logs: `cat /opt/edudisplej/api/api-client.log`
3. Test manually: `python3 /opt/edudisplej/init/edudisplej-api-client.py`

### Package Installation Issues
1. Update package lists: `sudo apt-get update`
2. Check disk space: `df -h`
3. Review APT logs: `cat /opt/edudisplej/apt.log`

---

## Future Development Plans

### Short Term (Next Release)
1. Complete API server implementation
2. Web dashboard for device management
3. Screenshot upload functionality
4. Device grouping and batch operations
5. Configuration management via API

### Medium Term
1. VLC integration for video playback
2. Scheduled content rotation
3. Remote configuration updates
4. System monitoring and alerts
5. Automatic updates

### Long Term
1. Multi-display support
2. Content scheduling system
3. Analytics and reporting
4. Mobile app for management
5. Cloud-based content delivery

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
