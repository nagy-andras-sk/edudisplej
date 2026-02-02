# Centralized Data Transfer - Implementation Guide

## Overview

This document describes the centralized data transfer architecture between the server and the displays (kiosks). The new architecture consolidates configuration and data into a single location for better maintainability and clarity.

## Directory Structure

All data is centralized in `/opt/edudisplej/data/`:

```
/opt/edudisplej/
├── data/
│   └── config.json          # Centralized configuration file
├── init/                    # Service scripts
│   ├── edudisplej-config-manager.sh
│   ├── edudisplej-screenshot-service.sh
│   └── edudisplej_sync_service.sh
└── logs/                    # Log files
    ├── sync.log
    └── screenshot-service.log
```

## Configuration File: config.json

Location: `/opt/edudisplej/data/config.json`

### Structure

```json
{
    "company_name": "Example Company",
    "company_id": 123,
    "device_id": "abc123def456",
    "token": "api-token-here",
    "sync_interval": 300,
    "last_update": "2026-02-02 12:00:00",
    "last_sync": "2026-02-02 12:05:00",
    "screenshot_enabled": true,
    "last_screenshot": "2026-02-02 12:04:30",
    "module_versions": {
        "clock": "1.0.0",
        "weather": "1.2.1"
    },
    "service_versions": {
        "sync_service": "1.0.0",
        "screenshot_service": "1.0.0"
    }
}
```

### Field Descriptions

| Field | Type | Description |
|-------|------|-------------|
| `company_name` | string | Name of the assigned company |
| `company_id` | integer/null | Company ID from the server |
| `device_id` | string | Unique device identifier |
| `token` | string | API authentication token |
| `sync_interval` | integer | Sync interval in seconds (controlled by server) |
| `last_update` | string | Timestamp of last module update |
| `last_sync` | string | Timestamp of last successful sync |
| `screenshot_enabled` | boolean | Whether screenshot capture is enabled |
| `last_screenshot` | string | Timestamp of last screenshot capture |
| `module_versions` | object | Installed module versions |
| `service_versions` | object | Service script versions |

## Screenshot Service

### Overview

A separate independent service handles screenshot capture and upload when enabled in `config.json`.

### Service Details

- **Script**: `/opt/edudisplej/init/edudisplej-screenshot-service.sh`
- **Systemd Service**: `edudisplej-screenshot-service.service`
- **Interval**: 15 seconds (when enabled)
- **Log**: `/opt/edudisplej/logs/screenshot-service.log`

### Behavior

1. Reads `screenshot_enabled` from `config.json` every 15 seconds
2. If enabled, captures screenshot using `DISPLAY=:0 scrot /tmp/screen.png`
3. Uploads to server with filename format: `scrn_edudisplejmac_YYYYMMDDHHMMSS.png`
4. Updates `last_screenshot` timestamp in `config.json`
5. If disabled, skips capture and waits for next cycle

### Screenshot Filename Format

```
scrn_edudisplejmac_20260202120530.png
     └─┬──┘└──┬──┘ └────┬────────┘
       │     │          └─ Timestamp (YYYYMMDDHHmmss)
       │     └─ MAC address (no colons)
       └─ Prefix
```

### Enable/Disable Screenshot

Screenshots are controlled by the server through the dashboard. The setting is synced to `config.json` via the `hw_data_sync` API.

To manually enable/disable:
```bash
/opt/edudisplej/init/edudisplej-config-manager.sh update screenshot_enabled true
# or
/opt/edudisplej/init/edudisplej-config-manager.sh update screenshot_enabled false
```

## Config Manager

### Script

`/opt/edudisplej/init/edudisplej-config-manager.sh`

### Commands

```bash
# Initialize config.json
edudisplej-config-manager.sh init

# Update a value
edudisplej-config-manager.sh update <key> <value>

# Get a value
edudisplej-config-manager.sh get <key>

# Show entire config
edudisplej-config-manager.sh show

# Migrate from old config files
edudisplej-config-manager.sh migrate
```

### Examples

```bash
# Get current company name
edudisplej-config-manager.sh get company_name

# Update sync interval
edudisplej-config-manager.sh update sync_interval 120

# Enable screenshots
edudisplej-config-manager.sh update screenshot_enabled true

# Show complete configuration
edudisplej-config-manager.sh show
```

## Sync Service Updates

The sync service (`edudisplej_sync_service.sh`) now:

1. Initializes `config.json` if it doesn't exist
2. Updates `config.json` with data from server responses
3. Reads configuration from `config.json` when needed
4. No longer handles screenshot capture (delegated to screenshot service)

### Data Flow

```
Server (hw_data_sync.php)
    ↓
  [API Response with config data]
    ↓
Sync Service
    ↓
Updates config.json
    ↓
Screenshot Service reads config.json
    ↓
Captures and uploads if enabled
```

## API Updates

### hw_data_sync.php

Now returns additional fields:

```json
{
    "success": true,
    "kiosk_id": 123,
    "device_id": "abc123",
    "sync_interval": 300,
    "screenshot_enabled": true,
    "company_id": 456,
    "company_name": "Example Company",
    "token": "api-token",
    "needs_update": false
}
```

### screenshot_sync.php

Now supports custom filename format:

**Request:**
```json
{
    "mac": "aabbccddeeff",
    "filename": "scrn_edudisplejaabbccddeeff_20260202120530.png",
    "screenshot": "data:image/png;base64,..."
}
```

**Response:**
```json
{
    "success": true,
    "message": "Screenshot uploaded successfully"
}
```

## Migration from Old Structure

The system is backward compatible. On first run:

1. Config manager can migrate data from old files:
   - `/opt/edudisplej/kiosk.conf` → `device_id`
   - `/opt/edudisplej/sync_status.json` → `company_name`, etc.

2. Run migration:
```bash
/opt/edudisplej/init/edudisplej-config-manager.sh migrate
```

## Service Management

### Start/Stop Services

```bash
# Screenshot service
sudo systemctl start edudisplej-screenshot-service
sudo systemctl stop edudisplej-screenshot-service
sudo systemctl status edudisplej-screenshot-service

# Sync service
sudo systemctl restart edudisplej-sync
sudo systemctl status edudisplej-sync
```

### View Logs

```bash
# Screenshot service logs
tail -f /opt/edudisplej/logs/screenshot-service.log
journalctl -u edudisplej-screenshot-service -f

# Sync service logs
tail -f /opt/edudisplej/logs/sync.log
journalctl -u edudisplej-sync -f
```

## Benefits

1. **Centralized Configuration**: All settings in one place (`config.json`)
2. **Independent Services**: Screenshot service runs independently from sync
3. **Better Maintainability**: Clear separation of concerns
4. **Reduced Duplication**: Single source of truth for configuration
5. **Easier Debugging**: Centralized logs and configuration
6. **Server Control**: Screenshot and sync settings controlled from dashboard
7. **Consistent Filenames**: Predictable screenshot naming for server storage

## Troubleshooting

### Screenshot Service Not Running

```bash
# Check if enabled
systemctl is-enabled edudisplej-screenshot-service

# Check status
systemctl status edudisplej-screenshot-service

# View logs
journalctl -u edudisplej-screenshot-service -n 50
```

### Config.json Not Created

```bash
# Manually initialize
/opt/edudisplej/init/edudisplej-config-manager.sh init

# Verify
cat /opt/edudisplej/data/config.json
```

### Screenshots Not Being Captured

1. Check if enabled in config:
```bash
/opt/edudisplej/init/edudisplej-config-manager.sh get screenshot_enabled
```

2. Check if scrot is installed:
```bash
which scrot
```

3. Check service logs:
```bash
tail -f /opt/edudisplej/logs/screenshot-service.log
```

### Configuration Not Syncing

1. Check sync service status:
```bash
systemctl status edudisplej-sync
```

2. Check network connectivity:
```bash
curl -I https://control.edudisplej.sk
```

3. Check sync logs:
```bash
tail -f /opt/edudisplej/logs/sync.log
```

## Future Enhancements

- [ ] Version tracking for modules in config.json
- [ ] Service version auto-update detection
- [ ] Screenshot quality settings in config
- [ ] Screenshot retention policy
- [ ] Config backup and restore
- [ ] Config change notifications
- [ ] Remote config push from server
