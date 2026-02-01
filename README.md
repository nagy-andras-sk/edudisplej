# EduDisplej - Digital Signage for Educational Institutions

Simple, powerful digital display system for schools and universities.

## 🚀 Quick Install

```bash
curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash
```

After installation, **reboot your device**. The system will:
1. **Automatically register** with the control panel
2. **Wait for admin assignment** (assign device to company)
3. **Download modules** and start displaying

## 🔄 Complete Reinstall

To completely remove and reinstall the system:

```bash
sudo systemctl stop edudisplej-kiosk.service edudisplej-watchdog.service edudisplej-sync.service edudisplej-terminal.service 2>/dev/null; \
sudo systemctl disable edudisplej-kiosk.service edudisplej-watchdog.service edudisplej-sync.service edudisplej-terminal.service 2>/dev/null; \
sudo rm -f /etc/systemd/system/edudisplej-*.service; \
sudo rm -f /etc/sudoers.d/edudisplej; \
sudo rm -rf /opt/edudisplej; \
sudo systemctl daemon-reload; \
curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash
```

## 🔄 Update

System updates are installed **automatically every 24 hours**. No manual intervention needed.

To update manually:

```bash
sudo /opt/edudisplej/init/update.sh
```

## 📺 How It Works

1. **Automatic Registration**: Devices automatically register on first boot
2. **Web Management**: Configure displays at https://control.edudisplej.sk/admin/
3. **Module Sync**: Content syncs automatically every 5 minutes
4. **Display Rotation**: Modules rotate based on your configuration

## 🎯 Features

- ⏰ Clock module (digital/analog)
- 📅 Name days (Slovak/Hungarian)
- 🖥️ Split-screen layouts
- ⏱️ Scheduled content (e.g., lunch menu only at noon)
- 📊 Real-time monitoring
- 🔄 Automatic updates
- 🔑 Module license management
- 🏢 Multi-company support
- ⚙️ Per-kiosk module configuration

## 🆕 New Features

### Module System
- **Custom Modules**: Create your own display modules
- **License Management**: Control module access per company
- **Configuration Interface**: Easy-to-use dashboard for module settings
- **Module Rotation**: Automatic rotation between configured modules
- **Group Loop Configuration**: Configure module loops per group with drag-drop
- **Live Preview**: Real-time preview of module loop with progress tracking
- **Module Download**: Automatic download of modules to kiosks at startup
- **Local Caching**: Modules cached locally for offline operation

See [MODULES.md](MODULES.md) for detailed documentation on the module system.

### Admin Enhancements
- **Geolocation**: Automatic location detection from IP address
- **Search & Filter**: Live search across all kiosks
- **Sortable Tables**: Sort by company, status, location
- **Offline Alerts**: Highlight kiosks offline > 10 minutes
- **Quick Assignment**: Assign kiosks to companies with one click

### Company Dashboard
- **Self-Service**: Companies can configure their own kiosks
- **Module Configuration**: Enable/disable and customize modules
- **License Tracking**: View available and used licenses
- **Real-Time Status**: Monitor kiosk status and connectivity

## 🛠️ System Requirements

- Raspberry Pi or x86 Linux
- Internet connection
- HDMI display

## 📖 Management

Visit the control panel: **https://control.edudisplej.sk/admin/**

### Automatic System Updates

The kiosk automatically checks for system updates **once per day** through the background sync service:

- **What's updated**: System packages, scripts, and configuration files
- **When**: Daily automatic check at first startup, then every 24 hours
- **How**: System checks remote server (structure.json) and downloads changes if needed
- **Zero downtime**: Updates happen while kiosk is running, no reboot required (unless critical)

The update process is **fully automatic** - after the initial install, no manual update commands are needed.

### For Administrators
- Manage companies and users
- Assign module licenses
- Monitor all kiosks
- View system logs

### For Companies
Visit: **https://control.edudisplej.sk/dashboard/**
- Configure your kiosks
- Customize module settings
- Monitor your displays

## 🆘 Support

For issues, check system status:
```bash
sudo systemctl status edudisplej-sync.service
sudo systemctl status edudisplej-kiosk.service
```

View logs:
```bash
tail -f /opt/edudisplej/logs/sync.log
```

## 🔍 Troubleshooting

### Module Download Issues

If modules fail to download on kiosk startup:

```bash
# Manually run the download script
sudo /opt/edudisplej/init/edudisplej-download-modules.sh

# Check download logs
cat /tmp/edudisplej_download.log

# Verify device ID is configured
cat /opt/edudisplej/kiosk.conf

# Check API connectivity
curl -X POST https://control.edudisplej.sk/api/kiosk_loop.php \
  -d "device_id=YOUR_DEVICE_ID"
```

### Loop Player Not Starting

If the surf browser doesn't start or shows errors:

```bash
# Check if surf is installed
which surf

# Check loop player file
ls -l /opt/edudisplej/localweb/loop_player.html

# Check loop configuration
cat /opt/edudisplej/localweb/modules/loop.json

# View openbox autostart log
cat /tmp/openbox-autostart.log

# Restart kiosk service
sudo systemctl restart edudisplej-kiosk.service
```

### Check API Health

```bash
curl https://control.edudisplej.sk/api/health.php
```

This will show:
- PHP version and extensions
- Database connection status
- Kiosks table existence
- Current kiosk count

### Debug Registration Issues

If kiosks fail to register, check the detailed debug output:

```bash
# Enable debug mode in registration.php (set DEBUG_MODE = true)
# Then check sync logs:
sudo journalctl -u edudisplej-sync.service -n 100 --no-pager
```

The debug output will show exactly which step failed.

## 📄 Licencia / License

Tento projekt je proprietárny softvér. Všetky práva vyhradené.
This project is proprietary software. All rights reserved.

## 👥 Autor / Author

**Nagy András** - [nagy-andras-sk](https://github.com/nagy-andras-sk)

---

**Made with ❤️ for education**

