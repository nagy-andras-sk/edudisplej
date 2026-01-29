# EduDisplej - Digital Display System for Educational Institutions

EduDisplej is a comprehensive digital signage solution designed specifically for educational institutions (schools, universities). It enables centralized management of digital displays showing various types of content including clocks, calendars, name days, announcements, and more.

## 🎯 Features

### Device Management
- **Auto-registration**: Devices automatically register on first boot
- **Unconfigured state**: New devices show a branded waiting screen
- **Company assignment**: Organize devices by institution/company
- **Real-time monitoring**: Track device status and last seen time
- **Hardware tracking**: Monitor CPU, memory, network status

### Content Modules
- **📅 Clock Module**: Digital/analog clocks with customizable colors and formats
- **🎂 Name Days Module**: Slovak and Hungarian name day calendars
- **📋 Split Module**: Combined layouts for 16:9 displays (planned)
- **Extensible**: Easy to add custom modules

### Admin Dashboard
- **Multi-tenant**: Support for multiple companies/institutions
- **User roles**: Super admin, admin, content editor
- **Module licensing**: Control which modules are available per company
- **Module assignment**: Drag & drop module management
- **Screenshot monitoring**: Real-time display preview (10s refresh)

### Synchronization
- **Automatic sync**: Devices check for updates periodically
- **File streaming**: Content downloaded from central server
- **Offline capable**: Devices cache content locally
- **Loop system**: Content rotates automatically with configurable durations

## 🚀 Quick Start

### Prerequisites
- Linux-based system (Raspberry Pi, x86 Linux)
- PHP 7.4+ with mysqli
- MySQL/MariaDB 5.7+
- Apache/Nginx web server
- Chromium browser (for kiosk mode)

### Installation

1. **Clone the repository**
```bash
git clone https://github.com/nagy-andras-sk/edudisplej.git
cd edudisplej
```

2. **Setup database**
- Create MySQL database
- Update credentials in `webserver/control_edudisplej_sk/dbkonfiguracia.php`
- Run database migration: `http://your-server/dbjavito.php`

3. **Configure web server**
```bash
# Apache example
<VirtualHost *:80>
    ServerName control.edudisplej.sk
    DocumentRoot /path/to/edudisplej/webserver/control_edudisplej_sk
</VirtualHost>

<VirtualHost *:80>
    ServerName server.edudisplej.sk
    DocumentRoot /path/to/edudisplej/webserver/server_edudisplej_sk
</VirtualHost>
```

4. **Setup kiosk device**
```bash
# Copy sync service
sudo cp webserver/install/init/edudisplej_sync_service.sh /opt/edudisplej/
sudo chmod +x /opt/edudisplej/edudisplej_sync_service.sh

# Configure API URL
export EDUDISPLEJ_API_URL="http://control.edudisplej.sk/api.php"

# Start service
/opt/edudisplej/edudisplej_sync_service.sh start
```

5. **Access admin panel**
- URL: `http://control.edudisplej.sk/admin/`
- Default credentials: `admin` / `admin123`
- **⚠️ Change password immediately!**

## 📖 Documentation

- **[Implementation Guide](IMPLEMENTATION_GUIDE.md)**: Detailed technical documentation
- **API Documentation**: See API endpoints section below
- **Module Development**: Guide for creating custom modules (TBD)

## 🏗️ Architecture

```
┌─────────────────┐      ┌──────────────────┐      ┌──────────────────┐
│   Kiosk Device  │◄────►│  Control Panel   │◄────►│   Admin Users    │
│                 │      │    (control.)    │      │                  │
│ - Chromium      │      │ - PHP Backend    │      │ - Manage devices │
│ - Sync Service  │      │ - MySQL DB       │      │ - Assign modules │
│ - Local Cache   │      │ - User Auth      │      │ - Configure      │
└─────────────────┘      └──────────────────┘      └──────────────────┘
         ▲                                                    
         │                                                    
         │               ┌──────────────────┐                
         └──────────────►│  Content Server  │                
                         │    (server.)     │                
                         │ - HTML Modules   │                
                         │ - Static Assets  │                
                         └──────────────────┘                
```

## 🔌 API Endpoints

### Device Registration
```
POST /api.php?action=register
Body: { "mac": "...", "hostname": "...", "hw_info": {...} }
Response: { "success": true, "kiosk_id": 1, "device_id": "...", "is_configured": false }
```

### Module Sync
```
POST /api.php?action=modules
Body: { "mac": "...", "kiosk_id": 1 }
Response: { "success": true, "modules": [...] }
```

### Screenshot Upload
```
POST /api.php?action=screenshot
Body: { "mac": "...", "screenshot": "data:image/png;base64,..." }
Response: { "success": true }
```

### Hardware Data Sync
```
POST /api.php?action=hw_data
Body: { "mac": "...", "hw_info": {...} }
Response: { "success": true }
```

## 🎨 Module Configuration

### Clock Module
```json
{
  "clockType": "digital",
  "showSeconds": true,
  "showDate": true,
  "dateFormat": "long",
  "timeFormat": "24",
  "bgColor": "linear-gradient(135deg, #667eea 0%, #764ba2 100%)",
  "textColor": "#ffffff",
  "language": "sk"
}
```

### Name Days Module
```json
{
  "languages": ["sk", "hu"],
  "showEmoji": true,
  "emojiStyle": "neutral",
  "bgColor": "linear-gradient(135deg, #f093fb 0%, #f5576c 100%)",
  "textColor": "#ffffff"
}
```

## 🔐 Security

- **Database credentials**: Store in environment variables
- **HTTPS**: Required for production
- **API tokens**: Implement for device authentication
- **CSRF protection**: Enable for admin panel
- **Input validation**: All user inputs are sanitized
- **Prepared statements**: Protection against SQL injection
- **Password hashing**: Using PHP's password_hash()

## 📊 Database Schema

### Main Tables
- **kiosks**: Device registry and status
- **companies**: Institutions/organizations
- **users**: Admin panel users
- **modules**: Available content modules
- **module_licenses**: Module allocations per company
- **kiosk_modules**: Module assignments per device
- **sync_logs**: Synchronization history

## 🛠️ Development

### Adding a Custom Module

1. Create HTML file in `webserver/server_edudisplej_sk/modules/your_module.html`
2. Add module to database:
```sql
INSERT INTO modules (module_key, name, description) VALUES 
('your_module', 'Your Module Name', 'Module description');
```
3. Assign to kiosk via admin panel

### Module Structure
```html
<!DOCTYPE html>
<html>
<head>
    <title>Your Module</title>
    <style>/* Your styles */</style>
</head>
<body>
    <!-- Your content -->
    <script>
        // Load settings from URL params
        const urlParams = new URLSearchParams(window.location.search);
        const settings = JSON.parse(urlParams.get('settings') || '{}');
        
        // Your module logic
    </script>
</body>
</html>
```

## 🐛 Troubleshooting

### Device not appearing in admin panel
1. Check sync service logs: `/var/log/edudisplej/sync.log`
2. Verify API URL is correct
3. Test network connectivity to control server
4. Check database for device entry

### Module not displaying
1. Check modules_sync API response
2. Verify module HTML file exists on content server
3. Open browser console (F12) for JavaScript errors
4. Check module settings JSON validity

### Unconfigured screen stuck
1. Verify device is in database
2. Check `is_configured` flag: `SELECT * FROM kiosks WHERE id=X`
3. Assign at least one module via admin panel
4. Trigger sync manually

## 📝 Roadmap

### Version 1.1 (Current)
- [x] Device registration system
- [x] Unconfigured display screen
- [x] Clock module (digital/analog)
- [x] Name days module (SK/HU)
- [x] Module sync API
- [x] Database schema with modules
- [ ] Module assignment UI (in progress)
- [ ] Screenshot monitoring
- [ ] Split module (16:9)

### Version 1.2 (Planned)
- [ ] Visual module sequence editor
- [ ] Module marketplace
- [ ] Custom branding per company
- [ ] Email notifications
- [ ] Scheduled content
- [ ] Multi-language admin panel

### Version 2.0 (Future)
- [ ] Video content support
- [ ] RSS feed integration
- [ ] Weather module
- [ ] School timetable integration
- [ ] Emergency alert system
- [ ] Mobile app for management

## 🤝 Contributing

Contributions are welcome! Please read our contributing guidelines first.

1. Fork the repository
2. Create a feature branch: `git checkout -b feature/your-feature`
3. Commit changes: `git commit -am 'Add some feature'`
4. Push to branch: `git push origin feature/your-feature`
5. Submit a pull request

## 📄 License

This project is proprietary software. All rights reserved.

## 👥 Authors

- **Nagy András** - Initial work - [nagy-andras-sk](https://github.com/nagy-andras-sk)

## 🙏 Acknowledgments

- Built for educational institutions in Slovakia and Hungary
- Designed with simplicity and reliability in mind
- Special thanks to all contributors and testers

## 📞 Support

For support and questions:
- 📧 Email: info@edudisplej.sk
- 🐛 Issues: [GitHub Issues](https://github.com/nagy-andras-sk/edudisplej/issues)
- 📚 Documentation: [Wiki](https://github.com/nagy-andras-sk/edudisplej/wiki)

---

**Made with ❤️ for educational institutions**
