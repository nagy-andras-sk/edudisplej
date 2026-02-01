# Synchronization Update Detection

## Overview

The EduDisplej system now includes automatic update detection during hardware synchronization. When configuration changes are made on the server (such as loop order changes, module updates, etc.), the kiosk will be notified and can automatically restart to apply the changes.

## How It Works

### Server Side (hw_data_sync.php)

1. **Client sends sync request** with hardware data and optionally `last_update` timestamp from their local `loop.json`
2. **Server checks** if kiosk belongs to a group
3. **Server compares** the `updated_at` timestamp from `kiosk_group_modules` table with client's `last_update`
4. **Server responds** with:
   - `needs_update`: boolean flag indicating if update is required
   - `update_reason`: explanation of why update is needed
   - `update_action`: "restart" - action to take (restart browser/processes)

### Client Side (Kiosk)

The kiosk should:

1. **Send last_update** in sync request:
```json
{
  "mac": "00:11:22:33:44:55",
  "hostname": "kiosk-001",
  "hw_info": {...},
  "last_update": "2024-01-15 14:30:00"  // or Unix timestamp
}
```

2. **Check response** for update signal:
```json
{
  "success": true,
  "kiosk_id": 123,
  "needs_update": true,
  "update_reason": "Group configuration updated",
  "update_action": "restart"
}
```

3. **If needs_update is true**, execute update process:
   - Run the module download script: `/opt/edudisplej/init/edudisplej-download-modules.sh`
   - Run the update script: `/opt/edudisplej/init/update.sh`
   - Or simply restart the browser and loop player processes

## Example Client Implementation

```bash
#!/bin/bash
# Example sync script with update detection

LAST_UPDATE=$(cat /opt/edudisplej/localweb/loop.json | jq -r '.last_update // empty')

RESPONSE=$(curl -s -X POST https://control.edudisplej.sk/api/hw_data_sync.php \
  -H "Content-Type: application/json" \
  -d "{
    \"mac\": \"$(cat /sys/class/net/eth0/address)\",
    \"hostname\": \"$(hostname)\",
    \"hw_info\": {...},
    \"last_update\": \"$LAST_UPDATE\"
  }")

NEEDS_UPDATE=$(echo "$RESPONSE" | jq -r '.needs_update // false')

if [ "$NEEDS_UPDATE" = "true" ]; then
  echo "Update detected, triggering restart..."
  
  # Download latest modules
  bash /opt/edudisplej/init/edudisplej-download-modules.sh
  
  # Restart display processes
  systemctl restart edudisplej-kiosk.service
  
  # Or run full update
  # bash /opt/edudisplej/init/update.sh
fi
```

## Database Migration

Before using this feature, run the database migration to add timestamp columns:

```bash
php /path/to/webserver/control_edudisplej_sk/api/db_migration_timestamps.php
```

This adds `created_at` and `updated_at` columns to the `kiosk_group_modules` table.

## Benefits

- **Automatic synchronization** of configuration changes
- **No manual intervention** required on kiosks
- **Real-time updates** when loop order or modules change
- **Reduced downtime** - kiosks automatically restart when needed
- **Better tracking** of configuration changes with timestamps
