# EduDisplej Modules System

## Overview

The EduDisplej system now includes a complete module management system that allows:
- Creating custom modules with configurable settings
- Assigning module licenses to companies
- Configuring modules per kiosk through a web dashboard
- Automatic module rotation on kiosks

## Module Structure

Each module is stored in: `/webserver/server_edudisplej_sk/modules/{module-key}/`

Required files:
- `live.html` - The HTML file that displays on the kiosk
- `configure.json` - Configuration schema defining available settings

### Example Module Structure

```
modules/
  dateclock/
    ├── live.html          # Display file
    └── configure.json     # Configuration schema
  default-logo/
    ├── live.html
    └── configure.json
```

## Available Modules

### 1. default-logo
- **Description**: Displays EduDisplej logo with version number
- **Settings**:
  - `version` (text): Version number to display
  - `bgGradientStart` (color): Background gradient start color
  - `bgGradientEnd` (color): Background gradient end color
  - `textColor` (color): Text color

### 2. dateclock
- **Description**: Enhanced date and clock module with full customization
- **Settings**:
  - `clockType` (select): Digital or Analog
  - `clockSize` (select): Small, Medium, or Large
  - `dateSize` (select): Small, Medium, or Large
  - `showDate` (boolean): Show/hide date
  - `showDayName` (boolean): Show/hide day name
  - `datePosition` (select): Above or Below clock
  - `dateFormat` (select): YYYY-MM-DD, DD-MM-YYYY, MM-DD-YYYY, DD.MM.YYYY
  - `language` (select): English, Hungarian, Slovak
  - `bgGradientStart` (color): Background gradient start color
  - `bgGradientEnd` (color): Background gradient end color
  - `textColor` (color): Text color

### 3. clock
- **Description**: Basic clock & time module
- Existing module with basic functionality

### 4. namedays
- **Description**: Display Hungarian and Slovak name days
- Existing module

## Module Configuration Schema

The `configure.json` file defines:
- Module metadata (key, name, version, description)
- Available settings with types and validation
- Default values

Example:
```json
{
  "module_key": "dateclock",
  "module_name": "Date & Clock Module",
  "version": "1.0.0",
  "settings": {
    "clockType": {
      "type": "select",
      "label": "Clock Type",
      "options": [
        {"value": "digital", "label": "Digital"},
        {"value": "analog", "label": "Analog"}
      ],
      "default": "digital"
    }
  }
}
```

### Supported Setting Types
- `text` - Text input
- `select` - Dropdown selection
- `boolean` - Yes/No toggle
- `color` - Color picker
- `number` - Number input

## Module License Management

### Admin Interface
Access at: `https://control.edudisplej.sk/admin/module_licenses.php`

Features:
- Assign module licenses to companies
- Set quantity limits per module per company
- View license usage statistics

### How It Works
1. Admin assigns module licenses to a company with a quantity (e.g., 10 licenses)
2. Company users can enable modules on their kiosks
3. System enforces license limits (can't enable more kiosks than licensed)
4. Real-time tracking of license usage

## Company Dashboard

### Access
URL: `https://control.edudisplej.sk/dashboard/`

### Features
1. **View Company Kiosks**
   - See all kiosks assigned to the company
   - Check online/offline status
   - View location information

2. **Configure Kiosk Modules**
   - Enable/disable modules per kiosk
   - Set display order for module rotation
   - Set duration for each module
   - Customize module settings

3. **License Overview**
   - See available modules
   - Check license status

## API Endpoints

### 1. Module Sync (`/api/modules_sync.php`)
Returns active modules for a kiosk.

**Request:**
```json
{
  "kiosk_id": 123,
  "mac": "AA:BB:CC:DD:EE:FF"
}
```

**Response:**
```json
{
  "success": true,
  "kiosk_id": 123,
  "sync_interval": 300,
  "modules": [
    {
      "module_key": "dateclock",
      "name": "Date & Clock",
      "display_order": 0,
      "duration_seconds": 10,
      "settings": {
        "clockType": "digital",
        "language": "en"
      }
    }
  ]
}
```

### 2. Get Module File (`/api/get_module_file.php`)
Serves module files with injected settings.

**Parameters:**
- `module_key` - Module identifier
- `file` - File name (live.html or configure.json)
- `kiosk_id` - Kiosk ID (optional, for settings injection)

**Example:**
```
GET /api/get_module_file.php?module_key=dateclock&file=live.html&kiosk_id=123
```

### 3. Assign Company (`/api/assign_company.php`)
Assigns a kiosk to a company.

**Request:**
```json
{
  "kiosk_id": 123,
  "company_id": 5
}
```

### 4. Geolocation (`/api/geolocation.php`)
Fetches location from IP address.

**Parameters:**
- `ip` - IP address to lookup

**Response:**
```json
{
  "success": true,
  "location": "Bratislava, Slovakia"
}
```

## Creating New Modules

### Step 1: Create Module Directory
```bash
mkdir -p /webserver/server_edudisplej_sk/modules/mymodule
```

### Step 2: Create live.html
Create an HTML file that displays your content. Use JavaScript to read settings:

```html
<!DOCTYPE html>
<html>
<head>
    <title>My Module</title>
</head>
<body>
    <div id="content"></div>
    
    <script>
        // Load settings
        const defaultSettings = {
            message: 'Hello World',
            color: '#000000'
        };
        
        let settings = {...defaultSettings};
        
        // Try to load from URL params
        try {
            const urlParams = new URLSearchParams(window.location.search);
            const settingsParam = urlParams.get('settings');
            if (settingsParam) {
                settings = {...settings, ...JSON.parse(decodeURIComponent(settingsParam))};
            }
        } catch (e) {
            console.error('Failed to load settings:', e);
        }
        
        // Apply settings
        document.getElementById('content').textContent = settings.message;
        document.getElementById('content').style.color = settings.color;
    </script>
</body>
</html>
```

### Step 3: Create configure.json
Define the configuration schema:

```json
{
  "module_key": "mymodule",
  "module_name": "My Module",
  "version": "1.0.0",
  "description": "A custom module",
  "settings": {
    "message": {
      "type": "text",
      "label": "Message",
      "default": "Hello World"
    },
    "color": {
      "type": "color",
      "label": "Text Color",
      "default": "#000000"
    }
  },
  "defaults": {
    "message": "Hello World",
    "color": "#000000"
  }
}
```

### Step 4: Register Module in Database
Add to `dbjavito.php`:

```php
$default_modules = [
    // ... existing modules
    ['key' => 'mymodule', 'name' => 'My Module', 'description' => 'A custom module']
];
```

Run `dbjavito.php` to update the database.

## Module Display Rotation

Kiosks automatically rotate through enabled modules based on:
1. **Display Order**: Modules display in ascending order
2. **Duration**: Each module displays for the configured duration
3. **Loop**: After the last module, rotation starts from the first

Example rotation:
- Module A (10 seconds) → Module B (5 seconds) → Module C (15 seconds) → repeat

## Troubleshooting

### Module Not Appearing
1. Check if module is enabled for the kiosk
2. Verify company has license for the module
3. Check kiosk is assigned to a company
4. Review sync logs in admin panel

### Settings Not Applied
1. Verify settings saved in dashboard
2. Check kiosk has synced (last_seen timestamp)
3. Review module configuration in database

### License Issues
1. Check license quantity in admin panel
2. Verify license isn't over-allocated
3. Check company assignment is correct

## Best Practices

1. **Test Modules Locally**: Test HTML files before deployment
2. **Use Default Values**: Always provide sensible defaults
3. **Validate Input**: Add validation in configure.json
4. **Keep It Simple**: Modules should be lightweight and fast
5. **Error Handling**: Include error handling in JavaScript
6. **Responsive Design**: Use responsive CSS for different screen sizes
7. **Performance**: Optimize for kiosk hardware (Raspberry Pi)

## Security Considerations

1. **File Access**: Only serve files from module directories
2. **Input Validation**: Sanitize all user inputs
3. **SQL Injection**: Use prepared statements
4. **XSS Prevention**: Escape output in HTML
5. **License Enforcement**: Always check license limits

## Support

For issues or questions:
- Check system logs: `/opt/edudisplej/logs/sync.log`
- Review database logs: `sync_logs` table
- Contact: admin@edudisplej.sk
