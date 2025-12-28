# EduDisplej Client Installer

This directory contains the client-side installation system for EduDisplej.

## Contents

- **install.sh** - Main installer script (run on target device)
- **uninstall.sh** - Uninstaller script to remove EduDisplej
- **edudisplej.conf.template** - Configuration file template
- **systemd/** - Systemd service files

## Installation

Run the installer on a Debian/Ubuntu/Raspberry Pi OS system:

```bash
# Quick installation with default settings
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash

# Or download and run with custom options
wget https://edudisplej.sk/client/install.sh
chmod +x install.sh
sudo ./install.sh --lang=sk --port=8080
```

### Command Line Options

- `--lang=sk|en` - Set language (Slovak or English, default: sk)
- `--port=8080` - Set local webserver port (default: 8080)
- `--source-url=URL` - Set source URL for downloading files (default: http://edudisplej.sk/install)

### What the Installer Does

The installer performs the following steps:

1. **Root Privilege Check** - Verifies the script is run with root privileges
2. **Package Installation** - Installs apache2, openbox, and chromium (non-interactive)
3. **Directory Structure** - Creates:
   - `/opt/edudisplej` - Main installation directory
   - `/opt/edudisplej/wserver` - Local webserver content
   - `/opt/edudisplej/system` - System files (init scripts, configs)
4. **Local Webserver Setup** - Configures Apache to serve content on 127.0.0.1:PORT (default 8080)
5. **Hostname Configuration** - Sets hostname to `edudisplej-<random>` (8 characters)
6. **Service Registration** - Creates and enables `edudisplej.service` systemd service
7. **File Download** - Downloads system files from the source URL with verification
8. **System Reboot** - Reboots the system after 10 seconds

### Logging

All installation steps are logged to `/var/log/edudisplej-installer.log`

### Error Handling

The installer uses fail-fast approach:
- Any error stops the installation immediately
- Clear error messages in format: `HIBA: <step> - <reason>` (Slovak) or `ERROR: <step> - <reason>` (English)
- All errors are logged

### Idempotency

The installer is designed to be idempotent - you can run it multiple times safely:
- Skips already installed packages
- Preserves existing directories
- Updates configuration files
- Reconfigures services

## Uninstallation

To remove EduDisplej from the system:

```bash
sudo ./uninstall.sh
```

To keep configuration files:

```bash
sudo ./uninstall.sh --keep-config
```

The uninstaller:
- Stops and disables the systemd service
- Removes Apache configuration
- Deletes installation directory
- Optionally removes configuration files
- Cleans up running processes

## Configuration

After installation, the system is configured via:

- `/etc/edudisplej.conf` - Main configuration file
- `/opt/edudisplej/system/edudisplej.conf` - Runtime configuration

### Configuration Options

```bash
# Installation paths
EDUDISPLEJ_HOME=/opt/edudisplej
EDUDISPLEJ_SYSTEM=/opt/edudisplej/system
EDUDISPLEJ_WEBSERVER=/opt/edudisplej/wserver

# Webserver settings
EDUDISPLEJ_HTTP_PORT=8080

# Source URL
EDUDISPLEJ_SOURCE_URL=http://edudisplej.sk/install

# Language (sk or en)
EDUDISPLEJ_LANG=sk

# Operating mode
MODE=EDSERVER

# Kiosk URL
KIOSK_URL=https://www.edudisplej.sk/edserver/demo/client
```

## Systemd Service

The installer creates `/etc/systemd/system/edudisplej.service`:

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
# Check status
sudo systemctl status edudisplej.service

# View logs
sudo journalctl -u edudisplej.service -f

# Restart service
sudo systemctl restart edudisplej.service

# Stop service
sudo systemctl stop edudisplej.service
```

## File Structure After Installation

```
/opt/edudisplej/
├── system/                      # System files (downloaded from source)
│   ├── edudisplej-init.sh      # Main init script
│   ├── edudisplej.conf         # Runtime configuration
│   └── init/                    # Init modules
│       ├── common.sh
│       ├── kiosk.sh
│       ├── network.sh
│       ├── display.sh
│       ├── language.sh
│       └── xclient.sh
└── wserver/                     # Local webserver content
    └── index.html              # Default page

/etc/
├── edudisplej.conf             # System configuration
└── systemd/system/
    └── edudisplej.service      # Systemd service unit

/var/log/
└── edudisplej-installer.log    # Installation log
```

## Requirements

- Debian/Ubuntu/Raspberry Pi OS with systemd
- Root privileges
- Internet connection for package installation and file downloads
- At least 500MB free disk space

## Troubleshooting

### Service Fails to Start

Check logs:
```bash
sudo journalctl -u edudisplej.service -n 50
sudo cat /var/log/edudisplej-installer.log
```

### Apache Port Conflict

If port 8080 is already in use, reinstall with a different port:
```bash
sudo ./install.sh --port=8081
```

### Chromium Not Found

On some systems, the package is named `chromium-browser` instead of `chromium`. The installer handles both automatically.

### Network Issues

Ensure the device can reach the source URL. Test with:
```bash
curl -I http://edudisplej.sk/install/
```

## Security Considerations

- The local webserver only listens on 127.0.0.1 (localhost)
- No external access is allowed to the web interface
- All downloads should ideally use HTTPS (configurable via --source-url)
- The service runs as root (required for X server management)

## Version

Current version: **28 12 2025**

## License

© 2025 Nagy Andras

## Support

For issues and questions, please visit:
https://github.com/03Andras/edudisplej
