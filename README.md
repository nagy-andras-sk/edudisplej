# EduDisplej

EduDisplej is a Raspberry Pi-based digital signage solution that runs in kiosk mode using Chromium browser.

## Components

This repository contains two main components:

1. **Raspberry Pi Client** - Digital signage kiosk for Raspberry Pi
2. **TR2 File Server API** - Web server component for managing file servers with Docker support

## TR2 File Server API

### Fixed Issue: Function Redeclaration Error

The API has been structured to fix the PHP fatal error:
```
Fatal error: Cannot redeclare loadEnv() (previously declared in /var/www/api/heartbeat.php:12) 
in /var/www/api/qbit-password-manager.php on line 10
```

The `loadEnv()` function is now defined only once in `config.php` and included by all other PHP files, preventing redeclaration errors.

### Docker Deployment

Deploy the TR2 File Server with Docker:

```bash
# Configure environment
cp api/.env.example .env
# Edit .env and set your configuration (especially TR2_PAIRING_ID and TR2_UPNP_ENABLED=false)

# Build and start services
docker compose up -d

# Check logs
docker compose logs -f tr2-server
```

For detailed API documentation, see [api/README.md](api/README.md).

## Quick Installation

Install EduDisplej on your Raspberry Pi with a single command:

```bash
# Quick installation (recommended)
curl -fsSL http://edudisplej.sk/install/install.sh | sudo bash
```

Or download and run manually:

```bash
# Manual installation
wget http://edudisplej.sk/install/install.sh
chmod +x install.sh
sudo ./install.sh
```

The installer will:
- Check and install required packages (zip, unzip, curl, wget)
- Download the latest EduDisplej files
- Install to `/opt/edudisplej/`
- Set proper permissions
- Provide clear feedback in both Hungarian and English

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

## File Structure

```
/opt/edudisplej/
├── edudisplej-init.sh          # Main init script (started by systemd)
├── edudisplej.conf             # Configuration file
├── .mode                       # Current mode (EDUDISPLEJ_SERVER, STANDALONE, etc.)
└── init/
    ├── common.sh               # Shared functions, translations, config
    ├── kiosk.sh                # X server and Chromium kiosk setup
    ├── network.sh              # Network configuration (Wi-Fi, static IP)
    ├── display.sh              # Display resolution settings
    ├── language.sh             # Language selection
    └── xclient.sh              # X client wrapper for Openbox + Chromium
```

**Note**: Previous versions used `/home/edudisplej/` as the installation directory. The new recommended location is `/opt/edudisplej/`.

## Systemd Services

| Service | Description |
|---------|-------------|
| `edudisplej-init.service` | Initial setup script that runs on boot |
| `kioskchrome.service` | Chromium kiosk service (managed by init) |
| `kiosk.service` | Alternative kiosk service |

## Configuration

The configuration is stored in `/opt/edudisplej/edudisplej.conf`:

```bash
MODE=EDSERVER           # Operating mode
KIOSK_URL=https://...   # URL to display in kiosk mode
LANG=en                 # Language (en/sk)
PACKAGES_INSTALLED=1    # Flag for package installation
```

## Troubleshooting

### Service fails with exit code 2

If you see an error like:
```
edudisplej-init.service: Main process exited, code=exited, status=2/INVALIDARGUMENT
```

Common causes:
1. **Empty first line in script** - The shebang (`#!/bin/bash`) must be on the first line
2. **Windows line endings (CRLF)** - Convert to Unix line endings (LF) using `dos2unix`
3. **Missing init modules** - Ensure all files in `/opt/edudisplej/init/` are present

### Check service status

```bash
sudo systemctl status edudisplej-init.service
journalctl -u edudisplej-init.service -b
```

## License

© 2025 Nagy Andras 
