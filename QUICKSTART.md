# Quick Start Guide - EduDisplej v. 28 12 2025

Get your EduDisplej digital signage system up and running in minutes!

## Prerequisites

- Raspberry Pi or Debian/Ubuntu system
- Internet connection
- Root/sudo access
- At least 500MB free disk space

## One-Command Installation

For most users, this is all you need:

```bash
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash
```

**That's it!** The system will automatically:
1. ✓ Install required packages
2. ✓ Configure the system
3. ✓ Set up the kiosk
4. ✓ Reboot

After reboot, your digital signage will be running!

## Installation with Options

### Choose Language

```bash
# Slovak (default)
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash -s -- --lang=sk

# English
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash -s -- --lang=en
```

### Custom Port

```bash
# Use port 8081 instead of default 8080
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash -s -- --port=8081
```

### Custom Source URL

```bash
# Download from custom server
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash -s -- --source-url=https://myserver.com/edudisplej
```

### Combine Options

```bash
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash -s -- --lang=en --port=8081
```

## Manual Installation

If you prefer to review the script first:

```bash
# Download the installer
wget https://edudisplej.sk/client/install.sh

# Review the script (optional)
less install.sh

# Make executable
chmod +x install.sh

# Run with default settings
sudo ./install.sh

# Or with options
sudo ./install.sh --lang=en --port=8080
```

## What Happens During Installation?

The installer will display progress messages for each step:

1. **Root privilege check** - Ensures you have admin rights
2. **Package installation** - Installs Apache2, Openbox, Chromium
3. **Directory setup** - Creates `/opt/edudisplej/`
4. **Webserver config** - Sets up local web interface
5. **Hostname** - Sets unique hostname like `edudisplej-abc12345`
6. **Service registration** - Creates systemd service
7. **File download** - Gets system files from server
8. **Reboot** - Restarts system in 10 seconds

All actions are logged to `/var/log/edudisplej-installer.log`

## After Installation

### Check System Status

```bash
# Check if service is running
sudo systemctl status edudisplej.service

# View logs
sudo journalctl -u edudisplej.service -f

# View installation log
sudo cat /var/log/edudisplej-installer.log
```

### Configuration

Edit the configuration file:

```bash
sudo nano /etc/edudisplej.conf
```

Common settings:
- `EDUDISPLEJ_HTTP_PORT` - Change web port
- `EDUDISPLEJ_LANG` - Language (sk/en)
- `KIOSK_URL` - URL to display

After changes, restart:
```bash
sudo systemctl restart edudisplej.service
```

### Local Web Interface

Access the local web interface:
```
http://localhost:8080
```
(Replace 8080 with your configured port)

## Troubleshooting

### Installation Failed?

Check the log:
```bash
sudo cat /var/log/edudisplej-installer.log
```

### Service Won't Start?

Check status and logs:
```bash
sudo systemctl status edudisplej.service
sudo journalctl -u edudisplej.service -n 50
```

### Need to Reinstall?

The installer is idempotent - just run it again:
```bash
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash
```

## Uninstallation

To remove EduDisplej:

```bash
# Download uninstaller
wget https://edudisplej.sk/client/uninstall.sh
chmod +x uninstall.sh

# Remove everything
sudo ./uninstall.sh

# Or keep configuration
sudo ./uninstall.sh --keep-config
```

## Next Steps

### Customize Display

1. **Press F12** during boot to access configuration menu
2. Choose from:
   - Display settings
   - Network configuration
   - Language selection
   - Operating mode

### Change Display URL

Edit config and set your URL:
```bash
sudo nano /etc/edudisplej.conf
# Set KIOSK_URL=https://your-url.com
sudo systemctl restart edudisplej.service
```

### Update System

The system includes automatic updates every 24 hours via systemd timer.

Manual update:
```bash
sudo /opt/edudisplej/system/edudisplej-sync.sh
```

## Tips

- **Network**: Configure WiFi during F12 menu or via command line
- **Display**: Supports multiple monitors and rotation
- **Updates**: System auto-syncs files every 24 hours
- **Logs**: Check logs if display doesn't appear
- **Reboot**: Safe to reboot anytime - kiosk starts automatically

## Common Use Cases

### Digital Signage
Display announcements, schedules, or information screens in schools, offices, or public spaces.

### Information Display
Show real-time data, dashboards, or monitoring screens.

### Kiosk System
Run interactive web applications in full-screen kiosk mode.

## Support

- **Documentation**: See README.md files in repo
- **Migration**: See MIGRATION.md for upgrading
- **Security**: See SECURITY.md for security info
- **Issues**: https://github.com/03Andras/edudisplej/issues

## Requirements Checklist

Before installation, ensure you have:

- [ ] Raspberry Pi or Debian/Ubuntu system
- [ ] Internet connection (for downloading packages)
- [ ] Root/sudo access
- [ ] 500MB+ free disk space
- [ ] Display connected (for kiosk mode)

## Installation Time

- **Download**: ~30 seconds (depending on connection)
- **Package installation**: 2-5 minutes
- **Configuration**: ~1 minute
- **Total**: Approximately 5 minutes

## Version

**EDUDISPLEJ INSTALLER v. 28 12 2025**

---

## Questions?

- Check the full documentation: `README.md`
- View migration guide: `MIGRATION.md`
- Review security: `SECURITY.md`
- Client docs: `client/README.md`
- Server docs: `webserver/README.md`

---

**Ready to start?**

```bash
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash
```

© 2025 Nagy Andras
