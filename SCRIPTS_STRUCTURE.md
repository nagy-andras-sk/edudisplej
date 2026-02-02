# EduDisplej Scripts - Structure and Organization

## Directory Structure

```
/opt/edudisplej/
├── data/                          # Centralized data directory (NEW)
│   └── config.json                # Centralized configuration file
├── init/                          # Service scripts and configuration
│   ├── common.sh                  # Shared functions
│   ├── edudisplej-config-manager.sh    # Config.json management (NEW)
│   ├── edudisplej-download-modules.sh  # Module downloader
│   ├── edudisplej-hostname.sh     # Hostname configuration
│   ├── edudisplej-init.sh         # System initialization
│   ├── edudisplej-screenshot-service.sh # Screenshot service (NEW)
│   ├── edudisplej-system.sh       # System utilities
│   ├── edudisplej-watchdog.sh     # Health monitoring
│   ├── edudisplej_sync_service.sh # Main sync service (UPDATED)
│   ├── edudisplej_terminal_display.sh # Terminal display
│   ├── edudisplej_terminal_script.sh  # Terminal script
│   ├── kiosk-start.sh             # Kiosk startup
│   ├── kiosk.sh                   # Kiosk main script
│   └── update.sh                  # System update script (UPDATED)
├── logs/                          # Log files
│   ├── sync.log
│   └── screenshot-service.log
└── localweb/                      # Local web content
    └── modules/
        └── loop.json
```

## Active Scripts

### Core Services

**edudisplej_sync_service.sh** ✅
- Main synchronization service
- Registers kiosk with server
- Updates centralized config.json
- Manages module synchronization
- Uses: `/opt/edudisplej/data/config.json`

**edudisplej-screenshot-service.sh** ✅ (NEW)
- Independent screenshot capture service
- Reads config from: `/opt/edudisplej/data/config.json`
- Runs every 15 seconds when enabled
- Uploads screenshots with proper naming: `scrn_edudisplej{mac}_{timestamp}.png`
  - Example: `scrn_edudisplejaabbccddeeff_20260202132542.png`
  - `{mac}` = MAC address without colons (e.g., aabbccddeeff)
  - `{timestamp}` = YYYYMMDDHHmmss format

**edudisplej-watchdog.sh** ✅
- Monitors system health
- Restarts services if needed

### Configuration & Management

**edudisplej-config-manager.sh** ✅ (NEW)
- Manages `/opt/edudisplej/data/config.json`
- Commands: `init`, `update`, `get`, `show`, `migrate`
- Used by install.sh and update.sh

**common.sh** ✅
- Shared functions used across all scripts
- Hardware detection
- Logging utilities
- Network helpers

**edudisplej-download-modules.sh** ✅
- Downloads modules from server
- Updates loop.json
- Called by sync service

### System Scripts

**edudisplej-init.sh** ✅
- System initialization on first boot
- Sets up environment

**edudisplej-hostname.sh** ✅
- Manages hostname configuration

**edudisplej-system.sh** ✅
- System utilities and helpers

**kiosk.sh** ✅
- Main kiosk launcher
- Starts display surface

**kiosk-start.sh** ✅
- Kiosk startup wrapper

### Installation & Updates

**install.sh** ✅ (UPDATED)
- Main installation script
- Creates directory structure including `/opt/edudisplej/data`
- Initializes config.json
- Installs services

**update.sh** ✅ (UPDATED)
- System update script
- Ensures data directory exists
- Initializes config.json if missing
- Updates all components

### Terminal Display (Optional)

**edudisplej_terminal_display.sh** ✅
- Terminal status display (optional feature)

**edudisplej_terminal_script.sh** ✅
- Terminal script helper

## Removed/Obsolete Scripts

### ❌ edudisplej-screenshot.sh
**Reason:** Replaced by `edudisplej-screenshot-service.sh`
- Old one-shot screenshot script
- Now replaced by independent service
- **Status: REMOVED**

### ❌ edudisplej_sync_service_enhanced.sh
**Reason:** Consolidated into main sync service
- Was a test/alternative version
- Functionality merged into `edudisplej_sync_service.sh`
- **Status: REMOVED**

## Service Files

All services are defined in systemd units:

- `edudisplej-kiosk.service` - Main kiosk display
- `edudisplej-sync.service` - Sync service
- `edudisplej-watchdog.service` - Health monitoring
- `edudisplej-screenshot-service.service` - Screenshot service (NEW)
- `edudisplej-terminal.service` - Terminal display (optional)

## Architecture Changes

### Before (Old Structure)
```
Scattered config files:
- /opt/edudisplej/kiosk.conf
- /opt/edudisplej/sync_status.json
- Various .dot files
```

### After (New Centralized Structure)
```
Single source of truth:
- /opt/edudisplej/data/config.json

Benefits:
✅ All configuration in one place
✅ Easy to backup/restore
✅ Clear data ownership
✅ Better maintainability
```

## Script Dependencies

```
install.sh
  └─> edudisplej-config-manager.sh (initialize config.json)

update.sh
  └─> edudisplej-config-manager.sh (ensure config.json exists)

edudisplej_sync_service.sh
  ├─> common.sh (shared functions)
  ├─> /opt/edudisplej/data/config.json (read/write)
  └─> edudisplej-download-modules.sh (module updates)

edudisplej-screenshot-service.sh
  └─> /opt/edudisplej/data/config.json (read screenshot_enabled)

edudisplej-config-manager.sh
  └─> /opt/edudisplej/data/config.json (manage)
```

## Maintenance Guidelines

### Safe to Modify
- ✅ config.json (via edudisplej-config-manager.sh)
- ✅ Custom modules in localweb/modules/

### Do Not Modify Manually
- ❌ loop.json (managed by server)
- ❌ Service files in /etc/systemd/system/
- ❌ Scripts in /opt/edudisplej/init/ (updated via update.sh)

### Safe to Remove (if unused)
- Terminal display scripts (if feature not used)
- Old backup directories

## Troubleshooting

### Check if system is using new structure:
```bash
# Check if data directory exists
ls -la /opt/edudisplej/data/

# Check config.json
cat /opt/edudisplej/data/config.json

# View active services
systemctl list-units 'edudisplej-*'
```

### Verify screenshot service:
```bash
# Check service status
systemctl status edudisplej-screenshot-service

# View logs
journalctl -u edudisplej-screenshot-service -f

# Check if enabled in config
/opt/edudisplej/init/edudisplej-config-manager.sh get screenshot_enabled
```

### Force update to new structure:
```bash
# Run update script
sudo /opt/edudisplej/init/update.sh
```

## Version History

### v1.0 - Centralized Architecture
- Introduced `/opt/edudisplej/data/config.json`
- Added `edudisplej-screenshot-service.sh`
- Added `edudisplej-config-manager.sh`
- Updated `edudisplej_sync_service.sh` to use centralized config
- Updated `install.sh` to initialize data directory
- Updated `update.sh` to ensure data directory exists
- Removed obsolete scripts
- Consolidated duplicate functionality
