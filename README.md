# EduDisplej

EduDisplej is a Raspberry Pi-based digital signage solution that runs in kiosk mode using the Midori browser. It provides a robust, unattended installation system for Debian/Ubuntu/Raspberry Pi OS.

## Boot Flow (Midori-based)

- install.sh installs and registers the init service that launches [install/init/edudisplej-init.sh](webserver/install/init/edudisplej-init.sh) on boot
- init script loads modules, installs any missing required packages (midori, openbox, xinit, unclutter, curl), shows its current version, and self-updates from the install server when a newer bundle is available
- shows system summary (saved mode, kiosk URL, language, IP/gateway, Wi-Fi SSID, resolution, hostname)
- 10s countdown appears; press F12 or M during countdown to open the configuration menu, otherwise the saved mode starts automatically
- kiosk mode now uses Midori in fullscreen via X/Openbox; it restarts Midori if it exits

## Version

**EDUDISPLEJ INSTALLER v. 28 12 2025**

## Quick Installation

Install EduDisplej on your Raspberry Pi or any Debian-based system with a single command:

```bash
# Quick installation with default settings (recommended)

curl https://install.edudisplej.sk/install.sh | sed 's/\r$//' | sudo bash


---
Alapoktol elteres:
/opt/edudisplej/
és service.

Jelenlegi hiba: midori es csomagokat nem tolti le init..