# Group-Based Module Configuration System

## Overview

The EduDisplej system now supports group-based module configuration, allowing you to configure modules once and apply them to multiple kiosks (Raspberry Pis) simultaneously.

## Features

### 1. Kiosk Groups
- Create logical groups of kiosks (e.g., "Floor 1", "Building A", "Reception Displays")
- Assign multiple kiosks to a group
- Each group has its own module configuration
- Kiosks inherit configuration from their group

### 2. Group Module Configuration
- Configure modules once at the group level
- All kiosks in the group automatically receive the configuration
- Visual flow chain shows module rotation sequence
- Easy editing with drag-and-drop order and duration settings

### 3. Module Rotation Loop
- Automatic rotation through configured modules
- Each module displays for its configured duration
- Seamless transitions between modules
- Continuous loop without page reloads

## How It Works

### Configuration Flow

```
1. Dashboard → Create Group
   /dashboard/groups.php
   
2. Dashboard → Assign Kiosks to Group
   /dashboard/group_kiosks.php?id={group_id}
   
3. Dashboard → Configure Modules for Group
   /dashboard/group_modules.php?id={group_id}
   - Select modules to enable
   - Set display order (0, 1, 2...)
   - Set duration for each module
   - Customize module settings
   
4. Save Configuration
   → Stored in group_modules table
   
5. Raspberry Pi Syncs
   → index.html calls /api/modules_sync.php
   → API checks kiosk_group_assignments
   → Returns group_modules configuration
   
6. Module Rotation Starts
   → index.html loops through modules
   → Displays each for configured duration
   → Returns to first module (infinite loop)
```

### Sync Priority

The system follows this priority order:

1. **Group Configuration** (if kiosk is in a group)
   - Checks `kiosk_group_assignments` table
   - Loads modules from `group_modules` table
   - All kiosks in group get same config

2. **Kiosk-Specific Configuration** (fallback)
   - If no group assignment found
   - Loads modules from `kiosk_modules` table
   - Individual kiosk customization

## Database Schema

### New Table: group_modules

```sql
CREATE TABLE group_modules (
    id INT AUTO_INCREMENT PRIMARY KEY,
    group_id INT NOT NULL,
    module_id INT NOT NULL,
    display_order INT DEFAULT 0,
    duration_seconds INT DEFAULT 10,
    settings TEXT,
    is_active TINYINT(1) DEFAULT 1,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (group_id) REFERENCES kiosk_groups(id) ON DELETE CASCADE,
    FOREIGN KEY (module_id) REFERENCES modules(id) ON DELETE CASCADE
);
```

### Existing Tables Used

- `kiosk_groups` - Group definitions
- `kiosk_group_assignments` - Kiosk-to-group mappings
- `modules` - Available modules
- `module_licenses` - License control

## Dashboard Pages

### /dashboard/groups.php

**Create and Manage Groups**

Features:
- Create new group with name and description
- View all groups with kiosk count
- Quick links to:
  - Manage Kiosks (assign/remove)
  - Configure Modules
  - Delete group

Usage:
```
1. Enter group name (e.g., "Reception Displays")
2. Add description (optional)
3. Click "Create Group"
4. Group appears in list with management options
```

### /dashboard/group_kiosks.php?id={group_id}

**Assign Kiosks to Group**

Features:
- View kiosks currently in group
- Add kiosks from dropdown (shows available kiosks)
- Remove kiosks from group
- See kiosk status and location

Usage:
```
1. Select kiosk from dropdown
2. Click "Add to Group"
3. Kiosk inherits group's module configuration
4. To remove: Click "Remove from group" on kiosk card
```

### /dashboard/group_modules.php?id={group_id}

**Configure Group Modules**

Features:
- Enable/disable modules for the group
- Set display order (lower numbers first)
- Set duration for each module (in seconds)
- Customize module settings (colors, formats, etc.)
- **Visual Flow Chain Preview** shows rotation sequence
- See all kiosks that will receive this configuration

Visual Flow Chain Example:
```
📊 Module Flow Chain
┌────────────┐    ┌────────────┐    ┌────────────┐
│   Clock    │ → │    Logo    │ → │  Name Days │ → ↻ Loop
│    7s      │    │     5s     │    │    10s     │
└────────────┘    └────────────┘    └────────────┘
Total cycle time: 22 seconds
```

Usage:
```
1. Check "Enable" for modules you want to display
2. Set "Display Order" (0 = first, 1 = second, etc.)
3. Set "Duration" (how long module displays)
4. Customize module settings if available
5. Click "Save Group Configuration"
6. All kiosks in group will receive this configuration on next sync
```

## Raspberry Pi Module Rotation

### /server_edudisplej_sk/index.html

This is the main file that runs in the browser (surf) on the Raspberry Pi.

**How It Works:**

1. **Initial Load**
   - Shows loading spinner
   - Reads kiosk_id and MAC from localStorage
   - Calls `/api/modules_sync.php` to fetch configuration

2. **Module Sync**
   ```javascript
   POST /api/modules_sync.php
   Body: { "kiosk_id": 123, "mac": "AA:BB:CC:DD:EE:FF" }
   
   Response: {
     "success": true,
     "kiosk_id": 123,
     "config_source": "group",
     "group_id": 5,
     "modules": [
       {
         "module_key": "dateclock",
         "name": "Date & Clock",
         "display_order": 0,
         "duration_seconds": 7,
         "settings": {"clockType": "digital"}
       },
       {
         "module_key": "default-logo",
         "name": "Logo",
         "display_order": 1,
         "duration_seconds": 5,
         "settings": {}
       }
     ]
   }
   ```

3. **Module Rotation**
   - Loads each module in an iframe
   - Module URL: `/api/get_module_file.php?module_key=dateclock&file=live.html&kiosk_id=123`
   - Displays for configured duration
   - Automatically moves to next module
   - Loops back to first module after last one

4. **Periodic Sync**
   - Re-syncs every 5 minutes (300,000ms)
   - Updates configuration if changed in dashboard
   - No page reload needed - updates seamlessly

5. **Debug API**
   ```javascript
   // In browser console
   window.EduDisplej.getState()
   // Returns: {modules, currentModuleIndex, kioskId, mac, currentModule}
   
   window.EduDisplej.forceSync()
   // Forces immediate sync
   
   window.EduDisplej.nextModule()
   // Skip to next module immediately
   ```

## API Endpoints

### POST /api/modules_sync.php

**Returns module configuration for a kiosk**

Request:
```json
{
  "kiosk_id": 123,
  "mac": "AA:BB:CC:DD:EE:FF"
}
```

Response:
```json
{
  "success": true,
  "kiosk_id": 123,
  "device_id": "KIOSK-001",
  "sync_interval": 300,
  "is_configured": true,
  "config_source": "group",
  "group_id": 5,
  "modules": [
    {
      "module_key": "dateclock",
      "name": "Date & Clock Module",
      "display_order": 0,
      "duration_seconds": 7,
      "settings": {
        "clockType": "digital",
        "language": "sk"
      }
    }
  ]
}
```

Fields:
- `config_source`: "group" or "kiosk" - indicates where config came from
- `group_id`: Present if config_source is "group"
- `modules`: Array of modules sorted by display_order

### GET /api/get_module_file.php

**Serves module HTML with injected settings**

Parameters:
- `module_key`: Module identifier (e.g., "dateclock")
- `file`: File to serve ("live.html" or "configure.json")
- `kiosk_id`: Kiosk ID (optional, for settings injection)
- `settings`: JSON settings (optional, URL-encoded)

Example:
```
GET /api/get_module_file.php?module_key=dateclock&file=live.html&kiosk_id=123
```

Returns: HTML file with settings injected as JavaScript variable

## Usage Examples

### Example 1: Configure Reception Displays

```
1. Create group "Reception Displays"
2. Assign 3 kiosks to this group
3. Configure modules:
   - Clock (digital) - 10 seconds
   - Company Logo - 5 seconds
   - Name Days - 5 seconds
4. Save configuration
5. All 3 kiosks will rotate: Clock → Logo → Name Days → repeat
```

### Example 2: Different Configurations for Different Floors

```
Floor 1 Group:
- Clock (7s)
- Weather (8s)
- News Feed (10s)

Floor 2 Group:
- Logo (5s)
- Announcements (15s)
- Clock (5s)

Each floor's kiosks get their own rotation sequence
```

### Example 3: Update Configuration

```
1. Go to group_modules.php for "Reception Displays"
2. Change Clock duration from 10s to 15s
3. Add new module "Weather" with 8s duration
4. Save
5. Within 5 minutes, all kiosks in group update automatically
6. New sequence: Clock (15s) → Logo (5s) → Name Days (5s) → Weather (8s)
```

## Troubleshooting

### Module not appearing on kiosk

1. Check kiosk is assigned to group:
   - Go to `/dashboard/group_kiosks.php?id={group_id}`
   - Verify kiosk appears in list

2. Check module is enabled in group:
   - Go to `/dashboard/group_modules.php?id={group_id}`
   - Verify module checkbox is checked
   - Verify module has display_order set

3. Check kiosk sync logs:
   - Open browser console on kiosk
   - Look for sync messages
   - Check `window.EduDisplej.getState()`

### Configuration not updating

1. Wait for sync interval (up to 5 minutes)
2. Force sync: `window.EduDisplej.forceSync()` in console
3. Check last_seen timestamp in admin panel
4. Verify kiosk has internet connection

### Group vs Kiosk Configuration

Priority:
1. If kiosk is in a group → group configuration is used
2. If kiosk has no group → kiosk-specific configuration is used
3. If neither exists → unconfigured screen is shown

To override group config for one kiosk:
1. Remove kiosk from group
2. Configure kiosk individually via `/dashboard/kiosk_modules.php?id={kiosk_id}`

## Best Practices

1. **Group Organization**
   - Create groups by location (Floor 1, Building A)
   - Or by purpose (Reception, Info Boards, Menu Displays)
   - Keep groups manageable (5-20 kiosks per group)

2. **Module Duration**
   - Minimum: 3-5 seconds (for readability)
   - Recommended: 5-15 seconds per module
   - Consider total cycle time (all modules combined)

3. **Display Order**
   - Use increments of 10 (0, 10, 20) for easy insertion
   - Most important content first (lower numbers)
   - Clock/time modules at regular intervals

4. **Testing**
   - Test configuration on one kiosk first
   - Use preview feature to visualize flow
   - Monitor for 1-2 full cycles before deploying widely

5. **Maintenance**
   - Review configurations monthly
   - Remove unused modules
   - Update durations based on content changes
   - Check sync logs for errors

## Performance Considerations

1. **Sync Frequency**
   - Default: 5 minutes (300 seconds)
   - Reduce for frequent updates
   - Increase to reduce server load

2. **Module Count**
   - Recommended: 2-5 modules per group
   - Maximum: 10 modules (for reasonable cycle time)
   - More modules = longer cycle = less frequent display

3. **Module Size**
   - Keep HTML files under 500KB
   - Optimize images and assets
   - Use CSS efficiently

4. **Browser Performance**
   - Modules stay loaded in iframe
   - No page reloads during rotation
   - Memory usage remains stable
   - Works well on Raspberry Pi hardware

## Migration from Kiosk-Specific to Group Configuration

1. Create a group for existing kiosks
2. Assign kiosks to the group
3. Copy configuration from one kiosk to group:
   - Open `/dashboard/kiosk_modules.php?id={kiosk_id}`
   - Note enabled modules, order, and durations
   - Open `/dashboard/group_modules.php?id={group_id}`
   - Configure with same settings
4. Save group configuration
5. Kiosks will automatically use group config on next sync

## Security

- Group management requires authentication
- Users can only manage their company's groups
- Admins can manage all groups
- Configuration changes are logged
- API validates kiosk ownership before returning config

## Support

For issues:
- Check browser console on kiosk for errors
- Review sync logs in admin panel
- Verify license availability for modules
- Test with `window.EduDisplej.getState()` in console
