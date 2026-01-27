# EduDisplej Control Panel Installation Guide

## Overview
EduDisplej is a comprehensive digital display management system for educational institutions. This guide covers the installation and configuration of the control panel and kiosk sync service.

## Components

### 1. Control Panel (control_edudisplej_sk)
Web-based administration interface for managing kiosks.

**Features:**
- User authentication with role-based access
- **User Management** - Create, edit, delete users with company assignments
- Multi-tenant support (company management with edit/delete capabilities)
- **Database Auto-Fixer** - Automatic database structure validation and repair (dbjavito.php)
- Kiosk status monitoring
- **Visual Dashboard** - Kiosks grouped by company with status overview
- Screenshot requests
- Configurable sync intervals
- Real-time status updates

### 2. Public Website (www_edudisplej_sk)
Multilingual promotional website (Slovak, Hungarian, English) describing the EduDisplej system.

### 3. Sync Service
Background service running on kiosk devices for communication with control panel.

## Installation

### Prerequisites
- PHP 7.4 or higher with mysqli extension
- MySQL/MariaDB 5.7 or higher
- Web server (Apache/Nginx)
- Linux system for kiosk devices

### Database Setup

1. Create database and user:
```sql
CREATE DATABASE edudisplej_sk CHARACTER SET utf8mb3 COLLATE utf8mb3_general_ci;
CREATE USER 'edudisplej_sk'@'localhost' IDENTIFIED BY 'Pab)tB/g/PulNs)2';
GRANT ALL PRIVILEGES ON edudisplej_sk.* TO 'edudisplej_sk'@'localhost';
FLUSH PRIVILEGES;
```

2. Import database schema:
```bash
mysql -u edudisplej_sk -p edudisplej_sk < control_edudisplej_sk/database_schema.sql
```

### Web Server Setup

1. Copy web files to web server directory:
```bash
# For Apache (example)
sudo cp -r webserver/* /var/www/html/

# Set proper permissions
sudo chown -R www-data:www-data /var/www/html/
sudo chmod -R 755 /var/www/html/
```

2. Configure virtual hosts (optional but recommended):

**Apache example:**
```apache
<VirtualHost *:80>
    ServerName www.edudisplej.sk
    DocumentRoot /var/www/html/www_edudisplej_sk
    <Directory /var/www/html/www_edudisplej_sk>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>

<VirtualHost *:80>
    ServerName control.edudisplej.sk
    DocumentRoot /var/www/html/control_edudisplej_sk
    <Directory /var/www/html/control_edudisplej_sk>
        AllowOverride All
        Require all granted
    </Directory>
</VirtualHost>
```

### Kiosk Sync Service Setup

1. Copy sync service to kiosk:
```bash
sudo mkdir -p /opt/edudisplej
sudo cp webserver/install/init/edudisplej_sync_service.sh /opt/edudisplej/
sudo chmod +x /opt/edudisplej/edudisplej_sync_service.sh
```

2. Install systemd service:
```bash
sudo cp webserver/install/init/edudisplej-sync.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable edudisplej-sync.service
sudo systemctl start edudisplej-sync.service
```

3. Configure API URL (edit service file if needed):
```bash
sudo nano /etc/systemd/system/edudisplej-sync.service
# Update EDUDISPLEJ_API_URL environment variable
```

## Usage

### Admin Panel Access

1. Navigate to: `http://your-server/control_edudisplej_sk/admin.php`

2. Default login credentials:
   - Username: `admin`
   - Password: `admin123`
   - **⚠️ IMPORTANT: Change this password immediately after first login!**

3. Register new users at: `http://your-server/control_edudisplej_sk/userregistration.php`

### New Features

**Database Auto-Fixer:**
- Access: `http://your-server/control_edudisplej_sk/dbjavito.php`
- Automatically checks and fixes database structure
- Creates missing tables and columns
- Sets up foreign key constraints
- Run this after any database updates or if you encounter database errors

**User Management:**
- Access: Admin Panel → Users (👥 Users in navigation)
- Create new users with admin privileges
- Edit existing users (change username, email, password, admin status)
- Assign users to companies
- Delete users (cannot delete yourself)
- View all users with their roles and company assignments

**Enhanced Company Management:**
- Access: Admin Panel → Companies (🏢 Companies in navigation)
- Create new companies
- Edit company names
- Delete companies (protected if users or kiosks are assigned)
- Assign kiosks to companies with location and comments

**Visual Dashboard:**
- Main dashboard now shows kiosks grouped by company
- Quick status overview for each company
- Unassigned kiosks shown separately
- Card-based layout for better visualization

### Managing Kiosks

**Viewing Kiosks:**
- After login, the dashboard displays all registered kiosks
- Shows status, last seen time, hardware info, location, etc.

**Screenshot Requests:**
- Click "📸 Screenshot" button next to any kiosk
- Screenshot will be captured on next sync
- View screenshot in kiosk details

**Ping Interval Control:**
- Default: 5 minutes (300 seconds)
- Click "⚡ Fast Ping" to switch to 20 seconds
- Click "🐌 Slow" to switch back to 5 minutes
- Useful for debugging or frequent updates

### Kiosk Registration

Kiosks automatically register on first sync. Manual registration:
```bash
sudo /opt/edudisplej/edudisplej_sync_service.sh register
```

### API Endpoints

**Base URL:** `/control_edudisplej_sk/api.php`

**Available Actions:**

1. **Register Kiosk**
   - Endpoint: `?action=register`
   - Method: POST
   - Payload: `{"mac": "...", "hostname": "...", "hw_info": {...}}`

2. **Sync Status**
   - Endpoint: `?action=sync`
   - Method: POST
   - Payload: `{"mac": "...", "hostname": "...", "hw_info": {...}}`
   - Returns: sync_interval, screenshot_requested

3. **Upload Screenshot**
   - Endpoint: `?action=screenshot`
   - Method: POST
   - Payload: `{"mac": "...", "screenshot": "data:image/png;base64,..."}`

4. **Heartbeat**
   - Endpoint: `?action=heartbeat`
   - Method: POST
   - Payload: `{"mac": "..."}`

## Database Schema

### Tables

**users** - User authentication
- id (primary key)
- username (unique)
- password (hashed)
- email
- isadmin (boolean)
- company_id (foreign key) - NEW: Assign users to companies
- created_at, last_login

**kiosks** - Display devices
- id (primary key)
- hostname
- mac (unique identifier)
- installed, last_seen
- hw_info (JSON)
- screenshot_url, screenshot_requested
- status (online/offline/pending)
- company_id (foreign key)
- location, comment
- sync_interval (seconds)

**companies** - Multi-tenant organizations
- id (primary key)
- name
- created_at

**kiosk_groups** - Organization groups
- id (primary key)
- name
- company_id (foreign key)
- description

**kiosk_group_assignments** - Group membership
- kiosk_id, group_id (composite primary key)

**sync_logs** - Communication logs
- id (primary key)
- kiosk_id (foreign key)
- timestamp
- action
- details (JSON)

## Security Notes

1. **Change Default Password:** Immediately change the default admin password after installation
2. **Database Credentials:** The database password is specified in the requirements but should be changed in production:
   - Update `dbkonfiguracia.php` with a strong password
   - Update the database user password: `ALTER USER 'edudisplej_sk'@'localhost' IDENTIFIED BY 'your-new-password';`
3. **HTTPS:** Always use HTTPS in production environments to protect credentials and sensitive data
4. **Firewall:** Restrict API access to known kiosk IPs if possible
5. **File Permissions:** Ensure web files are not writable by the web server except the screenshots directory
6. **Regular Updates:** Keep PHP, MySQL, and all system packages updated
7. **API Security:** Consider implementing API key authentication for kiosk communication in production
8. **Session Security:** Configure PHP session settings for production use (secure cookies, httponly, etc.)

## Troubleshooting

### Kiosk Not Registering
1. Check network connectivity
2. Verify API URL is correct
3. Check sync service logs: `sudo journalctl -u edudisplej-sync -f`
4. Ensure database is accessible

### Screenshots Not Working
1. Verify `scrot` or `imagemagick` is installed on kiosk
2. Check DISPLAY environment variable
3. Ensure screenshots directory is writable
4. Review sync service logs

### Database Connection Issues
1. Verify database credentials in `dbkonfiguracia.php`
2. Check MySQL/MariaDB service is running
3. Ensure database user has proper permissions
4. Review PHP error logs

## Development Roadmap

This is a BETA version with technical foundation. Future enhancements:

- Module system for display content
- Content management (menus, schedules, etc.)
- Advanced grouping and organization
- Reporting and analytics
- Mobile app for management
- Enhanced security features
- Automated content scheduling
- Integration with school management systems

## Support

For issues, questions, or feature requests, please contact the development team.

## License

Copyright © 2024 EduDisplej. All rights reserved.
