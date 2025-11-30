# EduDisplej

EduDisplej is a Raspberry Pi-based digital signage solution that runs in kiosk mode using Chromium browser.

## Boot Process Diagram

```
┌─────────────────────────────────────────────────────────────────────────────┐
│                         RASPBERRY PI BOOT SEQUENCE                          │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            systemd initialization                            │
│                     (loads all enabled .service files)                       │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                    ┌─────────────────┴─────────────────┐
                    ▼                                   ▼
        ┌─────────────────────┐             ┌─────────────────────┐
        │   network.target    │             │ other system units  │
        │   (NetworkManager)  │             │                     │
        └─────────────────────┘             └─────────────────────┘
                    │
                    ▼
        ┌─────────────────────┐
        │ network-online.target│
        └─────────────────────┘
                    │
                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        edudisplej-init.service                               │
│                 ExecStart=/bin/bash /home/edudisplej/edudisplej-init.sh      │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                                      ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                        edudisplej-init.sh                                    │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 1. Load modules:                                                        │ │
│  │    ├── common.sh   (config, translations, menu functions)              │ │
│  │    ├── kiosk.sh    (X server, Chromium kiosk setup)                    │ │
│  │    ├── network.sh  (Wi-Fi, IP configuration)                           │ │
│  │    ├── display.sh  (resolution settings)                               │ │
│  │    └── language.sh (language selection)                                │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 2. Show startup banner with figlet                                      │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 3. Auto-generate hostname (if default "raspberrypi")                    │ │
│  │    └── Sets hostname to: edudisplej-<MAC_SUFFIX>                       │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 4. Wait for internet connection                                         │ │
│  │    └── Loops until ping to google.com succeeds                         │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 5. F12 menu window (5 seconds)                                          │ │
│  │    └── Press F12 to enter configuration menu                           │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                      │
                    ┌─────────────────┴─────────────────┐
                    ▼                                   ▼
        ┌─────────────────────┐             ┌─────────────────────┐
        │   F12 NOT pressed   │             │    F12 pressed      │
        │   (or mode exists)  │             │  (or no mode file)  │
        └─────────────────────┘             └─────────────────────┘
                    │                                   │
                    ▼                                   ▼
        ┌─────────────────────┐             ┌─────────────────────┐
        │  Load existing mode │             │   Interactive Menu  │
        │  from ~/.mode file  │             │                     │
        └─────────────────────┘             │  ┌───────────────┐  │
                    │                       │  │ 0. EduServer  │  │
                    │                       │  │ 1. Standalone │  │
                    │                       │  │ 2. Language   │  │
                    │                       │  │ 3. Display    │  │
                    │                       │  │ 4. Network    │  │
                    │                       │  │ 5. Exit       │  │
                    │                       │  └───────────────┘  │
                    │                       └─────────────────────┘
                    │                                   │
                    └───────────────┬───────────────────┘
                                    │
                                    ▼
                    ┌───────────────────────────────────┐
                    │   Mode: EDUDISPLEJ_SERVER         │
                    └───────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                            Start X Server                                    │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 1. Clean up old X sessions (pkill Xorg, remove locks)                  │ │
│  │ 2. Start X server via systemd-run for proper session tracking          │ │
│  │ 3. Launch xinit with xclient.sh wrapper                                │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                              xclient.sh                                      │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ 1. Set DISPLAY=:0                                                       │ │
│  │ 2. Disable screensaver/DPMS                                            │ │
│  │ 3. Hide cursor with unclutter                                          │ │
│  │ 4. Start Openbox window manager                                        │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
┌─────────────────────────────────────────────────────────────────────────────┐
│                         Chromium Kiosk Mode                                  │
│  ┌────────────────────────────────────────────────────────────────────────┐ │
│  │ Launch Chromium with kiosk flags:                                       │ │
│  │   --kiosk --start-fullscreen --noerrdialogs --incognito                │ │
│  │                                                                         │ │
│  │ URL: https://www.edudisplej.sk/edserver/demo/client (default)          │ │
│  │                                                                         │ │
│  │ Retry logic: up to 3 attempts with 20s delay between retries           │ │
│  └────────────────────────────────────────────────────────────────────────┘ │
└─────────────────────────────────────────────────────────────────────────────┘
                                    │
                                    ▼
                    ┌───────────────────────────────────┐
                    │         KIOSK RUNNING             │
                    │    (Chromium displaying URL)      │
                    └───────────────────────────────────┘
```

## File Structure

```
/home/edudisplej/
├── edudisplej-init.sh          # Main init script (started by systemd)
├── edudisplej.conf             # Configuration file
├── .mode                       # Current mode (EDUDISPLEJ_SERVER, STANDALONE, etc.)
└── init/
    ├── common.sh               # Shared functions, translations, config
    ├── kiosk.sh                # X server and Chromium kiosk setup
    ├── network.sh              # Network configuration (Wi-Fi, static IP)
    ├── display.sh              # Display resolution settings
    ├── language.sh             # Language selection
    └── xclient.sh              # X client wrapper for Openbox + Chromium
```

## Systemd Services

| Service | Description |
|---------|-------------|
| `edudisplej-init.service` | Initial setup script that runs on boot |
| `kioskchrome.service` | Chromium kiosk service (managed by init) |
| `kiosk.service` | Alternative kiosk service |

## Configuration

The configuration is stored in `/home/edudisplej/edudisplej.conf`:

```bash
MODE=EDSERVER           # Operating mode
KIOSK_URL=https://...   # URL to display in kiosk mode
LANG=en                 # Language (en/sk)
PACKAGES_INSTALLED=1    # Flag for package installation
```

## Troubleshooting

### Service fails with exit code 2

If you see an error like:
```
edudisplej-init.service: Main process exited, code=exited, status=2/INVALIDARGUMENT
```

Common causes:
1. **Empty first line in script** - The shebang (`#!/bin/bash`) must be on the first line
2. **Windows line endings (CRLF)** - Convert to Unix line endings (LF) using `dos2unix`
3. **Missing init modules** - Ensure all files in `/home/edudisplej/init/` are present

### $'\r': command not found errors

If you see errors like `$'\r': command not found` or `syntax error near unexpected token '$'r''`, the script files have Windows-style line endings (CRLF) instead of Unix line endings (LF).

**Symptoms:**
- Multiple `$'\r': command not found` errors
- Syntax errors on function definitions
- Script fails to start properly

**Solution:**
```bash
# Install dos2unix if not present
sudo apt install dos2unix

# Convert all shell scripts to Unix line endings
dos2unix /home/edudisplej/edudisplej-init.sh
dos2unix /home/edudisplej/init/*.sh

# Verify the fix
file /home/edudisplej/edudisplej-init.sh
# Should show: Bourne-Again shell script, ASCII text executable
# (NOT: ASCII text executable, with CRLF line terminators)
```

**Prevention:**
The repository includes a `.gitattributes` file that ensures all shell scripts use Unix line endings (LF) when checked out from Git.

### Check service status

```bash
sudo systemctl status edudisplej-init.service
journalctl -u edudisplej-init.service -b
```

## License

© 2025 Nagy Andras 
