# EduDisplej Health Monitoring & Command Execution System

## Overview

Új health monitoring és parancskezelő rendszer a Raspberry Pi-k számára. Lehetővé teszi az admin panelről a kioskok állapotának nyomon követését, parancsok végrehajtását, terminál hozzáférést és rendszer-vezérlést.

## Komponensek

### 1. Health Check Service (`edudisplej-health.service`)

**Fájl:** `/opt/edudisplej/init/edudisplej-health.sh`

**Funkcionalitás:**
- Rendszeri erőforrások monitorozása (CPU, RAM, hőmérséklet, disk)
- Szolgáltatások státuszának ellenőrzése
- Hálózati kapcsolat tesztelése
- Szinkronizálás státuszának figyelése
- Fast loop mód felismerése

**Intervallumok:**
- Normál módban: 300 másodperc (5 perc)
- Fast loop módban: 5 másodperc

**API Végpont:** `POST /api/health/report.php`

### 2. Command Executor Service (`edudisplej-command-executor.service`)

**Fájl:** `/opt/edudisplej/init/edudisplej-command-executor.sh`

**Funkcionalitás:**
- Parancsok lekérése az API-ból
- Parancsok végrehajtása biztonságosan
- Eredmények visszaküldése az API-nak
- Timeout kezelés (5 perc/parancs)

**Támogatott parancs típusok:**
- `custom` - Felhasználó által megadott parancs
- `reboot` - Rendszer újraindítás
- `restart_service` - Szolgáltatás újraindítása
- `enable_fast_loop` - Fast loop mód bekapcsolása
- `disable_fast_loop` - Fast loop mód kikapcsolása

**API Végpontok:**
- GET `/api/kiosk/get_commands.php` - Függőben lévő parancsok lekérése
- POST `/api/kiosk/command_result.php` - Eredmények feltöltése

### 3. API Végpontok

#### Health Monitoring

**POST `/api/health/report.php`**
- Kiosk által küldött health report
- Rendszer adatokat, szolgáltatás státuszokat, hálózati infókat tartalmaz

**GET `/api/health/status.php?kiosk_id=1`**
- Egy kiosk legutolsó health adatai

**GET `/api/health/list.php`**
- Összes kiosk health státusza
- Szűrhetők: `company_id`, `status`

#### Command Execution

**POST `/api/kiosk/execute_command.php`**
Parancs üzenetezéshez:
```json
{
    "kiosk_id": 1,
    "command": "whoami",
    "command_type": "custom"
}
```

**GET `/api/kiosk/get_commands.php`**
- Kiosk által hívott, függőben lévő parancsok lekérése
- Szükséges: API token az Authorization header-ben

**POST `/api/kiosk/command_result.php`**
- Kiosk küldi az eredményt
- Szükséges: API token az Authorization header-ben

**POST `/api/kiosk/control_fast_loop.php`**
- Fast loop mód be/kikapcsolása

**POST `/api/kiosk/reboot.php`**
- Rendszer újraindítás üzenetezése

**GET `/api/kiosk/get_command_result.php?command_id=1`**
- Parancs eredményének lekérdezése az admin panelről

### 4. Admin Dashboard (`/admin/kiosk_health.php`)

**Funkcionalitások:**
- Összes kiosk health státusza egy helyről
- Real-time monitor:
  - CPU hőmérséklet
  - Memória használat
  - Disk terület
  - Utolsó frissítés időpontja

**Vezérlési opciók:**
- 🖥️ **Terminal** - Remote parancs végrehajtás
- ⚡ **Fast Loop** - Gyors szinkronizálás bekapcsolása (5 mp)
- 🔄 **Reboot** - Rendszer újraindítás

**Terminál funkciók:**
- Valós idejű parancs végrehajtás
- Eredmények és hibák megjelenítése
- Parancsok várakozási sorának nyilvántartása

### 5. Adatbázis Táblák

**Automatikusan létrehozva a `dbjavito.php`-val:**

```sql
kiosk_health
├── id (PK)
├── kiosk_id (FK)
├── status (enum: healthy, warning, critical)
├── system_data (JSON)
├── services_data (JSON)
├── network_data (JSON)
├── sync_data (JSON)
└── timestamp

kiosk_health_logs (audit trail)
├── id (PK)
├── kiosk_id (FK)
├── status
├── details (JSON)
└── created_at

kiosk_command_queue
├── id (PK)
├── kiosk_id (FK)
├── command_type
├── command (TEXT)
├── status (pending, executed, failed, timeout)
├── output (LONGTEXT)
├── error (LONGTEXT)
├── created_at
└── executed_at

kiosk_command_logs (audit trail)
├── id (PK)
├── kiosk_id (FK)
├── command_id (FK)
├── action
├── details (JSON)
└── created_at
```

## Telepítés

### 1. Adatbázis inicializálása

Felkeresés: `http://control.edudisplej.sk/dbjavito.php`

Automatikusan létrehozza az összes szükséges táblát és indexet.

### 2. Szolgáltatások aktiválása

Az `install.sh` automatikusan telepíti és indítja:
- `edudisplej-health.service`
- `edudisplej-command-executor.service`

### 3. Install.sh frissítés

Az `install.sh`-ból a `structure.json` letöltéskor fel kell venni az új serviceket:

```json
{
    "services": [
        {
            "source": "edudisplej-health.sh",
            "name": "edudisplej-health.service",
            "enabled": true,
            "autostart": true,
            "description": "Health monitoring service"
        },
        {
            "source": "edudisplej-command-executor.sh",
            "name": "edudisplej-command-executor.service",
            "enabled": true,
            "autostart": true,
            "description": "Remote command executor"
        }
    ]
}
```

## Biztonsági megfontolások

### Command Injection Protection

1. **Custom parancsok korlátozása:**
   - Veszélyes pattern detektálása (rm -rf, dd, mkfs, command substitution)
   - Whitelist alapú parancs végrehajtás ha szükséges

2. **API Token szükséges:**
   - Command executor csak érvényes token-nel működik
   - Admin panel csak bejelentkezett felhasználónak elérhető

3. **Timeout kezelés:**
   - Parancsok 5 percnél tovább nem futhatnak
   - Automata error státusz timeout után

### Fast Loop Mód

- Gyorsított szinkronizálás (5 mp helyett 300 mp)
- Admin panelről be/kikapcsolható
- `/.fast_loop_enabled` flag fájl jelzi
- Health check is gyorsabb intervallummal fut

## Logging

**Health Check Log:** `/opt/edudisplej/logs/health.log`
**Command Executor Log:** `/opt/edudisplej/logs/command_executor.log`

## Troubleshooting

### Health Check Service nem indul

```bash
# Naplók ellenőrzése
journalctl -u edudisplej-health.service -f

# Kézi indítás debug módban
EDUDISPLEJ_DEBUG=true /opt/edudisplej/init/edudisplej-health.sh
```

### Parancsok nem hajtódnak végre

1. Command executor service futó-e?
   ```bash
   systemctl status edudisplej-command-executor.service
   ```

2. API token elérhető-e?
   ```bash
   cat /opt/edudisplej/lic/token
   ```

3. Naplók:
   ```bash
   journalctl -u edudisplej-command-executor.service -f
   ```

## Statisztikák és Monitoring

### Admin Dashboard statisztikák

- **Total Kiosks** - Összes kiosk száma
- **Online** - Egészséges és elérhető kioskok
- **Warning** - Magas CPU/memória/hőmérséklet
- **Offline** - 24 óra alatt nincs frissítés

## Jövőbeli fejlesztések

1. **Предефинированные parancsok:**
   - Közös karbantartási parancsok
   - GUI-n keresztül kiválasztható

2. **Log archiválás:**
   - Régi naplók tömörítése
   - Hosszú távú adattárolás

3. **Alerting:**
   - Email értesítések kritikus státuszokról
   - Webhook integrációk

4. **Metrics export:**
   - Prometheus kompatibilítás
   - Grafana dashboard
