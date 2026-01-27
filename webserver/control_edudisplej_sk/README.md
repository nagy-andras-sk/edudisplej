# EduDisplej Control Panel - Directory Structure

This document describes the reorganized directory structure for the EduDisplej control panel.

## Directory Structure

```
webserver/control_edudisplej_sk/
├── admin/                      # Admin panel (reorganized)
│   ├── index.php              # Main admin dashboard (formerly admin.php)
│   ├── companies.php          # Company management
│   ├── kiosk_details.php      # Kiosk details view
│   ├── users.php              # User management
│   ├── style.css              # Shared CSS for admin panel
│   └── script.js              # Shared JavaScript for admin panel
│
├── api/                        # API endpoints (reorganized)
│   ├── registration.php       # Device registration with device ID generation
│   ├── hw_data_sync.php       # Hardware data sync + public IP tracking
│   ├── screenshot_sync.php    # Screenshot upload and sync
│   └── modules_sync.php       # Module data synchronization
│
├── modules/                    # Module data for client synchronization
│   ├── default/
│   │   └── data.json          # Default module configuration
│   └── clock/
│       └── data.json          # Clock module configuration
│
├── admin.php                   # Redirect to admin/index.php (backwards compatibility)
├── api.php                     # Router to new API endpoints (backwards compatibility)
├── dbkonfiguracia.php         # Database configuration
├── dbjavito.php               # Database schema auto-fixer
└── userregistration.php       # User registration form
```

## API Endpoints

### New API Structure

The API has been reorganized into separate endpoint files for better maintainability:

#### 1. Registration Endpoint
**URL:** `/api/registration.php`

Registers a new kiosk device with automatic device ID generation.

**Device ID Format:** 4 random alphanumeric characters + 6 characters from MAC address

**Request:**
```json
{
  "mac": "aa:bb:cc:dd:ee:ff",
  "hostname": "edudisplej-001",
  "hw_info": { ... }
}
```

**Response:**
```json
{
  "success": true,
  "message": "Kiosk registered successfully",
  "kiosk_id": 1,
  "device_id": "AB12AABBCC"
}
```

#### 2. Hardware Data Sync Endpoint
**URL:** `/api/hw_data_sync.php`

Syncs hardware data and tracks public IP address.

**Request:**
```json
{
  "mac": "aa:bb:cc:dd:ee:ff",
  "hostname": "edudisplej-001",
  "hw_info": { ... }
}
```

**Response:**
```json
{
  "success": true,
  "kiosk_id": 1,
  "device_id": "AB12AABBCC",
  "sync_interval": 30,
  "screenshot_requested": false
}
```

#### 3. Screenshot Sync Endpoint
**URL:** `/api/screenshot_sync.php`

Uploads device screenshots.

**Request:**
```json
{
  "mac": "aa:bb:cc:dd:ee:ff",
  "screenshot": "data:image/png;base64,..."
}
```

#### 4. Modules Sync Endpoint
**URL:** `/api/modules_sync.php`

Retrieves module configuration data for the device.

**Request:**
```json
{
  "mac": "aa:bb:cc:dd:ee:ff",
  "device_id": "AB12AABBCC"
}
```

**Response:**
```json
{
  "success": true,
  "kiosk_id": 1,
  "device_id": "AB12AABBCC",
  "modules": [
    {
      "name": "default",
      "data": {
        "sync_interval": 30,
        "device_id": "AB12AABBCC",
        "last_updated": "2026-01-27 07:50:00"
      }
    },
    {
      "name": "clock",
      "data": {
        "enabled": true,
        "format": "24h",
        "timezone": "Europe/Bratislava"
      }
    }
  ]
}
```

### Backwards Compatibility

The old `api.php` file has been converted to a router that redirects requests to the new endpoints:

- `api.php?action=register` → `/api/registration.php`
- `api.php?action=sync` → `/api/hw_data_sync.php`
- `api.php?action=screenshot` → `/api/screenshot_sync.php`
- `api.php?action=heartbeat` → `/api/hw_data_sync.php`

## Client-Side Changes

### Enhanced Sync Service

A new enhanced sync service script has been created: `install/init/edudisplej_sync_service_enhanced.sh`

**Key Features:**
- Uses new API endpoints
- Generates and stores device ID
- Tracks public IP automatically
- Syncs modules from server
- Creates `/opt/edudisplej/modules` directory structure on clients
- Configurable sync interval from server

**Usage:**
```bash
# Start sync service loop
./edudisplej_sync_service_enhanced.sh start

# Register device only
./edudisplej_sync_service_enhanced.sh register

# Full sync (hw + modules)
./edudisplej_sync_service_enhanced.sh sync

# Hardware data sync only
./edudisplej_sync_service_enhanced.sh sync-hw

# Modules sync only
./edudisplej_sync_service_enhanced.sh sync-modules

# Screenshot capture
./edudisplej_sync_service_enhanced.sh screenshot
```

### Client Directory Structure

On Raspberry Pi devices, the following structure is created:

```
/opt/edudisplej/
├── kiosk.conf              # Configuration file (kiosk_id, device_id, mac)
└── modules/                # Module data synced from server
    ├── default/            # Default module
    ├── clock/              # Clock module
    └── .last_sync.json     # Last sync response cache
```

## Database Schema Changes

### New Fields in `kiosks` Table

- `device_id` (varchar(20)) - Unique device identifier (4 random + 6 from MAC)
- `public_ip` (varchar(45)) - Public IP address of the device

These fields are automatically added by running `dbjavito.php`.

## Admin Panel Changes

### Visual Changes
- Separated CSS into `admin/style.css`
- Separated JavaScript into `admin/script.js`
- All admin pages now use external CSS/JS files

### New Features
- Display device ID in kiosk details and main table
- Display public IP in kiosk details
- Cleaner, more maintainable code structure

## Migration Guide

### For Server Administrators

1. Run `dbjavito.php` to update the database schema:
   ```
   http://your-domain/control_edudisplej_sk/dbjavito.php
   ```

2. Update web server configuration if needed (the old URLs will still work via redirects)

3. Access admin panel at new location:
   ```
   http://your-domain/control_edudisplej_sk/admin/
   ```

### For Client Devices

1. Update sync service script to use the enhanced version:
   ```bash
   cp install/init/edudisplej_sync_service_enhanced.sh /usr/local/bin/
   chmod +x /usr/local/bin/edudisplej_sync_service_enhanced.sh
   ```

2. Update API URL environment variable (if using custom domain):
   ```bash
   export EDUDISPLEJ_API_URL="http://control.edudisplej.sk"
   ```

3. Restart sync service

## Notes

- All old URLs maintain backwards compatibility through redirects
- The old sync service script will continue to work with the new API router
- Module synchronization is optional and can be enabled/disabled per device
