# Module Download and Loop System

## Áttekintés / Overview

Az EduDisplej rendszer automatikusan letölti a modulokat és elindítja a loop playert amikor a kijelző elindul.

## Architektúra / Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                    KIOSK STARTUP FLOW                        │
└─────────────────────────────────────────────────────────────┘

1. System Boot
   └─> Auto-login (edudisplej user)
       └─> startx (X server)
           └─> Openbox
               └─> autostart script
                   │
                   ├─> edudisplej-download-modules.sh
                   │   │
                   │   ├─> GET kiosk_loop.php (loop config)
                   │   │   └─> Save to loop.json
                   │   │
                   │   ├─> GET download_module.php (for each module)
                   │   │   └─> Save to /opt/edudisplej/localweb/modules/{name}/
                   │   │
                   │   └─> Create loop_player.html
                   │
                   └─> surf -F file:///opt/edudisplej/localweb/loop_player.html
                       └─> Loop Player JavaScript
                           └─> Load loop.json
                               └─> Play modules in sequence (infinite loop)
```

## API Végpontok / API Endpoints

### 1. `/api/kiosk_loop.php`
**Cél**: Loop konfiguráció lekérdezés device_id alapján

**Request:**
```bash
POST /api/kiosk_loop.php
device_id=ABC123
```

**Response:**
```json
{
  "success": true,
  "kiosk_id": 42,
  "device_id": "ABC123",
  "loop_config": [
    {
      "module_id": 1,
      "module_name": "datetime",
      "module_type": "html",
      "duration_seconds": 10,
      "display_order": 1,
      "settings": "{\"type\":\"digital\",\"format\":\"24h\"}",
      "source": "group"
    }
  ],
  "module_count": 1
}
```

### 2. `/api/download_module.php`
**Cél**: Modul fájlok letöltése device_id és module_name alapján

**Request:**
```bash
POST /api/download_module.php
device_id=ABC123&module_name=datetime
```

**Response:**
```json
{
  "success": true,
  "module_name": "datetime",
  "files": [
    {
      "path": "m_datetime.html",
      "content": "PCFET0NUWVBFIGh0bWw+...", // base64 encoded
      "size": 12345,
      "modified": "2026-02-01 14:30:00"
    }
  ],
  "file_count": 1,
  "last_update": "2026-02-01 14:30:00"
}
```

## Fájlok / Files

### 1. `edudisplej-download-modules.sh`
**Lokáció**: `/opt/edudisplej/init/edudisplej-download-modules.sh`

**Funkció**:
- Betölti a device_id-t a `/opt/edudisplej/kiosk.conf`-ból
- Lekéri a loop konfigurációt az API-ról
- Letölti az összes engedélyezett modult
- Elmenti a loop.json-t metaadatokkal
- Létrehozza a loop_player.html-t

**Használat**:
```bash
# Manuális futtatás
sudo /opt/edudisplej/init/edudisplej-download-modules.sh

# Automatikusan fut az openbox autostart scriptből
```

### 2. `loop.json`
**Lokáció**: `/opt/edudisplej/localweb/modules/loop.json`

**Struktúra**:
```json
{
  "last_update": "2026-02-01 14:30:00",
  "loop": [
    {
      "module_id": 1,
      "module_name": "datetime",
      "module_type": "html",
      "duration_seconds": 10,
      "display_order": 1,
      "settings": "{\"type\":\"digital\",\"format\":\"24h\"}",
      "source": "group"
    }
  ]
}
```

### 3. `loop_player.html`
**Lokáció**: `/opt/edudisplej/localweb/loop_player.html`

**Funkció**:
- JavaScript-alapú loop player
- Betölti a loop.json-t
- Iframe-ben tölti be a modulokat
- URL paraméterekkel konfigurálja őket
- Automatikus loop (végtelen)
- Hibakezelés és retry

### 4. Module Files
**Lokáció**: `/opt/edudisplej/localweb/modules/{module_name}/`

**Példa**: `/opt/edudisplej/localweb/modules/datetime/`
```
datetime/
├── m_datetime.html         # Fő modul fájl
└── .metadata.json          # Metadata (last_update, stb.)
```

## Konfiguráció / Configuration

### Openbox Autostart
**Lokáció**: `~/.config/openbox/autostart` (vagy beégetve az edudisplej-init.sh-ba)

**Tartalom**:
```bash
# Download modules and create loop player
DOWNLOAD_SCRIPT="/opt/edudisplej/init/edudisplej-download-modules.sh"
if [ -x "$DOWNLOAD_SCRIPT" ]; then
    echo "Downloading modules and loop configuration..."
    "$DOWNLOAD_SCRIPT" >> "$LOG" 2>&1
fi

# Start surf browser with loop player
LOOP_PLAYER="/opt/edudisplej/localweb/loop_player.html"
if [ -f "$LOOP_PLAYER" ]; then
    echo "Starting surf browser with loop player..."
    surf -F "file://${LOOP_PLAYER}" &
fi
```

## Telepítés / Installation

### 1. Új telepítés
```bash
curl -fsSL https://install.edudisplej.sk/install.sh | sudo bash
```

A `structure.json` tartalmazza:
- `edudisplej-download-modules.sh` → `/opt/edudisplej/init/`

### 2. Frissítés meglévő rendszeren
```bash
sudo /opt/edudisplej/update.sh
```

Ez automatikusan:
- Letölti az új `edudisplej-download-modules.sh` fájlt
- Telepíti a `jq` package-et (JSON parsing)
- Frissíti az `edudisplej-init.sh`-t

## Függőségek / Dependencies

- **curl**: HTTP kérések
- **jq**: JSON parsing
- **surf**: Böngésző (minimális, WebKit-alapú)
- **base64**: Fájl dekódolás

## Hibaelhárítás / Troubleshooting

### 1. Modulok nem töltődnek le
```bash
# Ellenőrizd a device_id-t
cat /opt/edudisplej/kiosk.conf

# Manuálisan futtasd a download scriptet
sudo /opt/edudisplej/init/edudisplej-download-modules.sh

# Ellenőrizd az API elérhetőségét
curl -X POST https://control.edudisplej.sk/api/kiosk_loop.php \
  -d "device_id=$(grep DEVICE_ID /opt/edudisplej/kiosk.conf | cut -d= -f2)"
```

### 2. Loop player nem indul
```bash
# Ellenőrizd, hogy létezik-e
ls -l /opt/edudisplej/localweb/loop_player.html

# Ellenőrizd a loop.json-t
cat /opt/edudisplej/localweb/modules/loop.json | jq .

# Nézd meg az openbox log-ot
cat /tmp/openbox-autostart.log

# Ellenőrizd a surf folyamatot
ps aux | grep surf
```

### 3. Hiányzó jq
```bash
# Telepítsd manuálisan
sudo apt-get update
sudo apt-get install -y jq
```

### 4. JSON parsing hiba
```bash
# Ellenőrizd a loop.json szintaxist
jq . /opt/edudisplej/localweb/modules/loop.json

# Ha hibás, töröld és töltsd le újra
sudo rm /opt/edudisplej/localweb/modules/loop.json
sudo /opt/edudisplej/init/edudisplej-download-modules.sh
```

## Fejlesztés / Development

### Új modul hozzáadása

1. **Szerveren**: Hozd létre a modul könyvtárat
   ```
   /var/www/control.edudisplej.sk/modules/mymodule/
   └── m_mymodule.html
   ```

2. **Admin felületen**: Add hozzá a modult a csoporthoz
   - Dashboard → Groups → Loop Configuration
   - Drag & drop a modult a loop-ba
   - Állítsd be a duration-t és settings-et

3. **Kiosk**: Automatikusan letöltődik a következő újraindításkor

### Loop Player testreszabás

A `loop_player.html` módosítható az `edudisplej-download-modules.sh`-ban (beágyazva van).

**Fontos részek**:
- `loadLoopConfig()`: Loop betöltés
- `buildModuleUrl()`: Modul URL építés settings-ekkel
- `playCurrentModule()`: Modul lejátszás
- `nextModule()`: Következő modul (loop)

## Biztonsági megfontolások / Security

- **Device ID alapú autentikáció**: Csak regisztrált device_id-k tölthetnek le modulokat
- **Csoport/Kiosk jogosultság ellenőrzés**: Az API ellenőrzi, hogy a kiosk jogosult-e az adott modulhoz
- **Base64 kódolás**: Fájlok biztonságosan kerülnek átvitelre
- **File:// protokoll**: Helyi fájlok, nincs hálózati kitettség a loop lejátszás közben

---

**Készítette / Created by**: Nagy András
**Dátum / Date**: 2026-02-01
