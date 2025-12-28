# Migration Guide - EduDisplej v. 28 12 2025

This document explains how to migrate from the old structure to the new redesigned structure.

## What Changed?

The project has been completely restructured for better clarity and maintainability:

### Old Structure (Before v. 28 12 2025)
```
edudisplej/
└── webserver_files/
    ├── install/
    │   ├── install.sh         # Combined installer
    │   └── end-kiosk-system-files/
    └── edserver/
```

### New Structure (v. 28 12 2025)
```
edudisplej/
├── client/                    # Client installation system (NEW)
│   ├── install.sh            # Robust, redesigned installer
│   ├── uninstall.sh          # New uninstaller
│   ├── edudisplej.conf.template
│   └── systemd/
│
└── webserver/                 # Webserver content (REORGANIZED)
    ├── install/
    │   └── end-kiosk-system-files/
    └── edserver/
```

## Key Improvements

### 1. Clear Separation
- **Client files** are now in `/client` - these are what you download and run on target devices
- **Webserver files** are in `/webserver` - these match the structure on the remote server

### 2. Robust Installer
The new installer (`client/install.sh`) includes:
- Fail-fast error handling (`set -euo pipefail`)
- Comprehensive logging to `/var/log/edudisplej-installer.log`
- Idempotent operations (safe to run multiple times)
- Bilingual support (Slovak/English)
- Step-by-step progress messages
- Proper error messages: `CHYBA: <step> - <reason>` (Slovak) or `ERROR: <step> - <reason>` (English)

### 3. Directory Structure on Client
Old:
```
/opt/edudisplej/
├── edudisplej-init.sh
├── edudisplej.conf
└── init/
```

New:
```
/opt/edudisplej/
├── system/               # System files (from webserver)
│   ├── edudisplej-init.sh
│   ├── edudisplej.conf
│   └── init/
└── wserver/             # Local webserver content
    └── index.html
```

### 4. Configuration Changes
Old config: `/opt/edudisplej/edudisplej.conf`
New config: `/etc/edudisplej.conf` (system-wide)

Runtime config still at: `/opt/edudisplej/system/edudisplej.conf`

### 5. Service Changes
Service name remains: `edudisplej.service`
Service now points to: `/opt/edudisplej/system/edudisplej-init.sh`

## Migration Steps

### For Webserver Administrators

1. **Update webserver structure:**
   ```bash
   cd /var/www/edudisplej
   
   # Backup old structure
   cp -r webserver_files webserver_files.backup
   
   # Deploy new structure
   cp -r <repo>/webserver/* .
   cp -r <repo>/client /var/www/edudisplej/
   ```

2. **Update URLs in documentation:**
   - Old: `http://edudisplej.sk/install/install.sh`
   - New: `http://edudisplej.sk/client/install.sh`

3. **Keep old URLs for backward compatibility:**
   ```bash
   # Create symlink for backward compatibility
   ln -s ../client/install.sh install/install-new.sh
   ```

### For Existing Installations

If you have an existing EduDisplej installation and want to use the new installer:

1. **Backup your current configuration:**
   ```bash
   sudo cp /opt/edudisplej/edudisplej.conf /tmp/edudisplej.conf.backup
   ```

2. **Uninstall old version (optional):**
   ```bash
   # If using old structure
   sudo systemctl stop edudisplej-init.service
   sudo systemctl disable edudisplej-init.service
   ```

3. **Run new installer:**
   ```bash
   curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash
   ```

4. **Restore custom settings:**
   ```bash
   # Edit /etc/edudisplej.conf to restore your settings
   sudo nano /etc/edudisplej.conf
   ```

### For Clean Installations

Simply use the new installer:

```bash
# Download and run new installer
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash

# Or with options
curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash -s -- --lang=sk --port=8080
```

## Backward Compatibility

The old `webserver_files` directory is kept in the repository for reference but is deprecated. New installations should use the new structure.

### Compatibility Matrix

| Component | Old Path | New Path | Compatible? |
|-----------|----------|----------|-------------|
| Install script | `/install/install.sh` | `/client/install.sh` | No - use new |
| System files | `/install/end-kiosk-system-files/` | `/webserver/install/end-kiosk-system-files/` | Yes - structure unchanged |
| Config template | Various | `/client/edudisplej.conf.template` | No - redesigned |
| Service file | Manual setup | `/client/systemd/edudisplej.service` | No - path changed |

## Testing Your Migration

After migrating, verify:

1. **Installer works:**
   ```bash
   bash -n client/install.sh
   client/install.sh --help
   ```

2. **Files are accessible:**
   ```bash
   curl -I http://edudisplej.sk/client/install.sh
   curl -I http://edudisplej.sk/webserver/install/end-kiosk-system-files/edudisplej-init.sh
   ```

3. **Run installer on test device:**
   ```bash
   # On a test Raspberry Pi or VM
   curl -fsSL https://edudisplej.sk/client/install.sh | sudo bash --lang=en
   ```

## Rollback Plan

If you need to rollback to the old version:

1. **Use the old installer:**
   ```bash
   curl -fsSL https://edudisplej.sk/install/install.sh | sudo bash
   ```

2. **Or restore from backup:**
   ```bash
   sudo cp -r /opt/edudisplej.backup/* /opt/edudisplej/
   sudo systemctl restart edudisplej.service
   ```

## Common Issues

### Issue: Old installer still being used

**Solution:** Clear browser cache or update bookmarks to use new URL

### Issue: Service fails after migration

**Solution:** Check service points to correct path:
```bash
sudo systemctl cat edudisplej.service | grep ExecStart
# Should show: ExecStart=/bin/bash /opt/edudisplej/system/edudisplej-init.sh
```

### Issue: Config not found

**Solution:** Check both locations:
```bash
ls -la /etc/edudisplej.conf
ls -la /opt/edudisplej/system/edudisplej.conf
```

## Support

For issues during migration:
- Check logs: `sudo cat /var/log/edudisplej-installer.log`
- Review documentation: `client/README.md` and `webserver/README.md`
- GitHub Issues: https://github.com/03Andras/edudisplej/issues

## Timeline

- **Old structure**: Deprecated as of v. 28 12 2025
- **New structure**: Current and recommended
- **Support**: Old structure files kept for reference but not maintained

---

© 2025 Nagy Andras
