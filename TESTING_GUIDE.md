# EduDisplej Kiosk System - Testing Guide

## Pre-Installation Testing

### Test 1: Fresh Installation
```bash
# On a fresh Raspberry Pi or similar device:
curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash
```

**Expected Behavior:**
- Downloads all required files from server
- Creates `/opt/edudisplej/` directory structure
- Configures passwordless sudo for init script
- Configures autologin on tty1
- Reboots system after 10 seconds

**Verify:**
- [ ] All files downloaded successfully
- [ ] No CRLF line ending issues
- [ ] Sudoers configuration created in `/etc/sudoers.d/edudisplej`
- [ ] Autologin configured for tty1
- [ ] System reboots automatically

### Test 2: Post-Reboot System Start
After reboot, the system should:

**Expected Behavior:**
- System boots to tty1 and auto-logs in as console user
- User's `.profile` checks if first-time setup is needed
- If first boot: init script runs in foreground (visible on tty1)
- Shows EduDisplej banner and loading messages
- Waits for internet connection (up to 60 seconds)
- Checks and installs required packages with progress bar
- Registers device to server (if internet available)
- Shows system summary
- X server starts automatically after initialization

**Verify:**
- [ ] Auto-login works on tty1
- [ ] Init script runs visibly on first boot
- [ ] Progress bar shows package installation
- [ ] Internet connectivity check works
- [ ] Required packages installed (openbox, xinit, unclutter, chromium-browser, etc.)
- [ ] Device registration succeeds
- [ ] System summary displays correct information
- [ ] X server and kiosk mode start automatically

## Component Testing

### Test 3: Chromium Kiosk Mode
**Expected Behavior:**
- X server (Xorg) starts on display :0
- Openbox window manager starts
- Chromium launches in kiosk mode
- Displays clock.html in full screen
- No mouse cursor visible (unclutter)
- No screensaver or power management

**Verify:**
- [ ] X server running (`ps aux | grep Xorg`)
- [ ] Chromium running (`ps aux | grep chromium`)
- [ ] Display shows clock.html
- [ ] Full screen (no window decorations)
- [ ] Mouse cursor hidden after 0.5 seconds
- [ ] Screen doesn't blank

**Chromium Flags Check:**
```bash
# Check chromium is using optimized flags:
ps aux | grep chromium | grep kiosk
```
Should include: `--no-sandbox --disable-dev-shm-usage --disable-gpu --renderer-process-limit=1`

### Test 4: Device Registration
**Expected Behavior:**
- Device registers on first boot (when internet available)
- Registration info stored in `/opt/edudisplej/.registration.json`
- Does not re-register on subsequent boots
- MAC address and hostname sent to server

**Verify:**
- [ ] `.registration.json` file created
- [ ] Contains `"registered": true`
- [ ] Contains correct MAC address
- [ ] Contains device ID from server
- [ ] Database entry created on server
- [ ] No duplicate registrations

**Manual Check:**
```bash
# Check registration status:
cat /opt/edudisplej/.registration.json | jq .

# Check registration log:
cat /opt/edudisplej/registration.log

# Force re-registration (for testing):
sudo rm /opt/edudisplej/.registration.json
# Then reboot or manually run:
sudo /opt/edudisplej/init/edudisplej-init.sh
```

### Test 5: Logging System
**Expected Behavior:**
- Logs created in `/opt/edudisplej/`
- All logs include timestamps
- Logs rotate when exceeding 2MB
- Old logs moved to `.old` extension
- No disk fill-up issues

**Verify:**
- [ ] `session.log` exists and has timestamps
- [ ] `xclient.log` exists and has timestamps
- [ ] `apt.log` created when packages installed
- [ ] Log rotation works (test with large log files)
- [ ] Old logs preserved as `.old` files

**Manual Check:**
```bash
# Check log files:
ls -lh /opt/edudisplej/*.log

# Check log content:
tail -20 /opt/edudisplej/session.log
tail -20 /opt/edudisplej/xclient.log

# Test log rotation:
dd if=/dev/zero of=/opt/edudisplej/test.log bs=1M count=3
# Then restart service and verify .old file created
```

### Test 6: Watchdog Functionality
**Expected Behavior:**
- Chromium automatically restarts if it crashes
- Maximum 3 restart attempts per minute
- Waits 15 seconds between restart attempts
- Prevents infinite restart loops

**Verify:**
- [ ] Chromium restarts after manual kill
- [ ] Restart logged in xclient.log
- [ ] Delays between restarts work
- [ ] No infinite loops

**Manual Test:**
```bash
# Kill chromium and watch it restart:
pkill chromium
# Watch logs:
tail -f /opt/edudisplej/xclient.log

# Should see: "[xclient] Browser exited; restarting after 15s"
```

### Test 7: Package Management
**Expected Behavior:**
- Checks for required packages on boot
- Installs missing packages automatically
- Logs installation to apt.log
- Verifies each package after installation

**Verify:**
- [ ] Package check runs on boot
- [ ] Missing packages installed automatically
- [ ] Installation logged
- [ ] System recovers if package install fails

**Manual Test:**
```bash
# Remove a package and reboot:
sudo apt remove unclutter
sudo reboot
# After reboot, check if it reinstalls:
dpkg -l | grep unclutter
```

### Test 8: Process Management
**Expected Behavior:**
- Uses proper PID-based process termination
- No `pkill` usage (security risk)
- Graceful termination (TERM before KILL)
- Proper cleanup of zombie processes

**Verify:**
- [ ] No pkill in any script
- [ ] Processes terminated gracefully
- [ ] No zombie processes
- [ ] Proper PID handling

**Manual Check:**
```bash
# Search for pkill usage (should be none):
grep -r "pkill" /opt/edudisplej/init/

# Check for zombie processes:
ps aux | grep defunct
```

## Integration Testing

### Test 9: Low-End Hardware
Test on Raspberry Pi 3 or similar low-end device:

**Verify:**
- [ ] System boots successfully
- [ ] Chromium doesn't crash
- [ ] UI remains responsive
- [ ] No out-of-memory issues
- [ ] CPU usage acceptable (<80%)

### Test 10: Network Scenarios

**Test 10a: No Internet on Boot**
- Disconnect network before boot
- System should start in offline mode
- Display local clock.html
- No device registration attempted

**Test 10b: Internet Lost After Boot**
- Start with internet
- Disconnect during operation
- Chromium should continue running
- Re-registration not attempted

**Test 10c: Internet Restored**
- Start without internet
- Connect internet after boot
- Next boot should register device

**Verify:**
- [ ] Offline mode works
- [ ] No errors when offline
- [ ] Registration attempted when online
- [ ] System resilient to network changes

### Test 11: Crash Recovery
**Expected Behavior:**
- System recovers from crashes
- Chromium restarts automatically
- X server restarts if needed
- Logs all crash events

**Manual Tests:**
```bash
# Test 1: Kill chromium
pkill chromium
# Verify: Restarts automatically

# Test 2: Kill X server
sudo pkill Xorg
# Verify: System returns to login prompt, user auto-logs in, X restarts

# Test 3: Manually restart initialization
sudo /opt/edudisplej/init/edudisplej-init.sh
# Verify: Init script runs and everything restarts cleanly
```

### Test 12: Menu System
**Expected Behavior:**
- Press M or F12 during countdown to enter menu
- Menu allows configuration changes
- Settings persist across reboots

**Verify:**
- [ ] Menu accessible via M or F12
- [ ] Language selection works
- [ ] Network configuration works
- [ ] Display settings work
- [ ] Custom URL can be set
- [ ] Settings saved to config file
- [ ] Settings persist after reboot

## Performance Testing

### Test 13: Resource Usage
**Expected Behavior:**
- Low CPU usage when idle (<20%)
- Reasonable memory usage (<500MB on Pi 3)
- No memory leaks over time
- Disk usage stable (logs don't grow infinitely)

**Verify:**
- [ ] CPU usage acceptable
- [ ] Memory usage stable
- [ ] No memory leaks (24 hour test)
- [ ] Disk usage doesn't grow unbounded

**Monitoring Commands:**
```bash
# CPU and memory:
top -b -n 1 | head -20

# Memory usage over time:
watch -n 60 'free -m'

# Disk usage:
df -h /opt/edudisplej/

# Log sizes:
du -sh /opt/edudisplej/*.log
```

### Test 14: Long-Term Stability
**Expected Behavior:**
- Runs continuously for 7+ days
- No crashes or freezes
- Logs rotate properly
- Performance doesn't degrade

**Verify:**
- [ ] 24 hour uptime test passed
- [ ] 7 day uptime test passed
- [ ] No performance degradation
- [ ] Logs managed correctly

## Security Testing

### Test 15: Security Checks
**Expected Behavior:**
- No hardcoded credentials in client scripts
- Proper input sanitization in API
- SQL injection protection
- XSS protection

**Verify:**
- [ ] API uses prepared statements
- [ ] Input sanitization in register.php
- [ ] No SQL injection vulnerabilities
- [ ] No XSS vulnerabilities
- [ ] Database credentials protected on server

## Documentation

### Test 16: Documentation Completeness
**Verify:**
- [ ] IMPLEMENTATION_NOTES.md complete
- [ ] TESTING_GUIDE.md complete
- [ ] Installation instructions clear
- [ ] Troubleshooting section included

## Rollout Checklist

Before production deployment:
- [ ] All tests passed
- [ ] Code review completed
- [ ] Security audit completed
- [ ] Documentation complete
- [ ] Backup and recovery tested
- [ ] Monitoring configured
- [ ] Support team trained

## Known Issues
(Document any known issues or limitations here)

## Troubleshooting

### Issue: Chromium doesn't start
**Debug:**
```bash
# Check X server:
ps aux | grep Xorg

# Check logs:
cat /opt/edudisplej/xclient.log

# Check display:
echo $DISPLAY

# Manual start test:
DISPLAY=:0 chromium --version
```

### Issue: Device not registering
**Debug:**
```bash
# Check internet:
ping -c 3 google.com

# Check registration log:
cat /opt/edudisplej/registration.log

# Test API manually:
curl -X POST https://server.edudisplej.sk/api/register.php \
  -H "Content-Type: application/json" \
  -d '{"hostname":"test","mac":"00:11:22:33:44:55"}'
```

### Issue: High CPU usage
**Debug:**
```bash
# Check processes:
top -b -n 1

# Check chromium flags:
ps aux | grep chromium

# Restart X server:
sudo pkill Xorg
# System will auto-login and restart X via .profile
```

### Issue: Logs filling disk
**Debug:**
```bash
# Check log sizes:
du -sh /opt/edudisplej/*.log

# Check rotation:
ls -lh /opt/edudisplej/*.log*

# Manual cleanup if needed:
sudo truncate -s 0 /opt/edudisplej/*.log
```
