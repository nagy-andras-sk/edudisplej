# Terminal Display Mode

EduDisplej runs in **terminal display mode** - showing a fullscreen terminal on the main display (tty1).

## What You Should See

A **fullscreen terminal** with the EduDisplej banner and system information:

```
╔═══════════════════════════════════════════════════════════════════════════╗
║                                                                           ║
║   ███████╗██████╗ ██╗   ██╗██████╗ ██╗███████╗██████╗ ██╗     ███████╗   ║
║   ██╔════╝██╔══██╗██║   ██║██╔══██╗██║██╔════╝██╔══██╗██║     ██╔════╝   ║
║   █████╗  ██║  ██║██║   ██║██║  ██║██║███████╗██████╔╝██║     █████╗     ║
║   ██╔══╝  ██║  ██║██║   ██║██║  ██║██║╚════██║██╔═══╝ ██║     ██╔══╝     ║
║   ███████╗██████╔╝╚██████╔╝██████╔╝██║███████║██║     ███████╗███████╗   ║
║   ╚══════╝╚═════╝  ╚═════╝ ╚═════╝ ╚═╝╚══════╝╚═╝     ╚══════╝╚══════╝   ║
║                                                                           ║
╚═══════════════════════════════════════════════════════════════════════════╝

  System je pripraveny / System is ready
  ═══════════════════════════════════════
```

## Key Features

✅ **Runs on tty1** (main console, not vt7!)  
✅ **Cross-platform** (Raspberry Pi, Debian, Ubuntu, etc.)  
✅ **Fullscreen terminal** with system info  
✅ **Interactive bash shell** for commands  
✅ **Professional display** with ASCII banner  

## Verification via SSH

```bash
# 1. Check service status
sudo systemctl status edudisplej-kiosk.service

# 2. Verify X is running on vt1 (NOT vt7!)
ps aux | grep Xorg | grep -o 'vt[0-9]*'
# Expected output: vt1

# 3. Check terminal process
ps aux | grep edudisplej_terminal_script.sh

# 4. View logs
cat /tmp/openbox-autostart.log
tail -20 /tmp/edudisplej-watchdog.log
```

## Architecture Changes

### What Changed:

1. **Fixed VT allocation:**
   - X server now runs on **vt1** (main console)
   - Was incorrectly starting on vt7 before
   - Display now appears on primary screen

2. **Simplified boot flow:**
   - systemd → kiosk-start.sh → startx on vt1 → openbox → xterm → terminal script
   - Removed complex browser launch logic
   - Direct path to goal: terminal display

3. **Cross-platform compatibility:**
   - No Raspberry Pi specific assumptions
   - Auto-detects display outputs
   - Works on Debian, Ubuntu, Raspberry Pi

4. **Package installation:**
   - Only essential packages installed
   - Browser installation skipped (not needed for terminal mode)
   - `xterm` added to required packages

5. **Terminal script improved:**
   - Professional ASCII banner
   - Shows system information (hostname, IP, resolution)
   - Interactive bash shell

## Troubleshooting

### Display on wrong VT (nothing visible on screen)

**Check:**
```bash
ps aux | grep Xorg | grep -o 'vt[0-9]*'
```

**Expected:** `vt1`  
**If you see:** `vt7` → Edit `/opt/edudisplej/init/kiosk-start.sh` and change to `vt1`

### Terminal not appearing

**Check logs:**
```bash
cat /tmp/openbox-autostart.log | grep -i terminal
```

**Verify script is executable:**
```bash
ls -la /opt/edudisplej/init/edudisplej_terminal_script.sh
# Should show: -rwxr-xr-x (executable)
```

### Manual terminal test

```bash
# Run as the console user
DISPLAY=:0 xterm -fullscreen -bg black -fg green &
```

If this works, the issue is in the autostart script.

## Adding Browser Later (Optional)

The system is designed for terminal display only. If you need to add browser functionality later:

1. Install browser:
```bash
sudo apt-get install chromium-browser
```

2. Modify Openbox autostart:
```bash
sudo nano /home/<user>/.config/openbox/autostart
```

3. Add browser launch (after terminal):
```bash
# Launch browser
chromium-browser --kiosk --no-sandbox "https://www.time.is" &
```

## Success Criteria

✅ **Terminal visible on main display (physical screen)**  
✅ **X server running on vt1**  
✅ **EduDisplej banner displayed**  
✅ **Interactive bash shell working**  
✅ **System info showing correct details**  

## Documentation

See [BOOTORDER.md](BOOTORDER.md) for complete architecture documentation and boot sequence details.
