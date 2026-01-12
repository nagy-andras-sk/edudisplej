# EduDisplej Kiosk System - Implementation Notes

## Changes Made

### 1. Device Registration System
- **API Endpoint**: Created `/webserver/edudisplej_sk/api/register.php`
  - Accepts POST requests with hostname and MAC address
  - Stores device information in MySQL database (table: `kiosks`)
  - Prevents duplicate registrations by checking MAC address
  - Returns JSON response with registration status

- **Client Module**: Created `registration.sh`
  - Gets primary MAC address from network interfaces
  - Checks if device is already registered (stored in `.registration.json`)
  - Sends registration to remote server
  - Saves registration status locally to prevent re-registration
  - Integrated into `edudisplej-init.sh` to run on boot when internet is available

### 2. Chromium Kiosk Optimization
- **Improved Flags** (in `xclient.sh` and `kiosk.sh`):
  - Added `--no-sandbox` for compatibility with restricted environments
  - Added `--disable-dev-shm-usage` to prevent shared memory issues
  - Added `--ozone-platform=x11` for better X11 support
  - Added `--renderer-process-limit=1` to reduce resource usage on low-end devices
  - Removed conflicting flags like `--in-process-gpu` and `--single-process`

- **Process Management**:
  - **Removed all `pkill` usage** - replaced with proper PID-based process termination
  - Using `kill -TERM` first (graceful), then `kill -KILL` if needed
  - Proper cleanup of zombie processes

### 3. Logging Improvements
- **Log Rotation**:
  - Added automatic log rotation when logs exceed size limits
  - `xclient.log`: 2MB max (rotated on startup)
  - `apt.log`: 2MB max (rotated on startup)
  - `session.log`: New session log, old one moved to `.old` on startup
  - `watchdog.log`: 1MB max (rotated during runtime)

- **Session-based Logging**:
  - Old logs are moved to `.old` extension on new boot
  - Prevents disk fill-up on devices with limited storage
  - Logs only current session by default

- **Timestamps**:
  - All log entries include timestamps in format `[YYYY-MM-DD HH:MM:SS]`
  - Easier debugging and tracking of issues

### 4. Built-in Watchdog
- **xclient.sh** already has a watchdog mechanism:
  - Runs in infinite loop (`while true`)
  - Waits for chromium process to exit
  - Automatically restarts chromium after 15 seconds delay
  - Prevents restart loops (3 attempts, then longer wait)

- **Optional standalone watchdog** (`watchdog.sh`):
  - Can be used as backup monitoring service
  - Monitors chromium independently
  - Prevents restart loops (3 restarts per 60 seconds max)
  - Not activated by default (xclient.sh watchdog is sufficient)

### 5. Package Management
- **Added chromium-browser** to `REQUIRED_PACKAGES` in `edudisplej-init.sh`
  - Ensures browser is installed during initial setup
  - Falls back to `chromium` if `chromium-browser` is not available
  - Better error handling and logging for package installation

### 6. Improved Script Structure
- **Removed duplicate X session startup code**:
  - Removed redundant `ensure_x_session()` function
  - X server startup is now handled only by `kiosk.sh` via `start_x_server()`
  - Cleaner flow: `edudisplej-init.sh` → `start_kiosk_mode()` → `start_x_server()` → `xclient.sh`

## Installation Flow

1. User runs: `curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash`
2. `install.sh`:
   - Downloads all files from `download.php`
   - Creates systemd service `edudisplej-init.service`
   - Disables getty on tty1 (to prevent shell interference)
   - Reboots system

3. On boot, `edudisplej-init.service` runs `edudisplej-init.sh`:
   - Loads all modules (common, kiosk, network, display, language, **registration**)
   - Waits for internet connection
   - Checks and installs required packages (including chromium-browser)
   - **Registers device to server** (if not already registered)
   - Checks for updates
   - Shows system summary
   - Starts kiosk mode (if no menu interaction)

4. Kiosk startup (`kiosk.sh` → `start_kiosk_mode()`):
   - Cleans up old X sessions
   - Starts X server via `xinit`
   - Launches `xclient.sh` in X session

5. `xclient.sh` (runs in X session):
   - Sets up X environment (disables screensaver, power management)
   - Starts openbox window manager
   - Starts unclutter (hides mouse cursor)
   - **Infinite loop**: Starts chromium → waits for exit → restarts

## Default Behavior
- **URL**: `file:///opt/edudisplej/localweb/clock.html` (local clock)
- **Display**: Full-screen kiosk mode on tty1
- **Auto-restart**: Chromium automatically restarts if it crashes
- **Auto-update**: Checks for script updates on every boot (if internet available)
- **Auto-registration**: Registers device to server on first boot (if internet available)

## File Locations
- Installation: `/opt/edudisplej/`
- Scripts: `/opt/edudisplej/init/`
- Local web content: `/opt/edudisplej/localweb/`
- Logs: `/opt/edudisplej/*.log`
- Config: `/opt/edudisplej/edudisplej.conf`
- Registration: `/opt/edudisplej/.registration.json`

## Database Schema
Server database: `edudisplej`, user: `edud_server`

Table: `kiosks`
```sql
CREATE TABLE `kiosks` (
  `id` int(11) NOT NULL AUTO_INCREMENT,
  `hostname` text DEFAULT NULL,
  `installed` date NOT NULL DEFAULT current_timestamp(),
  `mac` text NOT NULL,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
```

## Testing Checklist
- [ ] Fresh installation from install.sh works
- [ ] Chromium starts on boot
- [ ] Clock.html displays correctly
- [ ] Device registration succeeds
- [ ] Logs don't fill disk (rotation works)
- [ ] Chromium restarts after crash
- [ ] Works on low-end hardware (Raspberry Pi, etc.)
- [ ] Network configuration menu works
- [ ] Display configuration menu works
- [ ] Language switching works

## Known Improvements
1. ✅ Removed pkill usage (security risk, kills wrong processes)
2. ✅ Added proper log rotation (prevents disk fill)
3. ✅ Optimized chromium flags for low-end devices
4. ✅ Built-in watchdog (automatic restart on crash)
5. ✅ Device registration to remote server
6. ✅ Better error handling and logging
7. ✅ Clean separation of concerns between scripts

## Future Enhancements (Not Implemented)
- Web-based device management dashboard
- Remote configuration updates
- Health monitoring and alerts
- A/B update system for safer updates
- Support for multiple display layouts
