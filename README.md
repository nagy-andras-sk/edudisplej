# EduDisplej

EduDisplej is a Raspberry Pi-based digital signage solution that runs in kiosk mode using Chromium browser. It provides a robust, unattended installation system for Debian/Ubuntu/Raspberry Pi OS.

## Version

**EDUDISPLEJ INSTALLER v. 28 12 2025**

## Quick Installation

Install EduDisplej on your Raspberry Pi or any Debian-based system with a single command:

```bash
# Quick installation with default settings (recommended)
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash
```

Or download and run manually with custom options:

```bash
# Download installer
wget https://edudisplej.sk/client/install.sh
chmod +x install.sh

# Install with default settings
sudo ./install.sh

# Install with custom settings
sudo ./install.sh --lang=en --port=8080
```

### Installation Options

- `--lang=sk|en` - Set language (Slovak or English, default: sk)
- `--port=8080` - Set local webserver port (default: 8080)
- `--source-url=URL` - Set source URL for files (default: http://edudisplej.sk/install)

### What Gets Installed

The installer automatically:
- ✓ Verifies root privileges
- ✓ Installs required packages (apache2, openbox, chromium)
- ✓ Creates directory structure (`/opt/edudisplej/`)
- ✓ Configures local webserver (127.0.0.1:8080)
- ✓ Sets hostname to `edudisplej-<random>`
- ✓ Creates and enables systemd service
- ✓ Downloads and verifies system files
- ✓ Reboots the system

All steps are logged to `/var/log/edudisplej-installer.log`

## Project Structure

```
edudisplej/
├── client/                      # Client-side installation system
│   ├── install.sh              # Main installer script
│   ├── uninstall.sh            # Uninstaller script
│   ├── edudisplej.conf.template # Configuration template
│   ├── systemd/                # Systemd service files
│   │   └── edudisplej.service
│   └── README.md               # Client documentation
│
├── webserver/                   # Webserver content (exactly as hosted)
│   ├── install/                # Installation files for download
│   │   └── end-kiosk-system-files/  # System files for clients
│   │       ├── edudisplej-init.sh   # Main init script
│   │       ├── edudisplej.conf      # Config template
│   │       └── init/                # Init modules
│   │           ├── common.sh
│   │           ├── kiosk.sh
│   │           ├── network.sh
│   │           ├── display.sh
│   │           ├── language.sh
│   │           └── xclient.sh
│   ├── edserver/               # EduServer application
│   └── README.md               # Webserver documentation
│
└── README.md                    # This file
```

## Features

- **Robust Installation**: Fail-fast error handling with clear messages
- **Idempotent**: Safe to run multiple times
- **Bilingual**: Slovak and English language support
- **Logging**: Complete installation logs at `/var/log/edudisplej-installer.log`
- **Secure**: Local webserver only accessible from localhost
- **Automatic**: Unattended installation with system reboot
- **Modular**: Clean separation of client and webserver components

## Boot Process Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         RASPBERRY PI BOOT SEQUENCE                          │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            systemd initialization                            │
│                     (loads all enabled .service files)                       │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                    ┌─────────────────┴─────────────────┐
                    ▼                                   ▼
        ┌─────────────────────┐             ┌─────────────────────┐
        │   network.target    │             │ other system units  │
        │   (NetworkManager)  │             │                     │
        └─────────────────────┘             └─────────────────────┘
                    │
                    ▼
        ┌─────────────────────┐
        │ network-online.target│
        └─────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        edudisplej-init.service                               │
│                 ExecStart=/bin/bash /opt/edudisplej/edudisplej-init.sh       │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        edudisplej-init.sh                                    │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 1. Load modules:                                                        │ │
│  │    ├── common.sh   (config, translations, menu functions)              │ │
│  │    ├── kiosk.sh    (X server, Chromium kiosk setup)                    │ │
│  │    ├── network.sh  (Wi-Fi, IP configuration)                           │ │
│  │    ├── display.sh  (resolution settings)                               │ │
│  │    └── language.sh (language selection)                                │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 2. Show startup banner with figlet                                      │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 3. Auto-generate hostname (if default "raspberrypi")                    │ │
│  │    └── Sets hostname to: edudisplej-<MAC_SUFFIX>                       │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 4. Wait for internet connection                                         │ │
│  │    └── Loops until ping to google.com succeeds                         │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 5. F12 menu window (5 seconds)                                          │ │
│  │    └── Press F12 to enter configuration menu                           │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                    ┌─────────────────┴─────────────────┐
                    ▼                                   ▼
        ┌─────────────────────┐             ┌─────────────────────┐
        │   F12 NOT pressed   │             │    F12 pressed      │
        │   (or mode exists)  │             │  (or no mode file)  │
        └─────────────────────┘             └─────────────────────┘
                    │                                   │
                    ▼                                   ▼
        ┌─────────────────────┐             ┌─────────────────────┐
        │  Load existing mode │             │   Interactive Menu  │
        │  from ~/.mode file  │             │                     │
        └─────────────────────┘             │  ┌───────────────┐  │
                    │                       │  │ 0. EduServer  │  │
                    │                       │  │ 1. Standalone │  │
                    │                       │  │ 2. Language   │  │
                    │                       │  │ 3. Display    │  │
                    │                       │  │ 4. Network    │  │
                    │                       │  │ 5. Exit       │  │
                    │                       │  └───────────────┘  │
                    │                       └─────────────────────┘
                    │                                   │
                    └───────────────┬───────────────────┘
                                    │
                                    ▼
                    ┌───────────────────────────────────┐
                    │   Mode: EDUDISPLEJ_SERVER         │
                    └───────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            Start X Server                                    │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 1. Clean up old X sessions (pkill Xorg, remove locks)                  │ │
│  │ 2. Start X server via systemd-run for proper session tracking          │ │
│  │ 3. Launch xinit with xclient.sh wrapper                                │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                              xclient.sh                                      │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 1. Set DISPLAY=:0                                                       │ │
│  │ 2. Disable screensaver/DPMS                                            │ │
│  │ 3. Hide cursor with unclutter                                          │ │
│  │ 4. Start Openbox window manager                                        │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         Chromium Kiosk Mode                                  │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ Launch Chromium with kiosk flags:                                       │ │
│  │   --kiosk --start-fullscreen --noerrdialogs --incognito                │ │
│  │                                                                         │ │
│  │ URL: https://www.edudisplej.sk/edserver/demo/client (default)          │ │
│  │                                                                         │ │
│  │ Retry logic: up to 3 attempts with 20s delay between retries           │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
                    ┌───────────────────────────────────┐
                    │         KIOSK RUNNING             │
                    │    (Chromium displaying URL)      │
                    └───────────────────────────────────┘
```

## File Structure After Installation

```
/opt/edudisplej/
├── system/                      # System files (downloaded during install)
│   ├── edudisplej-init.sh      # Main init script (started by systemd)
│   ├── edudisplej.conf         # Configuration file
│   └── init/                    # Init modules
│       ├── common.sh           # Shared functions, translations, config
│       ├── kiosk.sh            # X server and Chromium kiosk setup
│       ├── network.sh          # Network configuration (Wi-Fi, static IP)
│       ├── display.sh          # Display resolution settings
│       ├── language.sh         # Language selection
│       └── xclient.sh          # X client wrapper for Openbox + Chromium
│
└── wserver/                     # Local webserver content
    └── index.html              # Default welcome page

/etc/
├── edudisplej.conf             # System configuration
└── systemd/system/
    └── edudisplej.service      # Systemd service unit
```

**Note**: The new installer uses `/opt/edudisplej/system/` for system files and `/opt/edudisplej/wserver/` for local webserver content.

## Systemd Service

The installer creates and enables `/etc/systemd/system/edudisplej.service`:

```ini
[Unit]
Description=EduDisplej Kiosk System
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
ExecStart=/bin/bash /opt/edudisplej/system/edudisplej-init.sh
Restart=on-failure
RestartSec=10
User=root
Environment="DISPLAY=:0"
Environment="HOME=/opt/edudisplej"

[Install]
WantedBy=multi-user.target
```

### Service Management

```bash
# Check service status
sudo systemctl status edudisplej.service

# View logs
journalctl -u edudisplej.service -f

# Restart service
sudo systemctl restart edudisplej.service
```

## Configuration

The configuration is stored in `/etc/edudisplej.conf`:

```bash
# Installation paths
EDUDISPLEJ_HOME=/opt/edudisplej
EDUDISPLEJ_SYSTEM=/opt/edudisplej/system
EDUDISPLEJ_WEBSERVER=/opt/edudisplej/wserver

# Webserver settings
EDUDISPLEJ_HTTP_PORT=8080

# Source URL for system files
EDUDISPLEJ_SOURCE_URL=http://edudisplej.sk/install

# Language (sk or en)
EDUDISPLEJ_LANG=sk

# Operating mode
MODE=EDSERVER

# Kiosk URL
KIOSK_URL=https://www.edudisplej.sk/edserver/demo/client
```

## Uninstallation

To remove EduDisplej:

```bash
# Download uninstaller
wget https://edudisplej.sk/client/uninstall.sh
chmod +x uninstall.sh

# Remove completely
sudo ./uninstall.sh

# Or keep configuration
sudo ./uninstall.sh --keep-config
```

## Troubleshooting

### Installation Issues

**Check logs:**
```bash
sudo cat /var/log/edudisplej-installer.log
```

**Common problems:**
- Not running as root → Run with `sudo`
- Port conflict → Use `--port=<different-port>`
- Network issues → Check internet connection
- Package not found → Update apt: `sudo apt-get update`

### Service Fails to Start

If you see an error like:
```
edudisplej.service: Main process exited, code=exited, status=2/INVALIDARGUMENT
```

Common causes:
1. **Empty first line in script** - The shebang (`#!/bin/bash`) must be on the first line
2. **Windows line endings (CRLF)** - Convert to Unix line endings (LF)
3. **Missing init modules** - Ensure all files in `/opt/edudisplej/system/init/` are present
4. **Permission issues** - Check file permissions with `ls -la /opt/edudisplej/system/`

**Check service status:**
```bash
sudo systemctl status edudisplej.service
sudo journalctl -u edudisplej.service -b
```

### Apache Won't Start

**Check port availability:**
```bash
sudo netstat -tulpn | grep :8080
```

**Check Apache configuration:**
```bash
sudo apache2ctl configtest
sudo systemctl status apache2
```

### Chromium Not Starting

**Test manually:**
```bash
DISPLAY=:0 chromium --kiosk --start-fullscreen https://www.edudisplej.sk/edserver/demo/client
```

## Development

### Testing the Installer

To test the installer in a development environment:

```bash
# Clone the repository
git clone https://github.com/03Andras/edudisplej.git
cd edudisplej

# Test installer (use VM or test device)
sudo ./client/install.sh --lang=en --port=8080
```

### Project Structure

- **`/client`** - Client installation system
  - Installer script with fail-fast error handling
  - Systemd service templates
  - Configuration templates
  - Uninstaller

- **`/webserver`** - Webserver content mirror
  - Installation files for download
  - Init modules and scripts
  - Static content

### Contributing

1. Fork the repository
2. Create a feature branch
3. Make your changes
4. Test thoroughly on a clean system
5. Submit a pull request

## Requirements

- **OS**: Debian/Ubuntu/Raspberry Pi OS with systemd
- **Privileges**: Root access required
- **Network**: Internet connection for package installation
- **Storage**: At least 500MB free disk space
- **Architecture**: ARM (Raspberry Pi) or x86_64

## Security Notes

- Local webserver listens only on 127.0.0.1 (no external access)
- All downloads should use HTTPS in production (configurable)
- Service runs as root (required for X server and display management)
- No sensitive data stored in configuration files
- All installations are logged

## Version History

- **v. 28 12 2025** - Complete redesign with robust installer
  - Fail-fast error handling
  - Idempotent installation
  - Bilingual support (Slovak/English)
  - Structured client/webserver separation
  - Comprehensive logging

## License

© 2025 Nagy Andras 
