# EduDisplej Webserver Content

This directory contains the webserver-side files for EduDisplej. These files are served by the remote webserver and accessed by client devices during installation and operation.

## Directory Structure

```
webserver/
├── install/                          # Installation files
│   ├── install.sh                   # Legacy installer (deprecated)
│   ├── uninstall.sh                 # Legacy uninstaller
│   ├── edudisplej-sync.sh          # Sync script
│   ├── install.zip                  # Packaged installation files
│   ├── install_steps_en_sk.txt     # Installation steps documentation
│   └── end-kiosk-system-files/      # System files for end devices
│       ├── edudisplej-init.sh      # Main initialization script
│       ├── edudisplej.conf         # Configuration template
│       ├── api.php                  # API endpoint
│       └── init/                    # Init module scripts
│           ├── common.sh            # Common functions and translations
│           ├── kiosk.sh            # Kiosk mode setup
│           ├── network.sh          # Network configuration
│           ├── display.sh          # Display settings
│           ├── language.sh         # Language selection
│           └── xclient.sh          # X client wrapper
├── edserver/                        # EduServer application
│   └── demo/                        # Demo content
└── logo.png                         # EduDisplej logo

```

## File Descriptions

### Installation Files (`install/`)

These files are accessed by clients during the installation process.

#### `end-kiosk-system-files/`

Contains the actual system files that are deployed to client devices:

- **edudisplej-init.sh** - Main initialization script executed by systemd on boot
  - Loads all init modules
  - Shows startup banner
  - Auto-generates hostname if needed
  - Waits for internet connection
  - Provides F12 menu for configuration
  - Starts kiosk mode

- **edudisplej.conf** - Configuration file template
  - Operating mode (EDSERVER/STANDALONE)
  - Kiosk URL
  - Language settings
  - Package installation flags

- **api.php** - API endpoint for device communication
  - Handles device registration
  - Provides configuration updates

#### Init Modules (`init/`)

Modular scripts loaded by edudisplej-init.sh:

- **common.sh** - Core functionality
  - Configuration loading
  - Translation strings (Slovak/English)
  - Menu display functions
  - Logging utilities

- **kiosk.sh** - Kiosk mode management
  - X server startup
  - Chromium browser configuration
  - Full-screen kiosk setup
  - Retry logic for browser crashes

- **network.sh** - Network configuration
  - Wi-Fi setup
  - Static IP configuration
  - Connection testing

- **display.sh** - Display management
  - Resolution configuration
  - Multiple monitor support
  - Display rotation

- **language.sh** - Language selection
  - Slovak/English switching
  - Configuration persistence

- **xclient.sh** - X client wrapper
  - Openbox window manager setup
  - Screensaver/DPMS disable
  - Cursor hiding with unclutter
  - Chromium launch with kiosk flags

### Legacy Files

- **install.sh** (legacy) - Old installer script, kept for backward compatibility
- **uninstall.sh** (legacy) - Old uninstaller script
- **edudisplej-sync.sh** - Synchronization script for periodic updates

## Server Setup

To serve these files, configure your webserver (Apache/Nginx) to serve this directory structure.

### Apache Configuration Example

```apache
<VirtualHost *:80>
    ServerName edudisplej.sk
    DocumentRoot /var/www/edudisplej/webserver
    
    <Directory /var/www/edudisplej/webserver>
        Options +Indexes +FollowSymLinks
        AllowOverride None
        Require all granted
    </Directory>
    
    # Ensure .sh and .conf files are served correctly
    <FilesMatch "\.(sh|conf)$">
        Header set Content-Type "text/plain; charset=utf-8"
    </FilesMatch>
    
    ErrorLog ${APACHE_LOG_DIR}/edudisplej-error.log
    CustomLog ${APACHE_LOG_DIR}/edudisplej-access.log combined
</VirtualHost>
```

### Nginx Configuration Example

```nginx
server {
    listen 80;
    server_name edudisplej.sk;
    root /var/www/edudisplej/webserver;
    
    location / {
        autoindex on;
        try_files $uri $uri/ =404;
    }
    
    location ~ \.(sh|conf)$ {
        add_header Content-Type "text/plain; charset=utf-8";
    }
    
    access_log /var/log/nginx/edudisplej-access.log;
    error_log /var/log/nginx/edudisplej-error.log;
}
```

## File Access URLs

When properly configured, files are accessible at:

- Installation script: `http://edudisplej.sk/install/install.sh`
- System files: `http://edudisplej.sk/install/end-kiosk-system-files/...`
- Init modules: `http://edudisplej.sk/install/end-kiosk-system-files/init/...`

## File Permissions

Recommended permissions for webserver files:

```bash
# Directories: 755
find /var/www/edudisplej/webserver -type d -exec chmod 755 {} \;

# Regular files: 644
find /var/www/edudisplej/webserver -type f -exec chmod 644 {} \;

# Script files: 755 (executable)
find /var/www/edudisplej/webserver -type f -name "*.sh" -exec chmod 755 {} \;
```

## Content Updates

When updating webserver content:

1. Update the relevant files in this directory
2. Test file accessibility via HTTP
3. Verify MIME types are correct for .sh and .conf files
4. Update version numbers if applicable
5. Test installation on a clean system

## Hash Verification

For security, the new installer (in `/client/install.sh`) can verify file hashes. To enable this:

1. Generate SHA256 hashes for all files:
   ```bash
   find install/end-kiosk-system-files -type f -exec sha256sum {} \; > checksums.txt
   ```

2. Serve the checksums file alongside the installation files

3. Update the installer to verify hashes before using files

## Client Installation Flow

1. Client downloads `/client/install.sh`
2. Installer runs and downloads files from `/webserver/install/end-kiosk-system-files/`
3. Files are installed to `/opt/edudisplej/system/` on client
4. Systemd service starts `/opt/edudisplej/system/edudisplej-init.sh`
5. Init script loads modules from `/opt/edudisplej/system/init/`

## Testing

To test webserver content:

```bash
# Test file accessibility
curl -I http://edudisplej.sk/install/end-kiosk-system-files/edudisplej-init.sh

# Download and verify a file
wget http://edudisplej.sk/install/end-kiosk-system-files/edudisplej-init.sh
bash -n edudisplej-init.sh  # Syntax check

# Test full installation
curl -fsSL http://edudisplej.sk/client/install.sh | sudo bash
```

## Security Considerations

- All files are publicly accessible (by design for easy installation)
- No sensitive information should be stored in these files
- API endpoints should validate requests
- Consider HTTPS for production deployments
- Implement rate limiting on download endpoints

## Maintenance

Regular maintenance tasks:

- Monitor download logs for errors
- Update init modules as needed
- Test compatibility with new Debian/Ubuntu releases
- Archive old versions for rollback capability
- Update documentation when making changes

## Version Information

Current structure version: **28 12 2025**

## License

© 2025 Nagy Andras

## Support

For issues and questions, please visit:
https://github.com/03Andras/edudisplej
