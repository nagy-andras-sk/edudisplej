# EduDisplej - Digital Signage for Educational Institutions

Simple, powerful digital display system for schools and universities.

## 🚀 Quick Install

```bash
curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash
```

After installation, reboot your device.

## 🔄 Update

```bash
sudo /opt/edudisplej/update.sh
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

## 🛠️ System Requirements

- Raspberry Pi or x86 Linux
- Internet connection
- HDMI display

## 📖 Management

Visit the control panel: **https://control.edudisplej.sk/admin/**

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

## 📄 Licencia / License

Tento projekt je proprietárny softvér. Všetky práva vyhradené.
This project is proprietary software. All rights reserved.

## 👥 Autor / Author

**Nagy András** - [nagy-andras-sk](https://github.com/nagy-andras-sk)

---

**Made with ❤️ for education**

