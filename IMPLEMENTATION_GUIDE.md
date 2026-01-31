# EduDisplej System - Implementation Guide

## What Has Been Implemented

### Phase 1: Device Registration & Unconfigured Display ✅
- **Registration API** (`/api/registration.php`): 
  - Returns device configuration status
  - Tracks if device is assigned to company
  - Generates unique device IDs
  
- **Unconfigured Display** (`/server_edudisplej_sk/unconfigured.html`):
  - Shows "EDUDISPLEJ" branding
  - Slovak message for unconfigured devices
  - Real-time date and time display
  - Auto-refreshes every 5 minutes to check for configuration updates

- **Module Sync API** (`/api/modules_sync.php`):
  - Returns list of active modules for a kiosk
  - Returns unconfigured module if device not configured
  - Logs all sync operations

- **Enhanced Sync Service** (`/install/init/edudisplej_sync_service.sh`):
  - Downloads content from server
  - Handles configured/unconfigured states
  - Creates module loader HTML with rotation logic
  - Supports file streaming for content downloads

### Phase 2: Database Schema ✅
New tables added:
- **modules**: Stores available module definitions
- **module_licenses**: Tracks module allocations per company
- **kiosk_modules**: Assigns modules to specific kiosks with settings
  
Updated tables:
- **users**: Added `role` and `is_super_admin` fields
- **kiosks**: Added `is_configured`, `friendly_name`, `screenshot_timestamp`

Default modules seeded:
- clock
- namedays
- split_clock_namedays
- unconfigured

### Phase 3: Modules ✅
- **Clock Module** (`/modules/clock.html`):
  - Digital and analog clock modes
  - 12/24 hour formats
  - Customizable colors
  - Date display with multiple formats
  - Multilingual (Slovak/Hungarian)
  
- **Name Days Module** (`/modules/namedays.html`):
  - Full Slovak and Hungarian name day calendar (365 days)
  - Shows today and tomorrow
  - Customizable emojis (neutral/male/female themes)
  - Multiple language support
  - Animated displays

## What Still Needs to Be Done

### Phase 2: Admin Dashboard Features (High Priority)
1. **Module Assignment UI** in kiosk_details.php:
   - Visual module selector
   - Drag & drop ordering
   - Duration configuration per module
   - Module settings editor

2. **Company Management**:
   - Assign kiosks to companies
   - Module licensing UI
   - User assignment to companies

3. **User Role Management**:
   - Super admin functionality
   - Content editor permissions
   - User group management

### Phase 3: Additional Modules
1. **Split Module** for 16:9 displays:
   - Combines clock + name days
   - Calendar view
   - Optimized layout

### Phase 4: Module Sequence Visualization (Medium Priority)
1. **Visual Editor**:
   - Show module flow with arrows
   - Edit sequence via drag & drop
   - Preview loop

2. **Content Generation**:
   - API to generate module HTML with settings
   - Cache management
   - Update triggers

### Phase 5: Screenshot & Monitoring (Medium Priority)
1. **Screenshot Monitor**:
   - Real-time screenshot viewer
   - Auto-refresh popup
   - Screenshot history

2. **Hardware Info Display**:
   - Collapsible tech specs panel
   - Temperature monitoring
   - Network status
   - Display resolution info

### Phase 6: Testing & Cleanup (High Priority)
1. Run database migration: `http://control.edudisplej.sk/dbjavito.php`
2. Test unconfigured display
3. Configure first module
4. Test module rotation
5. Test screenshot functionality
6. Remove unused code

## Installation Steps

### 1. Database Setup
```bash
# Visit the database auto-fixer
http://control.edudisplej.sk/dbjavito.php

# This will:
# - Create all new tables
# - Add new columns to existing tables
# - Seed default modules
# - Create default admin user (admin/admin123)
```

### 2. Sync Service Setup
The sync service is already configured in `/install/init/edudisplej_sync_service.sh`.
No changes needed - it will automatically:
- Register new devices
- Download content based on configuration
- Show unconfigured screen for unassigned devices
- Rotate through modules when configured

### 3. Configure First Kiosk
1. Device boots and registers via API
2. Shows unconfigured screen
3. Admin logs into control panel
4. Assigns device to company (optional)
5. Adds modules to device
6. Device syncs and starts showing content

## API Endpoints

### Registration
**POST** `/api.php?action=register`
```json
{
  "mac": "AA:BB:CC:DD:EE:FF",
  "hostname": "kiosk-01",
  "hw_info": { "cpu": "...", "memory": "..." }
}
```

Response:
```json
{
  "success": true,
  "kiosk_id": 1,
  "device_id": "ABCD123456",
  "is_configured": false,
  "company_assigned": false
}
```

### Module Sync
**POST** `/api.php?action=modules`
```json
{
  "mac": "AA:BB:CC:DD:EE:FF",
  "kiosk_id": 1
}
```

Response:
```json
{
  "success": true,
  "kiosk_id": 1,
  "device_id": "ABCD123456",
  "is_configured": true,
  "modules": [
    {
      "module_key": "clock",
      "name": "Clock & Time",
      "display_order": 0,
      "duration_seconds": 10,
      "settings": {
        "clockType": "digital",
        "showSeconds": true
      }
    }
  ]
}
```

## Module Configuration

### Clock Module Settings
```json
{
  "clockType": "digital|analog",
  "showSeconds": true|false,
  "showDate": true|false,
  "dateFormat": "long|short|numeric",
  "timeFormat": "24|12",
  "bgColor": "CSS gradient or color",
  "textColor": "#ffffff",
  "language": "sk|hu"
}
```

### Name Days Module Settings
```json
{
  "languages": ["sk", "hu"],
  "showEmoji": true|false,
  "emojiStyle": "neutral|male|female",
  "bgColor": "CSS gradient or color",
  "textColor": "#ffffff"
}
```

## Directory Structure
```
webserver/
├── control_edudisplej_sk/          # Admin control panel
│   ├── admin/                      # Admin UI
│   │   └── index.php              # Main dashboard
│   ├── api/                        # API endpoints
│   │   ├── registration.php       # Device registration
│   │   ├── modules_sync.php       # Module sync
│   │   ├── screenshot_sync.php    # Screenshot handling
│   │   └── hw_data_sync.php       # Hardware data
│   ├── dbjavito.php               # Database migration tool
│   └── dbkonfiguracia.php         # DB configuration
├── server_edudisplej_sk/          # Content server
│   ├── unconfigured.html          # Unconfigured display
│   └── modules/                    # Module HTML files
│       ├── clock.html             # Clock module
│       └── namedays.html          # Name days module
└── install/
    └── init/
        └── edudisplej_sync_service.sh  # Sync service
```

## Next Steps (Recommended Priority)

1. **Run database migration** - Visit `/dbjavito.php`
2. **Test device registration** - Boot a device and check it appears in admin panel
3. **Implement module assignment UI** - Add modules to a kiosk
4. **Test module display** - Verify content rotates correctly
5. **Add screenshot functionality** - Implement real-time monitoring
6. **Add split module** - For 16:9 displays
7. **Implement user/company management** - Full admin features
8. **Security review** - Check for vulnerabilities
9. **Performance optimization** - Optimize database queries
10. **Documentation** - User manual and API docs

## Security Considerations

- Database credentials should be moved to environment variables
- Add rate limiting to APIs
- Implement CSRF protection for admin panel
- Add input validation and sanitization
- Use prepared statements (already implemented)
- Implement proper session management
- Add API authentication tokens
- Enable HTTPS in production

## Performance Tips

- Cache module content locally on devices
- Minimize API calls with appropriate sync intervals
- Use CDN for static assets
- Optimize images for displays
- Implement database indexing
- Use connection pooling
- Add Redis/Memcached for session storage

## Troubleshooting

### Device not appearing in admin panel
- Check API URL in sync service
- Verify network connectivity
- Check database connection
- Review sync service logs

### Module not displaying
- Check modules_sync API response
- Verify module HTML files exist
- Check browser console for errors
- Verify module settings JSON is valid

### Unconfigured screen not showing
- Check is_configured flag in database
- Verify unconfigured.html is accessible
- Check sync service logic
- Review browser console

## Support

For issues and questions:
- Check logs in `/var/log/edudisplej/`
- Review sync service output
- Check browser console (F12)
- Verify database schema with dbjavito.php
