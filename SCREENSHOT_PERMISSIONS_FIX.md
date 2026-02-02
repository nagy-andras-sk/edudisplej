# Screenshot Service - Integrated Solution

## Overview

A screenshot funkció integrálva lett az `edudisplej_sync_service.sh`-ba. Ez azt jelenti:
- ✅ Nincsen külön `edudisplej` user
- ✅ Nincs külön screenshot service
- ✅ Az edudisplej_sync_service.sh-val egy szálban futnak
- ✅ Az edudisplej-sync.service indítja automatikusan
- ✅ Ugyanazzal a ciklussal működik (SYNC_INTERVAL-al)

## Architecture

```
edudisplej-sync.service
    └─ edudisplej_sync_service.sh (CONSOLE_USER/pi user-el)
        ├─ register_and_sync()
        │  └─ Device registration, kiosk_id sync, loop config
        ├─ capture_and_upload_screenshot()
        │  ├─ Check screenshot_enabled flag from config.json
        │  ├─ Capture screenshot (DISPLAY=:0)
        │  ├─ Encode to base64
        │  └─ Upload to API
        └─ Sleep SYNC_INTERVAL seconds
```

## How It Works

### 1. Screenshot Enable/Disable
Ugyanaz, mint a korábbi megoldás:
- Dashboard-on ki/be lehet kapcsolni kijelzőnként
- Toggle API frissíti a `screenshot_enabled` flag-et config.json-ben
- Frissíti a `sync_interval`-t is:
  - Screenshot enabled → 15 másodperc
  - Screenshot disabled → 120 másodperc

### 2. Screenshot Cycle
Az edudisplej_sync_service.sh main loop-jában:
```bash
while true; do
    # Regular sync
    if register_and_sync; then
        log "Sync completed successfully"
        # Screenshot capture - ha enabled
        capture_and_upload_screenshot
    fi
    sleep "$SYNC_INTERVAL"
done
```

### 3. Graceful Error Handling
Ha nincs scrot, vagy DISPLAY nem elérhető:
- A service nem crash-el
- Szimplán bejelegyzi: `Screenshots disabled - skipping`
- A sync folyamatát nem blokkolja

## Modified Files

### webserver/install/install.sh
- ✅ Eltávolítva: `edudisplej` user creation
- ✅ Eltávolítva: edudisplej-screenshot-service.service setup
- ✅ Szimplifikálva: permissions setup (csak CONSOLE_USER ownership)
- Screenshots könyvtár: `${TARGET_DIR}/data/screenshots`

### webserver/install/init/edudisplej-config-manager.sh
- ✅ Eltávolítva: edudisplej user reference
- ✅ Szimplifikálva: ownership setup
- Screenshots könyvtár: auto-create

### webserver/install/init/edudisplej_sync_service.sh
- ✅ Hozzáadva: `capture_and_upload_screenshot()` függvény
- ✅ Integrálva a main loop-ba
- ✅ Auto-skip ha screenshot_enabled = false
- ✅ Auto-skip ha scrot nincs telepítve

## Installation

Az új install.sh teljesen automatikus:
```bash
# Ugyanaz az install parancs:
curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash

# A rendszer:
# ✓ Telepít scrot-ot (automatikusan included)
# ✓ Beállít screenshots könyvtárat
# ✓ Létrehozza config.json-t
# ✓ edudisplej-sync.service indul
# ✓ Automatikus screenshot capture (ha enabled)
```

## Testing

```bash
# Device-on SSH-val:

# 1. Check sync service running
sudo systemctl status edudisplej-sync.service

# 2. Watch logs (including screenshot uploads)
sudo journalctl -u edudisplej-sync.service -f

# 3. Manuálisan ki/be lehet kapcsolni:
curl -X POST https://control.edudisplej.sk/api/toggle_screenshot.php \
  -H "Content-Type: application/json" \
  -d '{"kiosk_id": <ID>, "screenshot_enabled": 1}'

# 4. Screenshot-ok megtekintése (szerver oldalon)
ls -lh /var/www/html/control_edudisplej_sk/screenshots/
```

## Quick Verification

```bash
# A config-ban check:
cat /opt/edudisplej/data/config.json | jq '.screenshot_enabled'

# Logs:
sudo journalctl -u edudisplej-sync.service | grep -i screenshot

# Manual test:
DISPLAY=:0 scrot /tmp/test.png && echo "OK" || echo "FAILED"
```

## Advantages vs Old Solution

| Aspekt | Régi | Új |
|--------|------|-----|
| Extra user | ✗ edudisplej | ✓ Nincs |
| Extra service | ✗ screenshot-service | ✓ Nincs |
| Permission issues | ✗ Sok | ✓ Nincs |
| Sync interval | ✗ Rögzített 15s | ✓ Dynamic |
| Screenshot-ok | ✗ 15s-onként | ✓ SYNC_INTERVAL-al |
| Integration | ✗ Separate | ✓ Unified |
| Maintenance | ✗ 2 service | ✓ 1 service |

## No Breaking Changes

- Dashboard még ugyanúgy működik
- API endpoints ugyanazok
- Config.json formátuma ugyanaz
- Screenshot_enabled flag ugyanaz
- Upload API endpoint ugyanaz

Csak a backend implementáció lett módosítva - a kliens oldalról nézve semmi nem változott!

