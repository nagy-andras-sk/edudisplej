# PDF Module Admin Integration Summary

## Overview
The PDF Viewer module has been fully integrated into the edudisplej loop editor, enabling administrators to:
- Upload PDF files with drag-and-drop support
- Configure PDF display settings through a tabbed interface
- Assign PDFs to display loops on a per-company basis

## Integration Points

### 1. Loop Editor (Dashboard)
**Files Modified:**
- `webserver/control_edudisplej_sk/dashboard/group_loop/index.php` (lines 3850-4050, 4169-4227)
- `webserver/control_edudisplej_sk/dashboard/group_loop/assets/js/app.js` (lines 3811-3835, 4103-4160)

**What was integrated:**
- `showCustomizationModal()` function now supports `moduleKey === 'pdf'`
- Renders a tabbed settings panel with:
  - **Basic Tab**: Orientation, Zoom Level, Background Color
  - **Navigation Tab**: Navigation Mode (manual/auto), Scroll Speed, Pause Settings
  - **Advanced Tab**: Fixed Page Mode, Page Number Display
- PDF file upload with drag-and-drop and file size validation (max 50MB)
- Base64 encoding for PDF data storage
- Live preview button linking to m_pdf.html renderer

### 2. Settings Collection
**saveCustomization()** function now handles PDF module settings:
```javascript
// PDF settings collected from form:
{
  pdfDataBase64: "...",              // Base64-encoded PDF content
  orientation: "landscape|portrait", // Default: landscape
  zoomLevel: 100,                    // 50-400%, default: 100
  navigationMode: "manual|auto",     // Default: manual
  displayMode: "fit-page",           // Fixed value
  autoScrollSpeedPxPerSec: 30,      // 5-200 px/sec, default: 30
  autoScrollStartPauseMs: 2000,      // Start pause in milliseconds
  autoScrollEndPauseMs: 2000,        // End pause in milliseconds
  pausePoints: [],                   // Array of pause configurations
  fixedViewMode: false,              // Single page display mode
  fixedPage: 1,                      // Page number to display (if fixed mode)
  bgColor: "#ffffff",                // Background color
  showPageNumbers: true              // Display page numbers
}
```

### 3. Module Registry Integration
**Status:** ✅ Complete
- PDF module is registered in `modules` table via `module_bootstrap.php`
- Policy validation configured in `module_policy.php`
- Registry entry in `module_registry.php`

### 4. Licensing Integration
**Status:** ✅ Enabled
- Per-company licensing through `module_licenses.php`
- Admin can assign PDF module to individual companies
- Loop editor filters modules by company license

## UI/UX Features

### Upload Area
```html
<!-- Drag & Drop Upload -->
- Visual feedback on hover
- File type validation (.pdf only)
- File size limit enforcement (50MB max)
- Display of uploaded file size in KB
- Click-to-browse fallback support
```

### Tabbed Interface
```
┌─ Alap ─┬─ Navigáció ─┬─ Haladó ─┐
├────────┴──────────────┴──────────┤
│ Tájolás:      [Landscape▼]       │
│ Zoom szint:   [100]%             │
│ Háttérszín:   [Color picker]     │
└────────────────────────────────────┘
```

### Settings Tabs

**Alap (Basic):**
- Orientation selector (landscape/portrait)
- Zoom level slider (50-400%)
- Background color picker

**Navigáció (Navigation):**
- Navigation mode toggle (manual vs auto-scroll)
- Conditional scroll speed input (appears when auto-scroll selected)
- Start and end pause time inputs in milliseconds

**Haladó (Advanced):**
- Fixed page mode checkbox
- Conditional fixed page number input
- Page numbers display toggle

### Preview Function
```javascript
openPdfPreview()
// Opens m_pdf.html in new window with Base64 data
// URL: ../../../modules/pdf/m_pdf.html?data=<encoded_base64>
```

## Data Flow

### Upload Flow
```
User uploads PDF
       ↓
FileReader.readAsDataURL()
       ↓
Base64 encoding
       ↓
window.pdfModuleSettings.pdfDataBase64 = encoded_string
       ↓
Form submission
```

### Settings Save Flow
```
User clicks Mentés (Save)
       ↓
saveCustomization(index) called
       ↓
Collect all PDF form values
       ↓
loopItems[index].settings = newSettings
       ↓
Close modal
       ↓
Auto-save to API (/api/group_loop/config.php)
```

### Settings Persistence Flow
```
Loop configuration saved
       ↓
POST /api/group_loop/config.php
       ↓
Backend validates via module_policy.php
       ↓
Settings persisted to kiosk_group_modules table
       ↓
Kiosk receives PDF module config on sync
```

## Compatibility

### Browser Support
- Modern browsers supporting:
  - FileReader API (for PDF upload)
  - drag-and-drop events
  - CSS Grid and Flexbox
  - localStorage (for draft caching)

### PDF.js Compatibility
- PDF.js v3.11.174 from CDN
- Supports all standard PDF features
- Automatic fallback for unavailable features

## API Endpoints Used

### Primary Endpoints
1. **GET** `/api/group_loop/config.php?group_id={id}`
   - Retrieve current loop configuration

2. **POST** `/api/group_loop/config.php?group_id={id}`
   - Save loop configuration with PDF module settings
   - Validates settings via `module_policy.php`

## Settings Validation

### Policy Rules (module_policy.php)
```php
'pdf' => [
    'duration' => [
        'min' => 1,
        'max' => 3600,
        'default' => 10
    ],
    'fields' => [
        'pdfDataBase64' => [
            'type' => 'string',
            'maxLen' => 50000000  // 50MB
        ],
        'orientation' => [
            'type' => 'enum',
            'values' => ['landscape', 'portrait'],
            'default' => 'landscape'
        ],
        'zoomLevel' => [
            'type' => 'int',
            'min' => 50,
            'max' => 400,
            'default' => 100
        ],
        // ... additional fields ...
    ]
]
```

### Validation Flow
```
User submits settings
       ↓
API receives JSON payload
       ↓
edudisplej_sanitize_module_settings() called
       ↓
Against module_policy['pdf'] rules
       ↓
Invalid data rejected with error
       ↓
Valid data persisted
```

## Deployment Checklist

- [✓] PDF module files created (manifest, renderer, config, policy, registry)
- [✓] Admin UI component created
- [✓] Loop editor integration completed (index.php, app.js)
- [✓] Settings collection and validation
- [✓] Module registration integrated into maintenance task
- [✓] Documentation provided (PDF_MODULE_GUIDE.md, PDF_MODULE_IMPLEMENTATION.md)

### Pre-Deployment Tasks
1. **Run Maintenance Task:** Register all core modules including PDF
   ```bash
   # Via web:
   curl https://yourdomain.com/control_edudisplej_sk/cron/maintenance/run_maintenance.php
   
   # Or via SSH:
   ssh user@host "php /path/to/cron/maintenance/run_maintenance.php"
   ```

2. **Verify Module Registration:** Check admin panel for all modules including "PDF Megjelenítő"

3. **Assign Licenses:** For each company, go to admin > Module Licenses and enable PDF module

4. **Test Loop Editor:** Create a loop with PDF module and verify settings UI

5. **Test Kiosk Sync:** Verify PDF files download to kioskos correctly

## Troubleshooting

### PDF Upload Not Working
1. Check browser console for FileReader errors
2. Verify file size < 50MB
3. Confirm PDF is valid (try opening in Preview/Reader)

### Settings Not Saving
1. Check browser network tab for POST to /api/group_loop/config.php
2. Verify HTTP 200 response with success: true
3. Check API error message in response

### PDF Not Rendering on Kiosk
1. Verify PDF.js CDN is accessible from kiosk network
2. Check PDF.js source URL is not blocked
3. Ensure Base64 data was properly encoded (no truncation)
4. Check PDF file validity (some complex PDFs may not render)

### Performance Issues
1. For PDFs > 10MB: Consider compression before upload
2. Limit zoom level recommendations to 50-200% for large files
3. Use manual navigation mode for very large PDFs

## Future Enhancements

- [ ] Barcode scanning for PDF selection
- [ ] Orientation auto-detection
- [ ] Page range selection (display pages 5-10)
- [ ] Full pause points editor with visual timeline
- [ ] PDF search/bookmark navigation
- [ ] Annotation support for signage scenarios
- [ ] Multi-page transition effects
- [ ] PDF signature verification

## Module Registration

The PDF module (and all core modules) are automatically registered as part of the database maintenance task in `cron/maintenance/maintenance_task.php`.

**Core modules registered automatically:**
- clock (Óra)
- datetime (Dátum és Óra)
- dateclock (Dátum-Óra)
- default-logo (Alapértelmezett logó)
- text (Szöveg)
- pdf (PDF Megjelenítő) ← NEW
- unconfigured (Beállítás nélküli - technical)

**When is registration triggered?**
- Automatic: During cron maintenance (every 5 minutes by default)
- Manual: Call `/cron/maintenance/run_maintenance.php`
- Updates existing modules if they already exist in the database

## Files Modified

```
webserver/control_edudisplej_sk/
├── dashboard/group_loop/
│   ├── index.php               [MODIFIED - PDF UI integration]
│   └── assets/js/app.js        [MODIFIED - PDF support in app.js]
├── modules/
│   └── pdf/
│       ├── module.json         [CREATED]
│       ├── m_pdf.html          [CREATED]
│       └── config/
│           └── default_settings.json [CREATED]
├── modules/module_policy.php   [MODIFIED - added pdf policy]
├── modules/module_registry.php [MODIFIED - added pdf registry]
└── cron/maintenance/
    └── maintenance_task.php    [MODIFIED - module registration]

docs/
├── PDF_MODULE_GUIDE.md         [CREATED - user documentation]
├── PDF_MODULE_IMPLEMENTATION.md [CREATED - developer info]
└── PDF_MODULE_ADMIN_INTEGRATION.md [THIS FILE]
```

## Support

For issues or questions:
1. Check PDF_MODULE_GUIDE.md for user documentation
2. Review PDF_MODULE_IMPLEMENTATION.md for technical details
3. Check admin panel logs for API errors
4. Verify kiosk logs for display issues
